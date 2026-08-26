<?php

// 销售出库单控制器：草稿 CRUD + from-order 预填 + 今日汇总 + 审核（写操作全部下沉 SalesOutboundService）

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\SaveSalesOutboundRequest;
use App\Models\SalesOrder;
use App\Models\SalesOutbound;
use App\Models\SalesOutboundItem;
use App\Services\SalesOutboundService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class SalesOutboundController extends Controller
{
    use ApiResponse;

    public function __construct(private SalesOutboundService $outboundService) {}

    /** 分页列表：单号/仓库/状态/日期范围 筛选；含客户/仓库/库位名与来源订单单号 */
    public function index(Request $request)
    {
        $query = SalesOutbound::query()
            ->join('customers', 'customers.id', '=', 'sales_outbounds.customer_id')
            ->join('warehouses', 'warehouses.id', '=', 'sales_outbounds.warehouse_id')
            ->join('locations', 'locations.id', '=', 'sales_outbounds.location_id')
            ->leftJoin('sales_orders', 'sales_orders.id', '=', 'sales_outbounds.order_id')
            ->select(
                // 显式列出列表所需主表列（与下方 map 闭包字段一一对应），避免 select 通配拉取未列字段
                'sales_outbounds.id',
                'sales_outbounds.no',
                'sales_outbounds.customer_id',
                'sales_outbounds.warehouse_id',
                'sales_outbounds.location_id',
                'sales_outbounds.order_id',
                'sales_outbounds.status',
                'sales_outbounds.total_amount',
                'sales_outbounds.outbound_at',
                'sales_outbounds.operator',
                'sales_outbounds.created_at',
                'customers.name as customer_name',
                'warehouses.name as warehouse_name',
                'locations.name as location_name',
                'sales_orders.no as order_no',
            )
            ->orderByDesc('sales_outbounds.id');

        if ($keyword = $request->input('keyword')) {
            $query->where('sales_outbounds.no', 'like', "%{$keyword}%");
        }
        if ($request->filled('warehouse_id')) {
            $query->where('sales_outbounds.warehouse_id', $request->input('warehouse_id'));
        }
        if ($request->filled('status')) {
            $query->where('sales_outbounds.status', (int) $request->input('status'));
        }
        // 日期范围闭区间筛选（出库时间）
        if ($request->filled('date_from')) {
            $query->whereDate('sales_outbounds.outbound_at', '>=', $request->input('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('sales_outbounds.outbound_at', '<=', $request->input('date_to'));
        }

        $rows = $query->paginate(max(1, min(100, (int) $request->input('per_page', 10))));

        return $this->ok([
            // join 别名列经 getAttribute 读取（PHPStan 静态分析可识别）
            'items' => $rows->map(fn ($o) => [
                'id' => $o->id,
                'no' => $o->no,
                'customer_id' => $o->customer_id,
                'customer_name' => $o->getAttribute('customer_name'),
                'warehouse_id' => $o->warehouse_id,
                'warehouse_name' => $o->getAttribute('warehouse_name'),
                'location_id' => $o->location_id,
                'location_name' => $o->getAttribute('location_name'),
                'order_id' => $o->order_id,
                'order_no' => $o->getAttribute('order_no'),
                'status' => (int) $o->status,
                'status_label' => SalesOutbound::STATUS_LABELS[$o->status] ?? '未知',
                'total_amount' => $o->total_amount,
                'outbound_at' => $o->outbound_at?->toDateTimeString(),
                'operator' => $o->operator,
                'created_at' => $o->created_at?->toDateTimeString(),
            ]),
            'total' => $rows->total(), 'page' => $rows->currentPage(), 'per_page' => $rows->perPage(),
        ]);
    }

    /** 「从订单生成」预填：订单头 + 未出库完的明细行（剩余量 = 订购数 - 已出库） */
    public function fromOrder(int $orderId)
    {
        $order = SalesOrder::with('customer')->with('items.product')->find($orderId);
        if (! $order || ! in_array($order->status, [SalesOrder::STATUS_APPROVED, SalesOrder::STATUS_PARTIAL], true)) {
            return $this->fail(1407, '该订单当前不可出库');
        }
        // 仅返回未出完的行（剩余量 > 0）
        $items = $order->items->filter(fn ($i) => bccomp((string) $i->shipped_qty, (string) $i->quantity, 2) < 0)
            ->values()
            ->map(fn ($i) => [
                'product_id' => $i->product_id,
                'product_name' => $i->product?->name,
                'product_code' => $i->product?->code,
                'quantity' => $i->quantity,
                // 剩余量 = 订购数 - 已出库累计（bcmath 精确）
                'remaining_qty' => bcsub((string) $i->quantity, (string) $i->shipped_qty, 2),
                'price' => $i->price,
                'order_item_id' => $i->id,
            ]);

        return $this->ok([
            'order_id' => $order->id,
            'order_no' => $order->no,
            'customer_id' => $order->customer_id,
            'customer_name' => $order->customer?->name,
            'order_date' => $order->order_date,
            'items' => $items,
        ]);
    }

    /** 当日已审核出库量按商品汇总（列表页顶部「出库数量汇总行」数据源，轻量统计） */
    public function todaySummary()
    {
        $rows = SalesOutboundItem::query()
            ->join('sales_outbounds', 'sales_outbounds.id', '=', 'sales_outbound_items.outbound_id')
            ->join('products', 'products.id', '=', 'sales_outbound_items.product_id')
            ->where('sales_outbounds.status', SalesOutbound::STATUS_APPROVED)
            ->whereDate('sales_outbounds.outbound_at', today())
            ->groupBy('sales_outbound_items.product_id', 'products.code', 'products.name')
            ->selectRaw(
                'sales_outbound_items.product_id as product_id, products.code as product_code, '
                .'products.name as product_name, SUM(sales_outbound_items.quantity) as quantity'
            )
            ->orderByDesc('quantity')
            ->get();

        return $this->ok([
            // join 别名列经 getAttribute 读取（PHPStan 静态分析可识别）
            'items' => $rows->map(fn ($r) => [
                'product_id' => $r->product_id,
                'product_code' => $r->getAttribute('product_code'),
                'product_name' => $r->getAttribute('product_name'),
                'quantity' => $r->quantity,
            ]),
        ]);
    }

    /** 新建草稿：仓库/库位必填 1406；关联订单行剩余量校验 1407；客户一致性 1407；重复商品 1412（写流程在 Service） */
    public function store(SaveSalesOutboundRequest $request)
    {
        $outbound = $this->outboundService->create($request->validated());

        return $this->ok(['no' => $outbound->no]);
    }

    /** 详情：头信息 + 明细（商品名/数量/单价/金额/订单行引用） */
    public function show(SalesOutbound $outbound)
    {
        return $this->ok([
            'id' => $outbound->id,
            'no' => $outbound->no,
            'customer_id' => $outbound->customer_id,
            'customer_name' => $outbound->customer?->name,
            'warehouse_id' => $outbound->warehouse_id,
            'warehouse_name' => $outbound->warehouse?->name,
            'location_id' => $outbound->location_id,
            'location_name' => $outbound->location?->name,
            'order_id' => $outbound->order_id,
            'order_no' => $outbound->order?->no,
            'status' => (int) $outbound->status,
            'status_label' => SalesOutbound::STATUS_LABELS[$outbound->status] ?? '未知',
            'total_amount' => $outbound->total_amount,
            'outbound_at' => $outbound->outbound_at?->toDateTimeString(),
            'operator' => $outbound->operator,
            'remark' => $outbound->remark,
            'items' => $outbound->items()->with('product')->get()->map(fn (SalesOutboundItem $i) => [
                'id' => $i->id,
                'product_id' => $i->product_id,
                'product_name' => $i->product?->name,
                'product_code' => $i->product?->code,
                'quantity' => $i->quantity,
                'price' => $i->price,
                'amount' => $i->amount,
                'order_item_id' => $i->order_item_id,
            ]),
        ]);
    }

    /** 更新草稿：仅草稿（1408）；items 全量替换；订单行校验同 store；事务内锁行复查防并发（写流程在 Service） */
    public function update(SaveSalesOutboundRequest $request, SalesOutbound $outbound)
    {
        $this->outboundService->update($outbound, $request->validated());

        return $this->ok();
    }

    /** 删除草稿：仅草稿（1408）；事务内锁行复查防并发（写流程在 Service） */
    public function destroy(SalesOutbound $outbound)
    {
        $this->outboundService->delete($outbound);

        return $this->ok();
    }

    /**
     * 审核：事务内「锁单幂等 1410 → 批量预锁订单行验剩余量 1407 → 批量预锁订单头验状态
     * → 批量预锁余额行校验余额充足 1409 → InventoryService 扣库存 → 回写 shipped_qty
     * → syncStatus 重算订单状态」任一步失败整体回滚（写流程在 Service）
     */
    public function approve(SalesOutbound $outbound)
    {
        $result = $this->outboundService->approve($outbound);

        return $this->ok($result);
    }
}
