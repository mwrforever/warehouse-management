<?php

// 领料单服务：草稿创建/更新/删除、审核（核心：事务内锁物料需求行防超领 1513 + 锁余额行防超卖 1515）、发料

namespace App\Services;

use App\Exceptions\InventoryException;
use App\Exceptions\ProductionException;
use App\Models\DocumentSequence;
use App\Models\InventoryBalance;
use App\Models\PickList;
use App\Models\PickListItem;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\ProductionOrderMaterial;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 领料单领域服务（D-1：控制器写操作全部下沉至此）
 *
 * 承接原控制器的单据写流程：草稿 CRUD、审核、发料（V1 一次发完）。
 * 审核为库存关键节点，单事务内完成「锁领料单行 → 锁工单行 → 批量预锁物料需求行 →
 * 批量预锁余额行 → InventoryService 写流水扣库存 → 回写工单需求 issued_qty → 置已审核」
 * （锁序铁律：单据行 → 工单行 → 物料行 → 余额行，批量按唯一索引序获取，无 ABBA 交叉锁）。
 * 业务失败统一抛 ProductionException（业务码沿用原口径 1513~1516 与 422），
 * 由全局异常处理器渲染 {code, message, data} 信封，与原控制器 fail() 响应字节级等价。
 * 库存写入口唯一：一律经 InventoryService，本服务不直接写库存表；
 * 余额引擎兜底拒绝（InventoryException）在此语境化翻译为 1515 业务码，不进入全局渲染器。
 * 非线程安全：同一单据的并发写依赖数据库行锁串行化，勿在事务外组合调用多个写方法。
 */
class PickListService
{
    public function __construct(
        private InventoryService $inventoryService,
        private DocumentSequenceService $sequenceService,
    ) {}

    /**
     * 新建领料草稿（原控制器 store 下沉）：明细非空/重复商品/数量>0 走 422；
     * 仓库库位缺失 422；工单状态非生产中 1513；超需求剩余 1513（草稿期即拦截）
     *
     * 校验链：明细业务校验 → 仓库/库位 → 工单状态（spec §5.1 生产中→领料）→
     * 工单物料行一次预取（P1-4：剩余校验 + 明细需求快照共用）→ 逐行剩余量校验 →
     * 事务内持久序列取号建单 + 建明细。
     *
     * @param  array  $data  已过 SavePickListRequest 格式校验的载荷
     *                       （order_id/warehouse_id/location_id/remark/items）
     * @return PickList 新建的领料单模型（含单号，供控制器回显）
     *
     * @throws ProductionException 明细/仓库库位 422 / 工单状态或超剩余 1513
     */
    public function create(array $data): PickList
    {
        // 明细业务校验（422 格式层：空明细/重复商品/数量≤0）
        $this->assertBusinessItems($data['items'] ?? []);
        // 仓库/库位必填（empty 与原 request->filled 等价：'' 经全局中间件转 null，0 会被 exists 规则先行 422 拦截）
        if (empty($data['warehouse_id']) || empty($data['location_id'])) {
            throw new ProductionException('仓库与库位不能为空', 422);
        }
        // 工单状态校验：spec §5.1 生产中→领料（草稿/已下达/已完成/已关闭工单不可领料；1513 领料族码段）
        $order = ProductionOrder::find($data['order_id']);
        if (! $order || $order->status !== ProductionOrder::STATUS_PRODUCING) {
            throw new ProductionException('工单当前状态不可领料', 1513);
        }
        // 工单物料行一次预取：草稿期剩余校验 + 明细需求快照共用（消除逐商品 2N 查询，P1-4）
        $materialMap = $this->materialMap((int) $data['order_id']);
        // 草稿期校验：逐行 ≤ 需求剩余（1513）
        $this->assertRemaining($data['items'] ?? [], $materialMap);

        // 事务第 2 参数为死锁(1213)重试次数（机理同 BomController::store：序列行首建间隙锁
        // 死锁败方整体回滚后重跑闭包重新取号，幂等安全）
        $pick = DB::transaction(function () use ($data, $materialMap) {
            $pick = $this->sequenceService->nextNoByConfig(
                DocumentSequence::TYPE_PL,
                fn (string $no) => PickList::create([
                    'no' => $no,
                    'order_id' => $data['order_id'],
                    'status' => PickList::STATUS_DRAFT,
                    'issue_status' => PickList::ISSUE_NONE,
                    'warehouse_id' => $data['warehouse_id'],
                    'location_id' => $data['location_id'],
                    'remark' => $data['remark'] ?? null,
                ]),
                // legacyMax 只取当日最大单号一行（orderByDesc+value 单查，P1-5：同日前缀字典序=序号序）
                fn (string $prefix, string $dateKey) => ($no = PickList::where('no', 'like', $prefix.date('Ymd').'%')
                    ->orderByDesc('no')->value('no')) ? DocumentSequenceService::seqFromNo($no, $prefix, $dateKey) : 0,
            );
            // 明细行：需求快照 + 本次领用量（需求快照取自预取 map，P1-4）
            $pick->items()->createMany(array_map(fn ($i) => [
                'product_id' => $i['product_id'],
                'required_qty' => $this->requiredQty($materialMap, (int) $i['product_id']),
                'pick_qty' => $i['pick_qty'],
                'issued_qty' => 0,
            ], $data['items']));

            return $pick;
        }, 2);

        // 单据创建审计日志（事务提交后记）：单号 + 来源工单 + 操作人
        Log::info('领料单创建成功', ['no' => $pick->no, 'order_id' => $pick->order_id, 'created_by' => auth()->id()]);

        return $pick;
    }

