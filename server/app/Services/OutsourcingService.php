<?php

// 委外服务：从工序节点预填组装（节点输入材料×单位用量 + 回收品 + 剩余委外量）与委外载荷组件校验（bcmath 权威）
// + 委外单写流程（D-1：草稿 CRUD/发出/回收/余料退回全部下沉）

namespace App\Services;

use App\Exceptions\InventoryException;
use App\Exceptions\ProductionException;
use App\Models\DocumentSequence;
use App\Models\InventoryBalance;
use App\Models\OutsourcingOrder;
use App\Models\OutsourcingReceipt;
use App\Models\OutsourcingReturn;
use App\Models\ProductionOrder;
use App\Models\RoutingNode;
use App\Models\WorkOrderOperation;
use App\Models\WorkOrderOperationEdge;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 委外加工领域服务（D-1：控制器写操作全部下沉至此）
 *
 * 承接原控制器的委外写流程：草稿 CRUD、发出（审核扣组件库存）、回收（创建即审核回收单 +
 * 工序联动推进）、余料退回（创建即审核，全退自动关闭），全部在 DB::transaction 内
 * 「锁行 → 复查状态 → 变更」执行。
 * 锁序铁律（与报工/完工全局同序，防 ABBA 死锁环）：
 *  - 发出：委外单 → 工单行 → 组件余额行（按 product_id 索引序批量预锁）；
 *  - 回收：委外单 → 同单全部工序行（id 升序）→ 工单行 → 回收单序列/流水（单据头 → 明细 → 库存）；
 *  - 退回：委外单 → 同单组件行（单据头 → 明细）→ 按 material_id 升序写流水。
 * 业务失败统一抛 ProductionException（业务码沿用原口径 1520~1524/1529 与 422），
 * 由全局异常处理器渲染 {code, message, data} 信封，与原控制器 fail() 响应字节级等价；
 * 余额引擎兜底拒绝（InventoryException）在此翻译为原语境化口径（1522/422），不进入全局渲染器。
 * 非线程安全：同一委外单的并发写依赖数据库行锁串行化，勿在事务外组合调用多个写方法。
 */
class OutsourcingService
{
    public function __construct(
        private InventoryService $inventoryService,
        private DocumentSequenceService $sequenceService,
    ) {}

    /**
     * 新建委外草稿（原控制器 store 下沉）：委外量判正/供应商/仓库库位 422；
     * 工序归属与节点口径 422；工单状态 [已下达,生产中] 1523；剩余量 1520
     *
     * 校验链：量/供应商/仓库库位 → 工序必须属于该工单 → 工艺路线节点口径（仅 is_outsourced=1
     * 节点可委外，spec 5 §4）→ 工单状态（B-1：草稿工单禁止挂委外，防工序行全删重建
     * 撞 operation_id RESTRICT 外键卡死）→ 剩余量（Σ同节点非草稿委外单）→
     * 事务内持久序列取号建单 + 组件行节点口径校验落库。
     * 幂等性：单号撞号由序列服务自动换号重试；事务死锁(1213)整体回滚后重跑重取号。
     *
     * @param  array  $data  已过 SaveOutsourcingRequest 格式校验的载荷
     *                       （order_id/operation_id/supplier_id/warehouse_id/location_id/quantity/remark/items）
     * @return OutsourcingOrder 新建的委外单模型（含单号，供控制器回显）
     *
     * @throws ProductionException 量非正/供应商/仓库库位缺失/工序归属/节点口径 422 /
     *                             工单状态 1523 / 剩余量 1520
     */
    public function create(array $data): OutsourcingOrder
    {
        // 委外量判正走 bccomp（D-3 铁律：禁浮点参与数量比较；正则已保证入参为两位小数十进制）
        if (bccomp((string) $data['quantity'], '0', 2) <= 0) {
            throw new ProductionException('委外数量必须大于 0', 422);
        }
        // empty 与原 request->filled 等价（'' 经全局中间件转 null，0 会被 exists 规则先行 422 拦截）
        if (empty($data['supplier_id'])) {
            throw new ProductionException('供应商不能为空', 422);
        }
        if (empty($data['warehouse_id']) || empty($data['location_id'])) {
            throw new ProductionException('仓库与库位不能为空', 422);
        }
        // 工序必须属于该工单（防跨单挂工序）
        $op = WorkOrderOperation::where('id', $data['operation_id'])->where('order_id', $data['order_id'])->first();
        if (! $op) {
            throw new ProductionException('工序不属于该工单', 422);
        }
        // 委外对象=工艺路线节点（仅 is_outsourced=1 节点可委外，spec 5 §4 规则定义）：无路线/无 node_no → 422
        $node = $this->routingNodeForOperation($data['operation_id']);
        if ((int) $op->is_outsourced !== 1) {
            throw new ProductionException('该工序不是委外工序', 422);
        }
        if ((int) $op->status === WorkOrderOperation::STATUS_DONE) {
            throw new ProductionException('该工序已完成，不可委外', 422);
        }
        // 草稿期校验：委外量 ≤ 剩余可委外量 = 工单数量 − Σ同节点非草稿委外单（1520，bcmath）
        $order = ProductionOrder::find($data['order_id']);
        // 工单状态校验（B-1）：与发出 approve 同口径 [已下达,生产中]（1523，spec §5.1 生产中→委外）——
        // 草稿工单的工序行会随工单编辑全删重建，草稿期挂委外单会令 operation_id 外键（RESTRICT）
        // 卡死工单编辑（QueryException 500 且无自愈路径），从源头禁止
        $canOutsource = $order && in_array($order->status, [
            ProductionOrder::STATUS_RELEASED, ProductionOrder::STATUS_PRODUCING,
        ], true);
        if (! $canOutsource) {
            throw new ProductionException('工单当前状态不可委外', 1523);
        }
        $outsourced = bcadd((string) OutsourcingOrder::where('operation_id', $data['operation_id'])
            ->where('status', '!=', OutsourcingOrder::STATUS_DRAFT)->sum('quantity'), '0', 2);
        // 工单缺失分支已由上方 1523 守卫承接（$canOutsource 对 null 工单恒 false），此处 $order 必非空
        if (bccomp((string) $data['quantity'], bcsub((string) $order->quantity, $outsourced, 2), 2) > 0) {
            throw new ProductionException('委外数量超过节点剩余计划量', 1520);
        }
        // 组件行类型归一（应发数量统一字符串供 bcmath 权威比较，物料/单位回整型）
        $data['items'] = $this->normalizeComponents($data['items']);
        // 组件查重：同物料只允许一行（422；与唯一键 uniq_outsourcing_order_items 同口径，防重复物料撞键 500）
        $this->assertComponentsUnique($data['items']);

        // 事务第 2 参数为死锁(1213)重试次数（机理同 BomController::store：序列行首建间隙锁
        // 死锁败方整体回滚后重跑闭包重新取号，幂等安全）
        $os = DB::transaction(function () use ($data, $node) {
            $os = $this->sequenceService->nextNoByConfig(
                DocumentSequence::TYPE_OS,
                fn (string $no) => OutsourcingOrder::create([
                    'no' => $no,
                    'order_id' => $data['order_id'],
                    'operation_id' => $data['operation_id'],
                    'supplier_id' => $data['supplier_id'],
                    'status' => OutsourcingOrder::STATUS_DRAFT,
                    'warehouse_id' => $data['warehouse_id'],
                    'location_id' => $data['location_id'],
                    'quantity' => $data['quantity'],
                    // 回收品=节点输出产品快照（回收入账商品口径，1529 一致性校验基准）
                    'output_product_id' => $node->output_product_id,
                    'remark' => $data['remark'] ?? null,
                ]),
                // legacyMax 只取当日最大单号一行（orderByDesc+value 单查，P1-5：同日前缀字典序=序号序）
                fn (string $prefix, string $dateKey) => ($no = OutsourcingOrder::where('no', 'like', $prefix.date('Ymd').'%')
                    ->orderByDesc('no')->value('no')) ? DocumentSequenceService::seqFromNo($no, $prefix, $dateKey) : 0,
            );
            // 组件行落库：节点逐行校验（应发 ≤ 单位用量×委外量，bcmath 权威）后落库
            $items = $this->validateItems($data['items'], $node, (string) $data['quantity']);
            $os->items()->createMany($items);

            return $os;
        }, 2);

        // 单据创建审计日志（事务提交后记）：单号 + 工单/工序 + 委外量（decimal 原值）+ 操作人
        Log::info('委外单创建成功', [
            'no' => $os->no, 'order_id' => $os->order_id, 'operation_id' => $os->operation_id,
            'quantity' => $os->quantity, 'created_by' => auth()->id(),
        ]);

        return $os;
    }

