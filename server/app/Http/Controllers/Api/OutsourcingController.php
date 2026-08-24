<?php

// 委外加工控制器：from-operation 预填 + 草稿 CRUD（组件载荷校验，委外量 ≤ 节点剩余计划量 1520）+ 发出
// （审核：按发料组件扣库存防超卖 1522）+ 回收（创建即审核回收单 + 工序联动，回收品=节点输出
// output_product_id 一致性 1529）+ 余料退回（创建即审核，全退自动关闭）
// 委外商品口径：仅 is_outsourced=1 的工艺路线节点可委外，发料组件与回收品均取节点口径（spec 5 §4 规则定义）

namespace App\Http\Controllers\Api;

use App\Exceptions\InventoryException;
use App\Exceptions\ProductionException;
use App\Http\Controllers\Controller;
use App\Models\DocumentSequence;
use App\Models\InventoryBalance;
use App\Models\OutsourcingOrder;
use App\Models\OutsourcingOrderItem;
use App\Models\OutsourcingReceipt;
use App\Models\OutsourcingReturn;
use App\Models\ProductionOrder;
use App\Models\WorkOrderOperation;
use App\Models\WorkOrderOperationEdge;
use App\Services\DocumentSequenceService;
use App\Services\InventoryService;
use App\Services\OutsourcingService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OutsourcingController extends Controller
{
    use ApiResponse;

    public function __construct(
        private InventoryService $inventoryService,
        private DocumentSequenceService $sequenceService,
        private OutsourcingService $outsourcingService,
    ) {}

    /** 分页列表：关键词/工单/工序/状态 筛选；含工单单号/供应商名/工序名/节点号/回收品与已回收累计 */
    public function index(Request $request)
    {
        $query = OutsourcingOrder::query()
            ->join('production_orders', 'production_orders.id', '=', 'outsourcing_orders.order_id')
            ->join('suppliers', 'suppliers.id', '=', 'outsourcing_orders.supplier_id')
            ->join('work_order_operations', 'work_order_operations.id', '=', 'outsourcing_orders.operation_id')
            ->join('processes', 'processes.id', '=', 'work_order_operations.process_id')
            // 回收品名称联查（output_product_id 可空=历史脏数据，leftJoin 保行）
            ->leftJoin('products', 'products.id', '=', 'outsourcing_orders.output_product_id')
            ->select(
                // 显式列出列表所需主表列（与下方 map 闭包字段一一对应），避免 select 通配拉取未列字段
                'outsourcing_orders.id',
                'outsourcing_orders.no',
                'outsourcing_orders.order_id',
                'outsourcing_orders.operation_id',
                'outsourcing_orders.supplier_id',
                'outsourcing_orders.quantity',
                'outsourcing_orders.status',
                'outsourcing_orders.approved_at',
                'outsourcing_orders.operator',
                'outsourcing_orders.created_at',
                'production_orders.no as order_no',
                'suppliers.name as supplier_name',
                'processes.name as process_name',
                'work_order_operations.node_no as node_no',
                'products.name as output_product_name',
            )
            // 已回收累计（标量子查询免 N+1；与 show 的 SUM 口径一致）
            ->withSum('receipts as receipt_qty_sum', 'quantity')
            ->orderByDesc('outsourcing_orders.id');

        if ($keyword = $request->input('keyword')) {
            $query->where('outsourcing_orders.no', 'like', "%{$keyword}%");
        }
        if ($request->filled('status')) {
            $query->where('outsourcing_orders.status', (int) $request->input('status'));
        }
        // 工单/工序键控过滤（列表联动筛选入口）
        if ($request->filled('order_id')) {
            $query->where('outsourcing_orders.order_id', (int) $request->input('order_id'));
        }
        if ($request->filled('operation_id')) {
            $query->where('outsourcing_orders.operation_id', (int) $request->input('operation_id'));
        }

        $rows = $query->paginate(max(1, min(100, (int) $request->input('per_page', 10))));

        return $this->ok([
            // join 别名列经 getAttribute 读取（PHPStan 静态分析可识别）
            'items' => $rows->map(fn (OutsourcingOrder $o) => [
                'id' => $o->id,
                'no' => $o->no,
                'order_id' => $o->order_id,
                'order_no' => $o->getAttribute('order_no'),
                'operation_id' => $o->operation_id,
                // 委外工序展示=节点号+工序名（列表联动/节点口径回显）
                'node_no' => $o->getAttribute('node_no'),
                'process_name' => $o->getAttribute('process_name'),
                // 回收品（节点输出半成品/成品；无路线历史单为空）
                'output_product_name' => $o->getAttribute('output_product_name'),
                'supplier_id' => $o->supplier_id,
                'supplier_name' => $o->getAttribute('supplier_name'),
                'quantity' => $o->quantity,
                // 已回收累计（SUM 归一 bcmath；回收弹窗打开前列表即可见进度）
                'received_qty' => bcadd((string) $o->getAttribute('receipt_qty_sum'), '0', 2),
                'status' => (int) $o->status,
                'status_label' => OutsourcingOrder::STATUS_LABELS[$o->status] ?? '未知',
                'approved_at' => $o->approved_at?->toDateTimeString(),
                'operator' => $o->operator,
                'created_at' => $o->created_at?->toDateTimeString(),
            ]),
            'total' => $rows->total(), 'page' => $rows->currentPage(), 'per_page' => $rows->perPage(),
        ]);
    }

    /** 新建草稿：工单状态 ∈ [已下达,生产中]（1523，与发出 approve 同口径）；委外量 ≤ 节点剩余计划量（1520）；工序必须属于该工单（422）；组件载荷检单元节点口径（422） */
    public function store(Request $request)
    {
        $data = $this->validatePayload($request);
        // 委外量判正走 bccomp（D-3 铁律：禁浮点参与数量比较；正则已保证入参为两位小数十进制）
        if (bccomp((string) $data['quantity'], '0', 2) <= 0) {
            return $this->fail(422, '委外数量必须大于 0');
        }
        if (! $request->filled('supplier_id')) {
            return $this->fail(422, '供应商不能为空');
        }
        if (! $request->filled('warehouse_id') || ! $request->filled('location_id')) {
            return $this->fail(422, '仓库与库位不能为空');
        }
        // 工序必须属于该工单（防跨单挂工序）
        $op = WorkOrderOperation::where('id', $data['operation_id'])->where('order_id', $data['order_id'])->first();
        if (! $op) {
            return $this->fail(422, '工序不属于该工单');
        }
        // 委外对象=工艺路线节点（仅 is_outsourced=1 节点可委外，spec 5 §4 规则定义）：无路线/无 node_no → 422，
        // 路由头/节点缺失等数据异常 422 由下方统一 catch 承接（修复轮 2 前在 try 外泄漏 500）
        try {
            $node = $this->outsourcingService->routingNodeForOperation($data['operation_id']);
            if ((int) $op->is_outsourced !== 1) {
                return $this->fail(422, '该工序不是委外工序');
            }
            if ((int) $op->status === WorkOrderOperation::STATUS_DONE) {
                return $this->fail(422, '该工序已完成，不可委外');
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
                return $this->fail(1523, '工单当前状态不可委外');
            }
            $outsourced = bcadd((string) OutsourcingOrder::where('operation_id', $data['operation_id'])
                ->where('status', '!=', OutsourcingOrder::STATUS_DRAFT)->sum('quantity'), '0', 2);
            // 工单缺失分支已由上方 1523 守卫承接（$canOutsource 对 null 工单恒 false），此处 $order 必非空
            if (bccomp((string) $data['quantity'], bcsub((string) $order->quantity, $outsourced, 2), 2) > 0) {
                return $this->fail(1520, '委外数量超过节点剩余计划量');
            }

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
                $items = $this->outsourcingService->validateItems($data['items'], $node, (string) $data['quantity']);
                $os->items()->createMany($items);

                return $os;
            }, 2);
        } catch (ProductionException $e) {
            // 422 节点缺失（数据异常）/ 组件载荷不符（应发超上限/重复/非节点材料）——整体回滚
            return $this->fail($e->getCode() ?: 422, $e->getMessage());
        }

        return $this->ok(['no' => $os->no]);
    }

    /** from-operation 预填：节点输入材料清单×单位用量 + 回收品 + 计划/已委外/剩余量（结构不符 422） */
    public function fromOperation(int $operationId)
    {
        try {
            return $this->ok($this->outsourcingService->fromOperation($operationId));
        } catch (ProductionException $e) {
            // 422：工单无路线/节点非委外/节点已完成等结构不符（预填不可用）
            return $this->fail($e->getCode() ?: 422, $e->getMessage());
        }
    }

    /** 详情：头信息 + 组件明细（余料退回可退数=已发−已退）+ 回收记录摘要 */
    public function show(OutsourcingOrder $outsourcing)
    {
        // 单行预载防 N+1：工序（含工艺名）+ 回收品 + 组件（物料/单位）
        $outsourcing->load(['operation.process', 'outputProduct', 'items.material', 'items.unit']);

        return $this->ok([
            'id' => $outsourcing->id,
            'no' => $outsourcing->no,
            'order_id' => $outsourcing->order_id,
            'order_no' => $outsourcing->order?->no,
            'operation_id' => $outsourcing->operation_id,
            // 委外工序展示=节点号+工艺名（编辑回填/退回弹窗口径）
            'node_no' => $outsourcing->operation?->node_no,
            'process_name' => $outsourcing->operation?->process?->name,
            // 回收品（节点输出快照，编辑弹窗只读展示）
            'output_product_name' => $outsourcing->outputProduct?->name,
            'supplier_id' => $outsourcing->supplier_id,
            'supplier_name' => $outsourcing->supplier?->name,
            'status' => (int) $outsourcing->status,
            'status_label' => OutsourcingOrder::STATUS_LABELS[$outsourcing->status] ?? '未知',
            'warehouse_id' => $outsourcing->warehouse_id,
            'warehouse_name' => $outsourcing->warehouse?->name,
            'location_id' => $outsourcing->location_id,
            'location_name' => $outsourcing->location?->name,
            'quantity' => $outsourcing->quantity,
            'approved_at' => $outsourcing->approved_at?->toDateTimeString(),
            'operator' => $outsourcing->operator,
            'remark' => $outsourcing->remark,
            // 已回收累计（回收弹窗剩余量数据源）
            'received_qty' => $this->receivedQty($outsourcing->id),
            // 组件明细（退回余料数据源：可退=已发−已退；id 供退回载荷 item_id）
            'items' => $outsourcing->items->map(fn (OutsourcingOrderItem $i) => [
                'id' => $i->id,
                'material_id' => $i->material_id,
                'material_name' => $i->material?->name,
                'required_qty' => $i->required_qty,
                'issued_qty' => $i->issued_qty,
                'returned_qty' => $i->returned_qty,
                'unit_name' => $i->unit?->name,
            ])->values(),
        ]);
    }

    /** 更新草稿：仅草稿（1521）；校验同 store；items 全量替换；事务内锁行复查防并发 */
    public function update(Request $request, OutsourcingOrder $outsourcing)
    {
        try {
            if ($outsourcing->status !== OutsourcingOrder::STATUS_DRAFT) {
                return $this->fail(1521, '已审核单据不可修改');
            }
            $data = $this->validatePayload($request);
            // 委外量判正走 bccomp（D-3 铁律：禁浮点参与数量比较；与 store 同口径）
            if (bccomp((string) $data['quantity'], '0', 2) <= 0) {
                return $this->fail(422, '委外数量必须大于 0');
            }
            if (! $request->filled('supplier_id')) {
                return $this->fail(422, '供应商不能为空');
            }
            if (! $request->filled('warehouse_id') || ! $request->filled('location_id')) {
                return $this->fail(422, '仓库与库位不能为空');
            }
            $op = WorkOrderOperation::where('id', $data['operation_id'])->where('order_id', $data['order_id'])->first();
            if (! $op) {
                return $this->fail(422, '工序不属于该工单');
            }
            // 委外对象=工艺路线节点（仅 is_outsourced=1 节点可委外，spec 5 §4 规则定义）：无路线/无 node_no → 422
            $node = $this->outsourcingService->routingNodeForOperation($data['operation_id']);
            if ((int) $op->is_outsourced !== 1) {
                return $this->fail(422, '该工序不是委外工序');
            }
            if ((int) $op->status === WorkOrderOperation::STATUS_DONE) {
                return $this->fail(422, '该工序已完成，不可委外');
            }
            // 草稿期校验：委外量 ≤ 剩余可委外量（排除本次编辑自身——草稿本不在非草稿口径，双保险）
            $order = ProductionOrder::find($data['order_id']);
            $outsourced = bcadd((string) OutsourcingOrder::where('operation_id', $data['operation_id'])
                ->where('id', '!=', $outsourcing->id)
                ->where('status', '!=', OutsourcingOrder::STATUS_DRAFT)->sum('quantity'), '0', 2);
            if (! $order || bccomp((string) $data['quantity'], bcsub((string) $order->quantity, $outsourced, 2), 2) > 0) {
                return $this->fail(1520, '委外数量超过节点剩余计划量');
            }

            DB::transaction(function () use ($outsourcing, $data, $node) {
                // 锁委外单行复查状态（幂等 1521）
                $locked = OutsourcingOrder::whereKey($outsourcing->id)->lockForUpdate()->firstOrFail();
                if ($locked->status !== OutsourcingOrder::STATUS_DRAFT) {
                    throw new ProductionException('已审核单据不可修改', 1521);
                }
                // 组件载荷校验（节点口径）——校验失败整单回滚
                $items = $this->outsourcingService->validateItems($data['items'], $node, (string) $data['quantity']);
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
        } catch (ProductionException $e) {
            // 1521 已审核（锁行复查与并发审核幂等拦截）/ 422 组件载荷不符
            return $this->fail($e->getCode() ?: 1521, $e->getMessage());
        }

        return $this->ok();
    }

    /** 删除草稿：仅草稿（1521）；事务内锁行复查防并发 */
    public function destroy(OutsourcingOrder $outsourcing)
    {
        try {
            if ($outsourcing->status !== OutsourcingOrder::STATUS_DRAFT) {
                return $this->fail(1521, '已审核单据不可删除');
            }
            DB::transaction(function () use ($outsourcing) {
                // 锁委外单行复查状态（幂等 1521）
                $locked = OutsourcingOrder::whereKey($outsourcing->id)->lockForUpdate()->firstOrFail();
                if ($locked->status !== OutsourcingOrder::STATUS_DRAFT) {
                    throw new ProductionException('已审核单据不可删除', 1521);
                }
                $locked->delete();
            });
        } catch (ProductionException $e) {
            // 1521 已审核（锁行复查与并发审核幂等拦截）
            return $this->fail($e->getCode() ?: 1521, $e->getMessage());
        }

        return $this->ok();
    }

    /**
     * 发出（审核）：事务内「锁单幂等 1523（已审核/已回收/已关闭三态拦截；已关闭=终态，防
     * 全退关闭后再 approve 二次扣减组件库存）→ 零组件历史草稿防线 422 → 锁工单行校验状态
     * [RELEASED, PRODUCING] 1523 → 剩余量复查（Σ同节点非草稿 + 本次 ≤ 工单计划量，1520）→
     * 按发料组件逐行扣（锁序：委外单 → 工单 → 组件余额行按 material_id 升序，不足 →
     * 1522「商品[组件名]库存不足」整单回滚；每组件一条 outsourcing_out 流水（source_no=委外单号、
     * remark=委外发出）→ issued_qty 回写=应发 → 已发出）」任一步失败整体回滚
     */
    public function approve(OutsourcingOrder $outsourcing)
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
                // 发出——防 $movements 为空时跳过扣减直接置已审核（历史脏数据兜底，同 1529 数据异常哲学）
                if ($locked->items()->count() === 0) {
                    throw new ProductionException('委外单缺少发料组件，不可发出', 422);
                }
                // 锁工单行：校验工单状态（草稿/关闭不可发出）——锁序「委外单 → 工单」与回收 storeReceipt 单调同向
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
                // 组件预载（items + material 各一条查询，循环内不再触发 N+1 懒加载）
                $locked->load('items.material');
                // 组件预锁与校验：按 material_id 升序遍历（多组件锁序稳定，同仓同库位并发扣减串行化；
                // 余额行「锁+取值」合并单查——锁后从内存取 quantity，避免锁后重查）
                $movements = [];
                foreach ($locked->items->sortBy('material_id') as $item) {
                    $balanceRow = InventoryBalance::where('product_id', $item->material_id)
                        ->where('warehouse_id', $locked->warehouse_id)
                        ->where('location_id', $locked->location_id)
                        ->lockForUpdate()->first();
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
        } catch (ProductionException $e) {
            // 1523 幂等/工单状态不符 / 422 零组件脏数据防线 / 1520 剩余量复查 / 1522 库存不足（事务整体回滚）
            return $this->fail($e->getCode() ?: 1523, $e->getMessage());
        } catch (InventoryException $e) {
            // 余额引擎兜底拒绝（理论上被预校验拦截，防御路径）
            return $this->fail(1522, '库存不足，委外发出被拒绝');
        }

        return $this->ok($result);
    }

    /**
     * 回收：事务内「锁委外单（状态 ∈ [已发出,已回收]，草稿/已关闭 422；累计+本次 ≤ 委外量 1524，已回收单再回收必超收）
     * → 回收品一致性校验（回收商品=委外单 output_product_id 节点输出；为空数据异常或与请求 product_id 不符 →
     * 1529「回收商品与委外工序产出不一致」）→ 锁同单全部工序行（id 升序，含委外工序：DAG 后继就绪判定需读其它前驱状态，
     * 与报工/完工在行级全序上单调同向）→ 锁工单行校验状态 → InventoryService 写 outsourcing_in 流水(+qty，
     * 商品=output_product_id) → 创建回收单（创建即审核）→ 累计 ≥ 委外量 → 委外单已回收 + 工序标记完成 +
     * 推进「直接后继中全部前驱已完成」的待开工节点（并行分支独立推进，与 OperationReportController::store
     * 同口径）」任一步失败整体回滚；
     * 锁序 outsourcing→全部工序(升序)→order 与报工（op 全集→order）/完工（全工序→order）行级单调同向，
     * 消除「末批回收 vs 工序报工」并发 ABBA 死锁环
     */
    public function storeReceipt(Request $request, OutsourcingOrder $outsourcing)
    {
        $data = $this->validatePayloadReceipt($request);
        // 回收量判正走 bccomp（D-3 铁律：禁浮点参与数量比较；正则已保证入参为两位小数十进制）
        if (bccomp((string) $data['quantity'], '0', 2) <= 0) {
            return $this->fail(422, '回收数量必须大于 0');
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
                // 统一引擎写流水+加余额（同事务双写；商品=回收品节点输出）
                $this->inventoryService->apply([[
                    'product_id' => $outputProductId,
                    'warehouse_id' => $data['warehouse_id'],
                    'location_id' => $data['location_id'],
                    'direction' => 1,
                    'quantity' => $data['quantity'],
                    'source_type' => 'outsourcing_in',
                    'source_id' => $locked->id,
                    'source_no' => '',
                    'remark' => '委外回收',
                ]], auth()->id());
                // 创建回收单（创建即审核）：单号 OSR 先占号再补流水单号（先建单号引用唯一）
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
                // 流水单号回补（流水创建时回收单号未定，先以委外单号占位后回补——审计链完整）
                DB::table('inventory_movements')
                    ->where('source_type', 'outsourcing_in')
                    ->where('source_id', $locked->id)
                    ->where('source_no', '')
                    ->update(['source_no' => $receipt->no]);

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
        } catch (ProductionException $e) {
            // 1524 超收 / 422 状态不符（事务整体回滚）
            return $this->fail($e->getCode() ?: 422, $e->getMessage());
        } catch (InventoryException $e) {
            // 余额引擎兜底（入库方向理论不触发，防御路径）
            return $this->fail(422, '回收失败，请重试');
        }

        return $this->ok($result);
    }

    /** 回收记录列表：该委外单全部回收单（按回收时间倒序；预载仓库/库位防 N+1，与 returnList 同构） */
    public function receipts(OutsourcingOrder $outsourcing)
    {
        $rows = $outsourcing->receipts()
            ->with(['warehouse', 'location'])
            ->orderByDesc('received_at')
            ->paginate(max(1, min(100, (int) request('per_page', 10))));

        return $this->ok([
            'items' => $rows->map(fn (OutsourcingReceipt $r) => [
                'id' => $r->id,
                'no' => $r->no,
                'quantity' => $r->quantity,
                'warehouse_id' => $r->warehouse_id,
                'warehouse_name' => $r->warehouse?->name,
                'location_id' => $r->location_id,
                'location_name' => $r->location?->name,
                'received_at' => $r->received_at->toDateTimeString(),
                'operator' => $r->operator,
                'remark' => $r->remark,
            ]),
            'total' => $rows->total(), 'page' => $rows->currentPage(), 'per_page' => $rows->perPage(),
        ]);
    }

    /**
     * 余料退回：事务内「锁委外单（状态 ∈ [已发出,已回收]，草稿/已关闭 422「当前委外单不可退回」）→ 锁同单组件行
     * （单语句获取，锁序 单据头 → 明细）→ 逐行校验组件归属与退回量 ≤ 已发−已退（bcmath，
     * 422「退回数量超过已发未退数量」）→ 按 material_id 升序写 outsourcing_return 流水(+qty，
     * source_no 空串占位后续回补——库存行锁序与发出 approve 同向) → 创建退回单（TYPE_OSRT 取号 ORT、创建即审核；
     * 多行提交仅记首行——偏离记录③，明细以流水逐行留痕）→ 流水单号回补 → returned_qty 回写（bcadd 累计）→
     * 全部组件 returned==issued → 委外单已关闭」任一步失败整体回滚
     */
    public function storeReturn(Request $request, OutsourcingOrder $outsourcing)
    {
        $data = $this->validatePayloadReturn($request);

        try {
            $result = null;
            // 事务第 2 参数为死锁(1213)重试次数（机理同 storeReceipt：ORT 序列行首间隙锁死锁败方
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
                // 按 material_id 升序写流水（余额行锁序与发出 approve 同向，多组件并发退回串行化）
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
                        'source_id' => $locked->id,
                        'source_no' => '',
                        'remark' => '余料退回',
                    ];
                }
                // 全部行校验通过后统一写流水（余额行升序加锁；初建无退货单号，先以空串占位后回补）
                if ($movements !== []) {
                    $this->inventoryService->apply($movements, auth()->id());
                }
                // 创建退回单（创建即审核）：单号 ORT 占号后统一回补流水单号（多行提交仅记首行——偏离记录③）
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
                // 流水单号回补（流水创建时退回单号未定，空串占位后统一回补——审计链完整）
                DB::table('inventory_movements')
                    ->where('source_type', 'outsourcing_return')
                    ->where('source_id', $locked->id)
                    ->where('source_no', '')
                    ->update(['source_no' => $return->no]);

                // returned_qty 回写 = 已退累计（bcmath 累加，防覆盖历史退回）
                foreach ($lines as $l) {
                    $l['item']->returned_qty = bcadd((string) $l['item']->returned_qty, $l['quantity'], 2);
                    $l['item']->save();
                }
                // 全部组件已退满（returned==issued，bcmath 权威）→ 委外单自动关闭（余料退回完成）
                $allReturned = $locked->items()->get()
                    ->every(fn ($i) => bccomp((string) $i->returned_qty, (string) $i->issued_qty, 2) === 0);
                if ($allReturned) {
                    $locked->status = OutsourcingOrder::STATUS_CLOSED;
                    $locked->save();
                }
                $result = ['no' => $return->no];
            }, 2);
        } catch (ProductionException $e) {
            // 422 状态不符/超退/组件归属不符（事务整体回滚）
            return $this->fail($e->getCode() ?: 422, $e->getMessage());
        } catch (InventoryException $e) {
            // 余额引擎兜底（入库方向理论不触发，防御路径）
            return $this->fail(422, '退回失败，请重试');
        }

        return $this->ok($result);
    }

    /** 退回记录列表：该委外单全部退回单（按退回时间倒序；预载物料/仓库/库位防 N+1） */
    public function returnList(OutsourcingOrder $outsourcing)
    {
        $rows = $outsourcing->returns()
            ->with(['material', 'warehouse', 'location'])
            ->orderByDesc('returned_at')
            ->paginate(max(1, min(100, (int) request('per_page', 10))));

        return $this->ok([
            'items' => $rows->map(fn (OutsourcingReturn $r) => [
                'id' => $r->id,
                'no' => $r->no,
                'item_id' => $r->item_id,
                'material_id' => $r->material_id,
                'material_name' => $r->material?->name,
                'quantity' => $r->quantity,
                'warehouse_id' => $r->warehouse_id,
                'warehouse_name' => $r->warehouse?->name,
                'location_id' => $r->location_id,
                'location_name' => $r->location?->name,
                'returned_at' => $r->returned_at->toDateTimeString(),
                'operator' => $r->operator,
                'remark' => $r->remark,
            ]),
            'total' => $rows->total(), 'page' => $rows->currentPage(), 'per_page' => $rows->perPage(),
        ]);
    }

    // 已回收累计（Σ 回收单数量；SQL SUM 聚合——回收单创建即审核 status 恒 1，SUM 与逐行 bcadd 语义等价，
    // 跨库 SUM 返回形态不一（MySQL 字符串 / SQLite 数值）统一 bcmath 归一；无回收单 SUM 为空 → '0.00'，
    // 与 index 的 withSum（0）口径一致（P1-3；修复轮：show 曾返回 '0' 与 index '0.00' 不一致）
    private function receivedQty(int $outsourcingId): string
    {
        $total = OutsourcingReceipt::where('outsourcing_id', $outsourcingId)
            ->selectRaw('SUM(quantity) as total')
            ->value('total');

        return $total === null ? '0.00' : bcadd((string) $total, '0', 2);
    }

    // 委外单载荷格式校验（422 仅格式层）；业务码在方法内检查；items 组件行归一化字符串供 bcmath 校验
    private function validatePayload(Request $request): array
    {
        $data = $request->validate([
            'order_id' => 'required|integer|exists:production_orders,id',
            'operation_id' => 'required|integer|exists:work_order_operations,id',
            'supplier_id' => 'nullable|integer|exists:suppliers,id',
            'warehouse_id' => 'nullable|integer|exists:warehouses,id',
            'location_id' => 'nullable|integer|exists:locations,id',
            'quantity' => 'required|numeric|regex:/^-?\d+(\.\d{1,2})?$/',
            'remark' => 'nullable|string|max:200',
            // 发料组件必填（载荷重构后草稿必带组件）；数量限两位小数，正则按字符串形态校验拦 1e2 科学计数
            'items' => 'required|array|min:1',
            'items.*.material_id' => 'required|integer|exists:products,id',
            'items.*.required_qty' => 'required|numeric|regex:/^\d+(\.\d{1,2})?$/',
            'items.*.unit_id' => 'required|integer|exists:units,id',
        ]);

        // 组件行类型归一：应发数量统一字符串（bcmath 权威比较），物料/单位回整型
        $data['items'] = array_map(
            fn (array $item) => [
                'material_id' => (int) $item['material_id'],
                'required_qty' => (string) $item['required_qty'],
                'unit_id' => (int) $item['unit_id'],
            ],
            (array) $data['items'],
        );

        // 组件查重：同物料只允许一行（422 格式层；validateItems 同口径兜底）——
        // 此处统一拦截，避免重复物料直落撞唯一键 uniq_outsourcing_order_items 抛 500
        $seen = [];
        foreach ($data['items'] as $item) {
            if (isset($seen[$item['material_id']])) {
                throw ValidationException::withMessages(['items' => '发料组件重复']);
            }
            $seen[$item['material_id']] = true;
        }

        return $data;
    }

    // 回收载荷格式校验（422 仅格式层）；product_id 可空=冒烟校验（提供时须等于节点输出，业务码 1529 在事务内）
    private function validatePayloadReceipt(Request $request): array
    {
        return $request->validate([
            'quantity' => 'required|numeric|regex:/^-?\d+(\.\d{1,2})?$/',
            'product_id' => 'nullable|integer|exists:products,id',
            'warehouse_id' => 'required|integer|exists:warehouses,id',
            'location_id' => 'required|integer|exists:locations,id',
            'remark' => 'nullable|string|max:200',
        ]);
    }

    // 退回载荷格式校验（422 仅格式层）；业务码在事务内检查；items 组件行归一化字符串供 bcmath 校验
    private function validatePayloadReturn(Request $request): array
    {
        $data = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.item_id' => 'required|integer|exists:outsourcing_order_items,id',
            'items.*.quantity' => 'required|numeric|regex:/^\d+(\.\d{1,2})?$/',
            'warehouse_id' => 'required|integer|exists:warehouses,id',
            'location_id' => 'required|integer|exists:locations,id',
            'remark' => 'nullable|string|max:200',
        ]);

        // 组件行类型归一：退回数量统一字符串（bcmath 权威比较），组件行回整型
        $data['items'] = array_map(
            fn (array $item) => [
                'item_id' => (int) $item['item_id'],
                'quantity' => (string) $item['quantity'],
            ],
            (array) $data['items'],
        );

        // 组件行查重：同 item_id 只允许一行（422 格式层）——防「事务内同一内存模型逐行校验」下
        // 两行提交各自 ≤ 剩余可退、累计退回却超已发的库存账实不一致（修复轮 1）
        $seen = [];
        foreach ($data['items'] as $item) {
            if (isset($seen[$item['item_id']])) {
                throw ValidationException::withMessages(['items' => '退回组件重复']);
            }
            $seen[$item['item_id']] = true;
        }

        return $data;
    }
}