    /**
     * 更新领料草稿（原控制器 update 下沉）：仅草稿（1514）；校验同 create；事务内锁行复查防并发
     *
     * @param  PickList  $pick  路由绑定的领料单模型（草稿状态才可改）
     * @param  array  $data  已过 SavePickListRequest 格式校验的载荷
     *
     * @throws ProductionException 非草稿 1514 / 其余业务码同 create（422/1513）
     */
    public function update(PickList $pick, array $data): void
    {
        // 状态前置校验（不进事务，快速失败）：非草稿直接拒绝
        if ($pick->status !== PickList::STATUS_DRAFT) {
            throw new ProductionException('已审核单据不可修改', 1514);
        }
        // 明细业务校验（同 create 口径）
        $this->assertBusinessItems($data['items'] ?? []);
        if (empty($data['warehouse_id']) || empty($data['location_id'])) {
            throw new ProductionException('仓库与库位不能为空', 422);
        }
        // 工单状态校验：spec §5.1 生产中→领料（同 create 口径）
        $order = ProductionOrder::find($data['order_id']);
        if (! $order || $order->status !== ProductionOrder::STATUS_PRODUCING) {
            throw new ProductionException('工单当前状态不可领料', 1513);
        }
        // 工单物料行一次预取（同 create 口径，P1-4）
        $materialMap = $this->materialMap((int) $data['order_id']);
        $this->assertRemaining($data['items'] ?? [], $materialMap);

        DB::transaction(function () use ($pick, $data, $materialMap) {
            // 锁领料单行复查状态：与审核并发时防止改到正在审核的单（幂等 1514）
            $locked = PickList::whereKey($pick->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== PickList::STATUS_DRAFT) {
                throw new ProductionException('已审核单据不可修改', 1514);
            }
            $locked->update([
                'order_id' => $data['order_id'],
                'warehouse_id' => $data['warehouse_id'],
                'location_id' => $data['location_id'],
                'remark' => $data['remark'] ?? $locked->remark,
            ]);
            // 明细全量替换（草稿单无流水引用，直接重建；需求快照取自预取 map，P1-4）
            $locked->items()->delete();
            $locked->items()->createMany(array_map(fn ($i) => [
                'product_id' => $i['product_id'],
                'required_qty' => $this->requiredQty($materialMap, (int) $i['product_id']),
                'pick_qty' => $i['pick_qty'],
                'issued_qty' => 0,
            ], $data['items']));
        });
    }

