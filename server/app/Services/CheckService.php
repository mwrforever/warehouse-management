<?php

// 盘点单服务：草稿创建/更新/删除、审核（事务+行锁+状态前置校验）+ 盘盈盘亏走统一库存引擎

namespace App\Services;

use App\Exceptions\InventoryException;
use App\Models\DocumentSequence;
use App\Models\InventoryBalance;
use App\Models\InventoryCheck;
use App\Models\InventoryCheckItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 盘点单领域服务（D-1：控制器写操作全部下沉至此）
 *
 * 承接原控制器的单据写流程：草稿 CRUD、审核，全部在 DB::transaction 内
 * 「锁单据头行 → 复查状态 → 变更」三步执行（锁序铁律：单据头 → 明细 → 库存）。
 * 业务失败统一抛 InventoryException（业务码沿用原口径 422/1201~1206），
 * 由全局异常处理器渲染 {code, message, data} 信封，与原控制器 fail() 响应等价。
 * 审核时盘盈/盘亏逐项经 InventoryService（全系统唯一库存写入口）落流水，
 * 余额行锁序由引擎按 (product_id, warehouse_id, location_id) 升序统一规范化。
 * 非线程安全：同一单据的并发写依赖数据库行锁串行化，勿在事务外组合调用多个写方法。
 */
class CheckService
{
    public function __construct(
        private DocumentSequenceService $sequenceService,
        private InventoryService $inventoryService,
    ) {}

    /**
     * 新建盘点草稿（原控制器 store 下沉）：账面数服务端快照；实盘负数 1201；无余额商品 1205
     *
     * 校验链：明细归一化（事务外：查重/负实盘/无余额校验 + 账面快照）→
     * 事务内持久序列取号建单 + 建明细。幂等性：单号撞号由序列服务自动换号重试；
     * 事务死锁(1213)整体回滚后重跑重取号。
     *
     * @param  array  $data  已过 SaveCheckRequest 格式校验的载荷（warehouse_id/remark/items）
     * @return InventoryCheck 新建的盘点单模型（含单号，供控制器回显）
     *
     * @throws InventoryException 明细重复商品×库位 422 / 实盘数为负 1201 / 无余额商品 1205
     */
    public function create(array $data): InventoryCheck
    {
        // 明细归一化：查重/负实盘/无余额校验 + 账面数快照（事务外先校验，与原实现错误优先级一致）
        $items = $this->prepareItems($data['items'], (int) $data['warehouse_id']);

        // 事务第 2 参数为死锁(1213)重试次数（机理同 BomController::store：序列行首建间隙锁
        // 死锁败方整体回滚后重跑闭包重新取号，幂等安全）
        $check = DB::transaction(function () use ($data, $items) {
            // 建单：单号走持久序列（并发撞号 1062/19 由服务换号重试；删除不回退号段）
            $check = $this->sequenceService->nextNoByConfig(
                DocumentSequence::TYPE_CHECK,
                fn (string $no) => InventoryCheck::create([
                    'no' => $no,
                    'warehouse_id' => $data['warehouse_id'],
                    'status' => InventoryCheck::STATUS_DRAFT,
                    'remark' => $data['remark'] ?? null,
                ]),
                // 老库衔接：序列行首次初始化时以当日既有 CK 单号段最大值为起点
                fn (string $prefix, string $dateKey) => ($no = InventoryCheck::where('no', 'like', $prefix.date('Ymd').'%')
                    ->orderByDesc('no')->value('no')) ? DocumentSequenceService::seqFromNo($no, $prefix, $dateKey) : 0,
            );
            foreach ($items as $i) {
                InventoryCheckItem::create(['check_id' => $check->id] + $i);
            }

            return $check;
        }, 2);

        // 单据创建审计日志（事务提交后记）：单号 + 盘点行数 + 操作人
        Log::info('盘点单创建成功', [
            'no' => $check->no, 'item_count' => count($items), 'created_by' => auth()->id(),
        ]);

        return $check;
    }

    /**
     * 更新盘点草稿（原控制器 update 下沉）：仅草稿可改（1202）；items 全量替换
     *
     * 明细归一化（422/1201/1205）与原实现一致在事务外先行（无效明细先于状态冲突返回）；
     * 事务内锁单据头行复查状态：与审核并发时防止改到正在审核的单（幂等 1202）。
     *
     * @param  InventoryCheck  $check  路由绑定的盘点单模型
     * @param  array  $data  已过 SaveCheckRequest 格式校验的载荷
     *
     * @throws InventoryException 明细业务校验 422/1201/1205 / 已审核 1202
     */
    public function update(InventoryCheck $check, array $data): void
    {
        // 明细归一化同 create（事务外先校验，与原实现错误优先级一致）
        $items = $this->prepareItems($data['items'], (int) $data['warehouse_id']);
        // 状态前置校验（不进事务，快速失败）：已审核直接拒绝（与 1202 同口径）
        if ($check->status === InventoryCheck::STATUS_APPROVED) {
            throw new InventoryException('已审核单据不可修改', 1202);
        }
        DB::transaction(function () use ($check, $data, $items) {
            // 锁盘点单行复查状态：与审核并发时防止改到正在审核的单（幂等 1202）
            $locked = InventoryCheck::whereKey($check->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === InventoryCheck::STATUS_APPROVED) {
                throw new InventoryException('已审核单据不可修改', 1202);
            }
            $locked->update(['warehouse_id' => $data['warehouse_id'], 'remark' => $data['remark'] ?? $locked->remark]);
            // 明细全量替换（旧行随头级联或先删后插）
            $locked->items()->delete();
            foreach ($items as $i) {
                InventoryCheckItem::create(['check_id' => $locked->id] + $i);
            }
        });
    }

