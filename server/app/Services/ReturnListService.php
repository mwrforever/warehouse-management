<?php

// 退料单服务：草稿创建/更新/删除、审核（核心：事务内锁物料需求行防超退 1517 + InventoryService 写 return 流水(+1) 冲销已领）

namespace App\Services;

use App\Exceptions\InventoryException;
use App\Exceptions\ProductionException;
use App\Models\DocumentSequence;
use App\Models\PickList;
use App\Models\ProductionOrder;
use App\Models\ProductionOrderMaterial;
use App\Models\ReturnList;
use App\Models\ReturnListItem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 退料单领域服务（D-1：控制器写操作全部下沉至此）
 *
 * 承接原控制器的单据写流程：草稿 CRUD 与审核。审核为库存关键节点，单事务内完成
 * 「锁退料单行 → 锁工单行 → 批量预锁物料需求行 → InventoryService 写 return 流水(+1)
 * → 冲销工单需求 issued_qty → 置已审核」（锁序铁律：单据行 → 工单行 → 物料行，
 * 批量按唯一索引序获取，无 ABBA 交叉锁；入库方向无需余额校验）。
 * 业务失败统一抛 ProductionException（业务码沿用原口径 1517~1519 与 422），
 * 由全局异常处理器渲染 {code, message, data} 信封，与原控制器 fail() 响应字节级等价。
 * 库存写入口唯一：一律经 InventoryService，本服务不直接写库存表；
 * 余额引擎兜底拒绝（InventoryException）在此语境化翻译为 1517 业务码，不进入全局渲染器。
 * 非线程安全：同一单据的并发写依赖数据库行锁串行化，勿在事务外组合调用多个写方法。
 */
class ReturnListService
{
    public function __construct(
        private InventoryService $inventoryService,
        private DocumentSequenceService $sequenceService,
    ) {}

    /**
     * 新建退料草稿（原控制器 store 下沉）：明细空/重复商品/数量>0/仓库库位 422；
     * 领料单归属 422；工单状态不可退料 1517；超已领总量 1517（草稿期即拦截）
     *
     * 校验链：明细业务校验 → 仓库/库位 → 工单状态（生产中/已完成可退料，G1 完工余料退回放行）→
     * 领料单归属（防跨工单挂单）→ 工单物料行一次预取（P1-4）→ 逐行已领校验 →
     * 事务内持久序列取号建单 + 建明细。
     *
     * @param  array  $data  已过 SaveReturnListRequest 格式校验的载荷
     *                       （order_id/pick_id/warehouse_id/location_id/remark/items）
     * @return ReturnList 新建的退料单模型（含单号，供控制器回显）
     *
     * @throws ProductionException 明细/仓库库位/领料单归属 422 / 工单状态或超已领 1517
     */
    public function create(array $data): ReturnList
    {
        // 明细业务校验（422 格式层：空明细/重复商品/数量≤0）
        $this->assertBusinessItems($data['items'] ?? []);
        // 仓库/库位必填（empty 与原 request->filled 等价：'' 经全局中间件转 null，0 会被 exists 规则先行 422 拦截）
        if (empty($data['warehouse_id']) || empty($data['location_id'])) {
            throw new ProductionException('仓库与库位不能为空', 422);
        }
        // 工单状态校验：退料允许生产中/已完成（完工余料退回，G1 放行）；草稿/已下达未领料、关闭后无操作（PRD-14）仍拒绝
        $order = ProductionOrder::find($data['order_id']);
        if (! $order || ! in_array($order->status, [ProductionOrder::STATUS_PRODUCING, ProductionOrder::STATUS_COMPLETED], true)) {
            throw new ProductionException('工单当前状态不可退料', 1517);
        }
        // pick_id 归属校验：领料单必须属于同一工单（防跨工单挂单，追溯语义错乱；422 惯例，spec 码段满）
        $this->assertPickBelongs($data['pick_id'] ?? null, $data['order_id']);
        // 工单物料行一次预取：草稿期已领校验用（消除逐商品 N+1，P1-4）
        $materialMap = $this->materialMap((int) $data['order_id']);
        // 草稿期校验：逐行 ≤ 该商品已领总量（1517）
        $this->assertIssued($data['items'] ?? [], $materialMap);

        // 事务第 2 参数为死锁(1213)重试次数（机理同 BomController::store：序列行首建间隙锁
        // 死锁败方整体回滚后重跑闭包重新取号，幂等安全）
        $return = DB::transaction(function () use ($data) {
            $return = $this->sequenceService->nextNoByConfig(
                DocumentSequence::TYPE_RL,
                fn (string $no) => ReturnList::create([
                    'no' => $no,
                    'order_id' => $data['order_id'],
                    'pick_id' => $data['pick_id'] ?? null,
                    'status' => ReturnList::STATUS_DRAFT,
                    'warehouse_id' => $data['warehouse_id'],
                    'location_id' => $data['location_id'],
                    'remark' => $data['remark'] ?? null,
                ]),
                // legacyMax 只取当日最大单号一行（orderByDesc+value 单查，P1-5：同日前缀字典序=序号序）
                fn (string $prefix, string $dateKey) => ($no = ReturnList::where('no', 'like', $prefix.date('Ymd').'%')
                    ->orderByDesc('no')->value('no')) ? DocumentSequenceService::seqFromNo($no, $prefix, $dateKey) : 0,
            );
            $return->items()->createMany(array_map(fn ($i) => [
                'product_id' => $i['product_id'],
                'quantity' => $i['quantity'],
            ], $data['items']));

            return $return;
        }, 2);

        // 单据创建审计日志（事务提交后记）：单号 + 来源工单 + 操作人
        Log::info('退料单创建成功', ['no' => $return->no, 'order_id' => $return->order_id, 'created_by' => auth()->id()]);

        return $return;
    }