    /**
     * 删除领料草稿（原控制器 destroy 下沉）：仅草稿（1514）；事务内锁行复查防并发
     *
     * @param  PickList  $pick  路由绑定的领料单模型（内存模型持单号供审计日志追溯）
     *
     * @throws ProductionException 非草稿 1514
     */
    public function delete(PickList $pick): void
    {
        // 状态前置校验（不进事务，快速失败）：非草稿直接拒绝
        if ($pick->status !== PickList::STATUS_DRAFT) {
            throw new ProductionException('已审核单据不可删除', 1514);
        }
        DB::transaction(function () use ($pick) {
            // 锁领料单行复查状态（幂等 1514）
            $locked = PickList::whereKey($pick->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== PickList::STATUS_DRAFT) {
                throw new ProductionException('已审核单据不可删除', 1514);
            }
            $locked->delete();
        });

        // 单据删除审计日志（事务提交后记）：内存模型仍持有单号，可用于追溯
        Log::info('领料单草稿删除', ['no' => $pick->no, 'operator' => auth()->id()]);
    }

    /**
     * 审核（原控制器 approve 下沉，核心库存链路）
     *
     * 单事务内「锁单幂等 1516 → 锁工单行校验状态 1516 → 批量预锁物料需求行复核 1513 →
     * 批量预锁余额行校验充足 1515 → InventoryService 扣库存（pick, -1）→ 回写工单需求
     * issued_qty → 置已审核」，任一步失败整体回滚。
     * 余额引擎兜底拒绝（InventoryException）在此语境化翻译为 1515 业务码并保留台账 warn。
     *
     * @param  PickList  $pick  路由绑定的领料单模型
     * @return array{no: string} 单号（供控制器响应回显）
     *
     * @throws ProductionException 重复审核/工单状态 1516 / 超需求 1513 / 库存不足 1515
     */
    public function approve(PickList $pick): array
    {
        try {
            $result = null;
            // attempts=2：死锁自动重试一次（B-3 纵深防御；余额行锁序已由 InventoryService 排序规范化统一）
            DB::transaction(function () use ($pick, &$result) {
                // 锁领料单行：同一单据重复审核在此判重（幂等 1516）
                $locked = PickList::whereKey($pick->id)->lockForUpdate()->firstOrFail();
                if ($locked->status === PickList::STATUS_APPROVED) {
                    throw new ProductionException('该领料单已审核', 1516);
                }
                // 锁工单行校验状态：spec §5.1 生产中→领料；锁序 单据行→工单行→物料行→余额行
                // （全局无「物料→工单」反向路径，与委外发出/成品入库的 单据→工单 锁序一致，无 ABBA 环）
                $order = ProductionOrder::whereKey($locked->order_id)->lockForUpdate()->firstOrFail();
                if ($order->status !== ProductionOrder::STATUS_PRODUCING) {
                    throw new ProductionException('工单当前状态不可领料', 1516);
                }
                $movements = [];
                $issueMap = []; // [material_id => 本次领用累计] 待回写
                // 循环前批量预锁（P1-2，宪法 §4.2.4 建议）：物料需求行/余额行按唯一索引序一次锁定，
                // 循环内查 map——明细 N 行时查询次数从 ~2N 降为常数；批量锁按索引序获取与逐行等价，
                // 且消除「两单明细顺序相反」时的交叉锁窗口（同索引序获取，无 ABBA 新方向）
                $productIds = $locked->items->pluck('product_id');
                /** @var Collection<int, ProductionOrderMaterial> $pmMap 已锁定的物料需求行（回写复用，免二次查询） */
                $pmMap = ProductionOrderMaterial::query()
                    ->where('order_id', $locked->order_id)
                    ->whereIn('material_id', $productIds)
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('material_id');
                /** @var Collection<int, InventoryBalance> $balanceMap 已锁定的余额行（防超卖校验复用） */
                $balanceMap = InventoryBalance::query()
                    ->whereIn('product_id', $productIds)
                    ->where('warehouse_id', $locked->warehouse_id)
                    ->where('location_id', $locked->location_id)
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('product_id');
                /** @var Collection<int, Product> $productMap 商品编码映射（1515 错误消息取码用；
                    循环前批量预取，错误分支不再 Product::find 循环内单查） */
                $productMap = Product::query()->whereIn('id', $productIds)->get()->keyBy('id');
                /** @var PickListItem $item */
                foreach ($locked->items as $item) {
                    // 复核物料需求行：防并发超领（并发审核同一物料已在上方批量锁定串行化）
                    $pm = $pmMap->get($item->product_id);
                    if (! $pm) {
                        throw new ProductionException('领料数量超过需求数量', 1513);
                    }
                    // 剩余 = 需求 - 已领；本次超剩余 → 1513 整体回滚（防超领）
                    $remaining = bcsub((string) $pm->required_qty, (string) $pm->issued_qty, 2);
                    if (bccomp((string) $item->pick_qty, $remaining, 2) > 0) {
                        throw new ProductionException('领料数量超过需求数量', 1513);
                    }
                    $issueMap[$item->product_id] = bcadd((string) ($issueMap[$item->product_id] ?? '0'), (string) $item->pick_qty, 2);
                    // 防超卖：余额行已批量锁定，校验余额充足（并发审核同一商品在此串行化；消息含商品编码与精确库存快照）
                    $balance = $balanceMap->get($item->product_id);
                    $current = $balance ? (string) $balance->quantity : '0';
                    if (bccomp((string) $item->pick_qty, $current, 2) > 0) {
                        // 1515 消息契约不含库存快照，仅含商品编码（E2E 断言 MAT-001）
                        // ?? 左值天然 null 安全（map 未命中时回退 #id 展示），nullsafe 显式多余故用 ->
                        $code = $productMap->get($item->product_id)->code ?? ('#'.$item->product_id);
                        throw new ProductionException("商品[{$code}]库存不足", 1515);
                    }
                    $movements[] = [
                        'product_id' => $item->product_id,
                        'warehouse_id' => $locked->warehouse_id,
                        'location_id' => $locked->location_id,
                        'direction' => -1,
                        'quantity' => $item->pick_qty,
                        'source_type' => 'pick',
                        'source_id' => $locked->id,
                        'source_no' => $locked->no,
                        'remark' => '生产领料',
                    ];
                }
                // 统一引擎写流水+扣余额（同事务双写；余额行已被本事务锁定，引擎内重复加锁幂等）
                $this->inventoryService->apply($movements, auth()->id());
                // 回写工单物料需求 issued_qty（bcmath 累加）：复用第一循环已锁定的行对象——
                // 行已被本事务锁定且期间无人可改，二次查询纯属多余（N 条明细省 N 次查询）
                foreach ($issueMap as $materialId => $qty) {
                    $pm = $pmMap[$materialId];
                    $pm->issued_qty = bcadd((string) $pm->issued_qty, $qty, 2);
                    $pm->save();
                }
                // 置已审核 + 审核人/时间
                $locked->status = PickList::STATUS_APPROVED;
                $locked->operator = auth()->user()->name ?? '';
                $locked->approved_at = now();
                $locked->save();
                $result = ['no' => $locked->no];
            }, 2);
        } catch (InventoryException $e) {
            // 余额引擎兜底拒绝（理论上被预校验拦截，防御路径）；走到此分支说明预校验与引擎
            // 判定不一致，记 warn 便于排查数据不一致；
            // 语境化翻译为 1515 业务码保持前端口径（不发原始引擎消息，防内部细节外泄）
            Log::warning('领料审核被余额引擎兜底拒绝（预校验未拦截，疑似数据不一致）', [
                'no' => $pick->no, 'reason' => $e->getMessage(),
            ]);

            throw new ProductionException('库存不足，领料被拒绝', 1515);
        }

        // 状态变更审计日志（事务提交后记）：审核即材料扣减出库 + 工单已领量回写，属库存关键节点
        Log::info('领料单审核通过', ['no' => $result['no'], 'order_id' => $pick->order_id, 'operator' => auth()->id()]);

        return $result;
    }