    /**
     * 更新委外草稿（原控制器 update 下沉）：仅草稿（1521）；校验同 create；items 全量替换；事务内锁行复查防并发
     *
     * @param  OutsourcingOrder  $outsourcing  路由绑定的委外单模型（草稿状态才可改）
     * @param  array  $data  已过 SaveOutsourcingRequest 格式校验的载荷
     *
     * @throws ProductionException 非草稿 1521 / 其余业务码同 create（422/1523/1520）
     */
    public function update(OutsourcingOrder $outsourcing, array $data): void
    {
        // 状态前置校验（不进事务，快速失败）：非草稿直接拒绝
        if ($outsourcing->status !== OutsourcingOrder::STATUS_DRAFT) {
            throw new ProductionException('已审核单据不可修改', 1521);
        }
        // 委外量判正走 bccomp（D-3 铁律：禁浮点参与数量比较；与 create 同口径）
        if (bccomp((string) $data['quantity'], '0', 2) <= 0) {
            throw new ProductionException('委外数量必须大于 0', 422);
        }
        // empty 与原 request->filled 等价（'' 经全局中间件转 null，0 会被 exists 规则先行 422 拦截）
        if (empty($data['supplier_id'])) {
            throw new ProductionException('供应商不能为空', 422);
        }
        if (empty($data['warehouse_id']) || empty($data['location_id'])) {
            throw new ProductionException('仓库与库位不能为空', 422);
        }
        $op = WorkOrderOperation::where('id', $data['operation_id'])->where('order_id', $data['order_id'])->first();
        if (! $op) {
            throw new ProductionException('工序不属于该工单', 422);
        }
        // 委外对象=工艺路线节点（仅 is_outsourced=1 节点可委外，spec 5 §4 规则定义）：无路线/无 node_no → 422
        $node = $this->routingNodeForOperation($data['operation_id']);
        if ((int) $op->is_outsourced !== 1) {
            throw new ProductionException('该工序不是委外工序', 422);
        }
        if ((int) $op->status === WorkOrderOperation::STATUS_DONE) {
            throw new ProductionException('该工序已完成，不可委外', 422);
        }
        // 草稿期校验：委外量 ≤ 剩余可委外量（排除本次编辑自身——草稿本不在非草稿口径，双保险）
        $order = ProductionOrder::find($data['order_id']);
        // 工单状态校验（B-1b）：编辑可改挂工单/工序，不校验则可把工单 A 建的委外草稿改挂草稿工单 B，
        // 绕过 create 的 B-1 校验——草稿工单 B 编辑虽已被工单侧 1504 引用守卫兜底（不再 500），
        // 但其编辑/删除会被该草稿委外单莫名冻结且形成口径外脏数据，故与 create/approve 同口径
        // [已下达,生产中] 从源头禁止（1523）
        $canOutsource = $order && in_array($order->status, [
            ProductionOrder::STATUS_RELEASED, ProductionOrder::STATUS_PRODUCING,
        ], true);
        if (! $canOutsource) {
            throw new ProductionException('工单当前状态不可委外', 1523);
        }
        $outsourced = bcadd((string) OutsourcingOrder::where('operation_id', $data['operation_id'])
            ->where('id', '!=', $outsourcing->id)
            ->where('status', '!=', OutsourcingOrder::STATUS_DRAFT)->sum('quantity'), '0', 2);
        // 工单缺失分支已由上方 1523 守卫承接（$canOutsource 对 null 工单恒 false），此处 $order 必非空
        if (bccomp((string) $data['quantity'], bcsub((string) $order->quantity, $outsourced, 2), 2) > 0) {
            throw new ProductionException('委外数量超过节点剩余计划量', 1520);
        }
        // 组件行类型归一（同 create）+ 组件查重（422，防重复物料撞唯一键 500）
        $data['items'] = $this->normalizeComponents($data['items']);
        $this->assertComponentsUnique($data['items']);

        DB::transaction(function () use ($outsourcing, $data, $node) {
            // 锁委外单行复查状态（幂等 1521）
            $locked = OutsourcingOrder::whereKey($outsourcing->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== OutsourcingOrder::STATUS_DRAFT) {
                throw new ProductionException('已审核单据不可修改', 1521);
            }
            // 组件载荷校验（节点口径）——校验失败整单回滚
            $items = $this->validateItems($data['items'], $node, (string) $data['quantity']);
            $locked->update([
                'order_id' => $data['order_id'],
                'operation_id' => $data['operation_id'],
                'supplier_id' => $data['supplier_id'],
                'warehouse_id' => $data['warehouse_id'],
                'location_id' => $data['location_id'],
                'quantity' => $data['quantity'],
                // 回收品=节点输出产品快照（回收入账商品口径，1529 一致性校验基准）
                'output_product_id' => $node->output_product_id,
                'remark' => $data['remark'] ?? $locked->remark,
            ]);
            // 组件全量替换（草稿单无流水引用，直接重建；唯一键防重复）
            $locked->items()->delete();
            $locked->items()->createMany($items);
        });
    }