    /**
     * 更新退料草稿（原控制器 update 下沉）：仅草稿（1518）；校验同 create；事务内锁行复查防并发
     *
     * @param  ReturnList  $return  路由绑定的退料单模型（草稿状态才可改）
     * @param  array  $data  已过 SaveReturnListRequest 格式校验的载荷
     *
     * @throws ProductionException 非草稿 1518 / 其余业务码同 create（422/1517）
     */
    public function update(ReturnList $return, array $data): void
    {
        // 状态前置校验（不进事务，快速失败）：非草稿直接拒绝
        if ($return->status !== ReturnList::STATUS_DRAFT) {
            throw new ProductionException('已审核单据不可修改', 1518);
        }
        // 明细业务校验（同 create 口径）
        $this->assertBusinessItems($data['items'] ?? []);
        if (empty($data['warehouse_id']) || empty($data['location_id'])) {
            throw new ProductionException('仓库与库位不能为空', 422);
        }
        // 工单状态校验：生产中/已完成可退料（同 create 口径，G1 完工余料退回放行）
        $order = ProductionOrder::find($data['order_id']);
        if (! $order || ! in_array($order->status, [ProductionOrder::STATUS_PRODUCING, ProductionOrder::STATUS_COMPLETED], true)) {
            throw new ProductionException('工单当前状态不可退料', 1517);
        }
        $this->assertPickBelongs($data['pick_id'] ?? null, $data['order_id']);
        // 工单物料行一次预取（同 create 口径，P1-4）
        $materialMap = $this->materialMap((int) $data['order_id']);
        $this->assertIssued($data['items'] ?? [], $materialMap);

        DB::transaction(function () use ($return, $data) {
            // 锁退料单行复查状态（幂等 1518）
            $locked = ReturnList::whereKey($return->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== ReturnList::STATUS_DRAFT) {
                throw new ProductionException('已审核单据不可修改', 1518);
            }
            $locked->update([
                'order_id' => $data['order_id'],
                'pick_id' => $data['pick_id'] ?? $locked->pick_id,
                'warehouse_id' => $data['warehouse_id'],
                'location_id' => $data['location_id'],
                'remark' => $data['remark'] ?? $locked->remark,
            ]);
            // 明细全量替换（草稿单无流水引用，直接重建）
            $locked->items()->delete();
            $locked->items()->createMany(array_map(fn ($i) => [
                'product_id' => $i['product_id'],
                'quantity' => $i['quantity'],
            ], $data['items']));
        });
    }