    /**
     * 删除盘点草稿（原控制器 destroy 下沉）：已审核不可删（1203）
     *
     * 事务内锁单据头行复查状态：与审核并发时防止删到正在审核的单（幂等 1203）。
     *
     * @param  InventoryCheck  $check  路由绑定的盘点单模型（内存模型持单号供审计日志追溯）
     *
     * @throws InventoryException 已审核 1203
     */
    public function delete(InventoryCheck $check): void
    {
        // 状态前置校验（不进事务，快速失败）：已审核直接拒绝（与 1203 同口径）
        if ($check->status === InventoryCheck::STATUS_APPROVED) {
            throw new InventoryException('已审核单据不可删除', 1203);
        }
        DB::transaction(function () use ($check) {
            // 锁盘点单行复查状态：与审核并发时防止删到正在审核的单（幂等 1203）
            $locked = InventoryCheck::whereKey($check->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === InventoryCheck::STATUS_APPROVED) {
                throw new InventoryException('已审核单据不可删除', 1203);
            }
            $locked->delete();
        });

        // 单据删除审计日志（事务提交后记）：内存模型仍持有单号，可用于追溯
        Log::info('盘点单草稿删除', ['no' => $check->no, 'operator' => auth()->id()]);
    }

    /**
     * 审核盘点单（原控制器 approve 下沉）：事务内逐项生成 check_in/check_out 流水并更新余额；幂等 1204；并发 1206
     *
     * 盘盈/盘亏行按商品聚合变更：循环内逐行调 InventoryService::apply（单笔调用，余额行锁序
     * 规范化由引擎统一），循环外单条聚合日志（changed_items/increased/decreased，禁止逐明细打印）；
     * 锁序与既有实现完全一致：盘点单行 → 明细序内联预锁余额行 → apply 单笔再锁同余额行。
     *
     * @param  InventoryCheck  $check  路由绑定的盘点单模型（内存模型持单号供审计日志追溯）
     * @return array 审核结果：changed_items/increased/decreased/increased_items/decreased_items
     *
     * @throws InventoryException 已审核 1204 / 账面快照被并发变动 1206
     */
    public function approve(InventoryCheck $check): array
    {
        // 状态前置校验（不进事务，快速失败）：同一单据重复审核在此判重（幂等 1204）
        if ($check->status === InventoryCheck::STATUS_APPROVED) {
            throw new InventoryException('该盘点单已审核', 1204);
        }
        $result = null;
        // attempts=2：死锁自动重试一次（B-3；余额行锁序已由 InventoryService 统一，兜底盘点按明细序
        // 内联预锁的残余交叉窗口——apply 单条调用无法重排该预锁序列）
        DB::transaction(function () use ($check, &$result) {
            // 锁盘点单行：同一单据重复审核在此判重（幂等）
            $locked = InventoryCheck::whereKey($check->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === InventoryCheck::STATUS_APPROVED) {
                throw new InventoryException('该盘点单已审核', 1204);
            }
            $changed = 0;
            $increased = '0';  // 盘盈数量合计（bcadd 累加，D-3 铁律禁浮点累加）
            $decreased = '0';  // 盘亏数量合计
            $increasedItems = 0; // 盘盈项数（前端「盘盈 X 项 +N」文案用）
            $decreasedItems = 0; // 盘亏项数
            // 明细行显式标注模型类型（larastan 无法从关系泛型推断 foreach 元素）
            /** @var InventoryCheckItem $item */
            foreach ($locked->items as $item) {
                // 差异 = 实盘 − 账面（bcsub 构造性精确，两位小数字符串满足 decimal cast 的 string 属性）
                $diff = bcsub((string) $item->actual_qty, (string) $item->book_qty, 2);
                $item->diff_qty = $diff;
                $item->save();
                // 零差异不生成流水（差值恒为两位小数，bccomp === 0 即零差异，无浮点容差窗口）
                if (bccomp($diff, '0', 2) === 0) {
                    continue;
                }
                // 锁余额行：账面快照已被并发变动（其他盘点单先审）→ 整体回滚 1206
                $balance = InventoryBalance::where('product_id', $item->product_id)
                    ->where('warehouse_id', $locked->warehouse_id)
                    ->where('location_id', $item->location_id)
                    ->lockForUpdate()
                    ->first();
                // ! $balance 为防御性分支：余额行只增不删，账面快照存在时理论不可达（1205 已拦无账商品）；
                // 若未来支持账外盘盈（暂不做，见 docs/bugs/2026-08-13-盘点盘盈无余额行误拒.md），
                // 此处须改为按「无余额行=账面 0」比对放行盘盈
                // 余额与账面快照均为两位小数，bcsub 差值非 0 即并发变动（替代浮点 0.005 容差比较）
                if (
                    ! $balance
                    || bccomp(bcsub((string) $balance->quantity, (string) $item->book_qty, 2), '0', 2) !== 0
                ) {
                    throw new InventoryException('库存已变动，请重新盘点', 1206);
                }
                $direction = bccomp($diff, '0', 2) > 0 ? 1 : -1;
                // 流水数量 = 差值绝对值（负差值侧 bcsub 反转符号，保持恒正契约）
                $diffAbs = $direction > 0 ? $diff : bcsub('0', $diff, 2);
                // 盘盈/盘亏走统一引擎（同事务，双写一致）
                $this->inventoryService->apply([[
                    'product_id' => $item->product_id,
                    'warehouse_id' => $locked->warehouse_id,
                    'location_id' => $item->location_id,
                    'direction' => $direction,
                    'quantity' => $diffAbs,
                    'source_type' => $direction > 0 ? 'check_in' : 'check_out',
                    'source_id' => $locked->id,
                    'source_no' => $locked->no,
                    'remark' => $direction > 0 ? '盘盈' : '盘亏',
                ]], auth()->id());
                $changed++;
                if ($direction > 0) {
                    $increased = bcadd($increased, $diffAbs, 2);
                    $increasedItems++;
                } else {
                    $decreased = bcadd($decreased, $diffAbs, 2);
                    $decreasedItems++;
                }
            }
            $locked->status = InventoryCheck::STATUS_APPROVED;
            $locked->checker = auth()->user()->name ?? '';
            $locked->check_time = now();
            $locked->save();
            $result = [
                'changed_items' => $changed,
                // 合计出参转数值保持 JSON 数字类型不变（累加本身已 bcmath 精确，此转型仅序列化口径）
                'increased' => (float) $increased,
                'decreased' => (float) $decreased,
                'increased_items' => $increasedItems,
                'decreased_items' => $decreasedItems,
            ];
        }, 2);

        // 状态变更审计日志（事务提交后记）：盘盈/盘亏汇总（循环外聚合一条，禁止逐明细打印；
        // 数量为 decimal 原值，inventory_movements 流水已逐笔留痕）
        Log::info('盘点单审核通过', [
            'no' => $check->no, 'changed_items' => $result['changed_items'],
            'increased' => $result['increased'], 'decreased' => $result['decreased'],
            'operator' => auth()->id(),
        ]);

        return $result;
    }