    /**
     * 删除委外草稿（原控制器 destroy 下沉）：仅草稿（1521）；事务内锁行复查防并发
     *
     * @param  OutsourcingOrder  $outsourcing  路由绑定的委外单模型（内存模型持单号供审计日志追溯）
     *
     * @throws ProductionException 非草稿 1521
     */
    public function delete(OutsourcingOrder $outsourcing): void
    {
        // 状态前置校验（不进事务，快速失败）：非草稿直接拒绝
        if ($outsourcing->status !== OutsourcingOrder::STATUS_DRAFT) {
            throw new ProductionException('已审核单据不可删除', 1521);
        }
        DB::transaction(function () use ($outsourcing) {
            // 锁委外单行复查状态（幂等 1521）
            $locked = OutsourcingOrder::whereKey($outsourcing->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== OutsourcingOrder::STATUS_DRAFT) {
                throw new ProductionException('已审核单据不可删除', 1521);
            }
            $locked->delete();
        });

        // 单据删除审计日志（事务提交后记）：内存模型仍持有单号，可用于追溯
        Log::info('委外单草稿删除', ['no' => $outsourcing->no, 'operator' => auth()->id()]);
    }

    /**
     * 发出（审核）（原控制器 approve 下沉）：事务内「锁单幂等 1523（已审核/已回收/已关闭三态拦截；
     * 已关闭=终态，防全退关闭后再 approve 二次扣减组件库存）→ 零组件历史草稿防线 422 →
     * 锁工单行校验状态 [RELEASED, PRODUCING] 1523 → 剩余量复查（Σ同节点非草稿 + 本次 ≤ 工单计划量，1520）→
     * 按发料组件逐行扣（锁序：委外单 → 工单 → 组件余额行按 product_id 索引序批量预锁，不足 →
     * 1522「商品[组件名]库存不足」整单回滚；每组件一条 outsourcing_out 流水（source_no=委外单号、
     * remark=委外发出）→ issued_qty 回写=应发 → 已发出）」任一步失败整体回滚；
     * 余额引擎兜底拒绝（InventoryException）在此语境化翻译为 1522 业务码并保留台账 warn
     *
     * @param  OutsourcingOrder  $outsourcing  路由绑定的委外单模型
     * @return array{no: string} 单号（供控制器响应回显）
     *
     * @throws ProductionException 重复/状态冲突 1523 / 缺组件 422 / 剩余量 1520 / 库存不足 1522
     */
    public function approve(OutsourcingOrder $outsourcing): array
    {
        try {
            $result = null;
            // 事务第 2 参数为死锁(1213)重试次数（并发发出/回收的余额行与工单行锁冲突，
            // 死锁败方整体回滚后重跑闭包重新锁行+重发库存流水，幂等安全）
            DB::transaction(function () use ($outsourcing, &$result) {
                // 锁委外单行：同一单据重复审核在此判重（幂等 1523）；已关闭为状态机终态
                // （余料全退自动），与已审核/已回收一并拦截——防「全退关闭后再 approve」二次
                // 全额扣组件库存、状态被打回已审核（修复前 STATUS_CLOSED 未被拦截）
                $locked = OutsourcingOrder::whereKey($outsourcing->id)->lockForUpdate()->firstOrFail();
                if ($locked->status === OutsourcingOrder::STATUS_APPROVED) {
                    throw new ProductionException('该委外单已审核', 1523);
                }
                if ($locked->status === OutsourcingOrder::STATUS_RECEIVED) {
                    throw new ProductionException('该委外单已回收', 1523);
                }
                if ($locked->status === OutsourcingOrder::STATUS_CLOSED) {
                    throw new ProductionException('该委外单已关闭', 1523);
                }
                // 零组件历史草稿防线（迁移前建单可能无 outsourcing_order_items 行）：无发料组件不可
                // 发出——防 $movements 为空时跳过扣减直接置已审核（历史脏数据兜底，同 1529 数据异常哲学）；
                // 判空与组件预载共用本次查询（P-3）：items+material 各一条查询供下方扣减循环复用
                // （空集合时 material 预载短路不发查询），不再 count+load 双查 items 表
                $locked->load('items.material');
                if ($locked->items->isEmpty()) {
                    throw new ProductionException('委外单缺少发料组件，不可发出', 422);
                }
                // 锁工单行：校验工单状态（草稿/关闭不可发出）——锁序「委外单 → 工单」与回收 receive 单调同向
                $order = ProductionOrder::whereKey($locked->order_id)->lockForUpdate()->firstOrFail();
                if (! in_array($order->status, [ProductionOrder::STATUS_RELEASED, ProductionOrder::STATUS_PRODUCING], true)) {
                    throw new ProductionException('工单当前状态不可委外', 1523);
                }
                // 事务内剩余量复查：草稿期校验在事务外且互不计，审批时须守「已委外合计（含自身）≤ 工单计划量」，
                // 防同节点两草稿各 ≤ 计划量先后审批致合计超计划（组件双倍发出/回收）
                // 同节点并发审批已被工单行锁串行化，SUM 普通读即可（锁序不变）
                $plannedByNode = OutsourcingOrder::where('operation_id', $locked->operation_id)
                    ->where('status', '!=', OutsourcingOrder::STATUS_DRAFT)
                    ->where('id', '!=', $locked->id)
                    ->sum('quantity');
                $aggregate = bcadd((string) $plannedByNode, (string) $locked->quantity, 2);
                if (bccomp($aggregate, (string) $order->quantity, 2) > 0) {
                    throw new ProductionException('委外数量超过节点剩余计划量', 1520);
                }
                // 按发料组件逐行扣（spec 5 §4 规则定义：委外商品=节点输入组件；仅 is_outsourced=1 节点可委外）
                // 组件余额批量预锁（B-104，宪法 §4.2.2 禁循环内锁查询；与 PickList/PurchaseInbound/
                // SalesOutbound 批量预锁同款）：发出仓=单头 warehouse/location（同单全部组件同仓同位），
                // 余额行按 balance_unique(product_id, warehouse_id, location_id) 最左前缀 in 一次锁定——
                // N 组件 N 次锁查询降为 1 次；in 按 product_id 索引序获取行锁，与原「material_id 升序
                // 逐行锁」等价单调（组件 material_id 即余额行 product_id，同索引序获取，无 ABBA 新方向）
                /** @var Collection<int, InventoryBalance> $balanceMap 已锁定的余额行（防超卖校验复用，keyBy product_id） */
                $balanceMap = InventoryBalance::query()
                    ->whereIn('product_id', $locked->items->pluck('material_id'))
                    ->where('warehouse_id', $locked->warehouse_id)
                    ->where('location_id', $locked->location_id)
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('product_id');
                // 按发料组件逐行校验（spec 5 §4 规则定义：委外商品=节点输入组件；sortBy material_id
                // 与余额行锁序同向，多组件校验顺序稳定；余额值取自己锁定的内存模型，锁后不再查库）
                $movements = [];
                foreach ($locked->items->sortBy('material_id') as $item) {
                    $balanceRow = $balanceMap->get($item->material_id);
                    // 该仓该位余额（bcmath 归一；无余额行=0——?? 对空左操作数短路，无需 nullsafe）
                    $balance = bcadd((string) ($balanceRow->quantity ?? '0'), '0', 2);
                    if (bccomp($balance, (string) $item->required_qty, 2) < 0) {
                        throw new ProductionException(
                            '商品['.($item->material->name ?? '#'.$item->material_id).']库存不足',
                            1522
                        );
                    }
                    $movements[] = [
                        'product_id' => $item->material_id,
                        'warehouse_id' => $locked->warehouse_id,
                        'location_id' => $locked->location_id,
                        'direction' => -1,
                        // 引擎 quantity 契约：两位小数十进制字符串（D-3 bcmath 化，原 float 契约/偏离记录⑤已消除）
                        'quantity' => (string) $item->required_qty,
                        'source_type' => 'outsourcing_out',
                        'source_id' => $locked->id,
                        'source_no' => $locked->no,
                        'remark' => '委外发出',
                    ];
                }
                // 全部组件校验通过后统一写流水+扣余额（余额行已按升序预锁，引擎内重复加锁幂等）
                if ($movements !== []) {
                    $this->inventoryService->apply($movements, auth()->id());
                }
                foreach ($locked->items as $item) {
                    // 实发=应发：草稿期可调应发，发出时全额扣减（简化模型，spec 5 偏离记录①）
                    $item->issued_qty = (string) $item->required_qty;
                    $item->save();
                }
                // 置已审核（已发出）+ 操作人/时间
                $locked->status = OutsourcingOrder::STATUS_APPROVED;
                $locked->operator = auth()->user()->name ?? '';
                $locked->approved_at = now();
                $locked->save();
                $result = ['no' => $locked->no];
            }, 2);
        } catch (InventoryException $e) {
            // 余额引擎兜底拒绝（理论上被预校验拦截，防御路径）；走到此分支说明预校验与引擎
            // 判定不一致，记 warn 便于排查数据不一致；
            // 语境化翻译为 1522 业务码保持前端口径（不发原始引擎消息，防内部细节外泄）
            Log::warning('委外发出被余额引擎兜底拒绝（预校验未拦截，疑似数据不一致）', [
                'no' => $outsourcing->no, 'reason' => $e->getMessage(),
            ]);

            throw new ProductionException('库存不足，委外发出被拒绝', 1522);
        }

        // 状态变更审计日志（事务提交后记）：发出即组件扣减出库，属库存关键节点
        // （库存笔级明细由 InventoryService 聚合记录，此处仅记单据维度，避免重复）
        Log::info('委外单发出', ['no' => $result['no'], 'order_id' => $outsourcing->order_id, 'operator' => auth()->id()]);

        return $result;
    }

