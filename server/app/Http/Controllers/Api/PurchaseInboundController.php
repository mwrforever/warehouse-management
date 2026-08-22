<?php

// 采购入库单控制器：草稿 CRUD + from-order 预填 + 审核（核心：事务内 InventoryService 加库存 + 订单联动）

namespace App\Http\Controllers\Api;

use App\Exceptions\PurchaseException;
use App\Http\Controllers\Controller;
use App\Models\DocumentSequence;
use App\Models\PurchaseInbound;
use App\Models\PurchaseInboundItem;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Services\CostPriceService;
use App\Services\DocumentSequenceService;
use App\Services\InventoryService;
use App\Services\PurchaseOrderService;
use App\Support\ApiResponse;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PurchaseInboundController extends Controller
{
    use ApiResponse;

    public function __construct(
        private InventoryService $inventoryService,
        private PurchaseOrderService $orderService,
        private DocumentSequenceService $sequenceService,
        private CostPriceService $costPriceService,
    ) {}

    /** 分页列表：单号/仓库/状态/日期范围 筛选；含供应商/仓库/库位名与来源订单单号 */
    public function index(Request $request)
    {
        $query = PurchaseInbound::query()
            ->join('suppliers', 'suppliers.id', '=', 'purchase_inbounds.supplier_id')
            ->join('warehouses', 'warehouses.id', '=', 'purchase_inbounds.warehouse_id')
            ->join('locations', 'locations.id', '=', 'purchase_inbounds.location_id')
            ->leftJoin('purchase_orders', 'purchase_orders.id', '=', 'purchase_inbounds.order_id')
            ->select(
                'purchase_inbounds.*',
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

    /** 新建草稿：仓库/库位必填 1307；关联订单行剩余量校验 1308；重复商品 1312 */
    public function store(Request $request)
    {
        $data = $this->validatePayload($request);
        // 业务码校验（仓库/库位必填走业务码而非 422，与 spec 1307 一致）
        if (! $request->filled('warehouse_id') || ! $request->filled('location_id')) {
            return $this->fail(1307, '仓库与库位不能为空');
        }
        // 从订单生成：数量 0 = 本次不收货（剔除不落库）；手动新增：仍要求 > 0（防空数量单据）；
        // items 键可整体缺失（validatePayload 未 required），?? 兜底防 undefined key
        $fromOrder = ! empty($data['order_id']);
        $items = $fromOrder
            ? array_values(array_filter($data['items'] ?? [], fn ($i) => bccomp((string) $i['quantity'], '0', 2) !== 0))
            : ($data['items'] ?? []);
        if (empty($items)) {
            return $this->fail(1301, '请至少添加一条明细');
        }
        foreach ($items as $item) {
            // 从订单允许 0（已在过滤剔除），仅拦负数；手动新增 0 仍拒绝（bcmath 精确比较）
            $cmp = bccomp((string) $item['quantity'], '0', 2);
            if ($cmp < 0 || (! $fromOrder && $cmp === 0)) {
                return $this->fail(1302, $fromOrder ? '数量不能小于 0' : '数量必须大于 0');
            }
            if (bccomp((string) $item['price'], '0', 2) < 0) {
                return $this->fail(1311, '价格不能为负数');
            }
        }
        if ($this->hasDuplicateItem($items)) {
            return $this->fail(1312, '明细存在重复商品');
        }
        // 明细带订单行引用但未携带 order_id → 1308（防绕过订单状态联动）
        if (empty($data['order_id']) && $this->hasOrderItemRef($items)) {
            return $this->fail(1308, '入库明细与订单行不一致');
        }
        // 关联订单行校验：行归属/订单可入库/供应商一致/不超剩余量（草稿期即拦截，审核期再锁行复核）
        if ($orderId = $data['order_id'] ?? null) {
            $check = $this->validateOrderItems($orderId, (int) $data['supplier_id'], $items);
            if ($check !== null) {
                return $this->fail(1308, $check);
            }
        }

        $inbound = DB::transaction(function () use ($data, $items) {
            $inbound = $this->sequenceService->nextNoByConfig(
                DocumentSequence::TYPE_PI,
                fn (string $no) => PurchaseInbound::create([
                    'no' => $no,
                    'supplier_id' => $data['supplier_id'],
                    'warehouse_id' => $data['warehouse_id'],
                    'location_id' => $data['location_id'],
                    'order_id' => $data['order_id'] ?? null,
                    'status' => PurchaseInbound::STATUS_DRAFT,
                    'total_amount' => $this->orderService->calculateTotal($items),
                    'remark' => $data['remark'] ?? null,
                ]),
                fn (string $prefix, string $dateKey) => ($no = PurchaseInbound::where('no', 'like', $prefix.date('Ymd').'%')
                    ->orderByDesc('no')->value('no')) ? DocumentSequenceService::seqFromNo($no, $prefix, $dateKey) : 0,
            );
            $inbound->items()->createMany(array_map(fn ($i) => [
                'product_id' => $i['product_id'],
                'quantity' => $i['quantity'],
                'price' => $i['price'],
                'amount' => $this->orderService->lineAmount((string) $i['quantity'], (string) $i['price']),
                'order_item_id' => $i['order_item_id'] ?? null,
            ], $items));

            return $inbound;
        });

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

    /** 更新草稿：仅草稿（1309）；items 全量替换；订单行校验同 store；事务内锁行复查防并发 */
    public function update(Request $request, PurchaseInbound $inbound)
    {
        try {
            if ($inbound->status !== PurchaseInbound::STATUS_DRAFT) {
                return $this->fail(1309, '已审核单据不可修改');
            }
            $data = $this->validatePayload($request);
            if (! $request->filled('warehouse_id') || ! $request->filled('location_id')) {
                return $this->fail(1307, '仓库与库位不能为空');
            }
            // 从订单生成：数量 0 = 本次不收货（剔除不落库）；手动新增：仍要求 > 0（防空数量单据）；
            // items 键可整体缺失（validatePayload 未 required），?? 兜底防 undefined key
            $fromOrder = ! empty($data['order_id']);
            $items = $fromOrder
                ? array_values(array_filter($data['items'] ?? [], fn ($i) => bccomp((string) $i['quantity'], '0', 2) !== 0))
                : ($data['items'] ?? []);
            if (empty($items)) {
                return $this->fail(1301, '请至少添加一条明细');
            }
            foreach ($items as $item) {
                // 从订单允许 0（已在过滤剔除），仅拦负数；手动新增 0 仍拒绝（bcmath 精确比较）
                $cmp = bccomp((string) $item['quantity'], '0', 2);
                if ($cmp < 0 || (! $fromOrder && $cmp === 0)) {
                    return $this->fail(1302, $fromOrder ? '数量不能小于 0' : '数量必须大于 0');
                }
                if (bccomp((string) $item['price'], '0', 2) < 0) {
                    return $this->fail(1311, '价格不能为负数');
                }
            }
            if ($this->hasDuplicateItem($items)) {
                return $this->fail(1312, '明细存在重复商品');
            }
            // 明细带订单行引用但未携带 order_id → 1308（防绕过订单状态联动）
            if (empty($data['order_id']) && $this->hasOrderItemRef($items)) {
                return $this->fail(1308, '入库明细与订单行不一致');
            }
            if ($orderId = $data['order_id'] ?? null) {
                $check = $this->validateOrderItems($orderId, (int) $data['supplier_id'], $items);
                if ($check !== null) {
                    return $this->fail(1308, $check);
                }
            }

            DB::transaction(function () use ($inbound, $data, $items) {
                // 锁入库单行复查状态：与审核并发时防止改到正在审核的单（幂等 1309）
                $locked = PurchaseInbound::whereKey($inbound->id)->lockForUpdate()->firstOrFail();
                if ($locked->status !== PurchaseInbound::STATUS_DRAFT) {
                    throw new PurchaseException('已审核单据不可修改', 1309);
                }
                $locked->update([
                    'supplier_id' => $data['supplier_id'],
                    'warehouse_id' => $data['warehouse_id'],
                    'location_id' => $data['location_id'],
                    'order_id' => $data['order_id'] ?? null,
                    'total_amount' => $this->orderService->calculateTotal($items),
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
                ], $items));
            });
        } catch (PurchaseException $e) {
            // 1309 已审核（锁行复查与并发审核幂等拦截）
            return $this->fail($e->getCode() ?: 1309, $e->getMessage());
        }

        return $this->ok();
    }

    /** 删除草稿：仅草稿（1309）；事务内锁行复查防并发 */
    public function destroy(PurchaseInbound $inbound)
    {
        try {
            if ($inbound->status !== PurchaseInbound::STATUS_DRAFT) {
                return $this->fail(1309, '已审核单据不可删除');
            }
            DB::transaction(function () use ($inbound) {
                // 锁入库单行复查状态：与审核并发时防止删到正在审核的单（幂等 1309）
                $locked = PurchaseInbound::whereKey($inbound->id)->lockForUpdate()->firstOrFail();
                if ($locked->status !== PurchaseInbound::STATUS_DRAFT) {
                    throw new PurchaseException('已审核单据不可删除', 1309);
                }
                $locked->delete();
            });
        } catch (PurchaseException $e) {
            // 1309 已审核（锁行复查与并发审核幂等拦截）
            return $this->fail($e->getCode() ?: 1309, $e->getMessage());
        }

        return $this->ok();
    }

    /**
     * 审核（核心）：事务内「锁单幂等 → 批量预锁订单行验剩余量 → 批量预锁订单头验状态 → InventoryService 加库存
     * → 回写 received_qty → syncStatus 重算订单状态」任一步失败整体回滚
     */
    public function approve(PurchaseInbound $inbound)
    {
        try {
            $result = null;
            DB::transaction(function () use ($inbound, &$result) {
                // 锁入库单行：同一单据重复审核在此判重（幂等 1310）
                $locked = PurchaseInbound::whereKey($inbound->id)->lockForUpdate()->firstOrFail();
                if ($locked->status === PurchaseInbound::STATUS_APPROVED) {
                    throw new PurchaseException('该入库单已审核', 1310);
                }
                $movements = [];
                $received = []; // [order_item_id => 本次累计入库量] 待回写
                // 循环前批量预锁（P1-2，宪法 §4.2.4 建议）：订单行按 id 一次锁定、订单头按去重
                // order_id 批量锁一次（同订单多明细不再重复锁同一行）；锁序保持「订单行→订单头」
                // 全局方向，批量按索引序获取还消除了「两单明细顺序相反」时的交叉锁窗口（无 ABBA 新方向）
                $orderItemIds = $locked->items->pluck('order_item_id')->filter()->values()->all();
                /** @var Collection<int, PurchaseOrderItem> $oiMap 已锁定的订单行（回写复用，免二次查询） */
                $oiMap = $orderItemIds === []
                    ? collect()
                    : PurchaseOrderItem::query()->whereIn('id', $orderItemIds)->lockForUpdate()->get()->keyBy('id');
                $orderIds = $oiMap->pluck('order_id')->unique()->values()->all();
                /** @var Collection<int, PurchaseOrder> $orderMap 已锁定的订单头（状态复核复用） */
                $orderMap = $orderIds === []
                    ? collect()
                    : PurchaseOrder::query()->whereIn('id', $orderIds)->lockForUpdate()->get()->keyBy('id');
                /** @var PurchaseInboundItem $item */
                foreach ($locked->items as $item) {
                    if ($item->order_item_id) {
                        // 复核订单行：防并发超收（并发审核同一行已在上方批量锁定串行化）
                        $oi = $oiMap->get($item->order_item_id);
                        // 防御：订单行已被删除或商品不一致（数据完整性，归 1308 语义族）
                        if (! $oi || $oi->product_id !== $item->product_id) {
                            throw new PurchaseException('入库明细与订单行不一致', 1308);
                        }
                        // 复核订单头状态：关闭/已完成/草稿均不可入库（1308）；
                        // 头缺失（FK cascade 下不可达的防御路径）与 firstOrFail 同 404 语义
                        $order = $orderMap->get($oi->order_id);
                        if (! $order) {
                            throw (new ModelNotFoundException)->setModel(PurchaseOrder::class, $oi->order_id);
                        }
                        if (! in_array($order->status, [PurchaseOrder::STATUS_APPROVED, PurchaseOrder::STATUS_PARTIAL], true)) {
                            throw new PurchaseException('该订单当前不可入库', 1308);
                        }
                        // 剩余量 = 订购数 - 已入库累计；本次入库量超剩余 → 1308 整体回滚（防超收）
                        $remaining = bcsub((string) $oi->quantity, (string) $oi->received_qty, 2);
                        if (bccomp((string) $item->quantity, $remaining, 2) > 0) {
                            throw new PurchaseException('入库数量超过订单剩余数量', 1308);
                        }
                        $received[$oi->id] = bcadd((string) ($received[$oi->id] ?? '0'), (string) $item->quantity, 2);
                    }
                    $movements[] = [
                        'product_id' => $item->product_id,
                        'warehouse_id' => $locked->warehouse_id,
                        'location_id' => $locked->location_id,
                        'direction' => 1,
                        'quantity' => $item->quantity,
                        'source_type' => 'purchase_inbound',
                        'source_id' => $locked->id,
                        'source_no' => $locked->no,
                        'remark' => '采购入库',
                    ];
                }
                // 统一引擎写流水+余额（同事务双写，恒等式由 InventoryService 保证）
                $this->inventoryService->apply($movements, auth()->id());
                // 回写订单行累计入库量（bcmath 累加）并重算订单状态（全部入完 → 已完成）：
                // 复用第一循环已锁定的行对象——行已被本事务锁定且期间无人可改，
                // 二次查询纯属多余（N 条订单行省 N 次查询；与领退料审核回写同构）
                foreach ($received as $oiId => $addQty) {
                    $oi = $oiMap[$oiId];
                    $oi->received_qty = bcadd((string) $oi->received_qty, $addQty, 2);
                    $oi->save();
                }
                $this->orderService->syncStatus($locked->order_id);
                // 置已审核 + 审核人/时间
                $locked->status = PurchaseInbound::STATUS_APPROVED;
                $locked->operator = auth()->user()->name ?? '';
                $locked->inbound_at = now();
                $locked->save();
                $result = ['no' => $locked->no];
            });
            // 审核成功（事务已提交）失效成本价缓存：审核是价格集合的唯一变化点（见 CostPriceService 失效契约）；
            // 回滚路径抛异常跳过此处，缓存最多早清（下次访问重建），无脏读风险
            try {
                $this->costPriceService->flush();
            } catch (\Throwable $e) {
                // 缓存层失败不回滚已提交的审核（单据已生效）：最坏缓存脏一次、下次访问重建；
                // 若向调用方抛错，前端会误判审核失败而重试，撞 1310 幂等造成困惑，故吞异常仅记 warn
                Log::warning('采购入库审核后成本价缓存失效失败，已忽略：'.$e->getMessage());
            }
        } catch (PurchaseException $e) {
            // 1310 幂等 / 1308 超量或订单状态不符（余额不足等防御性场景同样归 1308 语义族）
            return $this->fail($e->getCode() ?: 1308, $e->getMessage());
        }

        return $this->ok($result);
    }

    // 载荷格式校验（422 仅格式层）；仓库/库位/数量/价格业务码在方法内检查
    private function validatePayload(Request $request): array
    {
        return $request->validate([
            'supplier_id' => 'required|integer|exists:suppliers,id',
            'warehouse_id' => 'nullable|integer|exists:warehouses,id',
            'location_id' => 'nullable|integer|exists:locations,id',
            'order_id' => 'nullable|integer|exists:purchase_orders,id',
            'remark' => 'nullable|string|max:200',
            // 注意：items 不加 required——空数组 [] 走 1301 业务码（422 仅拦缺失字段与类型错误）
            'items' => 'array',
            'items.*.product_id' => 'required|integer|exists:products,id',
            // 数量/单价限两位小数（正则按字符串形态校验，拦截 1e2 科学计数法避免 bcmul ValueError；允许负号形态，负值由业务层拦截 1302/1311）
            'items.*.quantity' => 'required|numeric|regex:/^-?\d+(\.\d{1,2})?$/',
            'items.*.price' => 'required|numeric|regex:/^-?\d+(\.\d{1,2})?$/',
            'items.*.order_item_id' => 'nullable|integer|exists:purchase_order_items,id',
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

    // 订单行校验：行必须属于该订单、商品一致、供应商一致、订单可入库、不超剩余量（返回错误文案或 null）
    private function validateOrderItems(int $orderId, int $supplierId, array $items): ?string
    {
        $order = PurchaseOrder::with('items')->find($orderId);
        if (! $order || ! in_array($order->status, [PurchaseOrder::STATUS_APPROVED, PurchaseOrder::STATUS_PARTIAL], true)) {
            return '该订单当前不可入库';
        }
        // 入库单供应商必须与来源订单一致（防跨供应商挂单，前端禁用选择器仅 UI 层）
        if ((int) $order->supplier_id !== $supplierId) {
            return '供应商与来源订单不一致';
        }
        foreach ($items as $item) {
            if (! isset($item['order_item_id'])) {
                continue; // 独立行不受订单约束
            }
            $oi = $order->items->firstWhere('id', $item['order_item_id']);
            if (! $oi || $oi->product_id !== $item['product_id']) {
                return '入库明细与订单行不一致';
            }
            // 剩余量 = 订购数 - 已入库累计；超量拒绝（草稿期即拦截）
            $remaining = bcsub((string) $oi->quantity, (string) $oi->received_qty, 2);
            if (bccomp((string) $item['quantity'], $remaining, 2) > 0) {
                return '入库数量超过订单剩余数量';
            }
        }

        return null;
    }
}
