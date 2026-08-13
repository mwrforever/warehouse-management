<?php

// 生产工单控制器：草稿 CRUD + BOM 展开（物料快照/工序序列）+ 物料需求接口；下达/开工/完工/关闭见 Task 4

namespace App\Http\Controllers\Api;

use App\Exceptions\ProductionException;
use App\Http\Controllers\Controller;
use App\Models\BomHeader;
use App\Models\DocumentSequence;
use App\Models\FinishedInbound;
use App\Models\OutsourcingOrder;
use App\Models\PickList;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\ProductionOrderMaterial;
use App\Models\ReturnList;
use App\Models\WorkOrderOperation;
use App\Services\DocumentSequenceService;
use App\Services\ProductionOrderService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProductionOrderController extends Controller
{
    use ApiResponse;

    public function __construct(
        private ProductionOrderService $orderService,
        private DocumentSequenceService $sequenceService,
    ) {}

    /** 分页列表：单号/成品/状态/日期范围 筛选；含成品名与状态中文标签与完成率 */
    public function index(Request $request)
    {
        $query = ProductionOrder::query()
            ->join('products', 'products.id', '=', 'production_orders.product_id')
            ->select('production_orders.*', 'products.name as product_name', 'products.code as product_code')
            ->orderByDesc('production_orders.id');

        if ($keyword = $request->input('keyword')) {
            $query->where('production_orders.no', 'like', "%{$keyword}%");
        }
        if ($request->filled('product_id')) {
            $query->where('production_orders.product_id', $request->input('product_id'));
        }
        if ($request->filled('status')) {
            $query->where('production_orders.status', (int) $request->input('status'));
        }
        // 日期范围闭区间筛选（计划日期）
        if ($request->filled('date_from')) {
            $query->whereDate('production_orders.plan_date', '>=', $request->input('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('production_orders.plan_date', '<=', $request->input('date_to'));
        }

        $rows = $query->paginate(max(1, min(100, (int) $request->input('per_page', 10))));

        return $this->ok([
            // join 别名列经 getAttribute 读取（PHPStan 静态分析可识别）
            'items' => $rows->map(fn (ProductionOrder $o) => [
                'id' => $o->id,
                'no' => $o->no,
                'product_id' => $o->product_id,
                'product_name' => $o->getAttribute('product_name'),
                'product_code' => $o->getAttribute('product_code'),
                'quantity' => $o->quantity,
                'completed_qty' => $o->completed_qty,
                // 完成率（%）供列表进度条展示
                'progress' => $this->orderService->progress((string) $o->completed_qty, (string) $o->quantity),
                'plan_date' => $o->plan_date,
                'status' => (int) $o->status,
                'status_label' => ProductionOrder::STATUS_LABELS[$o->status] ?? '未知',
                'created_by' => $o->created_by,
                'released_at' => $o->released_at?->toDateTimeString(),
                'completed_at' => $o->completed_at?->toDateTimeString(),
            ]),
            'total' => $rows->total(), 'page' => $rows->currentPage(), 'per_page' => $rows->perPage(),
        ], '', JSON_PRESERVE_ZERO_FRACTION);
    }

    /**
     * 新建草稿：事务内「锁成品行 → 校验启用 BOM（1501）→ 单号持久序列 → BOM 展开快照物料需求与工序序列」
     * 数量 ≤ 0 → 1502（业务码，生产 spec 明确）；请求携带 bom_id 忽略，一律以启用版本为准
     */
    public function store(Request $request)
    {
        $data = $this->validatePayload($request);
        // 数量 ≤ 0 走业务码 1502（生产 spec 明确，与采购/销售 422 不同）
        if ((float) $data['quantity'] <= 0) {
            return $this->fail(1502, '数量必须大于 0');
        }

        try {
            $order = DB::transaction(function () use ($data) {
                // 锁成品行：与 BOM 启用切换并发时串行化（1501 判定读一致）
                $product = Product::whereKey($data['product_id'])->lockForUpdate()->firstOrFail();
                // 启用版本唯一（BOM 模块不变式），按 id 倒序取最新启用版
                $bom = BomHeader::where('product_id', $product->id)->where('status', 1)->orderByDesc('id')->first();
                if (! $bom) {
                    throw new ProductionException('该成品没有启用版本的 BOM', 1501);
                }
                $expansion = $this->orderService->expandBom($product, (string) $data['quantity'], $bom);

                $order = $this->sequenceService->nextNo(
                    DocumentSequence::TYPE_MO,
                    'MO',
                    fn (string $no) => ProductionOrder::create([
                        'no' => $no,
                        'product_id' => $data['product_id'],
                        'quantity' => $data['quantity'],
                        'plan_date' => $data['plan_date'],
                        'bom_id' => $bom->id,
                        'status' => ProductionOrder::STATUS_DRAFT,
                        'completed_qty' => 0,
                        'created_by' => auth()->id(),
                        'remark' => $data['remark'] ?? null,
                    ]),
                    fn () => (int) (ProductionOrder::where('no', 'like', 'MO'.date('Ymd').'-%')
                        ->get('no')->map(fn ($o) => (int) substr((string) $o->no, -3))->max() ?? 0),
                );
                // BOM 展开结果快照：物料需求（order_id+material_id 唯一）+ 工序序列（order_id+seq 唯一）
                $order->materials()->createMany(array_map(fn ($m) => [
                    'material_id' => $m['material_id'],
                    'required_qty' => $m['required_qty'],
                    'issued_qty' => 0,
                ], $expansion['materials']));
                $order->operations()->createMany(array_map(fn ($op) => [
                    'process_id' => $op['process_id'],
                    'seq' => $op['seq'],
                    'status' => WorkOrderOperation::STATUS_PENDING,
                    'qualified_qty' => 0,
                    'defective_qty' => 0,
                    'hours' => 0,
                ], $expansion['operations']));

                return $order;
            });
        } catch (ProductionException $e) {
            // 1501 无启用 BOM（事务内抛出，捕获后转业务码信封返回）
            return $this->fail($e->getCode() ?: 1501, $e->getMessage());
        }

        return $this->ok(['no' => $order->no]);
    }

    /** 详情：抬头 + 物料需求（需求/已领/剩余）+ 工序列表（状态与累计合格/不良/工时） */
    public function show(ProductionOrder $order)
    {
        return $this->ok([
            'id' => $order->id,
            'no' => $order->no,
            'product_id' => $order->product_id,
            'product_name' => $order->product?->name,
            'product_code' => $order->product?->code,
            'quantity' => $order->quantity,
            'plan_date' => $order->plan_date,
            'bom_id' => $order->bom_id,
            'bom_code' => $order->bom?->code,
            'status' => (int) $order->status,
            'status_label' => ProductionOrder::STATUS_LABELS[$order->status] ?? '未知',
            'completed_qty' => $order->completed_qty,
            'progress' => $this->orderService->progress((string) $order->completed_qty, (string) $order->quantity),
            'created_by' => $order->created_by,
            'released_at' => $order->released_at?->toDateTimeString(),
            'completed_at' => $order->completed_at?->toDateTimeString(),
            'closed_at' => $order->closed_at?->toDateTimeString(),
            'remark' => $order->remark,
            // 物料需求快照：剩余 = 需求 - 已领（bcmath 精确）
            'materials' => $order->materials()->with('material')->orderBy('id')->get()
                ->map(fn (ProductionOrderMaterial $m) => [
                    'material_id' => $m->material_id,
                    'material_name' => $m->material?->name,
                    'material_code' => $m->material?->code,
                    'required_qty' => $m->required_qty,
                    'issued_qty' => $m->issued_qty,
                    'remaining_qty' => bcsub((string) $m->required_qty, (string) $m->issued_qty, 2),
                ]),
            'operations' => $order->operations()->with('process')->orderBy('seq')->get()
                ->map(fn (WorkOrderOperation $op) => [
                    'id' => $op->id,
                    'seq' => $op->seq,
                    'process_id' => $op->process_id,
                    'process_name' => $op->process?->name,
                    'process_code' => $op->process?->code,
                    'status' => (int) $op->status,
                    'status_label' => $this->orderService->operationStatusLabel((int) $op->status),
                    'qualified_qty' => $op->qualified_qty,
                    'defective_qty' => $op->defective_qty,
                    'hours' => $op->hours,
                ]),
        ], '', JSON_PRESERVE_ZERO_FRACTION);
    }

    /** 更新草稿：仅草稿（1503）；物料快照/工序序列全量重建（BOM 展开）；事务内锁行复查防并发 */
    public function update(Request $request, ProductionOrder $order)
    {
        try {
            if ($order->status !== ProductionOrder::STATUS_DRAFT) {
                return $this->fail(1503, '已下达工单不可修改');
            }
            $data = $this->validatePayload($request);
            if ((float) $data['quantity'] <= 0) {
                return $this->fail(1502, '数量必须大于 0');
            }

            DB::transaction(function () use ($order, $data) {
                // 锁工单行复查状态：与下达并发时防止改到正在下达的单（幂等 1503）
                $locked = ProductionOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
                if ($locked->status !== ProductionOrder::STATUS_DRAFT) {
                    throw new ProductionException('已下达工单不可修改', 1503);
                }
                // 锁成品行 + 取启用 BOM（与 store 同口径）
                Product::whereKey($data['product_id'])->lockForUpdate()->firstOrFail();
                $bom = BomHeader::where('product_id', $data['product_id'])->where('status', 1)->orderByDesc('id')->first();
                if (! $bom) {
                    throw new ProductionException('该成品没有启用版本的 BOM', 1501);
                }
                $expansion = $this->orderService->expandBom($locked->product, (string) $data['quantity'], $bom);

                $locked->update([
                    'product_id' => $data['product_id'],
                    'quantity' => $data['quantity'],
                    'plan_date' => $data['plan_date'],
                    'bom_id' => $bom->id,
                    'remark' => $data['remark'] ?? $locked->remark,
                ]);
                // 物料快照/工序序列全量重建（草稿工单无流水引用，直接重建）
                $locked->materials()->delete();
                $locked->materials()->createMany(array_map(fn ($m) => [
                    'material_id' => $m['material_id'],
                    'required_qty' => $m['required_qty'],
                    'issued_qty' => 0,
                ], $expansion['materials']));
                $locked->operations()->delete();
                $locked->operations()->createMany(array_map(fn ($op) => [
                    'process_id' => $op['process_id'],
                    'seq' => $op['seq'],
                    'status' => WorkOrderOperation::STATUS_PENDING,
                    'qualified_qty' => 0,
                    'defective_qty' => 0,
                    'hours' => 0,
                ], $expansion['operations']));
            });
        } catch (ProductionException $e) {
            // 1503 已下达（锁行复查与并发下达幂等拦截）/1501 BOM 变更（改成品后新成品无启用 BOM）
            return $this->fail($e->getCode() ?: 1503, $e->getMessage());
        }

        return $this->ok();
    }

    /** 删除草稿：仅草稿（1504）；被生产单据引用不可删；事务内锁行复查防并发 */
    public function destroy(ProductionOrder $order)
    {
        try {
            if ($order->status !== ProductionOrder::STATUS_DRAFT) {
                return $this->fail(1504, '已下达工单不可删除');
            }
            DB::transaction(function () use ($order) {
                // 锁工单行复查状态（幂等 1504）
                $locked = ProductionOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
                if ($locked->status !== ProductionOrder::STATUS_DRAFT) {
                    throw new ProductionException('已下达工单不可删除', 1504);
                }
                // 防孤儿单据：草稿工单已被领料/退料/委外/成品入库单引用 → 拒绝删除（1504 同族）
                $referenced = PickList::where('order_id', $locked->id)->exists()
                    || ReturnList::where('order_id', $locked->id)->exists()
                    || OutsourcingOrder::where('order_id', $locked->id)->exists()
                    || FinishedInbound::where('order_id', $locked->id)->exists();
                if ($referenced) {
                    throw new ProductionException('工单已被生产单据使用，不可删除', 1504);
                }
                $locked->delete();
            });
        } catch (ProductionException $e) {
            // 1504 已下达/被单据引用（锁行复查与并发下达幂等拦截）
            return $this->fail($e->getCode() ?: 1504, $e->getMessage());
        }

        return $this->ok();
    }

    /** 物料需求列表：BOM 展开快照（需求/已领/剩余），领料单「从工单生成」预填数据源 */
    public function materials(ProductionOrder $order)
    {
        return $this->ok([
            'items' => $order->materials()->with('material')->orderBy('id')->get()
                ->map(fn (ProductionOrderMaterial $m) => [
                    'material_id' => $m->material_id,
                    'material_name' => $m->material?->name,
                    'material_code' => $m->material?->code,
                    'required_qty' => $m->required_qty,
                    'issued_qty' => $m->issued_qty,
                    'remaining_qty' => bcsub((string) $m->required_qty, (string) $m->issued_qty, 2),
                ]),
        ]);
    }

    // 载荷格式校验（422 仅格式层）；数量值域 1502 在方法内检查（业务码）
    private function validatePayload(Request $request): array
    {
        return $request->validate([
            'product_id' => 'required|integer|exists:products,id',
            // 数量限两位小数（正则按字符串形态校验，拦截 1e2 科学计数法避免 bcmul ValueError；负值形态放行到 1502）
            'quantity' => 'required|numeric|regex:/^-?\d+(\.\d{1,2})?$/',
            'plan_date' => 'required|date',
            // bom_id 仅校验格式：请求携带的 bom_id 一律忽略，以启用版本为准（后端权威，存在性不做校验）
            'bom_id' => 'nullable|integer',
            'remark' => 'nullable|string|max:200',
        ]);
    }
}