    /**
     * 回收（原控制器 storeReceipt 下沉）：事务内「锁委外单（状态 ∈ [已发出,已回收]，草稿/已关闭 422；
     * 累计+本次 ≤ 委外量 1524，已回收单再回收必超收）→ 回收品一致性校验（回收商品=委外单
     * output_product_id 节点输出；为空数据异常或与请求 product_id 不符 → 1529「回收商品与委外工序
     * 产出不一致」）→ 锁同单全部工序行（id 升序，含委外工序：DAG 后继就绪判定需读其它前驱状态，
     * 与报工/完工在行级全序上单调同向）→ 锁工单行校验状态 → 创建回收单（创建即审核，
     * 先取号建单再写流水 PF-2）→ InventoryService 写 outsourcing_in 流水(+qty，
     * 商品=output_product_id，source=回收单) → 累计 ≥ 委外量 → 委外单已回收 + 工序标记完成 +
     * 推进「直接后继中全部前驱已完成」的待开工节点（并行分支独立推进，与报工同口径）」任一步失败整体回滚；
     * 锁序 outsourcing→全部工序(升序)→order 与报工（op 全集→order）/完工（全工序→order）行级单调同向，
     * 消除「末批回收 vs 工序报工」并发 ABBA 死锁环；
     * 余额引擎兜底拒绝（InventoryException）在此语境化翻译为 422 业务码并保留台账 warn
     *
     * @param  OutsourcingOrder  $outsourcing  路由绑定的委外单模型
     * @param  array  $data  已过 SaveOutsourcingReceiptRequest 格式校验的载荷
     *                       （quantity/product_id/warehouse_id/location_id/remark）
     * @return array{no: string} 回收单号（供控制器响应回显）
     *
     * @throws ProductionException 量非正/状态不可回收 422 / 产出一致性 1529 / 超收 1524 / 工单状态 1523
     */
    public function receive(OutsourcingOrder $outsourcing, array $data): array
    {
        // 回收量判正走 bccomp（D-3 铁律：禁浮点参与数量比较；正则已保证入参为两位小数十进制）
        if (bccomp((string) $data['quantity'], '0', 2) <= 0) {
            throw new ProductionException('回收数量必须大于 0', 422);
        }

        try {
            $result = null;
            // 事务第 2 参数为死锁(1213)重试次数（机理同 BomController::store：OSR 序列行首建
            // 间隙锁死锁败方整体回滚后重跑闭包重新取号+重发库存流水，幂等安全）
            DB::transaction(function () use ($outsourcing, $data, &$result) {
                // 锁委外单行：回收并发串行化（累计回收判定一致）；仅 [已发出, 已回收] 可回收——
                // 草稿（422）与已关闭（422 防关闭后回灌库存）拦截；已回收单放行到超收校验：
                // 再回收必然超收 → 1524（超收链路由 Feature 用例锁定，事务整体回滚）
                $locked = OutsourcingOrder::whereKey($outsourcing->id)->lockForUpdate()->firstOrFail();
                if (! in_array($locked->status, [OutsourcingOrder::STATUS_APPROVED, OutsourcingOrder::STATUS_RECEIVED], true)) {
                    throw new ProductionException('当前委外单不可回收', 422);
                }
                // 回收商品=委外单回收品（节点输出）：仅 is_outsourced=1 节点可委外后新单必带节点输出快照，
                // output_product_id 为空仅剩历史脏数据（数据异常防御）、请求显式 product_id 冒烟校验不符同样 1529
                $outputProductId = (int) $locked->output_product_id;
                if ($outputProductId <= 0) {
                    throw new ProductionException('回收商品与委外工序产出不一致', 1529);
                }
                if (($data['product_id'] ?? null) !== null && (int) $data['product_id'] !== $outputProductId) {
                    throw new ProductionException('回收商品与委外工序产出不一致', 1529);
                }
                // 累计回收 + 本次 ≤ 委外量（超收 1524 整体回滚）
                $received = $this->receivedQty($locked->id);
                if (bccomp(bcadd($received, (string) $data['quantity'], 2), (string) $locked->quantity, 2) > 0) {
                    throw new ProductionException('回收数量超过委外数量', 1524);
                }
                // 锁同单全部工序行（id 升序，含委外工序，单条语句获取）：DAG 后继就绪判定需读其它前驱状态，
                // 与报工（op 全集→order）/完工（全工序→order）在行级全序上单调同向——若仅锁委外工序行，
                // 并发「末批回收 vs 分支报工」的获取序列非单调会构成 ABBA 死锁环（修复：RTG 委外推进）
                $allOps = WorkOrderOperation::where('order_id', $locked->order_id)
                    ->orderBy('id')->lockForUpdate()->get();
                // 委外工序行从已锁全集取（与报工控制器同构；行缺失防御性跳过）
                $op = $allOps->find($locked->operation_id);
                // 锁工单行校验工单状态（回收商品取自已锁委外单 output_product_id，工单行仅承载状态语义）
                $order = ProductionOrder::whereKey($locked->order_id)->lockForUpdate()->firstOrFail();
                // 工单状态校验：与发出 approve 同口径 [RELEASED, PRODUCING]（spec §5.1 生产中→委外）
                if (! in_array($order->status, [ProductionOrder::STATUS_RELEASED, ProductionOrder::STATUS_PRODUCING], true)) {
                    throw new ProductionException('工单当前状态不可委外', 1523);
                }
                // 先取号创建回收单（创建即审核），再写库存流水（PF-2 重排）：流水创建时回收单已落库，
                // source_id/source_no 直接携带回收单 id/单号（与全项目「流水来源=承载单据本身」口径一致）——
                // 消除旧「先 apply 空串占位、事后 UPDATE 回补 source_no」的 inventory_movements
                // 单列索引范围扫描回补与并发事务间的 movements 行锁等待窗口；锁序「单据头 → 库存」天然全序
                $receipt = $this->sequenceService->nextNoByConfig(
                    DocumentSequence::TYPE_OSR,
                    fn (string $no) => OutsourcingReceipt::create([
                        'no' => $no,
                        'outsourcing_id' => $locked->id,
                        'quantity' => $data['quantity'],
                        'warehouse_id' => $data['warehouse_id'],
                        'location_id' => $data['location_id'],
                        'status' => OutsourcingReceipt::STATUS_APPROVED,
                        'received_at' => now(),
                        'operator' => auth()->user()->name ?? '',
                        'remark' => $data['remark'] ?? null,
                    ]),
                    // legacyMax 只取当日最大单号一行（orderByDesc+value 单查，P1-5：同日前缀字典序=序号序）
                    fn (string $prefix, string $dateKey) => ($no = OutsourcingReceipt::where('no', 'like', $prefix.date('Ymd').'%')
                        ->orderByDesc('no')->value('no')) ? DocumentSequenceService::seqFromNo($no, $prefix, $dateKey) : 0,
                );
                // 统一引擎写流水+加余额（同事务双写；商品=回收品节点输出，流水来源=回收单，审计链完整）
                $this->inventoryService->apply([[
                    'product_id' => $outputProductId,
                    'warehouse_id' => $data['warehouse_id'],
                    'location_id' => $data['location_id'],
                    'direction' => 1,
                    'quantity' => $data['quantity'],
                    'source_type' => 'outsourcing_in',
                    'source_id' => $receipt->id,
                    'source_no' => $receipt->no,
                    'remark' => '委外回收',
                ]], auth()->id());

                // 累计回收 ≥ 委外量 → 委外单已回收 + 委外工序标记完成（spec §6；回收只对未完成工序生效）；
                // 追加推进：直接后继中「全部前驱已完成」的待开工节点置进行中（并行分支独立推进，与报工控制器同口径；
                // 无路线的历史脏单无 operation edges，推进循环自然空转——仅置 DONE）
                $receivedNow = bcadd($received, (string) $data['quantity'], 2);
                if (bccomp($receivedNow, (string) $locked->quantity, 2) >= 0) {
                    $locked->status = OutsourcingOrder::STATUS_RECEIVED;
                    $locked->save();
                    if ($op && $op->status !== WorkOrderOperation::STATUS_DONE) {
                        $op->status = WorkOrderOperation::STATUS_DONE;
                        // 边一次取出内存建邻接、前驱状态用已锁定的工序全集判定（§4.2.2 禁循环内查询）
                        $edges = WorkOrderOperationEdge::where('order_id', $order->id)->get();
                        // 已完成集合：全集中已 DONE 的行 + 本工序（本轮即将落 DONE，对后继就绪判定等效已完成）
                        $doneIds = [$op->id => true];
                        foreach ($allOps as $s) {
                            if ($s->status === WorkOrderOperation::STATUS_DONE) {
                                $doneIds[$s->id] = true;
                            }
                        }
                        $byId = $allOps->keyBy('id');
                        $predsByTo = $edges->groupBy('to_operation_id');
                        foreach ($edges->where('from_operation_id', $op->id) as $edge) {
                            $succ = $byId->get($edge->to_operation_id);
                            if (! $succ || $succ->status !== WorkOrderOperation::STATUS_PENDING) {
                                continue;
                            }
                            // 后继就绪判定：全部前驱均在已完成集合（空前驱不会出现——本节点即其前驱）
                            $allPredsDone = ($predsByTo->get($edge->to_operation_id) ?? collect())
                                ->every(fn (WorkOrderOperationEdge $e) => isset($doneIds[$e->from_operation_id]));
                            if ($allPredsDone) {
                                $succ->status = WorkOrderOperation::STATUS_RUNNING;
                                $succ->save();
                            }
                        }
                        $op->save();
                    }
                }
                $result = ['no' => $receipt->no];
            }, 2);
        } catch (InventoryException $e) {
            // 余额引擎兜底（入库方向理论不触发，防御路径）；走到此分支说明引擎内出现
            // 非预期拒绝，记 warn 便于排查数据不一致；
            // 语境化翻译为 422 业务码保持前端口径（不发原始引擎消息，防内部细节外泄）
            Log::warning('委外回收被余额引擎兜底拒绝（理论不可达，疑似数据不一致）', [
                'no' => $outsourcing->no, 'reason' => $e->getMessage(),
            ]);

            throw new ProductionException('回收失败，请重试', 422);
        }

        // 单据创建审计日志（事务提交后记）：回收单创建即审核，含本次回收量（decimal 原值）
        Log::info('委外回收单创建（即审核）', [
            'no' => $result['no'], 'outsourcing_id' => $outsourcing->id,
            'quantity' => $data['quantity'], 'operator' => auth()->id(),
        ]);

        return $result;
    }

