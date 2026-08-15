<?php

// 销售出库单控制器：草稿 CRUD + from-order 预填 + 审核（核心：事务内锁余额行防超卖 + InventoryService 扣库存 + 订单联动）

namespace App\Http\Controllers\Api;

use App\Exceptions\InventoryException;
use App\Exceptions\SalesException;
use App\Http\Controllers\Controller;
use App\Models\DocumentSequence;
use App\Models\InventoryBalance;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use App\Models\SalesOutbound;
use App\Models\SalesOutboundItem;
use App\Services\DocumentSequenceService;
use App\Services\InventoryService;
use App\Services\SalesOrderService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SalesOutboundController extends Controller
{
    use ApiResponse;

    public function __construct(
        private InventoryService $inventoryService,
        private SalesOrderService $orderService,
        private DocumentSequenceService $sequenceService,
    ) {}

    /** 分页列表：单号/仓库/状态/日期范围 筛选；含客户/仓库/库位名与来源订单单号 */
    public function index(Request $request)
    {
        $query = SalesOutbound::query()
            ->join('customers', 'customers.id', '=', 'sales_outbounds.customer_id')
            ->join('warehouses', 'warehouses.id', '=', 'sales_outbounds.warehouse_id')
            ->join('locations', 'locations.id', '=', 'sales_outbounds.location_id')
            ->leftJoin('sales_orders', 'sales_orders.id', '=', 'sales_outbounds.order_id')
            ->select(
                'sales_outbounds.*',
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

    /** 新建草稿：仓库/库位必填 1406；关联订单行剩余量校验 1407；客户一致性 1407；重复商品 1412 */
    public function store(Request $request)
    {
        $data = $this->validatePayload($request);
        // 业务码校验（仓库/库位必填走业务码而非 422，与 spec 1406 一致）
        if (! $request->filled('warehouse_id') || ! $request->filled('location_id')) {
            return $this->fail(1406, '仓库与库位不能为空');
        }
        if ($fail = $this->validateBusinessItems($data['items'])) {
            return $fail;
        }
        // 明细带订单行引用但未携带 order_id → 1407（防绕过订单状态联动）
        if (empty($data['order_id']) && $this->hasOrderItemRef($data['items'])) {
            return $this->fail(1407, '出库明细与订单行不一致');
        }
        // 关联订单行校验：行归属/订单可出库/客户一致/不超剩余量（草稿期即拦截，审核期再锁行复核）
        if ($orderId = $data['order_id'] ?? null) {
            $check = $this->validateOrderItems($orderId, (int) $data['customer_id'], $data['items']);
            if ($check !== null) {
                return $this->fail(1407, $check);
            }
        }

        $outbound = DB::transaction(function () use ($data) {
            $outbound = $this->sequenceService->nextNo(
                DocumentSequence::TYPE_SOUT,
                'SOUT',
                fn (string $no) => SalesOutbound::create([
                    'no' => $no,
                    'customer_id' => $data['customer_id'],
                    'warehouse_id' => $data['warehouse_id'],
                    'location_id' => $data['location_id'],
                    'order_id' => $data['order_id'] ?? null,
                    'status' => SalesOutbound::STATUS_DRAFT,
                    'total_amount' => $this->orderService->calculateTotal($data['items']),
                    'remark' => $data['remark'] ?? null,
                ]),
                fn () => (int) (SalesOutbound::where('no', 'like', 'SOUT'.date('Ymd').'-%')
                    ->get('no')->map(fn ($o) => (int) substr((string) $o->no, -3))->max() ?? 0),
            );
            $outbound->items()->createMany(array_map(fn ($i) => [
                'product_id' => $i['product_id'],
                'quantity' => $i['quantity'],
                'price' => $i['price'],
                'amount' => $this->orderService->lineAmount((string) $i['quantity'], (string) $i['price']),
                'order_item_id' => $i['order_item_id'] ?? null,
            ], $data['items']));

            return $outbound;
        });

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

    /** 更新草稿：仅草稿（1408）；items 全量替换；订单行校验同 store；事务内锁行复查防并发 */
    public function update(Request $request, SalesOutbound $outbound)
    {
        try {
            if ($outbound->status !== SalesOutbound::STATUS_DRAFT) {
                return $this->fail(1408, '已审核单据不可修改');
            }
            $data = $this->validatePayload($request);
            if (! $request->filled('warehouse_id') || ! $request->filled('location_id')) {
                return $this->fail(1406, '仓库与库位不能为空');
            }
            if ($fail = $this->validateBusinessItems($data['items'])) {
                return $fail;
            }
            if (empty($data['order_id']) && $this->hasOrderItemRef($data['items'])) {
                return $this->fail(1407, '出库明细与订单行不一致');
            }
            if ($orderId = $data['order_id'] ?? null) {
                $check = $this->validateOrderItems($orderId, (int) $data['customer_id'], $data['items']);
                if ($check !== null) {
                    return $this->fail(1407, $check);
                }
            }

            DB::transaction(function () use ($outbound, $data) {
                // 锁出库单行复查状态：与审核并发时防止改到正在审核的单（幂等 1408）
                $locked = SalesOutbound::whereKey($outbound->id)->lockForUpdate()->firstOrFail();
                if ($locked->status !== SalesOutbound::STATUS_DRAFT) {
                    throw new SalesException('已审核单据不可修改', 1408);
                }
                $locked->update([
                    'customer_id' => $data['customer_id'],
                    'warehouse_id' => $data['warehouse_id'],
                    'location_id' => $data['location_id'],
                    'order_id' => $data['order_id'] ?? null,
                    'total_amount' => $this->orderService->calculateTotal($data['items']),
                    'remark' => $data['remark'] ?? $locked->remark,
                ]);
                // 明细全量替换（草稿单无流水引用，直接重建）
                $locked->items()->delete();
                $locked->items()->createMany(array_map(fn ($i) => [
                    'product_id' => $i['product_id'],
                    'quantity' => $i['quantity'],
                    'price' => $i['price'],
                    'amount' => $this->orderService->lineAmount((string) $i['quantity'], (string) $i['price']),
                    'order_item_id' => $i['order_item_id'] ?? null,
                ], $data['items']));
            });
        } catch (SalesException $e) {
            // 1408 已审核（锁行复查与并发审核幂等拦截）
            return $this->fail($e->getCode() ?: 1408, $e->getMessage());
        }

        return $this->ok();
    }

    /** 删除草稿：仅草稿（1408）；事务内锁行复查防并发 */
    public function destroy(SalesOutbound $outbound)
    {
        try {
            if ($outbound->status !== SalesOutbound::STATUS_DRAFT) {
                return $this->fail(1408, '已审核单据不可删除');
            }
            DB::transaction(function () use ($outbound) {
                // 锁出库单行复查状态：与审核并发时防止删到正在审核的单（幂等 1408）
                $locked = SalesOutbound::whereKey($outbound->id)->lockForUpdate()->firstOrFail();
                if ($locked->status !== SalesOutbound::STATUS_DRAFT) {
                    throw new SalesException('已审核单据不可删除', 1408);
                }
                $locked->delete();
            });
        } catch (SalesException $e) {
            // 1408 已审核（锁行复查与并发审核幂等拦截）
            return $this->fail($e->getCode() ?: 1408, $e->getMessage());
        }

        return $this->ok();
    }

    /**
     * 审核（核心）：事务内「锁单幂等 1410 → 逐行锁订单行验剩余量 1407 → 逐行锁余额行校验余额充足 1409
     * → InventoryService 扣库存 → 回写 shipped_qty → syncStatus 重算订单状态」任一步失败整体回滚
     */
    public function approve(SalesOutbound $outbound)
    {
        try {
            $result = null;
            DB::transaction(function () use ($outbound, &$result) {
                // 锁出库单行：同一单据重复审核在此判重（幂等 1410）
                $locked = SalesOutbound::whereKey($outbound->id)->lockForUpdate()->firstOrFail();
                if ($locked->status === SalesOutbound::STATUS_APPROVED) {
                    throw new SalesException('该出库单已审核', 1410);
                }
                $movements = [];
                $shipped = []; // [order_item_id => 本次累计出库量] 待回写
                /** @var array<int, SalesOrderItem> $oiMap 已锁定的订单行（回写复用，免二次查询） */
                $oiMap = [];
                /** @var SalesOutboundItem $item */
                foreach ($locked->items as $item) {
                    if ($item->order_item_id) {
                        // 锁订单行：防并发超收（两张出库单同时审同一行时串行化）
                        $oi = SalesOrderItem::whereKey($item->order_item_id)->lockForUpdate()->first();
                        // 防御：订单行已被删除或商品不一致（数据完整性，归 1407 语义族）
                        if (! $oi || $oi->product_id !== $item->product_id) {
                            throw new SalesException('出库明细与订单行不一致', 1407);
                        }
                        // 锁订单头复查状态：关闭/已完成/草稿均不可出库（1407）
                        $order = SalesOrder::whereKey($oi->order_id)->lockForUpdate()->firstOrFail();
                        if (! in_array($order->status, [SalesOrder::STATUS_APPROVED, SalesOrder::STATUS_PARTIAL], true)) {
                            throw new SalesException('该订单当前不可出库', 1407);
                        }
                        // 剩余量 = 订购数 - 已出库累计；本次出库量超剩余 → 1407 整体回滚（防超收）
                        $remaining = bcsub((string) $oi->quantity, (string) $oi->shipped_qty, 2);
                        if (bccomp((string) $item->quantity, $remaining, 2) > 0) {
                            throw new SalesException('出库数量超过订单剩余数量', 1407);
                        }
                        $shipped[$oi->id] = bcadd((string) ($shipped[$oi->id] ?? '0'), (string) $item->quantity, 2);
                        // 锁定行留存复用（明细按 商品+订单行 查重，同订单行不会重复出现）
                        $oiMap[$oi->id] = $oi;
                    }
                    // 防超卖：锁余额行校验（并发审核同一商品在此串行化；消息含商品名与当前库存快照）
                    $balance = InventoryBalance::where('product_id', $item->product_id)
                        ->where('warehouse_id', $locked->warehouse_id)
                        ->where('location_id', $locked->location_id)
                        ->lockForUpdate()
                        ->first();
                    $current = $balance ? (string) $balance->quantity : '0';
                    if (bccomp((string) $item->quantity, $current, 2) > 0) {
                        // 库存快照去掉小数尾零展示（14.00 → 14；0.00/缺行 → 0）
                        $qtyText = str_contains($current, '.') ? rtrim(rtrim($current, '0'), '.') : $current;
                        // 商品名取不到时回退商品 id（商品被删的兜底展示）
                        $product = Product::find($item->product_id);
                        $name = $product ? $product->name : ('#'.$item->product_id);
                        throw new SalesException("商品[{$name}]库存不足，当前库存 {$qtyText}", 1409);
                    }
                    $movements[] = [
                        'product_id' => $item->product_id,
                        'warehouse_id' => $locked->warehouse_id,
                        'location_id' => $locked->location_id,
                        'direction' => -1,
                        'quantity' => $item->quantity,
                        'source_type' => 'sales_outbound',
                        'source_id' => $locked->id,
                        'source_no' => $locked->no,
                        'remark' => '销售出库',
                    ];
                }
                // 统一引擎写流水+扣余额（同事务双写，恒等式由 InventoryService 保证；余额行已被本事务锁定，引擎内重复加锁幂等）
                $this->inventoryService->apply($movements, auth()->id());
                // 回写订单行累计出库量（bcmath 累加）并重算订单状态（全部出完 → 已完成）：
                // 复用第一循环已锁定的行对象——行已被本事务锁定且期间无人可改，
                // 二次查询纯属多余（N 条订单行省 N 次查询；与采购入库/领退料审核回写同构）
                foreach ($shipped as $oiId => $addQty) {
                    $oi = $oiMap[$oiId];
                    $oi->shipped_qty = bcadd((string) $oi->shipped_qty, $addQty, 2);
                    $oi->save();
                }
                $this->orderService->syncStatus($locked->order_id);
                // 置已审核 + 审核人/时间
                $locked->status = SalesOutbound::STATUS_APPROVED;
                $locked->operator = auth()->user()->name ?? '';
                $locked->outbound_at = now();
                $locked->save();
                $result = ['no' => $locked->no];
            });
        } catch (SalesException $e) {
            // 1410 幂等 / 1407 超量或订单状态不符 / 1409 库存不足（事务整体回滚）
            return $this->fail($e->getCode() ?: 1407, $e->getMessage());
        } catch (InventoryException $e) {
            // 余额引擎兜底拒绝（理论上被预校验拦截，防御路径；消息不含商品名时用通用文案）
            return $this->fail(1409, '库存不足，出库被拒绝');
        }

        return $this->ok($result);
    }

    // 载荷格式校验（422 仅格式层）；仓库/库位/数量/价格业务码在方法内检查
    private function validatePayload(Request $request): array
    {
        return $request->validate([
            'customer_id' => 'required|integer|exists:customers,id',
            'warehouse_id' => 'nullable|integer|exists:warehouses,id',
            'location_id' => 'nullable|integer|exists:locations,id',
            'order_id' => 'nullable|integer|exists:sales_orders,id',
            'remark' => 'nullable|string|max:200',
            // 注意：items 不加 required——空数组 [] 走 1401 业务码（422 仅拦缺失字段与类型错误）
            'items' => 'array',
            'items.*.product_id' => 'required|integer|exists:products,id',
            // 数量/单价限两位小数（正则按字符串形态校验，拦截 1e2 科学计数法避免 bcmul ValueError；允许负号形态，负值由业务层拦截 422/1411）
            'items.*.quantity' => 'required|numeric|regex:/^-?\d+(\.\d{1,2})?$/',
            'items.*.price' => 'required|numeric|regex:/^-?\d+(\.\d{1,2})?$/',
            'items.*.order_item_id' => 'nullable|integer|exists:sales_order_items,id',
        ]);
    }

    // 明细查重：同商品+同订单行 只允许一行（独立行按商品查重；订单行按 商品+订单行 查重）
    private function hasDuplicateItem(array $items): bool
    {
        $seen = [];
        foreach ($items as $item) {
            $key = ($item['order_item_id'] ?? 0).'-'.$item['product_id'];
            if (isset($seen[$key])) {
                return true;
            }
            $seen[$key] = true;
        }

        return false;
    }

    // 明细是否带订单行引用（order_item_id 非空）
    private function hasOrderItemRef(array $items): bool
    {
        return collect($items)->contains(fn ($i) => ! empty($i['order_item_id'] ?? null));
    }

    // 明细业务校验（store/update 共用）：空明细 1401 / 数量≤0 422 / 原料禁售 422 / 负价 1411 / 重复商品 1412
    // 校验通过返回 null，未通过返回对应业务码/422 的 fail 响应（JSON 信封，由调用方直接 return）
    private function validateBusinessItems(array $items): ?JsonResponse
    {
        if (empty($items)) {
            return $this->fail(1401, '请至少添加一条明细');
        }
        foreach ($items as $item) {
            if ((float) $item['quantity'] <= 0) {
                return $this->fail(422, '数量必须大于 0');
            }
            if ((float) $item['price'] < 0) {
                return $this->fail(1411, '价格不能为负数');
            }
            // 原料禁售（SAL-10）：仅成品/半成品可销售（前端下拉已过滤，后端防御性兜底）
            $product = Product::find($item['product_id']);
            if ($product && $product->type === 'raw_material') {
                return $this->fail(422, '原料商品不可销售');
            }
        }
        if ($this->hasDuplicateItem($items)) {
            return $this->fail(1412, '明细存在重复商品');
        }

        return null;
    }

    // 订单行校验：行必须属于该订单、商品一致、客户一致、订单可出库、不超剩余量（返回错误文案或 null）
    private function validateOrderItems(int $orderId, int $customerId, array $items): ?string
    {
        $order = SalesOrder::with('items')->find($orderId);
        if (! $order || ! in_array($order->status, [SalesOrder::STATUS_APPROVED, SalesOrder::STATUS_PARTIAL], true)) {
            return '该订单当前不可出库';
        }
        // 出库单客户必须与来源订单一致（防跨客户挂单，前端禁用选择器仅 UI 层）
        if ((int) $order->customer_id !== $customerId) {
            return '客户与来源订单不一致';
        }
        foreach ($items as $item) {
            if (! isset($item['order_item_id'])) {
                continue; // 独立行不受订单约束
            }
            $oi = $order->items->firstWhere('id', $item['order_item_id']);
            if (! $oi || $oi->product_id !== $item['product_id']) {
                return '出库明细与订单行不一致';
            }
            // 剩余量 = 订购数 - 已出库累计；超量拒绝（草稿期即拦截）
            $remaining = bcsub((string) $oi->quantity, (string) $oi->shipped_qty, 2);
            if (bccomp((string) $item['quantity'], $remaining, 2) > 0) {
                return '出库数量超过订单剩余数量';
            }
        }

        return null;
    }
}
