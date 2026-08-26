<?php

// 生产工单控制器：列表/详情/物料需求 读取 + 草稿 CRUD/下达/开工/完工/关闭 薄壳（写流程全部下沉 ProductionOrderService）

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Production\SaveProductionOrderRequest;
use App\Models\ProductionOrder;
use App\Models\ProductionOrderMaterial;
use App\Models\WorkOrderOperation;
use App\Models\WorkOrderOperationEdge;
use App\Services\ProductionOrderService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class ProductionOrderController extends Controller
{
    use ApiResponse;

    public function __construct(private ProductionOrderService $orderService) {}

    /** 分页列表：单号/成品/状态/日期范围 筛选；含成品名与状态中文标签与完成率 */
    public function index(Request $request)
    {
        $query = ProductionOrder::query()
            ->join('products', 'products.id', '=', 'production_orders.product_id')
            // 显式列出列表所需主表列（与下方 map 闭包字段一一对应），避免 select 通配拉取未列字段
            ->select(
                'production_orders.id',
                'production_orders.no',
                'production_orders.product_id',
                'production_orders.quantity',
                'production_orders.completed_qty',
                'production_orders.plan_date',
                'production_orders.status',
                'production_orders.created_by',
                'production_orders.released_at',
                'production_orders.completed_at',
                'products.name as product_name',
                'products.code as product_code',
            )
            ->orderByDesc('production_orders.id');

        if ($keyword = $request->input('keyword')) {
            $query->where('production_orders.no', 'like', "%{$keyword}%");
        }
        if ($request->filled('product_id')) {
            $query->where('production_orders.product_id', $request->input('product_id'));
        }
        if ($request->filled('status')) {
            $query->where('production_orders.status', (int) $request->input('status'));
        }
        // 日期范围闭区间筛选（计划日期）
        if ($request->filled('date_from')) {
            $query->whereDate('production_orders.plan_date', '>=', $request->input('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('production_orders.plan_date', '<=', $request->input('date_to'));
        }

        $rows = $query->paginate(max(1, min(100, (int) $request->input('per_page', 10))));

        return $this->ok([
            // join 别名列经 getAttribute 读取（PHPStan 静态分析可识别）
            'items' => $rows->map(fn (ProductionOrder $o) => [
                'id' => $o->id,
                'no' => $o->no,
                'product_id' => $o->product_id,
                'product_name' => $o->getAttribute('product_name'),
                'product_code' => $o->getAttribute('product_code'),
                'quantity' => $o->quantity,
                'completed_qty' => $o->completed_qty,
                // 完成率（%）供列表进度条展示
                'progress' => $this->orderService->progress((string) $o->completed_qty, (string) $o->quantity),
                'plan_date' => $o->plan_date,
                'status' => (int) $o->status,
                'status_label' => ProductionOrder::STATUS_LABELS[$o->status] ?? '未知',
                'created_by' => $o->created_by,
                'released_at' => $o->released_at?->toDateTimeString(),
                'completed_at' => $o->completed_at?->toDateTimeString(),
            ]),
            'total' => $rows->total(), 'page' => $rows->currentPage(), 'per_page' => $rows->perPage(),
        ], '', JSON_PRESERVE_ZERO_FRACTION);
    }

    /**
     * 新建草稿：格式校验 422 → ProductionOrderService::create（锁成品行/BOM 展开/单号持久序列/快照，业务码 1501/1502）
     * 响应含 routing_warning 仅无启用工艺路线回退旧逻辑时携带（前端提示）
     */
    public function store(SaveProductionOrderRequest $request)
    {
        // 回退告警文案经引用带出（无路线时响应携带，供前端提示）
        $routingWarning = null;
        $order = $this->orderService->create($request->validated(), $routingWarning);

        // 响应含 id：前端新建成功后直接以 id 拉详情打开 BOM 展开弹窗，不依赖列表回查（防刷新失败误报创建失败）
        // routing_warning 仅回退旧逻辑时携带（array_filter 剔除 null 键）
        return $this->ok(array_filter([
            'no' => $order->no,
            'id' => $order->id,
            'routing_warning' => $routingWarning,
        ], fn ($v) => $v !== null));
    }

    /** 详情：抬头 + 物料需求（需求/已领/剩余）+ 工序列表（状态与累计合格/不良/工时）+ DAG 网络图（Task 8 前端画布） */
    public function show(ProductionOrder $order)
    {
        // 工序一次取出：operations 与 graph.nodes 复用同一集合（§4.2.2 防重复查询）
        $ops = $order->operations()->with(['process', 'outputProduct'])->orderBy('seq')->get();
        // 边一次取出并预加载两端工序：operations.predecessors（按 to_operation_id 分组）与 graph.edges 复用（防 map 内懒加载 N+1）
        $edges = $order->edges()->with(['fromOperation.process', 'toOperation'])->get();
        $predsByTo = $edges->groupBy('to_operation_id');

        $operationItems = $ops->map(fn (WorkOrderOperation $op) => [
            'id' => $op->id,
            'seq' => $op->seq,
            'process_id' => $op->process_id,
            'process_name' => $op->process?->name,
            'process_code' => $op->process?->code,
            'node_no' => $op->node_no,
            'output_product_id' => $op->output_product_id,
            'output_product_name' => $op->outputProduct?->name,
            'is_outsourced' => (int) $op->is_outsourced,
            'status' => (int) $op->status,
            'status_label' => $this->orderService->operationStatusLabel((int) $op->status),
            'qualified_qty' => $op->qualified_qty,
            'defective_qty' => $op->defective_qty,
            'hours' => $op->hours,
            // 直接前驱（工序网络展示/前端推进判断；旧工单无边 → 空集合）
            'predecessors' => ($predsByTo->get($op->id) ?? collect())->map(fn ($e) => [
                'id' => $e->fromOperation->id,
                'node_no' => $e->fromOperation->node_no,
                'process_name' => $e->fromOperation?->process?->name,
            ])->values(),
        ]);

        return $this->ok([
            'id' => $order->id,
            'no' => $order->no,
            'product_id' => $order->product_id,
            'product_name' => $order->product?->name,
            'product_code' => $order->product?->code,
            'quantity' => $order->quantity,
            'plan_date' => $order->plan_date,
            'bom_id' => $order->bom_id,
            'bom_code' => $order->bom?->code,
            // 路线快照锚定：null=旧逻辑展开（前端隐藏工序网络 tab）
            'routing_id' => $order->routing_id,
            'status' => (int) $order->status,
            'status_label' => ProductionOrder::STATUS_LABELS[$order->status] ?? '未知',
            'completed_qty' => $order->completed_qty,
            'progress' => $this->orderService->progress((string) $order->completed_qty, (string) $order->quantity),
            'created_by' => $order->created_by,
            'released_at' => $order->released_at?->toDateTimeString(),
            'completed_at' => $order->completed_at?->toDateTimeString(),
            'closed_at' => $order->closed_at?->toDateTimeString(),
            'remark' => $order->remark,
            // 物料需求快照：剩余 = 需求 - 已领（bcmath 精确）
            'materials' => $order->materials()->with('material')->orderBy('id')->get()
                ->map(fn (ProductionOrderMaterial $m) => [
                    'material_id' => $m->material_id,
                    'material_name' => $m->material?->name,
                    'material_code' => $m->material?->code,
                    'required_qty' => $m->required_qty,
                    'issued_qty' => $m->issued_qty,
                    'remaining_qty' => bcsub((string) $m->required_qty, (string) $m->issued_qty, 2),
                ]),
            'operations' => $operationItems,
            // 工序网络图（Vue Flow 渲染）：仅 DAG 工单返回，旧工单 null（前端隐藏该 tab）
            'graph' => $order->routing_id ? [
                'nodes' => $ops->map(fn (WorkOrderOperation $op) => [
                    'id' => $op->id,
                    'node_no' => $op->node_no,
                    'process_name' => $op->process?->name,
                    'status' => (int) $op->status,
                    'status_label' => $this->orderService->operationStatusLabel((int) $op->status),
                    'is_outsourced' => (int) $op->is_outsourced,
                    'qualified_qty' => $op->qualified_qty,
                    'defective_qty' => $op->defective_qty,
                    'hours' => $op->hours,
                ]),
                'edges' => $edges->map(fn (WorkOrderOperationEdge $e) => [
                    'from_operation_id' => $e->from_operation_id,
                    'to_operation_id' => $e->to_operation_id,
                    // 边模型 fromOperation/toOperation 未标泛型（返回 Model），节点号经 getAttribute 读
                    // （同 index join 别列传读惯用法；phpstan 无法静态识别 Model 动态属性）
                    'from_node_no' => $e->fromOperation?->getAttribute('node_no'),
                    'to_node_no' => $e->toOperation?->getAttribute('node_no'),
                ]),
            ] : null,
        ], '', JSON_PRESERVE_ZERO_FRACTION);
    }

    /** 更新草稿：格式校验 422 → Service（锁行复查 1503/委外引用 1504/BOM 展开重建；业务码 1501/1502） */
    public function update(SaveProductionOrderRequest $request, ProductionOrder $order)
    {
        $this->orderService->update($order, $request->validated());

        return $this->ok();
    }

    /** 删除草稿：Service 内锁行复查状态 + 生产单据引用检查（1504）+ 审计日志 */
    public function destroy(ProductionOrder $order)
    {
        $this->orderService->delete($order);

        return $this->ok();
    }

    /** 物料需求列表：BOM 展开快照（需求/已领/剩余），领料单「从工单生成」预填数据源 */
    public function materials(ProductionOrder $order)
    {
        return $this->ok([
            'items' => $order->materials()->with('material')->orderBy('id')->get()
                ->map(fn (ProductionOrderMaterial $m) => [
                    'material_id' => $m->material_id,
                    'material_name' => $m->material?->name,
                    'material_code' => $m->material?->code,
                    'required_qty' => $m->required_qty,
                    'issued_qty' => $m->issued_qty,
                    'remaining_qty' => bcsub((string) $m->required_qty, (string) $m->issued_qty, 2),
                ]),
        ]);
    }

    /** 下达（草稿→已下达）：Service 内锁行复查（1505）+ 缺料 warnings（只读不阻断）；返回 warnings 供前端提示 */
    public function release(ProductionOrder $order)
    {
        return $this->ok($this->orderService->release($order));
    }

    /** 开工（已下达→生产中）：Service 内锁定起点工序后锁工单行（1506），DAG 并行起点同时开工 */
    public function start(ProductionOrder $order)
    {
        $this->orderService->start($order);

        return $this->ok();
    }

    /** 完工（生产中→已完成）：Service 内锁全工序后锁工单行，双前置校验（1507 全工序完成 / 1508 有成品入库） */
    public function complete(ProductionOrder $order)
    {
        $this->orderService->complete($order);

        return $this->ok();
    }

    /** 关闭（已完成→关闭）：Service 内锁行复查（1505）；关闭为工单生命周期终态不可逆 */
    public function close(ProductionOrder $order)
    {
        $this->orderService->close($order);

        return $this->ok();
    }
}
