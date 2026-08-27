<?php

// 委外加工控制器：from-operation 预填 + 草稿 CRUD/发出/回收/余料退回 薄壳（写流程全部下沉 OutsourcingService）
// 委外商品口径：仅 is_outsourced=1 的工艺路线节点可委外，发料组件与回收品均取节点口径（spec 5 §4 规则定义）

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Production\SaveOutsourcingReceiptRequest;
use App\Http\Requests\Production\SaveOutsourcingRequest;
use App\Http\Requests\Production\SaveOutsourcingReturnRequest;
use App\Models\OutsourcingOrder;
use App\Models\OutsourcingOrderItem;
use App\Models\OutsourcingReceipt;
use App\Models\OutsourcingReturn;
use App\Services\OutsourcingService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class OutsourcingController extends Controller
{
    use ApiResponse;

    public function __construct(private OutsourcingService $outsourcingService) {}

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
    public function store(SaveOutsourcingRequest $request)
    {
        $os = $this->outsourcingService->create($request->validated());

        return $this->ok(['no' => $os->no]);
    }

    /** from-operation 预填：节点输入材料清单×单位用量 + 回收品 + 计划/已委外/剩余量（结构不符 422） */
    public function fromOperation(int $operationId)
    {
        return $this->ok($this->outsourcingService->fromOperation($operationId));
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
            'received_qty' => $this->outsourcingService->receivedQty($outsourcing->id),
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
    public function update(SaveOutsourcingRequest $request, OutsourcingOrder $outsourcing)
    {
        $this->outsourcingService->update($outsourcing, $request->validated());

        return $this->ok();
    }

    /** 删除草稿：仅草稿（1521）；事务内锁行复查防并发 */
    public function destroy(OutsourcingOrder $outsourcing)
    {
        $this->outsourcingService->delete($outsourcing);

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
        return $this->ok($this->outsourcingService->approve($outsourcing));
    }

    /**
     * 回收：事务内「锁委外单（状态 ∈ [已发出,已回收]，草稿/已关闭 422；累计+本次 ≤ 委外量 1524，已回收单再回收必超收）
     * → 回收品一致性校验（回收商品=委外单 output_product_id 节点输出；为空数据异常或与请求 product_id 不符 →
     * 1529「回收商品与委外工序产出不一致」）→ 锁同单全部工序行（id 升序，含委外工序：DAG 后继就绪判定需读其它前驱状态，
     * 与报工/完工在行级全序上单调同向）→ 锁工单行校验状态 → 创建回收单（创建即审核，
     * 先取号建单再写流水 PF-2）→ InventoryService 写 outsourcing_in 流水(+qty，
     * 商品=output_product_id，source=回收单) → 累计 ≥ 委外量 → 委外单已回收 + 工序标记完成 +
     * 推进「直接后继中全部前驱已完成」的待开工节点（并行分支独立推进，与 OperationReportController:store
     * 同口径）」任一步失败整体回滚；
     * 锁序 outsourcing→全部工序(升序)→order 与报工（op 全集→order）/完工（全工序→order）行级单调同向，
     * 消除「末批回收 vs 工序报工」并发 ABBA 死锁环
     */
    public function storeReceipt(SaveOutsourcingReceiptRequest $request, OutsourcingOrder $outsourcing)
    {
        return $this->ok($this->outsourcingService->receive($outsourcing, $request->validated()));
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
     * 422「退回数量超过已发未退数量」）→ 创建退回单（TYPE_OSRT 取号 ORT、创建即审核，先取号建单再写流水 PF-2；
     * 多行提交仅记首行——偏离记录③，明细以流水逐行留痕）→ 按 material_id 升序写 outsourcing_return 流水(+qty，
     * source=退回单——库存行锁序与发出 approve 同向) → returned_qty 回写（bcadd 累加）→
     * 全部组件 returned==issued → 委外单已关闭」任一步失败整体回滚
     */
    public function storeReturn(SaveOutsourcingReturnRequest $request, OutsourcingOrder $outsourcing)
    {
        return $this->ok($this->outsourcingService->returnItems($outsourcing, $request->validated()));
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
}
