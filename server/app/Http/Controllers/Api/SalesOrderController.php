<?php

// 销售订单控制器：草稿 CRUD + 审核 + 关闭 + 可出库订单列表 + 订单出库记录（update/destroy 事务内锁行复查防并发）

namespace App\Http\Controllers\Api;

use App\Exceptions\SalesException;
use App\Http\Controllers\Controller;
use App\Models\DocumentSequence;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use App\Models\SalesOutbound;
use App\Services\DocumentSequenceService;
use App\Services\SalesOrderService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SalesOrderController extends Controller
{
    use ApiResponse;

    public function __construct(
        private SalesOrderService $orderService,
        private DocumentSequenceService $sequenceService,
    ) {}

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

    /** 新建草稿：单号持久序列；金额 bcmath；明细非空 1401 / 数量≤0 422 / 原料禁售 422 / 负价 1411 / 重复商品 1412 */
    public function store(Request $request)
    {
        $data = $this->validatePayload($request);
        // 业务码校验（422 仅格式层，业务冲突走业务码；明细校验逻辑见 validateBusinessItems）
        if ($fail = $this->validateBusinessItems($data['items'])) {
            return $fail;
        }

        // 事务第 2 参数为死锁(1213)重试次数（机理同 BomController::store：序列行首建间隙锁
        // 死锁败方整体回滚后重跑闭包重新取号，幂等安全）
        $order = DB::transaction(function () use ($data) {
            // 单号走持久序列（撞号自动换号；删除不回退；老库 max 衔接）
            $order = $this->sequenceService->nextNoByConfig(
                DocumentSequence::TYPE_SO,
                fn (string $no) => SalesOrder::create([
                    'no' => $no,
                    'customer_id' => $data['customer_id'],
                    'order_date' => $data['order_date'],
                    'expected_date' => $data['expected_date'] ?? null,
                    'status' => SalesOrder::STATUS_DRAFT,
                    'total_amount' => $this->orderService->calculateTotal($data['items']),
                    'remark' => $data['remark'] ?? null,
                    'created_by' => auth()->id(),
                ]),
                fn (string $prefix, string $dateKey) => ($no = SalesOrder::where('no', 'like', $prefix.date('Ymd').'%')
                    ->orderByDesc('no')->value('no')) ? DocumentSequenceService::seqFromNo($no, $prefix, $dateKey) : 0,
            );
            $order->items()->createMany(array_map(fn ($i) => [
                'product_id' => $i['product_id'],
                'quantity' => $i['quantity'],
                'price' => $i['price'],
                'shipped_qty' => 0,
                'amount' => $this->orderService->lineAmount((string) $i['quantity'], (string) $i['price']),
            ], $data['items']));

            return $order;
        }, 2);

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

    /** 更新草稿：仅草稿（1402）；items 全量替换；金额重算；事务内锁行复查防并发 */
    public function update(Request $request, SalesOrder $order)
    {
        try {
            if ($order->status !== SalesOrder::STATUS_DRAFT) {
                return $this->fail(1402, '已审核订单不可修改');
            }
            $data = $this->validatePayload($request);
            // 明细业务校验与 store 共用同一 helper，保证两处校验口径一致（见 validateBusinessItems）
            if ($fail = $this->validateBusinessItems($data['items'])) {
                return $fail;
            }

            DB::transaction(function () use ($order, $data) {
                // 锁订单行复查状态：与审核并发时防止改到正在审核的单（幂等 1402）
                $locked = SalesOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
                if ($locked->status !== SalesOrder::STATUS_DRAFT) {
                    throw new SalesException('已审核订单不可修改', 1402);
                }
                $locked->update([
                    'customer_id' => $data['customer_id'],
                    'order_date' => $data['order_date'],
                    'expected_date' => $data['expected_date'] ?? null,
                    'total_amount' => $this->orderService->calculateTotal($data['items']),
                    'remark' => $data['remark'] ?? $locked->remark,
                ]);
                // 明细全量替换（草稿单无流水引用，直接重建）
                $locked->items()->delete();
                $locked->items()->createMany(array_map(fn ($i) => [
                    'product_id' => $i['product_id'],
                    'quantity' => $i['quantity'],
                    'price' => $i['price'],
                    'shipped_qty' => 0,
                    'amount' => $this->orderService->lineAmount((string) $i['quantity'], (string) $i['price']),
                ], $data['items']));
            });
        } catch (SalesException $e) {
            // 1402 已审核（锁行复查与并发审核幂等拦截）
            return $this->fail($e->getCode() ?: 1402, $e->getMessage());
        }

        return $this->ok();
    }

    /** 删除草稿：仅草稿（1403）；事务内锁行复查防并发 */
    public function destroy(SalesOrder $order)
    {
        try {
            if ($order->status !== SalesOrder::STATUS_DRAFT) {
                return $this->fail(1403, '已审核订单不可删除');
            }
            DB::transaction(function () use ($order) {
                // 锁订单行复查状态：与审核并发时防止删到正在审核的单（幂等 1403）
                $locked = SalesOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
                if ($locked->status !== SalesOrder::STATUS_DRAFT) {
                    throw new SalesException('已审核订单不可删除', 1403);
                }
                $locked->delete();
            });
        } catch (SalesException $e) {
            // 1403 已审核（锁行复查与并发审核幂等拦截）
            return $this->fail($e->getCode() ?: 1403, $e->getMessage());
        }

        return $this->ok();
    }

    /** 审核：仅草稿（幂等 1404）；置已审核 + approved_at；锁内复查抛错转业务码 */
    public function approve(SalesOrder $order)
    {
        try {
            if ($order->status !== SalesOrder::STATUS_DRAFT) {
                return $this->fail(1404, '该订单已审核');
            }
            DB::transaction(function () use ($order) {
                // 锁订单行：同一订单重复审核在此判重（幂等）
                $locked = SalesOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
                if ($locked->status !== SalesOrder::STATUS_DRAFT) {
                    throw new SalesException('该订单已审核', 1404);
                }
                $locked->status = SalesOrder::STATUS_APPROVED;
                $locked->approved_at = now();
                $locked->created_by = $locked->created_by ?? auth()->id();
                $locked->save();
            });
        } catch (SalesException $e) {
            // 1404 已审核（锁行复查与并发审核幂等拦截）
            return $this->fail($e->getCode() ?: 1404, $e->getMessage());
        }

        return $this->ok(['no' => $order->no]);
    }

    /** 关闭：仅已审核/部分出库（1405）；置关闭 + closed_at；关闭后不可再生成出库单；锁内复查抛错转业务码 */
    public function close(SalesOrder $order)
    {
        try {
            if (! in_array($order->status, [SalesOrder::STATUS_APPROVED, SalesOrder::STATUS_PARTIAL], true)) {
                return $this->fail(1405, '当前状态不可关闭');
            }
            DB::transaction(function () use ($order) {
                // 锁订单行复查状态：与出库审核并发时防止关闭正在出库的订单
                $locked = SalesOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
                if (! in_array($locked->status, [SalesOrder::STATUS_APPROVED, SalesOrder::STATUS_PARTIAL], true)) {
                    throw new SalesException('当前状态不可关闭', 1405);
                }
                $locked->status = SalesOrder::STATUS_CLOSED;
                $locked->closed_at = now();
                $locked->save();
            });
        } catch (SalesException $e) {
            // 1405 状态不符（锁行复查与并发拦截）
            return $this->fail($e->getCode() ?: 1405, $e->getMessage());
        }

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

    // 载荷格式校验（422 仅格式层）；业务码在方法内检查
    private function validatePayload(Request $request): array
    {
        return $request->validate([
            'customer_id' => 'required|integer|exists:customers,id',
            'order_date' => 'required|date',
            'expected_date' => 'nullable|date',
            'remark' => 'nullable|string|max:200',
            // 注意：items 不加 required——空数组 [] 走 1401 业务码（422 仅拦缺失字段与类型错误）
            'items' => 'array',
            'items.*.product_id' => 'required|integer|exists:products,id',
            // 数量/单价限两位小数（正则按字符串形态校验，拦截 1e2 科学计数法避免 bcmul ValueError；允许负号形态，负值由业务层拦截 422/1411）
            'items.*.quantity' => 'required|numeric|regex:/^-?\d+(\.\d{1,2})?$/',
            'items.*.price' => 'required|numeric|regex:/^-?\d+(\.\d{1,2})?$/',
        ]);
    }

    // 明细查重：同商品只允许一行
    private function hasDuplicateProduct(array $items): bool
    {
        $seen = [];
        foreach ($items as $item) {
            if (isset($seen[$item['product_id']])) {
                return true;
            }
            $seen[$item['product_id']] = true;
        }

        return false;
    }

    // 明细业务校验（store/update 共用）：空明细 1401 / 数量≤0 422 / 原料禁售 422 / 负价 1411 / 重复商品 1412
    // 校验通过返回 null，未通过返回对应业务码/422 的 fail 响应（JSON 信封，由调用方直接 return）
    private function validateBusinessItems(array $items): ?JsonResponse
    {
        if (empty($items)) {
            return $this->fail(1401, '请至少添加一条明细');
        }
        // 商品批量预取（B-105）：一次 whereIn 拉全明细商品，替代循环内逐行 Product::find 的 N+1 查询
        $products = Product::whereIn('id', collect($items)->pluck('product_id')->unique())->get()->keyBy('id');
        foreach ($items as $item) {
            // 数量/价格正负校验走 bccomp（D-3 铁律：禁浮点参与数量与金额比较；正则已保证入参为两位小数十进制）
            if (bccomp((string) $item['quantity'], '0', 2) <= 0) {
                return $this->fail(422, '数量必须大于 0');
            }
            if (bccomp((string) $item['price'], '0', 2) < 0) {
                return $this->fail(1411, '价格不能为负数');
            }
            // 原料禁售（SAL-10）：仅成品/半成品可销售（前端下拉已过滤，后端防御性兜底）
            $product = $products->get($item['product_id']);
            if ($product && $product->type === 'raw_material') {
                return $this->fail(422, '原料商品不可销售');
            }
        }
        if ($this->hasDuplicateProduct($items)) {
            return $this->fail(1412, '明细存在重复商品');
        }

        return null;
    }
}
