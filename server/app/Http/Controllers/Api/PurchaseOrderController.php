<?php

// 采购订单控制器：草稿 CRUD + 审核 + 关闭 + 可入库订单列表 + 订单入库记录（写操作全部下沉 PurchaseOrderService）

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Purchase\SaveOrderRequest;
use App\Models\PurchaseInbound;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Services\PurchaseOrderService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class PurchaseOrderController extends Controller
{
    use ApiResponse;

    public function __construct(private PurchaseOrderService $orderService) {}

    /** 分页列表：单号/供应商/状态/日期范围 筛选；含供应商名与状态中文标签 */
    public function index(Request $request)
    {
        $query = PurchaseOrder::query()
            ->join('suppliers', 'suppliers.id', '=', 'purchase_orders.supplier_id')
            ->leftJoin('users', 'users.id', '=', 'purchase_orders.created_by')
            ->select(
                // 显式列出列表所需主表列（与下方 map 闭包字段一一对应），避免 select 通配拉取未列字段
                'purchase_orders.id',
                'purchase_orders.no',
                'purchase_orders.supplier_id',
                'purchase_orders.order_date',
                'purchase_orders.expected_date',
                'purchase_orders.total_amount',
                'purchase_orders.status',
                'purchase_orders.created_by',
                'purchase_orders.approved_at',
                'suppliers.name as supplier_name',
                'users.name as created_by_name',
            )
            ->orderByDesc('purchase_orders.id');

        if ($keyword = $request->input('keyword')) {
            $query->where('purchase_orders.no', 'like', "%{$keyword}%");
        }
        if ($request->filled('supplier_id')) {
            $query->where('purchase_orders.supplier_id', $request->input('supplier_id'));
        }
        if ($request->filled('status')) {
            $query->where('purchase_orders.status', (int) $request->input('status'));
        }
        // 日期范围闭区间筛选（下单日期）
        if ($request->filled('date_from')) {
            $query->whereDate('purchase_orders.order_date', '>=', $request->input('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('purchase_orders.order_date', '<=', $request->input('date_to'));
        }

        $rows = $query->paginate(max(1, min(100, (int) $request->input('per_page', 10))));

        return $this->ok([
            // join 别名列经 getAttribute 读取（PHPStan 静态分析可识别）
            'items' => $rows->map(fn (PurchaseOrder $o) => [
                'id' => $o->id,
                'no' => $o->no,
                'supplier_id' => $o->supplier_id,
                'supplier_name' => $o->getAttribute('supplier_name'),
                'order_date' => $o->order_date,
                'expected_date' => $o->expected_date,
                'total_amount' => $o->total_amount,
                'status' => (int) $o->status,
                'status_label' => PurchaseOrder::STATUS_LABELS[$o->status] ?? '未知',
                'created_by' => $o->created_by,
                'created_by_name' => $o->getAttribute('created_by_name'),
                'approved_at' => $o->approved_at?->toDateTimeString(),
            ]),
            'total' => $rows->total(), 'page' => $rows->currentPage(), 'per_page' => $rows->perPage(),
        ]);
    }

    /** 可入库订单列表：已审核/部分入库、未关闭、有剩余量（「从订单生成」下拉数据源）；keyword 单号模糊 + 分页（B-106） */
    public function available(Request $request)
    {
        $query = PurchaseOrder::query()
            ->join('suppliers', 'suppliers.id', '=', 'purchase_orders.supplier_id')
            ->select(
                // 显式列出下拉所需列（与下方 map 闭包字段一一对应），避免 select 通配拉取未列字段
                'purchase_orders.id',
                'purchase_orders.no',
                'purchase_orders.order_date',
                'suppliers.name as supplier_name',
            )
            ->whereIn('purchase_orders.status', [PurchaseOrder::STATUS_APPROVED, PurchaseOrder::STATUS_PARTIAL])
            // 有剩余量判定下推 SQL（exists 子查询，B-106）：存在「已入库累计 < 订购数」明细行的订单才可入库；
            // 两列均为 decimal(12,2)，数据库按精确十进制比较，与原 bccomp 集合过滤语义一致，
            // 且免全量装载订单头+全部明细行再集合过滤（订单量增长后旧实现响应线性膨胀、下拉不可选）
            ->whereHas('items', fn ($q) => $q->whereColumn('received_qty', '<', 'quantity'));

        if ($keyword = $request->input('keyword')) {
            // 单号关键字模糊搜索（% 在绑定值内参数绑定，禁止拼接）
            $query->where('purchase_orders.no', 'like', "%{$keyword}%");
        }

        // 下拉数据源默认 50 条/页、上限钳制 100（与其他列表接口同口径，防大 per_page 绕过）
        $rows = $query->orderByDesc('purchase_orders.id')
            ->paginate(max(1, min(100, (int) $request->input('per_page', 50))));

        return $this->ok([
            // join 别名列经 getAttribute 读取（PHPStan 静态分析可识别）
            'items' => $rows->map(fn (PurchaseOrder $o) => [
                'id' => $o->id,
                'no' => $o->no,
                'supplier_name' => $o->getAttribute('supplier_name'),
                'order_date' => $o->order_date,
            ]),
            'total' => $rows->total(), 'page' => $rows->currentPage(), 'per_page' => $rows->perPage(),
        ]);
    }

    /** 新建草稿：单号持久序列；金额分单位整数（half-up 到整数分）；明细非空 1301 / 数量>0 1302 / 负价 1311 / 重复商品 1312（写流程在 Service） */
    public function store(SaveOrderRequest $request)
    {
        $order = $this->orderService->create($request->validated());

        return $this->ok(['no' => $order->no]);
    }

    /** 详情：头信息 + 明细（商品名/订购数/已入库/单价/金额） */
    public function show(PurchaseOrder $order)
    {
        return $this->ok([
            'id' => $order->id,
            'no' => $order->no,
            'supplier_id' => $order->supplier_id,
            'supplier_name' => $order->supplier?->name,
            'order_date' => $order->order_date,
            'expected_date' => $order->expected_date,
            'status' => (int) $order->status,
            'status_label' => PurchaseOrder::STATUS_LABELS[$order->status] ?? '未知',
            'total_amount' => $order->total_amount,
            'remark' => $order->remark,
            'created_by' => $order->created_by,
            'approved_at' => $order->approved_at?->toDateTimeString(),
            'closed_at' => $order->closed_at?->toDateTimeString(),
            'items' => $order->items()->with('product')->get()->map(fn (PurchaseOrderItem $i) => [
                'id' => $i->id,
                'product_id' => $i->product_id,
                'product_name' => $i->product?->name,
                'product_code' => $i->product?->code,
                'quantity' => $i->quantity,
                'received_qty' => $i->received_qty,
                'price' => $i->price,
                'amount' => $i->amount,
            ]),
        ]);
    }

    /** 更新草稿：仅草稿（1303）；items 全量替换；金额重算；事务内锁行复查防并发（写流程在 Service） */
    public function update(SaveOrderRequest $request, PurchaseOrder $order)
    {
        $this->orderService->update($order, $request->validated());

        return $this->ok();
    }

    /** 删除草稿：仅草稿（1304）；事务内锁行复查防并发（写流程在 Service） */
    public function destroy(PurchaseOrder $order)
    {
        $this->orderService->delete($order);

        return $this->ok();
    }

    /** 审核：仅草稿（幂等 1305）；置已审核 + approved_at + 创建人；锁内复查抛错转业务码（写流程在 Service） */
    public function approve(PurchaseOrder $order)
    {
        $this->orderService->approve($order);

        return $this->ok(['no' => $order->no]);
    }

    /** 关闭：仅已审核/部分入库（1306）；置关闭 + closed_at；关闭后不可再生成入库单（写流程在 Service） */
    public function close(PurchaseOrder $order)
    {
        $this->orderService->close($order);

        return $this->ok();
    }

    /** 该订单的入库单列表（详情页「入库记录」tab） */
    public function inbounds(PurchaseOrder $order)
    {
        $rows = PurchaseInbound::where('order_id', $order->id)
            ->with('supplier')->orderByDesc('id')->get();

        return $this->ok([
            'items' => $rows->map(fn (PurchaseInbound $i) => [
                'id' => $i->id,
                'no' => $i->no,
                'status' => (int) $i->status,
                'status_label' => PurchaseInbound::STATUS_LABELS[$i->status] ?? '未知',
                'inbound_at' => $i->inbound_at?->toDateTimeString(),
                'operator' => $i->operator,
                'total_amount' => $i->total_amount,
            ]),
        ]);
    }
}
