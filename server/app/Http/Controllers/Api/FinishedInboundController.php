<?php

// 成品入库单控制器：草稿 CRUD + 审核（核心：事务内锁工单行防超量 1525 + InventoryService 写 finished_inbound 流水(+qty)
// + completed_qty 累计 + 满产自动完成工单）

namespace App\Http\Controllers\Api;

use App\Exceptions\InventoryException;
use App\Exceptions\ProductionException;
use App\Http\Controllers\Controller;
use App\Models\DocumentSequence;
use App\Models\FinishedInbound;
use App\Models\FinishedInboundItem;
use App\Models\ProductionOrder;
use App\Models\WorkOrderOperation;
use App\Services\DocumentSequenceService;
use App\Services\InventoryService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FinishedInboundController extends Controller
{
    use ApiResponse;

    public function __construct(
        private InventoryService $inventoryService,
        private DocumentSequenceService $sequenceService,
    ) {}

    /** 分页列表：单号/仓库/状态 筛选；含工单单号/成品名与状态标签 */
    public function index(Request $request)
    {
        $query = FinishedInbound::query()
            ->join('production_orders', 'production_orders.id', '=', 'finished_inbounds.order_id')
            ->join('products', 'products.id', '=', 'production_orders.product_id')
            ->select(
                'finished_inbounds.*',
                'production_orders.no as order_no',
                'products.name as product_name',
                'products.code as product_code',
            )
            ->orderByDesc('finished_inbounds.id');

        if ($keyword = $request->input('keyword')) {
            $query->where('finished_inbounds.no', 'like', "%{$keyword}%");
        }
        if ($request->filled('status')) {
            $query->where('finished_inbounds.status', (int) $request->input('status'));
        }
        if ($request->filled('date_from')) {
            $query->whereDate('finished_inbounds.created_at', '>=', $request->input('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('finished_inbounds.created_at', '<=', $request->input('date_to'));
        }

        $rows = $query->paginate(max(1, min(100, (int) $request->input('per_page', 10))));

        return $this->ok([
            // join 别名列经 getAttribute 读取（PHPStan 静态分析可识别）；数量 = Σ 明细行（单成品语义取首行）
            'items' => $rows->map(fn (FinishedInbound $f) => [
                'id' => $f->id,
                'no' => $f->no,
                'order_id' => $f->order_id,
                'order_no' => $f->getAttribute('order_no'),
                'product_id' => $f->order_id ? $f->order?->product_id : null,
                'product_name' => $f->getAttribute('product_name'),
                'product_code' => $f->getAttribute('product_code'),
                // 数量 = Σ 明细行（单成品语义取首行）；聚合不套 decimal cast，显式格式化两位小数（CheckController 先例）
                'quantity' => number_format((float) $f->items()->sum('quantity'), 2, '.', ''),
                'warehouse_id' => $f->warehouse_id,
                'warehouse_name' => $f->warehouse?->name,
                'location_id' => $f->location_id,
                'location_name' => $f->location?->name,
                'status' => (int) $f->status,
                'status_label' => FinishedInbound::STATUS_LABELS[$f->status] ?? '未知',
                'approved_at' => $f->approved_at?->toDateTimeString(),
                'operator' => $f->operator,
                'created_at' => $f->created_at?->toDateTimeString(),
            ]),
            'total' => $rows->total(), 'page' => $rows->currentPage(), 'per_page' => $rows->perPage(),
        ]);
    }

    /** 新建草稿：入库量 ≤ 剩余产量（1525）；成品必须与工单产品一致（1526）；明细为空/重复/数量≤0 422 */
    public function store(Request $request)
    {
        $data = $this->validatePayload($request);
        if ($fail = $this->validateBusinessItems($data)) {
            return $fail;
        }
        if (! $request->filled('warehouse_id') || ! $request->filled('location_id')) {
            return $this->fail(422, '仓库与库位不能为空');
        }
        // 草稿期校验：成品一致性（1526）+ 剩余产量（1525）
        $order = ProductionOrder::find($data['order_id']);
        if (! $order) {
            return $this->fail(422, '工单不存在');
        }
        if ($msg = $this->validateItems($order, $data['items'])) {
            [$code, $message] = $msg;

            return $this->fail($code, $message);
        }

        $fi = DB::transaction(function () use ($data) {
            $fi = $this->sequenceService->nextNo(
                DocumentSequence::TYPE_FI,
                'FI',
                fn (string $no) => FinishedInbound::create([
                    'no' => $no,
                    'order_id' => $data['order_id'],
                    'status' => FinishedInbound::STATUS_DRAFT,
                    'warehouse_id' => $data['warehouse_id'],
                    'location_id' => $data['location_id'],
                    'remark' => $data['remark'] ?? null,
                ]),
                fn () => (int) (FinishedInbound::where('no', 'like', 'FI'.date('Ymd').'-%')
                    ->get('no')->map(fn ($f) => (int) substr((string) $f->no, -3))->max() ?? 0),
            );
            $fi->items()->createMany(array_map(fn ($i) => [
                'product_id' => $i['product_id'],
                'quantity' => $i['quantity'],
            ], $data['items']));

            return $fi;
        });

        return $this->ok(['no' => $fi->no]);
    }

    /** 详情：头信息 + 明细（成品名/数量）+ 工单剩余产量（编辑弹窗数据源） */
    public function show(FinishedInbound $finishedInbound)
    {
        $order = $finishedInbound->order;

        return $this->ok([
            'id' => $finishedInbound->id,
            'no' => $finishedInbound->no,
            'order_id' => $finishedInbound->order_id,
            'order_no' => $order?->no,
            'status' => (int) $finishedInbound->status,
            'status_label' => FinishedInbound::STATUS_LABELS[$finishedInbound->status] ?? '未知',
            'warehouse_id' => $finishedInbound->warehouse_id,
            'warehouse_name' => $finishedInbound->warehouse?->name,
            'location_id' => $finishedInbound->location_id,
            'location_name' => $finishedInbound->location?->name,
            'approved_at' => $finishedInbound->approved_at?->toDateTimeString(),
            'operator' => $finishedInbound->operator,
            'remark' => $finishedInbound->remark,
            // 剩余产量 = 计划数 - 已完工（bcmath 精确）
            'remaining_qty' => $order ? bcsub((string) $order->quantity, (string) $order->completed_qty, 2) : '0',
            'items' => $finishedInbound->items()->with('product')->get()->map(fn (FinishedInboundItem $i) => [
                'id' => $i->id,
                'product_id' => $i->product_id,
                'product_name' => $i->product?->name,
                'product_code' => $i->product?->code,
                'quantity' => $i->quantity,
            ]),
        ]);
    }

    /** 更新草稿：仅草稿（1527）；校验同 store；事务内锁行复查防并发 */
    public function update(Request $request, FinishedInbound $finishedInbound)
    {
        try {
            if ($finishedInbound->status !== FinishedInbound::STATUS_DRAFT) {
                return $this->fail(1527, '已审核单据不可修改');
            }
            $data = $this->validatePayload($request);
            if ($fail = $this->validateBusinessItems($data)) {
                return $fail;
            }
            if (! $request->filled('warehouse_id') || ! $request->filled('location_id')) {
                return $this->fail(422, '仓库与库位不能为空');
            }
            $order = ProductionOrder::find($data['order_id']);
            if (! $order) {
                return $this->fail(422, '工单不存在');
            }
            if ($msg = $this->validateItems($order, $data['items'])) {
                [$code, $message] = $msg;

                return $this->fail($code, $message);
            }

            DB::transaction(function () use ($finishedInbound, $data) {
                // 锁入库单行复查状态（幂等 1527）
                $locked = FinishedInbound::whereKey($finishedInbound->id)->lockForUpdate()->firstOrFail();
                if ($locked->status !== FinishedInbound::STATUS_DRAFT) {
                    throw new ProductionException('已审核单据不可修改', 1527);
                }
                $locked->update([
                    'order_id' => $data['order_id'],
                    'warehouse_id' => $data['warehouse_id'],
                    'location_id' => $data['location_id'],
                    'remark' => $data['remark'] ?? $locked->remark,
                ]);
                // 明细全量替换（草稿单无流水引用，直接重建）
                $locked->items()->delete();
                $locked->items()->createMany(array_map(fn ($i) => [
                    'product_id' => $i['product_id'],
                    'quantity' => $i['quantity'],
                ], $data['items']));
            });
        } catch (ProductionException $e) {
            // 1527 已审核（锁行复查与并发审核幂等拦截）
            return $this->fail($e->getCode() ?: 1527, $e->getMessage());
        }

        return $this->ok();
    }

    /** 删除草稿：仅草稿（1527）；事务内锁行复查防并发 */
    public function destroy(FinishedInbound $finishedInbound)
    {
        try {
            if ($finishedInbound->status !== FinishedInbound::STATUS_DRAFT) {
                return $this->fail(1527, '已审核单据不可删除');
            }
            DB::transaction(function () use ($finishedInbound) {
                // 锁入库单行复查状态（幂等 1527）
                $locked = FinishedInbound::whereKey($finishedInbound->id)->lockForUpdate()->firstOrFail();
                if ($locked->status !== FinishedInbound::STATUS_DRAFT) {
                    throw new ProductionException('已审核单据不可删除', 1527);
                }
                $locked->delete();
            });
        } catch (ProductionException $e) {
            // 1527 已审核（锁行复查与并发审核幂等拦截）
            return $this->fail($e->getCode() ?: 1527, $e->getMessage());
        }

        return $this->ok();
    }

    /**
     * 审核（核心）：事务内「锁单幂等 1528 → 锁工单行复核剩余产量 1525 → InventoryService 写 finished_inbound 流水(+qty)
     * → completed_qty 累计 → 末工序已完成且满产 → 工单自动已完成」任一步失败整体回滚
     */
    public function approve(FinishedInbound $finishedInbound)
    {
        try {
            $result = null;
            DB::transaction(function () use ($finishedInbound, &$result) {
                // 锁入库单行：同一单据重复审核在此判重（幂等 1528）
                $locked = FinishedInbound::whereKey($finishedInbound->id)->lockForUpdate()->firstOrFail();
                if ($locked->status === FinishedInbound::STATUS_APPROVED) {
                    throw new ProductionException('该成品入库单已审核', 1528);
                }
                // 锁工单行：completed_qty 并发安全（多张 FI 同时审核串行化）
                $order = ProductionOrder::whereKey($locked->order_id)->lockForUpdate()->firstOrFail();
                $movements = [];
                $inboundTotal = '0';
                /** @var FinishedInboundItem $item */
                foreach ($locked->items as $item) {
                    // 成品一致性复核（草稿期后工单产品不可变，防御路径 1526）
                    if ($item->product_id !== $order->product_id) {
                        throw new ProductionException('入库商品与工单产品不一致', 1526);
                    }
                    // 剩余产量 = 计划数 - 已完工；本次超剩余 → 1525 整体回滚（防超量入库）
                    $remaining = bcsub((string) $order->quantity, (string) $order->completed_qty, 2);
                    if (bccomp((string) $item->quantity, $remaining, 2) > 0) {
                        throw new ProductionException('入库数量超过工单剩余产量', 1525);
                    }
                    $inboundTotal = bcadd($inboundTotal, (string) $item->quantity, 2);
                    $movements[] = [
                        'product_id' => $item->product_id,
                        'warehouse_id' => $locked->warehouse_id,
                        'location_id' => $locked->location_id,
                        'direction' => 1,
                        'quantity' => $item->quantity,
                        'source_type' => 'finished_inbound',
                        'source_id' => $locked->id,
                        'source_no' => $locked->no,
                        'remark' => '成品入库',
                    ];
                }
                // 统一引擎写流水+加余额（同事务双写）
                $this->inventoryService->apply($movements, auth()->id());
                // 工单 completed_qty 累计（bcmath）
                $order->completed_qty = bcadd((string) $order->completed_qty, $inboundTotal, 2);
                // 联动：末工序已完成且 completed_qty ≥ 计划数 → 工单自动「已完成」
                // 末工序判定用一致性快照读（不锁工序行）：与报工流转锁序 op→order 全系统同序，消除 order→op 反序死锁环
                $allDone = ! $order->operations()->where('status', '!=', WorkOrderOperation::STATUS_DONE)->exists();
                if ($allDone && bccomp((string) $order->completed_qty, (string) $order->quantity, 2) >= 0) {
                    $order->status = ProductionOrder::STATUS_COMPLETED;
                    $order->completed_at = now();
                }
                $order->save();
                // 置已审核 + 审核人/时间
                $locked->status = FinishedInbound::STATUS_APPROVED;
                $locked->operator = auth()->user()->name ?? '';
                $locked->approved_at = now();
                $locked->save();
                $result = ['no' => $locked->no];
            });
        } catch (ProductionException $e) {
            // 1528 幂等 / 1525 超剩余产量 / 1526 成品不一致（事务整体回滚）
            return $this->fail($e->getCode() ?: 1525, $e->getMessage());
        } catch (InventoryException $e) {
            // 余额引擎兜底（入库方向理论不触发，防御路径）
            return $this->fail(422, '入库失败，请重试');
        }

        return $this->ok($result);
    }

    // 载荷格式校验（422 仅格式层）；业务码在方法内检查
    private function validatePayload(Request $request): array
    {
        return $request->validate([
            'order_id' => 'required|integer|exists:production_orders,id',
            'warehouse_id' => 'nullable|integer|exists:warehouses,id',
            'location_id' => 'nullable|integer|exists:locations,id',
            'remark' => 'nullable|string|max:200',
            'items' => 'array',
            'items.*.product_id' => 'required|integer|exists:products,id',
            'items.*.quantity' => 'required|numeric|regex:/^-?\d+(\.\d{1,2})?$/',
        ]);
    }

    // 明细业务校验（store/update 共用）：空明细/数量≤0/重复商品 → 422（格式层；spec 码段满）
    private function validateBusinessItems(array $data): ?JsonResponse
    {
        $items = $data['items'] ?? [];
        if (empty($items)) {
            return $this->fail(422, '请至少添加一条明细');
        }
        $seen = [];
        foreach ($items as $item) {
            if ((float) $item['quantity'] <= 0) {
                return $this->fail(422, '入库数量必须大于 0');
            }
            if (isset($seen[$item['product_id']])) {
                return $this->fail(422, '明细存在重复商品');
            }
            $seen[$item['product_id']] = true;
        }

        return null;
    }

    // 草稿期校验：成品一致性（1526）+ 剩余产量（1525）；返回 [code, message] 或 null
    private function validateItems(ProductionOrder $order, array $items): ?array
    {
        $total = '0';
        foreach ($items as $item) {
            if ($item['product_id'] !== $order->product_id) {
                return [1526, '入库商品与工单产品不一致'];
            }
            $total = bcadd($total, (string) $item['quantity'], 2);
        }
        // 剩余产量 = 计划数 - 已完工（bcmath 精确）
        $remaining = bcsub((string) $order->quantity, (string) $order->completed_qty, 2);
        if (bccomp($total, $remaining, 2) > 0) {
            return [1525, '入库数量超过工单剩余产量'];
        }

        return null;
    }
}