    /**
     * 删除退料草稿（原控制器 destroy 下沉）：仅草稿（1518）；事务内锁行复查防并发
     *
     * @param  ReturnList  $return  路由绑定的退料单模型（内存模型持单号供审计日志追溯）
     *
     * @throws ProductionException 非草稿 1518
     */
    public function delete(ReturnList $return): void
    {
        // 状态前置校验（不进事务，快速失败）：非草稿直接拒绝
        if ($return->status !== ReturnList::STATUS_DRAFT) {
            throw new ProductionException('已审核单据不可删除', 1518);
        }
        DB::transaction(function () use ($return) {
            // 锁退料单行复查状态（幂等 1518）
            $locked = ReturnList::whereKey($return->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== ReturnList::STATUS_DRAFT) {
                throw new ProductionException('已审核单据不可删除', 1518);
            }
            $locked->delete();
        });

        // 单据删除审计日志（事务提交后记）：内存模型仍持有单号，可用于追溯
        Log::info('退料单草稿删除', ['no' => $return->no, 'operator' => auth()->id()]);
    }

    /**
     * 审核（原控制器 approve 下沉，核心库存链路）
     *
     * 单事务内「锁单幂等 1519 → 锁工单行校验状态 1519 → 批量预锁物料需求行复核 1517 →
     * InventoryService 写 return 流水(+1) → 冲销工单需求 issued_qty → 置已审核」，
     * 任一步失败整体回滚（入库方向无需余额校验）。
     * 余额引擎兜底拒绝（InventoryException）在此语境化翻译为 1517 业务码并保留台账 warn。
     *
     * @param  ReturnList  $return  路由绑定的退料单模型
     * @return array{no: string} 单号（供控制器响应回显）
     *
     * @throws ProductionException 重复审核/工单状态 1519 / 超已领 1517
     */
    public function approve(ReturnList $return): array
    {
        try {
            $result = null;
            // attempts=2：死锁自动重试一次（B-3 纵深防御；余额行锁序已由 InventoryService 排序规范化统一）
            DB::transaction(function () use ($return, &$result) {
                // 锁退料单行：同一单据重复审核在此判重（幂等 1519）
                $locked = ReturnList::whereKey($return->id)->lockForUpdate()->firstOrFail();
                if ($locked->status === ReturnList::STATUS_APPROVED) {
                    throw new ProductionException('该退料单已审核', 1519);
                }
                // 锁工单行校验状态：生产中/已完成可退料（同 create 口径，G1 完工余料退回放行）；
                // 锁序 单据行→工单行→物料行（全局无「物料→工单」反向路径，与领料审核同构，无 ABBA 环）
                $order = ProductionOrder::whereKey($locked->order_id)->lockForUpdate()->firstOrFail();
                if (! in_array($order->status, [ProductionOrder::STATUS_PRODUCING, ProductionOrder::STATUS_COMPLETED], true)) {
                    throw new ProductionException('工单当前状态不可退料', 1519);
                }
                $movements = [];
                $writeOff = []; // [material_id => 本次冲销量] 待回写
                // 循环前批量预锁（P1-2，宪法 §4.2.4 建议）：物料需求行按唯一索引序一次锁定，
                // 循环内查 map——明细 N 行时查询次数从 N 降为常数；批量锁按索引序获取与逐行等价，
                // 且消除「两单明细顺序相反」时的交叉锁窗口（同索引序获取，无 ABBA 新方向）
                /** @var Collection<int, ProductionOrderMaterial> $pmMap 已锁定的物料需求行（回写复用，免二次查询） */
                $pmMap = ProductionOrderMaterial::query()
                    ->where('order_id', $locked->order_id)
                    ->whereIn('material_id', $locked->items->pluck('product_id'))
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('material_id');
                /** @var ReturnListItem $item */
                foreach ($locked->items as $item) {
                    // 复核物料需求行：防并发超退（并发审核同一物料已在上方批量锁定串行化）
                    $pm = $pmMap->get($item->product_id);
                    if (! $pm) {
                        throw new ProductionException('退料数量超过已领数量', 1517);
                    }
                    // 本次退料 ≤ 当前已领（草稿期校验后已领可能被并发冲销，审核期锁行复核）
                    if (bccomp((string) $item->quantity, (string) $pm->issued_qty, 2) > 0) {
                        throw new ProductionException('退料数量超过已领数量', 1517);
                    }
                    $writeOff[$item->product_id] = bcadd((string) ($writeOff[$item->product_id] ?? '0'), (string) $item->quantity, 2);
                    $movements[] = [
                        'product_id' => $item->product_id,
                        'warehouse_id' => $locked->warehouse_id,
                        'location_id' => $locked->location_id,
                        'direction' => 1,
                        'quantity' => $item->quantity,
                        'source_type' => 'return',
                        'source_id' => $locked->id,
                        'source_no' => $locked->no,
                        'remark' => '生产退料',
                    ];
                }
                // 统一引擎写流水+加余额（同事务双写）
                $this->inventoryService->apply($movements, auth()->id());
                // 冲销工单物料需求 issued_qty（bcmath 减法）：复用第一循环已锁定的行对象——
                // 行已被本事务锁定且期间无人可改，二次查询纯属多余（N 条明细省 N 次查询）
                foreach ($writeOff as $materialId => $qty) {
                    $pm = $pmMap[$materialId];
                    $pm->issued_qty = bcsub((string) $pm->issued_qty, $qty, 2);
                    $pm->save();
                }
                // 置已审核 + 审核人/时间
                $locked->status = ReturnList::STATUS_APPROVED;
                $locked->operator = auth()->user()->name ?? '';
                $locked->approved_at = now();
                $locked->save();
                $result = ['no' => $locked->no];
            }, 2);
        } catch (InventoryException $e) {
            // 余额引擎兜底（入库方向理论不触发，防御路径）；走到此分支说明引擎内出现
            // 非预期拒绝，记 warn 便于排查数据不一致；
            // 语境化翻译为 1517 业务码保持前端口径（不发原始引擎消息，防内部细节外泄）
            Log::warning('退料审核被余额引擎兜底拒绝（理论不可达，疑似数据不一致）', [
                'no' => $return->no, 'reason' => $e->getMessage(),
            ]);

            throw new ProductionException('退料失败，请重试', 1517);
        }

        // 状态变更审计日志（事务提交后记）：审核即材料回库 + 已领量冲销，属库存关键节点
        Log::info('退料单审核通过', ['no' => $result['no'], 'order_id' => $return->order_id, 'operator' => auth()->id()]);

        return $result;
    }

