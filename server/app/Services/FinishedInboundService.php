<?php

// 成品入库单服务：草稿创建/更新/删除、审核（核心：事务内锁工单行防超量 1525 + InventoryService 写
// finished_inbound 流水(+qty) + completed_qty 累计 + 满产自动完成工单）

namespace App\Services;

use App\Exceptions\InventoryException;
use App\Exceptions\ProductionException;
use App\Models\DocumentSequence;
use App\Models\FinishedInbound;
use App\Models\FinishedInboundItem;
use App\Models\ProductionOrder;
use App\Models\WorkOrderOperation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 成品入库单领域服务（D-1：控制器写操作全部下沉至此）
 *
 * 承接原控制器的单据写流程：草稿 CRUD 与审核。审核为库存关键节点，单事务内完成
 * 「锁入库单行 → 锁工单行 → 明细成品一致性复核 → InventoryService 写 finished_inbound
 * 流水(+qty) → 工单 completed_qty 累计 → 末工序已完成且满产 → 工单自动已完成 → 置已审核」
 * （锁序铁律：单据行 → 工单行 → 库存；工单自动完成分支的末工序判定用一致性快照读不锁工序行，
 * 与报工流转锁序 op→order 全系统同序，消除 order→op 反序死锁环）。
 * 业务失败统一抛 ProductionException（业务码沿用原口径 1525~1528 与 422），
 * 由全局异常处理器渲染 {code, message, data} 信封，与原控制器 fail() 响应字节级等价。
 * 库存写入口唯一：一律经 InventoryService，本服务不直接写库存表；
 * 余额引擎兜底拒绝（InventoryException）在此语境化翻译为 422 业务码，不进入全局渲染器。
 * 非线程安全：同一单据的并发写依赖数据库行锁串行化，勿在事务外组合调用多个写方法。
 */
class FinishedInboundService
{
    public function __construct(
        private InventoryService $inventoryService,
        private DocumentSequenceService $sequenceService,
    ) {}

    /**
     * 新建成品入库草稿（原控制器 store 下沉）：入库量 ≤ 剩余产量（1525）；
     * 成品必须与工单产品一致（1526）；明细为空/重复/数量≤0 422
     *
     * 校验链：明细业务校验 → 仓库/库位 → 工单/状态（spec §5.1 生产中→成品入库）→
     * 草稿期成品一致性 + 剩余产量（1526/1525）→ 事务内持久序列取号建单 + 建明细。
     *
     * @param  array  $data  已过 SaveFinishedInboundRequest 格式校验的载荷
     *                       （order_id/warehouse_id/location_id/remark/items）
     * @return FinishedInbound 新建的入库单模型（含单号，供控制器回显）
     *
     * @throws ProductionException 明细/仓库库位/工单缺失 422 / 工单状态或超剩余产量 1525 / 成品不一致 1526
     */
    public function create(array $data): FinishedInbound
    {
        // 明细业务校验（422 格式层：空明细/重复商品/数量≤0）
        $this->assertBusinessItems($data['items'] ?? []);
        // 仓库/库位必填（empty 与原 request->filled 等价：'' 经全局中间件转 null，0 会被 exists 规则先行 422 拦截）
        if (empty($data['warehouse_id']) || empty($data['location_id'])) {
            throw new ProductionException('仓库与库位不能为空', 422);
        }
        // 草稿期校验：成品一致性（1526）+ 剩余产量（1525）
        $order = ProductionOrder::find($data['order_id']);
        if (! $order) {
            throw new ProductionException('工单不存在', 422);
        }
        // 工单状态校验：spec §5.1 生产中→成品入库（1525 入库族码段）
        if ($order->status !== ProductionOrder::STATUS_PRODUCING) {
            throw new ProductionException('工单当前状态不可入库', 1525);
        }
        $this->assertItems($order, $data['items'] ?? []);

        // 事务第 2 参数为死锁(1213)重试次数（机理同 BomController::store：序列行首建间隙锁
        // 死锁败方整体回滚后重跑闭包重新取号，幂等安全）
        $fi = DB::transaction(function () use ($data) {
            $fi = $this->sequenceService->nextNoByConfig(
                DocumentSequence::TYPE_FI,
                fn (string $no) => FinishedInbound::create([
                    'no' => $no,
                    'order_id' => $data['order_id'],
                    'status' => FinishedInbound::STATUS_DRAFT,
                    'warehouse_id' => $data['warehouse_id'],
                    'location_id' => $data['location_id'],
                    'remark' => $data['remark'] ?? null,
                ]),
                // legacyMax 只取当日最大单号一行（orderByDesc+value 单查，P1-5：同日前缀字典序=序号序）
                fn (string $prefix, string $dateKey) => ($no = FinishedInbound::where('no', 'like', $prefix.date('Ymd').'%')
                    ->orderByDesc('no')->value('no')) ? DocumentSequenceService::seqFromNo($no, $prefix, $dateKey) : 0,
            );
            $fi->items()->createMany(array_map(fn ($i) => [
                'product_id' => $i['product_id'],
                'quantity' => $i['quantity'],
            ], $data['items']));

            return $fi;
        }, 2);

        // 单据创建审计日志（事务提交后记）：单号 + 来源工单 + 操作人
        Log::info('成品入库单创建成功', ['no' => $fi->no, 'order_id' => $fi->order_id, 'created_by' => auth()->id()]);

        return $fi;
    }