    /**
     * 余料退回（原控制器 storeReturn 下沉）：事务内「锁委外单（状态 ∈ [已发出,已回收]，
     * 草稿/已关闭 422「当前委外单不可退回」）→ 锁同单组件行（单语句获取，锁序 单据头 → 明细）→
     * 逐行校验组件归属与退回量 ≤ 已发−已退（bcmath，422「退回数量超过已发未退数量」）→
     * 创建退回单（TYPE_OSRT 取号 ORT、创建即审核，先取号建单再写流水 PF-2；多行提交仅记首行——
     * 偏离记录③，明细以流水逐行留痕）→ 按 material_id 升序写 outsourcing_return 流水(+qty，
     * source=退回单——库存行锁序与发出 approve 同向) → returned_qty 回写（bcadd 累加）→
     * 全部组件 returned==issued → 委外单已关闭」任一步失败整体回滚；
     * 余额引擎兜底拒绝（InventoryException）在此语境化翻译为 422 业务码并保留台账 warn
     *
     * @param  OutsourcingOrder  $outsourcing  路由绑定的委外单模型
     * @param  array  $data  已过 SaveOutsourcingReturnRequest 格式校验的载荷
     *                       （items/warehouse_id/location_id/remark）
     * @return array{no: string} 退回单号（供控制器响应回显）
     *
     * @throws ProductionException 退回校验 422（状态不可退回/数量非正/组件不属于该单/超已发未退）
     */
    public function returnItems(OutsourcingOrder $outsourcing, array $data): array
    {
        // 组件行类型归一：退回数量统一字符串（bcmath 权威比较），组件行回整型
        $data['items'] = array_map(
            fn (array $item) => [
                'item_id' => (int) $item['item_id'],
                'quantity' => (string) $item['quantity'],
            ],
            (array) $data['items'],
        );
        // 组件行查重：同 item_id 只允许一行（422）——防「事务内同一内存模型逐行校验」下
        // 两行提交各自 ≤ 剩余可退、累计退回却超已发的库存账实不一致（修复轮 1）
        $seen = [];
        foreach ($data['items'] as $item) {
            if (isset($seen[$item['item_id']])) {
                throw new ProductionException('退回组件重复', 422);
            }
            $seen[$item['item_id']] = true;
        }

        try {
            $result = null;
            // 事务第 2 参数为死锁(1213)重试次数（机理同 receive：ORT 序列行首间隙锁死锁败方
            // 整体回滚后重跑闭包重新取号+重发库存流水，幂等安全）
            DB::transaction(function () use ($outsourcing, $data, &$result) {
                // 锁委外单行：退回并发串行化（累计退回判定一致）；仅 [已发出, 已回收] 可退回（草稿/已关闭 422）
                $locked = OutsourcingOrder::whereKey($outsourcing->id)->lockForUpdate()->firstOrFail();
                if (! in_array($locked->status, [OutsourcingOrder::STATUS_APPROVED, OutsourcingOrder::STATUS_RECEIVED], true)) {
                    throw new ProductionException('当前委外单不可退回', 422);
                }
                // 组件行锁定（单语句获取全部行，锁序 单据头 → 明细）：item 归属校验 + 退回量 ≤ 已发−已退
                $items = $locked->items()->lockForUpdate()->get()->keyBy('id');
                $lines = [];
                foreach ($data['items'] as $line) {
                    if (bccomp($line['quantity'], '0', 2) <= 0) {
                        throw new ProductionException('退回数量必须大于 0', 422);
                    }
                    $item = $items->get($line['item_id']);
                    if (! $item) {
                        throw new ProductionException('退回组件不属于该委外单', 422);
                    }
                    // 剩余可退 = 已发 − 已退（bcmath 权威；已发 0 的组件剩余 0，天然防未发先退）
                    $remaining = bcsub((string) $item->issued_qty, (string) $item->returned_qty, 2);
                    if (bccomp($line['quantity'], $remaining, 2) > 0) {
                        throw new ProductionException('退回数量超过已发未退数量', 422);
                    }
                    $lines[] = ['item' => $item, 'quantity' => $line['quantity']];
                }
                // 先取号创建退回单（创建即审核），再写库存流水（PF-2 重排）：流水创建时退回单已落库，
                // source_id/source_no 直接携带退回单 id/单号（与全项目「流水来源=承载单据本身」口径一致）——
                // 消除旧「先 apply 空串占位、事后 UPDATE 回补 source_no」的 inventory_movements
                // 单列索引范围扫描回补与并发事务间的 movements 行锁等待窗口；锁序「单据头 → 明细 → 库存」天然全序
                $return = $this->sequenceService->nextNoByConfig(
                    DocumentSequence::TYPE_OSRT,
                    fn (string $no) => OutsourcingReturn::create([
                        'no' => $no,
                        'outsourcing_id' => $locked->id,
                        'item_id' => $lines[0]['item']->id,
                        'material_id' => $lines[0]['item']->material_id,
                        'quantity' => $lines[0]['quantity'],
                        'warehouse_id' => $data['warehouse_id'],
                        'location_id' => $data['location_id'],
                        'status' => OutsourcingReturn::STATUS_APPROVED,
                        'returned_at' => now(),
                        'operator' => auth()->user()->name ?? '',
                        'remark' => $data['remark'] ?? null,
                    ]),
                    // legacyMax 只取当日最大单号一行（orderByDesc+value 单查，P1-5：同日前缀字典序=序号序）
                    fn (string $prefix, string $dateKey) => ($no = OutsourcingReturn::where('no', 'like', $prefix.date('Ymd').'%')
                        ->orderByDesc('no')->value('no')) ? DocumentSequenceService::seqFromNo($no, $prefix, $dateKey) : 0,
                );
                // 按 material_id 升序写流水（余额行锁序与发出 approve 同向，多组件并发退回串行化；
                // 流水来源=退回单，审计链完整）
                $movements = [];
                foreach (collect($lines)->sortBy(fn (array $l) => $l['item']->material_id) as $l) {
                    $movements[] = [
                        'product_id' => $l['item']->material_id,
                        'warehouse_id' => $data['warehouse_id'],
                        'location_id' => $data['location_id'],
                        'direction' => 1,
                        // 引擎 quantity 契约：两位小数十进制字符串（D-3 bcmath 化，原 float 契约/偏离记录⑤已消除）
                        'quantity' => (string) $l['quantity'],
                        'source_type' => 'outsourcing_return',
                        'source_id' => $return->id,
                        'source_no' => $return->no,
                        'remark' => '余料退回',
                    ];
                }
                // 全部行校验通过后统一写流水（余额行升序加锁）
                if ($movements !== []) {
                    $this->inventoryService->apply($movements, auth()->id());
                }

                // returned_qty 回写 = 已退累计（bcmath 累加，防覆盖历史退回）
                foreach ($lines as $l) {
                    $l['item']->returned_qty = bcadd((string) $l['item']->returned_qty, $l['quantity'], 2);
                    $l['item']->save();
                }
                // 全部组件已退满（returned==issued，bcmath 权威）→ 委外单自动关闭（余料退回完成）；
                // 判定复用事务早期已锁定的组件集合（P-4）：模型即本轮回写 returned_qty 的同一实例，
                // 二次查询纯冗余；every 语义与原查询集合等价（同事务读同值，keyBy 不丢行）
                $allReturned = $items
                    ->every(fn ($i) => bccomp((string) $i->returned_qty, (string) $i->issued_qty, 2) === 0);
                if ($allReturned) {
                    $locked->status = OutsourcingOrder::STATUS_CLOSED;
                    $locked->save();
                }
                $result = ['no' => $return->no];
            }, 2);
        } catch (InventoryException $e) {
            // 余额引擎兜底（入库方向理论不触发，防御路径）；走到此分支说明引擎内出现
            // 非预期拒绝，记 warn 便于排查数据不一致；
            // 语境化翻译为 422 业务码保持前端口径（不发原始引擎消息，防内部细节外泄）
            Log::warning('委外退回被余额引擎兜底拒绝（理论不可达，疑似数据不一致）', [
                'no' => $outsourcing->no, 'reason' => $e->getMessage(),
            ]);

            throw new ProductionException('退回失败，请重试', 422);
        }

        // 单据创建审计日志（事务提交后记）：退回单创建即审核，余料回库；含退回行数
        // （库存笔级明细由 InventoryService 聚合记录，此处仅记单据维度，避免重复）
        Log::info('委外退回单创建（即审核）', [
            'no' => $result['no'], 'outsourcing_id' => $outsourcing->id,
            'line_count' => count($data['items']), 'operator' => auth()->id(),
        ]);

        return $result;
    }

