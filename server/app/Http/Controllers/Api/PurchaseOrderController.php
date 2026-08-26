<?php

// 采购订单控制器：草稿 CRUD + 审核 + 关闭 + 可入库订单列表 + 订单入库记录

namespace App\Http\Controllers\Api;

use App\Exceptions\PurchaseException;
use App\Http\Controllers\Controller;
use App\Models\DocumentSequence;
use App\Models\PurchaseInbound;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Services\DocumentSequenceService;
use App\Services\PurchaseOrderService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PurchaseOrderController extends Controller
{
    use ApiResponse;

    public function __construct(
        private PurchaseOrderService $orderService,
        private DocumentSequenceService $sequenceService,
    ) {}

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

    /** 新建草稿：单号持久序列；金额分单位整数（half-up 到整数分）；明细非空 1301 / 数量>0 1302 / 负价 1311 / 重复商品 1312 */
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
                DocumentSequence::TYPE_PO,
                fn (string $no) => PurchaseOrder::create([
                    'no' => $no,
                    'supplier_id' => $data['supplier_id'],
                    'order_date' => $data['order_date'],
                    'expected_date' => $data['expected_date'] ?? null,
                    'status' => PurchaseOrder::STATUS_DRAFT,
                    'total_amount' => $this->orderService->calculateTotal($data['items']),
                    'remark' => $data['remark'] ?? null,
                    'created_by' => auth()->id(),
                ]),
                fn (string $prefix, string $dateKey) => ($no = PurchaseOrder::where('no', 'like', $prefix.date('Ymd').'%')
                    ->orderByDesc('no')->value('no')) ? DocumentSequenceService::seqFromNo($no, $prefix, $dateKey) : 0,
            );
            $order->items()->createMany(array_map(fn ($i) => [
                'product_id' => $i['product_id'],
                'quantity' => $i['quantity'],
                'price' => $i['price'],
                'received_qty' => 0,
                'amount' => $this->orderService->lineAmount((string) $i['quantity'], $i['price']),
            ], $data['items']));

            return $order;
        }, 2);

        // 单据创建审计日志（事务提交后记）：单号 + 操作人
        Log::info('采购订单创建成功', ['no' => $order->no, 'created_by' => auth()->id()]);

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

    /** 更新草稿：仅草稿（1303）；items 全量替换；金额重算；事务内锁行复查防并发 */
    public function update(Request $request, PurchaseOrder $order)
    {
        if ($order->status !== PurchaseOrder::STATUS_DRAFT) {
            return $this->fail(1303, '已审核订单不可修改');
        }
        $data = $this->validatePayload($request);
        // 明细业务校验与 store 共用同一 helper，保证两处校验口径一致（见 validateBusinessItems）
        if ($fail = $this->validateBusinessItems($data['items'])) {
            return $fail;
        }

        DB::transaction(function () use ($order, $data) {
            // 锁订单行复查状态：与审核并发时防止改到正在审核的单（幂等 1303）
            $locked = PurchaseOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== PurchaseOrder::STATUS_DRAFT) {
                throw new PurchaseException('已审核订单不可修改', 1303);
            }
            $locked->update([
                'supplier_id' => $data['supplier_id'],
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
                'received_qty' => 0,
                'amount' => $this->orderService->lineAmount((string) $i['quantity'], $i['price']),
            ], $data['items']));
        });

        return $this->ok();
    }

    /** 删除草稿：仅草稿（1304）；事务内锁行复查防并发 */
    public function destroy(PurchaseOrder $order)
    {
        if ($order->status !== PurchaseOrder::STATUS_DRAFT) {
            return $this->fail(1304, '已审核订单不可删除');
        }
        DB::transaction(function () use ($order) {
            // 锁订单行复查状态：与审核并发时防止删到正在审核的单（幂等 1304）
            $locked = PurchaseOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== PurchaseOrder::STATUS_DRAFT) {
                throw new PurchaseException('已审核订单不可删除', 1304);
            }
            $locked->delete();
        });

        // 单据删除审计日志（事务提交后记）：内存模型仍持有单号，可用于追溯
        Log::info('采购订单草稿删除', ['no' => $order->no, 'operator' => auth()->id()]);

        return $this->ok();
    }

    /** 审核：仅草稿（幂等 1305）；置已审核 + approved_at + 创建人；锁内复查抛错转业务码 */
    public function approve(PurchaseOrder $order)
    {
        if ($order->status !== PurchaseOrder::STATUS_DRAFT) {
            return $this->fail(1305, '该订单已审核');
        }
        DB::transaction(function () use ($order) {
            // 锁订单行：同一订单重复审核在此判重（幂等）
            $locked = PurchaseOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== PurchaseOrder::STATUS_DRAFT) {
                throw new PurchaseException('该订单已审核', 1305);
            }
            $locked->status = PurchaseOrder::STATUS_APPROVED;
            $locked->approved_at = now();
            $locked->created_by = $locked->created_by ?? auth()->id();
            $locked->save();
        });

        // 状态变更审计日志（事务提交后记）：审核是订单生效节点，后续可生成入库单
        Log::info('采购订单审核通过', ['no' => $order->no, 'operator' => auth()->id()]);

        return $this->ok(['no' => $order->no]);
    }

    /** 关闭：仅已审核/部分入库（1306）；置关闭 + closed_at；关闭后不可再生成入库单；锁内复查抛错转业务码 */
    public function close(PurchaseOrder $order)
    {
        if (! in_array($order->status, [PurchaseOrder::STATUS_APPROVED, PurchaseOrder::STATUS_PARTIAL], true)) {
            return $this->fail(1306, '当前状态不可关闭');
        }
        DB::transaction(function () use ($order) {
            // 锁订单行复查状态：与入库审核并发时防止关闭正在入库的订单
            $locked = PurchaseOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if (! in_array($locked->status, [PurchaseOrder::STATUS_APPROVED, PurchaseOrder::STATUS_PARTIAL], true)) {
                throw new PurchaseException('当前状态不可关闭', 1306);
            }
            $locked->status = PurchaseOrder::STATUS_CLOSED;
            $locked->closed_at = now();
            $locked->save();
        });

        // 状态变更审计日志（事务提交后记）：关闭后订单不可再生成入库单，属不可逆业务节点
        Log::info('采购订单关闭', ['no' => $order->no, 'operator' => auth()->id()]);

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

    // 载荷格式校验（422 仅格式层）；业务码在方法内检查
    private function validatePayload(Request $request): array
    {
        return $request->validate([
            'supplier_id' => 'required|integer|exists:suppliers,id',
            'order_date' => 'required|date',
            'expected_date' => 'nullable|date',
            'remark' => 'nullable|string|max:200',
            // 注意：items 不加 required——空数组 [] 走 1301 业务码（422 仅拦缺失字段与类型错误）
            'items' => 'array',
            'items.*.product_id' => 'required|integer|exists:products,id',
            // 数量限两位小数（正则按字符串形态校验，拦截 1e2 科学计数法避免 bcmul ValueError；允许负号形态，负值由业务层拦截 1302）；
            // 单价为分单位整数（R2：bigint 分列），integer 校验拦截小数分与科学计数法形态（负值仍由业务层拦截 1311）
            'items.*.quantity' => 'required|numeric|regex:/^-?\d+(\.\d{1,2})?$/',
            'items.*.price' => 'required|integer',
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

    // 明细业务校验（store/update 共用）：空明细 1301 / 数量≤0 1302 / 负价 1311 / 重复商品 1312
    // 校验通过返回 null，未通过返回对应业务码的 fail 响应（JSON 信封，由调用方直接 return）
    private function validateBusinessItems(array $items): ?JsonResponse
    {
        if (empty($items)) {
            return $this->fail(1301, '请至少添加一条明细');
        }
        foreach ($items as $item) {
            // 数量正负校验走 bccomp（D-3 铁律：禁浮点参与数量与金额比较；正则已保证入参为两位小数十进制）；
            // 单价经 integer 校验后为整数分，直接整数比较（无浮点参与）
            if (bccomp((string) $item['quantity'], '0', 2) <= 0) {
                return $this->fail(1302, '数量必须大于 0');
            }
            if ((int) $item['price'] < 0) {
                return $this->fail(1311, '价格不能为负数');
            }
        }
        if ($this->hasDuplicateProduct($items)) {
            return $this->fail(1312, '明细存在重复商品');
        }

        return null;
    }
}