    /**
     * 更新成品入库草稿（原控制器 update 下沉）：仅草稿（1527）；校验同 create；事务内锁行复查防并发
     *
     * @param  FinishedInbound  $finishedInbound  路由绑定的入库单模型（草稿状态才可改）
     * @param  array  $data  已过 SaveFinishedInboundRequest 格式校验的载荷
     *
     * @throws ProductionException 非草稿 1527 / 其余业务码同 create（422/1525/1526）
     */
    public function update(FinishedInbound $finishedInbound, array $data): void
    {
        // 状态前置校验（不进事务，快速失败）：非草稿直接拒绝
        if ($finishedInbound->status !== FinishedInbound::STATUS_DRAFT) {
            throw new ProductionException('已审核单据不可修改', 1527);
        }
        // 明细业务校验（同 create 口径）
        $this->assertBusinessItems($data['items'] ?? []);
        if (empty($data['warehouse_id']) || empty($data['location_id'])) {
            throw new ProductionException('仓库与库位不能为空', 422);
        }
        $order = ProductionOrder::find($data['order_id']);
        if (! $order) {
            throw new ProductionException('工单不存在', 422);
        }
        // 工单状态校验：spec §5.1 生产中→成品入库（同 create 口径）
        if ($order->status !== ProductionOrder::STATUS_PRODUCING) {
            throw new ProductionException('工单当前状态不可入库', 1525);
        }
        $this->assertItems($order, $data['items'] ?? []);

        DB::transaction(function () use ($finishedInbound, $data) {
            // 锁入库单行复查状态（幂等 1527）
            $locked = FinishedInbound::whereKey($finishedInbound->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== FinishedInbound::STATUS_DRAFT) {
                throw new ProductionException('已审核单据不可修改', 1527);
            }
            $locked->update([
                'order_id' => $data['order_id'],
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
     * 删除成品入库草稿（原控制器 destroy 下沉）：仅草稿（1527）；事务内锁行复查防并发
     *
     * @param  FinishedInbound  $finishedInbound  路由绑定的入库单模型（内存模型持单号供审计日志追溯）
     *
     * @throws ProductionException 非草稿 1527
     */
    public function delete(FinishedInbound $finishedInbound): void
    {
        // 状态前置校验（不进事务，快速失败）：非草稿直接拒绝
        if ($finishedInbound->status !== FinishedInbound::STATUS_DRAFT) {
            throw new ProductionException('已审核单据不可删除', 1527);
        }
        DB::transaction(function () use ($finishedInbound) {
            // 锁入库单行复查状态（幂等 1527）
            $locked = FinishedInbound::whereKey($finishedInbound->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== FinishedInbound::STATUS_DRAFT) {
                throw new ProductionException('已审核单据不可删除', 1527);
            }
            $locked->delete();
        });

        // 单据删除审计日志（事务提交后记）：内存模型仍持有单号，可用于追溯
        Log::info('成品入库单草稿删除', ['no' => $finishedInbound->no, 'operator' => auth()->id()]);
    }

    /**
     * 审核（原控制器 approve 下沉，核心库存链路）
     *
     * 单事务内「锁单幂等 1528 → 锁工单行复核剩余产量 1525（completed_qty 并发安全）→
     * InventoryService 写 finished_inbound 流水(+qty) → completed_qty 累计 →
     * 末工序已完成且满产 → 工单自动已完成 → 置已审核」，任一步失败整体回滚。
     * 工单自动完成分支的末工序判定用一致性快照读（不锁工序行）：与报工流转锁序
     * op→order 全系统同序，消除 order→op 反序死锁环。
     * 余额引擎兜底拒绝（InventoryException）在此语境化翻译为 422 业务码并保留台账 warn。
     *
     * @param  FinishedInbound  $finishedInbound  路由绑定的入库单模型
     * @return array{no: string} 单号（供控制器响应回显）
     *
     * @throws ProductionException 重复审核/工单状态 1528 / 成品不一致 1526 / 超剩余产量 1525
     */
    public function approve(FinishedInbound $finishedInbound): array
    {
        try {
            $result = null;
            // attempts=2：死锁自动重试一次（B-3 纵深防御；余额行锁序已由 InventoryService 排序规范化统一）
            DB::transaction(function () use ($finishedInbound, &$result) {
                // 锁入库单行：同一单据重复审核在此判重（幂等 1528）
                $locked = FinishedInbound::whereKey($finishedInbound->id)->lockForUpdate()->firstOrFail();
                if ($locked->status === FinishedInbound::STATUS_APPROVED) {
                    throw new ProductionException('该成品入库单已审核', 1528);
                }
                // 锁工单行：completed_qty 并发安全（多张 FI 同时审核串行化）
                $order = ProductionOrder::whereKey($locked->order_id)->lockForUpdate()->firstOrFail();
                // 工单状态校验：spec §5.1 生产中→成品入库；同时封死下方自动完成分支
                // 把「已下达」工单直跳「已完成」的越级流转（bug #2 额外危害）
                if ($order->status !== ProductionOrder::STATUS_PRODUCING) {
                    throw new ProductionException('工单当前状态不可入库', 1528);
                }
                $movements = [];
                $inboundTotal = '0';
                /** @var FinishedInboundItem $item */
                foreach ($locked->items as $item) {
                    // 成品一致性复核（草稿期后工单产品不可变，防御路径 1526）
                    if ($item->product_id !== $order->product_id) {
                        throw new ProductionException('入库商品与工单产品不一致', 1526);
                    }
                    // 剩余产量 = 计划数 - 已完工；本次超剩余 → 1525 整体回滚（防超量入库）
                    $remaining = bcsub((string) $order->quantity, (string) $order->completed_qty, 2);
                    if (bccomp((string) $item->quantity, $remaining, 2) > 0) {
                        throw new ProductionException('入库数量超过工单剩余产量', 1525);
                    }
                    $inboundTotal = bcadd($inboundTotal, (string) $item->quantity, 2);
                    $movements[] = [
                        'product_id' => $item->product_id,
                        'warehouse_id' => $locked->warehouse_id,
                        'location_id' => $locked->location_id,
                        'direction' => 1,
                        'quantity' => $item->quantity,
                        'source_type' => 'finished_inbound',
                        'source_id' => $locked->id,
                        'source_no' => $locked->no,
                        'remark' => '成品入库',
                    ];
                }
                // 统一引擎写流水+加余额（同事务双写）
                $this->inventoryService->apply($movements, auth()->id());
                // 工单 completed_qty 累计（bcmath）
                $order->completed_qty = bcadd((string) $order->completed_qty, $inboundTotal, 2);
                // 联动：末工序已完成且 completed_qty ≥ 计划数 → 工单自动「已完成」
                // 末工序判定用一致性快照读（不锁工序行）：与报工流转锁序 op→order 全系统同序，消除 order→op 反序死锁环
                $allDone = ! $order->operations()->where('status', '!=', WorkOrderOperation::STATUS_DONE)->exists();
                if ($allDone && bccomp((string) $order->completed_qty, (string) $order->quantity, 2) >= 0) {
                    $order->status = ProductionOrder::STATUS_COMPLETED;
                    $order->completed_at = now();
                }
                $order->save();
                // 置已审核 + 审核人/时间
                $locked->status = FinishedInbound::STATUS_APPROVED;
                $locked->operator = auth()->user()->name ?? '';
                $locked->approved_at = now();
                $locked->save();
                $result = ['no' => $locked->no];
            }, 2);
        } catch (InventoryException $e) {
            // 余额引擎兜底（入库方向理论不触发，防御路径）；走到此分支说明引擎内出现
            // 非预期拒绝，记 warn 便于排查数据不一致；
            // 语境化翻译为 422 业务码保持前端口径（不发原始引擎消息，防内部细节外泄）
            Log::warning('成品入库审核被余额引擎兜底拒绝（理论不可达，疑似数据不一致）', [
                'no' => $finishedInbound->no, 'reason' => $e->getMessage(),
            ]);

            throw new ProductionException('入库失败，请重试', 422);
        }

        // 状态变更审计日志（事务提交后记）：审核即成品入账 + 工单完工量累计，属库存关键节点
        // （库存笔级明细由 InventoryService 聚合记录，此处仅记单据维度，避免重复）
        Log::info('成品入库单审核通过', [
            'no' => $result['no'], 'order_id' => $finishedInbound->order_id, 'operator' => auth()->id(),
        ]);

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
                throw new ProductionException('入库数量必须大于 0', 422);
            }
            if (isset($seen[$item['product_id']])) {
                throw new ProductionException('明细存在重复商品', 422);
            }
            $seen[$item['product_id']] = true;
        }
    }

    // 草稿期校验：成品一致性（1526）+ 剩余产量（1525）
    private function assertItems(ProductionOrder $order, array $items): void
    {
        $total = '0';
        foreach ($items as $item) {
            if ($item['product_id'] !== $order->product_id) {
                throw new ProductionException('入库商品与工单产品不一致', 1526);
            }
            $total = bcadd($total, (string) $item['quantity'], 2);
        }
        // 剩余产量 = 计划数 - 已完工（bcmath 精确）
        $remaining = bcsub((string) $order->quantity, (string) $order->completed_qty, 2);
        if (bccomp($total, $remaining, 2) > 0) {
            throw new ProductionException('入库数量超过工单剩余产量', 1525);
        }
    }
}
