<?php

// 销售订单控制器：草稿 CRUD + 审核 + 关闭 + 可出库订单列表 + 订单出库记录（写操作全部下沉 SalesOrderService）

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\SaveSalesOrderRequest;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use App\Models\SalesOutbound;
use App\Services\SalesOrderService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class SalesOrderController extends Controller
{
    use ApiResponse;

    public function __construct(private SalesOrderService $orderService) {}

    /** 分页列表：单号/客户/状态/日期范围 筛选；含客户名与状态中文标签 */
    public function index(Request $request)
    {
        $query = SalesOrder::query()
            ->join('customers', 'customers.id', '=', 'sales_orders.customer_id')
            ->leftJoin('users', 'users.id', '=', 'sales_orders.created_by')
            ->select(
                // 显式列出列表所需主表列（与下方 map 闭包字段一一对应），避免 select 通配拉取未列字段
                'sales_orders.id',
                'sales_orders.no',
                'sales_orders.customer_id',
                'sales_orders.order_date',
                'sales_orders.expected_date',
                'sales_orders.total_amount',
                'sales_orders.status',
                'sales_orders.created_by',
                'sales_orders.approved_at',
                'customers.name as customer_name',
                'users.name as created_by_name',
            )
            ->orderByDesc('sales_orders.id');

        if ($keyword = $request->input('keyword')) {
            $query->where('sales_orders.no', 'like', "%{$keyword}%");
        }
        if ($request->filled('customer_id')) {
            $query->where('sales_orders.customer_id', $request->input('customer_id'));
        }
        if ($request->filled('status')) {
            $query->where('sales_orders.status', (int) $request->input('status'));
        }
        // 日期范围闭区间筛选（下单日期）
        if ($request->filled('date_from')) {
            $query->whereDate('sales_orders.order_date', '>=', $request->input('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('sales_orders.order_date', '<=', $request->input('date_to'));
        }

        $rows = $query->paginate(max(1, min(100, (int) $request->input('per_page', 10))));

        return $this->ok([
            // join 别名列经 getAttribute 读取（PHPStan 静态分析可识别）
            'items' => $rows->map(fn (SalesOrder $o) => [
                'id' => $o->id,
                'no' => $o->no,
                'customer_id' => $o->customer_id,
                'customer_name' => $o->getAttribute('customer_name'),
                'order_date' => $o->order_date,
                'expected_date' => $o->expected_date,
                'total_amount' => $o->total_amount,
                'status' => (int) $o->status,
                'status_label' => SalesOrder::STATUS_LABELS[$o->status] ?? '未知',
                'created_by' => $o->created_by,
                'created_by_name' => $o->getAttribute('created_by_name'),
                'approved_at' => $o->approved_at?->toDateTimeString(),
            ]),
            'total' => $rows->total(), 'page' => $rows->currentPage(), 'per_page' => $rows->perPage(),
        ]);
    }

    /** 可出库订单列表：已审核/部分出库、未关闭、有剩余量（「从订单生成」下拉数据源）；keyword 单号模糊 + 分页（B-106） */
    public function available(Request $request)
    {
        $query = SalesOrder::query()
            ->join('customers', 'customers.id', '=', 'sales_orders.customer_id')
            ->select(
                // 显式列出下拉所需列（与下方 map 闭包字段一一对应），避免 select 通配拉取未列字段
                'sales_orders.id',
                'sales_orders.no',
                'sales_orders.order_date',
                'customers.name as customer_name',
            )
            ->whereIn('sales_orders.status', [SalesOrder::STATUS_APPROVED, SalesOrder::STATUS_PARTIAL])
            // 有剩余量判定下推 SQL（exists 子查询，B-106）：存在「已出库累计 < 订购数」明细行的订单才可出库；
            // 两列均为 decimal(12,2)，数据库按精确十进制比较，与原 bccomp 集合过滤语义一致，
            // 且免全量装载订单头+全部明细行再集合过滤（订单量增长后旧实现响应线性膨胀、下拉不可选）
            ->whereHas('items', fn ($q) => $q->whereColumn('shipped_qty', '<', 'quantity'));

        if ($keyword = $request->input('keyword')) {
            // 单号关键字模糊搜索（% 在绑定值内参数绑定，禁止拼接）
            $query->where('sales_orders.no', 'like', "%{$keyword}%");
        }

        // 下拉数据源默认 50 条/页、上限钳制 100（与其他列表接口同口径，防大 per_page 绕过）
        $rows = $query->orderByDesc('sales_orders.id')
            ->paginate(max(1, min(100, (int) $request->input('per_page', 50))));

        return $this->ok([
            // join 别名列经 getAttribute 读取（PHPStan 静态分析可识别）
            'items' => $rows->map(fn (SalesOrder $o) => [
                'id' => $o->id,
                'no' => $o->no,
                'customer_name' => $o->getAttribute('customer_name'),
                'order_date' => $o->order_date,
            ]),
            'total' => $rows->total(), 'page' => $rows->currentPage(), 'per_page' => $rows->perPage(),
        ]);
    }

    /** 新建草稿：单号持久序列；金额分单位整数（half-up 到整数分）；明细非空 1401 / 数量≤0 422 / 原料禁售 422 / 负价 1411 / 重复商品 1412（写流程在 Service） */
    public function store(SaveSalesOrderRequest $request)
    {
        $order = $this->orderService->create($request->validated());

        return $this->ok(['no' => $order->no]);
    }

    /** 详情：头信息 + 明细（商品名/订购数/已出库/单价/金额） */
    public function show(SalesOrder $order)
    {
        return $this->ok([
            'id' => $order->id,
            'no' => $order->no,
            'customer_id' => $order->customer_id,
            'customer_name' => $order->customer?->name,
            'order_date' => $order->order_date,
            'expected_date' => $order->expected_date,
            'status' => (int) $order->status,
            'status_label' => SalesOrder::STATUS_LABELS[$order->status] ?? '未知',
            'total_amount' => $order->total_amount,
            'remark' => $order->remark,
            'created_by' => $order->created_by,
            'approved_at' => $order->approved_at?->toDateTimeString(),
            'closed_at' => $order->closed_at?->toDateTimeString(),
            'items' => $order->items()->with('product')->get()->map(fn (SalesOrderItem $i) => [
                'id' => $i->id,
                'product_id' => $i->product_id,
                'product_name' => $i->product?->name,
                'product_code' => $i->product?->code,
                'quantity' => $i->quantity,
                'shipped_qty' => $i->shipped_qty,
                'price' => $i->price,
                'amount' => $i->amount,
            ]),
        ]);
    }

    /** 更新草稿：仅草稿（1402）；items 全量替换；金额重算；事务内锁行复查防并发（写流程在 Service） */
    public function update(SaveSalesOrderRequest $request, SalesOrder $order)
    {
        $this->orderService->update($order, $request->validated());

        return $this->ok();
    }

    /** 删除草稿：仅草稿（1403）；事务内锁行复查防并发（写流程在 Service） */
    public function destroy(SalesOrder $order)
    {
        $this->orderService->delete($order);

        return $this->ok();
    }

    /** 审核：仅草稿（幂等 1404）；置已审核 + approved_at；锁内复查抛错转业务码（写流程在 Service） */
    public function approve(SalesOrder $order)
    {
        $this->orderService->approve($order);

        return $this->ok(['no' => $order->no]);
    }

    /** 关闭：仅已审核/部分出库（1405）；置关闭 + closed_at；关闭后不可再生成出库单（写流程在 Service） */
    public function close(SalesOrder $order)
    {
        $this->orderService->close($order);

        return $this->ok();
    }

    /** 该订单的出库单列表（详情页「出库记录」tab） */
    public function outbounds(SalesOrder $order)
    {
        $rows = SalesOutbound::where('order_id', $order->id)
            ->with('customer')->orderByDesc('id')->get();

        return $this->ok([
            'items' => $rows->map(fn (SalesOutbound $o) => [
                'id' => $o->id,
                'no' => $o->no,
                'status' => (int) $o->status,
                'status_label' => SalesOutbound::STATUS_LABELS[$o->status] ?? '未知',
                'outbound_at' => $o->outbound_at?->toDateTimeString(),
                'operator' => $o->operator,
                'total_amount' => $o->total_amount,
            ]),
        ]);
    }
}