    /**
     * 明细归一化（create/update 共用）：查重/负实盘/无余额校验 + 账面数快照
     *
     * 校验未通过抛对应业务码的 InventoryException（全局渲染为统一信封，
     * 与原控制器 fail() 响应等价）。
     *
     * @param  array  $items  明细行数组（每行含 product_id/location_id/actual_qty）
     * @param  int  $warehouseId  盘点仓库ID（载荷校验已保证 exists:warehouses）
     * @return array 归一化明细行：product_id/location_id/book_qty（余额快照）/actual_qty
     *
     * @throws InventoryException 明细重复商品×库位 422 / 实盘数为负 1201 / 无余额商品 1205
     */
    private function prepareItems(array $items, int $warehouseId): array
    {
        $rows = [];
        // 明细查重：同商品×库位 只允许一行（防扫码/粘贴误加重复行）
        $seen = [];
        foreach ($items as $item) {
            $key = $item['product_id'].'-'.$item['location_id'];
            if (isset($seen[$key])) {
                throw new InventoryException('盘点明细存在重复商品与库位', 422);
            }
            $seen[$key] = true;
            // 实盘数不能为负（bccomp 判负，D-3 铁律禁浮点比较；正则已保证入参为两位小数十进制）
            if (bccomp((string) $item['actual_qty'], '0', 2) < 0) {
                throw new InventoryException('实盘数量不能为负数', 1201);
            }
            // 无余额商品不可录盘（1205）：账外资产盘盈（book_qty=0 建账）属功能需求，
            // 用户 2026-08-13 裁决暂不做——实施改动点见 docs/bugs/2026-08-13-盘点盘盈无余额行误拒.md
            $balance = InventoryBalance::where('product_id', $item['product_id'])
                ->where('warehouse_id', $warehouseId)
                ->where('location_id', $item['location_id'])
                ->first();
            if (! $balance) {
                throw new InventoryException('商品在该仓库无库存，无需盘点', 1205);
            }
            // 账面数=创建时余额快照（审核时以此校验并发变动）
            $rows[] = [
                'product_id' => $item['product_id'],
                'location_id' => $item['location_id'],
                'book_qty' => $balance->quantity,
                'actual_qty' => $item['actual_qty'],
            ];
        }

        return $rows;
    }
}