    /**
     * 已回收累计（Σ 回收单数量；SQL SUM 聚合——回收单创建即审核 status 恒 1，SUM 与逐行 bcadd 语义等价，
     * 跨库 SUM 返回形态不一（MySQL 字符串 / SQLite 数值）统一 bcmath 归一；无回收单 SUM 为空 → '0.00'，
     * 与 index 的 withSum（0）口径一致（P1-3；修复轮：show 曾返回 '0' 与 index '0.00' 不一致）
     *
     * @param  int  $outsourcingId  委外单 ID
     * @return string 已回收累计数量（两位小数字符串）
     */
    public function receivedQty(int $outsourcingId): string
    {
        $total = OutsourcingReceipt::where('outsourcing_id', $outsourcingId)
            ->selectRaw('SUM(quantity) as total')
            ->value('total');

        return $total === null ? '0.00' : bcadd((string) $total, '0', 2);
    }

    /**
     * 从工序节点组装委外预填数据
     * 取数链：工序行 node_no → production_orders.routing_id → routing_nodes.materials（单位用量）
     * 组件库存=Σ全仓余额（读快照）；剩余可委外量=工单数量−Σ非草稿委外单（bcmath）
     *
     * @throws ProductionException 422（工单无路线/节点非委外/节点已完成/节点缺失）
     */
    public function fromOperation(int $operationId): array
    {
        $op = WorkOrderOperation::with('process')->findOrFail($operationId);
        $order = $op->order()->firstOrFail();
        if (! $order->routing_id || ! $op->node_no) {
            throw new ProductionException('该工单没有工艺路线，不可委外', 422);
        }
        if ((int) $op->is_outsourced !== 1) {
            throw new ProductionException('该工序不是委外工序', 422);
        }
        if ((int) $op->status === WorkOrderOperation::STATUS_DONE) {
            throw new ProductionException('该工序已完成，不可委外', 422);
        }
        // 委外节点取数改单节点直查（P1-D-2）：不再预载整条路线的全部节点材料明细——点查
        // uniq_routing_nodes_node_no 索引命中单节点，传输行数从全路线降为单节点材料集；
        // outputProduct 并入预载（返回值消费，消除原懒加载点查）。
        // 路由头缺失（外键约束下理论不可达）与原节点缺失统一收敛为 422，与报告 D-2 方案 a 口径一致
        $node = RoutingNode::query()
            ->where('routing_id', $order->routing_id)
            ->where('node_no', $op->node_no)
            ->with('materials.material', 'materials.unit', 'outputProduct')
            ->first();
        if (! $node) {
            throw new ProductionException('工艺路线节点不存在', 422);
        }

        // 已委外量：同节点全部非草稿委外单合计（SQL SUM 归一 bcmath）
        $outsourced = bcadd((string) OutsourcingOrder::where('operation_id', $operationId)
            ->where('status', '!=', OutsourcingOrder::STATUS_DRAFT)->sum('quantity'), '0', 2);
        $plan = (string) $order->quantity;

        // 组件库存：Σ全仓余额（每组件一行，SUM 形态归一——跨库返回形态不一统一 bcmath）
        $stockRows = InventoryBalance::query()
            ->whereIn('product_id', $node->materials->pluck('material_id'))
            ->selectRaw('product_id, SUM(quantity) as total')->groupBy('product_id')->get()->keyBy('product_id');

        return [
            'operation_id' => $op->id, 'node_no' => $op->node_no, 'process_name' => $op->process?->name,
            'order_id' => $order->id, 'order_no' => $order->no,
            'plan_qty' => $plan, 'outsourced_qty' => $outsourced,
            'remaining_qty' => bcsub($plan, $outsourced, 2),
            'output_product_id' => $node->output_product_id,
            'output_product_name' => $node->outputProduct?->name,
            'items' => $node->materials->map(fn ($m) => [
                'material_id' => $m->material_id, 'material_name' => $m->material?->name,
                'material_code' => $m->material?->code, 'qty_per_unit' => (string) $m->qty_per_unit,
                'unit_id' => $m->unit_id, 'unit_name' => $m->unit?->name,
                'stock' => bcadd((string) ($stockRows->get($m->material_id)?->getAttribute('total') ?? '0'), '0', 2),
            ])->values(),
        ];
    }