    // 明细业务校验（create/update 共用）：空明细/数量≤0/重复商品 → 422（格式层；spec 码段满）
    private function assertBusinessItems(array $items): void
    {
        if (empty($items)) {
            throw new ProductionException('请至少添加一条明细', 422);
        }
        $seen = [];
        foreach ($items as $item) {
            // 数量正负校验走 bccomp（D-3 铁律：禁浮点参与数量比较；正则已保证入参为两位小数十进制）
            if (bccomp((string) $item['quantity'], '0', 2) <= 0) {
                throw new ProductionException('退料数量必须大于 0', 422);
            }
            if (isset($seen[$item['product_id']])) {
                throw new ProductionException('明细存在重复商品', 422);
            }
            $seen[$item['product_id']] = true;
        }
    }

    // pick_id 归属校验：领料单必须属于同一工单（防跨工单挂单，追溯语义错乱；422 惯例，spec 码段满）
    private function assertPickBelongs(int|string|null $pickId, int|string $orderId): void
    {
        if (! empty($pickId)) {
            $belongs = PickList::whereKey($pickId)->where('order_id', $orderId)->exists();
            if (! $belongs) {
                throw new ProductionException('领料单不属于该工单', 422);
            }
        }
    }

    // 工单物料行预取（P1-4）：create/update 的草稿期已领校验共用一次查询，消除逐商品 N+1
    private function materialMap(int $orderId): Collection
    {
        return ProductionOrderMaterial::where('order_id', $orderId)->get()->keyBy('material_id');
    }

    // 草稿期已领校验：逐行 ≤ 该商品已领总量（1517，物料行取自预取 map）
    private function assertIssued(array $items, Collection $materialMap): void
    {
        foreach ($items as $item) {
            $pm = $materialMap->get($item['product_id']);
            if (! $pm || bccomp((string) $item['quantity'], (string) $pm->issued_qty, 2) > 0) {
                throw new ProductionException('退料数量超过已领数量', 1517);
            }
        }
    }
}