    /**
     * 发料（原控制器 issue 下沉）：仅已审核可发（422）；V1 一次发完——issue_status 置「全部发料」，
     * 明细行 issued_qty 回写
     *
     * @param  PickList  $pick  路由绑定的领料单模型
     * @return array{issue_status: string} 发料后 issue_status 中文标签（与原控制器 ISSUE_LABELS 回显口径一致）
     *
     * @throws ProductionException 未审核 422
     */
    public function issue(PickList $pick): array
    {
        // 状态前置校验（不进事务，快速失败）：未审核直接拒绝
        if ($pick->status !== PickList::STATUS_APPROVED) {
            throw new ProductionException('请先审核领料单', 422);
        }
        DB::transaction(function () use ($pick) {
            // 锁领料单行复查状态（并发审核/发料串行化）
            $locked = PickList::whereKey($pick->id)->lockForUpdate()->firstOrFail();
            // 幂等判重移入事务：锁行后复查 issue_status，防并发双请求同时越过外部判重
            // （结果写入相同故无正确性影响，此处消除竞态窗口）
            if ($locked->issue_status === PickList::ISSUE_ALL) {
                return;
            }
            if ($locked->status !== PickList::STATUS_APPROVED) {
                throw new ProductionException('请先审核领料单', 422);
            }
            $locked->issue_status = PickList::ISSUE_ALL;
            $locked->save();
            // 明细行已发量 = 本次领用（一次发完语义）
            foreach ($locked->items as $item) {
                $item->issued_qty = $item->pick_qty;
                $item->save();
            }
        });

        // 状态变更审计日志（事务提交后记）：发料即领料单实物出库确认（V1 一次发完）
        Log::info('领料单发料完成', ['no' => $pick->no, 'operator' => auth()->id()]);

        return ['issue_status' => PickList::ISSUE_LABELS[PickList::ISSUE_ALL]];
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
            if (bccomp((string) $item['pick_qty'], '0', 2) <= 0) {
                throw new ProductionException('领料数量必须大于 0', 422);
            }
            if (isset($seen[$item['product_id']])) {
                throw new ProductionException('明细存在重复商品', 422);
            }
            $seen[$item['product_id']] = true;
        }
    }

    // 工单物料行预取（P1-4）：create/update 的草稿期校验与明细需求快照共用一次查询，消除逐商品 N+1
    private function materialMap(int $orderId): Collection
    {
        return ProductionOrderMaterial::where('order_id', $orderId)->get()->keyBy('material_id');
    }

    // 草稿期剩余量校验：逐行 ≤ 需求剩余（1513，物料行取自预取 map）
    private function assertRemaining(array $items, Collection $materialMap): void
    {
        foreach ($items as $item) {
            $pm = $materialMap->get($item['product_id']);
            if (! $pm) {
                throw new ProductionException('领料数量超过需求数量', 1513);
            }
            $remaining = bcsub((string) $pm->required_qty, (string) $pm->issued_qty, 2);
            if (bccomp((string) $item['pick_qty'], $remaining, 2) > 0) {
                throw new ProductionException('领料数量超过需求数量', 1513);
            }
        }
    }

    // 物料需求数量（明细行快照：生成时点工单物料需求；取自预取 map）
    private function requiredQty(Collection $materialMap, int $productId): string
    {
        $pm = $materialMap->get($productId);

        return $pm ? (string) $pm->required_qty : '0';
    }
}