    /**
     * 取工序对应的工艺路线节点（create/update 用，逻辑同 fromOperation 的取数段）
     * 无路线/无 node_no → 422「该工单没有工艺路线，不可委外」：仅 is_outsourced=1 的路线节点可委外
     * （spec 5 §4 规则定义：成品口径收敛后与 fromOperation 语义一致）；有路线但路由头/节点缺失属数据异常：
     * 显式 422，禁止静默降级
     *
     * @throws ProductionException 422（无路线/无 node_no/路由头或节点缺失）/ 数据异常（工序/工单不存在）
     */
    public function routingNodeForOperation(int $operationId): RoutingNode
    {
        $op = WorkOrderOperation::findOrFail($operationId);
        $order = $op->order()->firstOrFail();
        if (! $order->routing_id || ! $op->node_no) {
            throw new ProductionException('该工单没有工艺路线，不可委外', 422);
        }
        // 同 fromOperation 的 P1-D-2 取数：单节点直查替代全路线预载——调用方仅消费节点材料
        // 与 output_product_id 列，无需预载 outputProduct 关系
        $node = RoutingNode::query()
            ->where('routing_id', $order->routing_id)
            ->where('node_no', $op->node_no)
            ->with('materials.material', 'materials.unit')
            ->first();
        if (! ($node instanceof RoutingNode)) {
            throw new ProductionException('工艺路线节点不存在', 422);
        }

        return $node;
    }

