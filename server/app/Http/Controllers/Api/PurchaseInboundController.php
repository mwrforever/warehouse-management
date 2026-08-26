<?php

// 采购入库单控制器：草稿 CRUD + from-order 预填 + 审核（写操作全部下沉 PurchaseInboundService，库存唯一写入口为 InventoryService）

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Purchase\SaveInboundRequest;
use App\Models\PurchaseInbound;
use App\Models\PurchaseInboundItem;
use App\Models\PurchaseOrder;
use App\Services\PurchaseInboundService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class PurchaseInboundController extends Controller
{
    use ApiResponse;

    public function __construct(private PurchaseInboundService $inboundService) {}

    /** 分页列表：单号/仓库/状态/日期范围 筛选；含供应商/仓库/库位名与来源订单单号 */
    public function index(Request $request)
    {
        $query = PurchaseInbound::query()
            ->join('suppliers', 'suppliers.id', '=', 'purchase_inbounds.supplier_id')
            ->join('warehouses', 'warehouses.id', '=', 'purchase_inbounds.warehouse_id')
            ->join('locations', 'locations.id', '=', 'purchase_inbounds.location_id')
            ->leftJoin('purchase_orders', 'purchase_orders.id', '=', 'purchase_inbounds.order_id')
            ->select(
                // 显式列出列表所需主表列（与下方 map 闭包字段一一对应），避免 select 通配拉取未列字段
                'purchase_inbounds.id',
                'purchase_inbounds.no',
                'purchase_inbounds.supplier_id',
                'purchase_inbounds.warehouse_id',
                'purchase_inbounds.location_id',
                'purchase_inbounds.order_id',
                'purchase_inbounds.status',
                'purchase_inbounds.total_amount',
                'purchase_inbounds.inbound_at',
                'purchase_inbounds.operator',
                'purchase_inbounds.created_at',
                'suppliers.name as supplier_name',
                'warehouses.name as warehouse_name',
                'locations.name as location_name',
                'purchase_orders.no as order_no',
            )
            ->orderByDesc('purchase_inbounds.id');

        if ($keyword = $request->input('keyword')) {
            $query->where('purchase_inbounds.no', 'like', "%{$keyword}%");
        }
        if ($request->filled('warehouse_id')) {
            $query->where('purchase_inbounds.warehouse_id', $request->input('warehouse_id'));
        }
        if ($request->filled('status')) {
            $query->where('purchase_inbounds.status', (int) $request->input('status'));
        }
        // 日期范围闭区间筛选（入库时间）
        if ($request->filled('date_from')) {
            $query->whereDate('purchase_inbounds.inbound_at', '>=', $request->input('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('purchase_inbounds.inbound_at', '<=', $request->input('date_to'));
        }

        $rows = $query->paginate(max(1, min(100, (int) $request->input('per_page', 10))));

        return $this->ok([
            // join 别名列经 getAttribute 读取（PHPStan 静态分析可识别）
            'items' => $rows->map(fn ($i) => [
                'id' => $i->id,
                'no' => $i->no,
                'supplier_id' => $i->supplier_id,
                'supplier_name' => $i->getAttribute('supplier_name'),
                'warehouse_id' => $i->warehouse_id,
                'warehouse_name' => $i->getAttribute('warehouse_name'),
                'location_id' => $i->location_id,
                'location_name' => $i->getAttribute('location_name'),
                'order_id' => $i->order_id,
                'order_no' => $i->getAttribute('order_no'),
                'status' => (int) $i->status,
                'status_label' => PurchaseInbound::STATUS_LABELS[$i->status] ?? '未知',
                'total_amount' => $i->total_amount,
                'inbound_at' => $i->inbound_at?->toDateTimeString(),
                'operator' => $i->operator,
                'created_at' => $i->created_at?->toDateTimeString(),
            ]),
            'total' => $rows->total(), 'page' => $rows->currentPage(), 'per_page' => $rows->perPage(),
        ]);
    }

    /** 「从订单生成」预填：订单头 + 未入库完的明细行（剩余量 = 订购数 - 已入库） */
    public function fromOrder(int $orderId)
    {
        $order = PurchaseOrder::with('supplier')->with('items.product')->find($orderId);
        if (! $order || ! in_array($order->status, [PurchaseOrder::STATUS_APPROVED, PurchaseOrder::STATUS_PARTIAL], true)) {
            return $this->fail(1308, '该订单当前不可入库');
        }
        // 仅返回未入完的行（剩余量 > 0）
        $items = $order->items->filter(fn ($i) => bccomp((string) $i->received_qty, (string) $i->quantity, 2) < 0)
            ->values()
            ->map(fn ($i) => [
                'product_id' => $i->product_id,
                'product_name' => $i->product?->name,
                'product_code' => $i->product?->code,
                'quantity' => $i->quantity,
                // 剩余量 = 订购数 - 已入库累计（bcmath 精确）
                'remaining_qty' => bcsub((string) $i->quantity, (string) $i->received_qty, 2),
                'price' => $i->price,
                'order_item_id' => $i->id,
            ]);

        return $this->ok([
            'order_id' => $order->id,
            'order_no' => $order->no,
            'supplier_id' => $order->supplier_id,
            'supplier_name' => $order->supplier?->name,
            'order_date' => $order->order_date,
            'items' => $items,
        ]);
    }

    /** 新建草稿：仓库/库位必填 1307；关联订单行剩余量校验 1308；重复商品 1312（写流程在 Service） */
    public function store(SaveInboundRequest $request)
    {
        $inbound = $this->inboundService->create($request->validated());

        return $this->ok(['no' => $inbound->no]);
    }

    /** 详情：头信息 + 明细（商品名/数量/单价/金额/订单行引用） */
    public function show(PurchaseInbound $inbound)
    {
        return $this->ok([
            'id' => $inbound->id,
            'no' => $inbound->no,
            'supplier_id' => $inbound->supplier_id,
            'supplier_name' => $inbound->supplier?->name,
            'warehouse_id' => $inbound->warehouse_id,
            'warehouse_name' => $inbound->warehouse?->name,
            'location_id' => $inbound->location_id,
            'location_name' => $inbound->location?->name,
            'order_id' => $inbound->order_id,
            'order_no' => $inbound->order?->no,
            'status' => (int) $inbound->status,
            'status_label' => PurchaseInbound::STATUS_LABELS[$inbound->status] ?? '未知',
            'total_amount' => $inbound->total_amount,
            'inbound_at' => $inbound->inbound_at?->toDateTimeString(),
            'operator' => $inbound->operator,
            'remark' => $inbound->remark,
            'items' => $inbound->items()->with('product')->get()->map(fn (PurchaseInboundItem $i) => [
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

    /** 更新草稿：仅草稿（1309）；items 全量替换；订单行校验同 store；事务内锁行复查防并发（写流程在 Service） */
    public function update(SaveInboundRequest $request, PurchaseInbound $inbound)
    {
        $this->inboundService->update($inbound, $request->validated());

        return $this->ok();
    }

    /** 删除草稿：仅草稿（1309）；事务内锁行复查防并发（写流程在 Service） */
    public function destroy(PurchaseInbound $inbound)
    {
        $this->inboundService->delete($inbound);

        return $this->ok();
    }

    /**
     * 审核（核心）：事务内「锁单幂等 → 批量预锁订单行验剩余量 → 批量预锁订单头验状态 → InventoryService 加库存
     * → 回写 received_qty → syncStatus 重算订单状态」任一步失败整体回滚（写流程在 Service）
     */
    public function approve(PurchaseInbound $inbound)
    {
        $result = $this->inboundService->approve($inbound);

        return $this->ok($result);
    }
}