    /**
     * 校验组件载荷：应发 > 0 且 ≤ 单位用量×委外量（后端权威，bcmath 4 位中间精度）
     *
     * @param  array<int, array{material_id:int, required_qty:string, unit_id:int}>  $items
     * @return array<int, array{material_id:int, required_qty:string, unit_id:int}>
     *
     * @throws ProductionException 422（空组件/非节点材料/应发非正/超折算上限/重复物料）
     */
    public function validateItems(array $items, RoutingNode $node, string $quantity): array
    {
        if ($items === []) {
            throw new ProductionException('至少需要一个发料组件', 422);
        }
        $seen = [];
        foreach ($items as $i) {
            $mat = $node->materials->firstWhere('material_id', (int) $i['material_id']);
            if (! $mat) {
                throw new ProductionException('发料组件不在该节点输入材料清单中', 422);
            }
            if (bccomp((string) $i['required_qty'], '0', 2) <= 0) {
                throw new ProductionException('应发数量必须大于 0', 422);
            }
            $cap = bcmul((string) $mat->qty_per_unit, $quantity, 4);
            if (bccomp((string) $i['required_qty'], $cap, 2) > 0) {
                throw new ProductionException('应发数量超过单位用量折算上限', 422);
            }
            if (isset($seen[$i['material_id']])) {
                throw new ProductionException('发料组件重复', 422);
            }
            $seen[$i['material_id']] = true;
        }

        return $items;
    }

    // 组件行类型归一：应发数量统一字符串（bcmath 权威比较），物料/单位回整型（原控制器 validatePayload 尾部逻辑）
    private function normalizeComponents(array $items): array
    {
        return array_map(
            fn (array $item) => [
                'material_id' => (int) $item['material_id'],
                'required_qty' => (string) $item['required_qty'],
                'unit_id' => (int) $item['unit_id'],
            ],
            $items,
        );
    }

    // 组件查重：同物料只允许一行（422；validateItems 同口径兜底）——
    // 此处统一拦截，避免重复物料直落撞唯一键 uniq_outsourcing_order_items 抛 500
    private function assertComponentsUnique(array $items): void
    {
        $seen = [];
        foreach ($items as $item) {
            if (isset($seen[$item['material_id']])) {
                throw new ProductionException('发料组件重复', 422);
            }
            $seen[$item['material_id']] = true;
        }
    }
}
