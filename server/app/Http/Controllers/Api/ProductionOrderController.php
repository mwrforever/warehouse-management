<?php

// 生产工单控制器：草稿 CRUD + BOM 展开（物料快照/工序序列）+ 物料需求接口；下达/开工/完工/关闭见 Task 4

namespace App\Http\Controllers\Api;

use App\Exceptions\ProductionException;
use App\Http\Controllers\Controller;
use App\Models\BomHeader;
use App\Models\DocumentSequence;
use App\Models\FinishedInbound;
use App\Models\InventoryBalance;
use App\Models\OutsourcingOrder;
use App\Models\PickList;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\ProductionOrderMaterial;
use App\Models\ReturnList;
use App\Models\RoutingHeader;
use App\Models\WorkOrderOperation;
use App\Models\WorkOrderOperationEdge;
use App\Services\DocumentSequenceService;
use App\Services\ProductionOrderService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
            // 回退告警文案经引用带出事务闭包（无路线时响应携带，供前端提示）
            $routingWarning = null;
            // 事务第 2 参数为死锁(1213)重试次数（机理同 BomController::store：序列行首建间隙锁
            // 死锁败方整体回滚后重跑闭包重新取号+重新展开 BOM，幂等安全）
            $order = DB::transaction(function () use ($data, &$routingWarning) {
                // 锁成品行：与 BOM 启用切换并发时串行化（1501 判定读一致）
                $product = Product::whereKey($data['product_id'])->lockForUpdate()->firstOrFail();
                // 启用版本唯一（BOM 模块不变式），按 id 倒序取最新启用版
                $bom = BomHeader::where('product_id', $product->id)->where('status', 1)->orderByDesc('id')->first();
                if (! $bom) {
                    throw new ProductionException('该成品没有启用版本的 BOM', 1501);
                }
                $expansion = $this->orderService->expandBom($product, (string) $data['quantity'], $bom);

                // 取启用工艺路线（同成品启用唯一，同 BOM 口径）：有→DAG 展开；无→旧逻辑全量工序快照 + 告警（RTG-06）
                $routing = RoutingHeader::where('product_id', $product->id)->where('status', 1)->orderByDesc('id')->first();
                if ($routing) {
                    $rex = $this->orderService->expandRouting($routing);
                } else {
                    // 无启用工艺路线：沿用旧逻辑（全量启用工序线性快照）并记告警日志
                    Log::warning('工单创建：成品无启用工艺路线，回退全量工序快照', ['product_id' => $product->id]);
                    $rex = null;
                    $routingWarning = '该成品无启用工艺路线，已按全量启用工序展开';
                }

                $order = $this->sequenceService->nextNoByConfig(
                    DocumentSequence::TYPE_MO,
                    fn (string $no) => ProductionOrder::create([
                        'no' => $no,
                        'product_id' => $data['product_id'],
                        'quantity' => $data['quantity'],
                        'plan_date' => $data['plan_date'],
                        'bom_id' => $bom->id,
                        // 路线快照锚定：null=旧逻辑展开（存量单不回写）
                        'routing_id' => $routing?->id,
                        'status' => ProductionOrder::STATUS_DRAFT,
                        'completed_qty' => 0,
                        'created_by' => auth()->id(),
                        'remark' => $data['remark'] ?? null,
                    ]),
                    // legacyMax 只取当日最大单号一行（orderByDesc+value 单查，P1-5：同日前缀字典序=序号序）
                    fn (string $prefix, string $dateKey) => ($no = ProductionOrder::where('no', 'like', $prefix.date('Ymd').'%')
                        ->orderByDesc('no')->value('no')) ? DocumentSequenceService::seqFromNo($no, $prefix, $dateKey) : 0,
                );
                // BOM 展开结果快照：物料需求（order_id+material_id 唯一）+ 工序序列（order_id+seq 唯一）
                $order->materials()->createMany(array_map(fn ($m) => [
                    'material_id' => $m['material_id'],
                    'required_qty' => $m['required_qty'],
                    'issued_qty' => 0,
                    // 物料归属工序节点：仅唯一消费节点时落 node_no（多节点共用按总量领料；无路线恒 null）
                    'node_no' => $rex['nodeOwners'][$m['material_id']] ?? null,
                ], $expansion['materials']));
                if ($rex) {
                    // DAG 工序快照：node_no/输出产品/委外标记随节点落到工序行
                    $createdOps = $order->operations()->createMany(array_map(fn ($op) => [
                        'process_id' => $op['process_id'],
                        'seq' => $op['seq'],
                        'node_no' => $op['node_no'],
                        'output_product_id' => $op['output_product_id'],
                        'is_outsourced' => $op['is_outsourced'],
                        'status' => WorkOrderOperation::STATUS_PENDING,
                        'qualified_qty' => 0,
                        'defective_qty' => 0,
                        'hours' => 0,
                    ], $rex['operations']));
                    // 边快照：node_no → 工序 id 映射后落边表（依赖边随快照固化，后续路线改版不影响本单）
                    $opIdByNo = [];
                    foreach ($createdOps as $i => $op) {
                        $opIdByNo[$rex['operations'][$i]['node_no']] = $op->id;
                    }
                    $order->edges()->createMany(array_map(fn ($e) => [
                        'from_operation_id' => $opIdByNo[$e['from']],
                        'to_operation_id' => $opIdByNo[$e['to']],
                    ], $rex['edges']));
                } else {
                    // 旧逻辑：全量启用工序线性快照（无 node_no/输出产品/委外标记）
                    $order->operations()->createMany(array_map(fn ($op) => [
                        'process_id' => $op['process_id'],
                        'seq' => $op['seq'],
                        'status' => WorkOrderOperation::STATUS_PENDING,
                        'qualified_qty' => 0,
                        'defective_qty' => 0,
                        'hours' => 0,
                    ], $expansion['operations']));
                }

                return $order;
            }, 2);
        } catch (ProductionException $e) {
            // 1501 无启用 BOM（事务内抛出，捕获后转业务码信封返回）
            return $this->fail($e->getCode() ?: 1501, $e->getMessage());
        }

        // 响应含 id：前端新建成功后直接以 id 拉详情打开 BOM 展开弹窗，不依赖列表回查（防刷新失败误报创建失败）
        // routing_warning 仅回退旧逻辑时携带（array_filter 剔除 null 键）
        return $this->ok(array_filter([
            'no' => $order->no,
            'id' => $order->id,
            'routing_warning' => $routingWarning,
        ], fn ($v) => $v !== null));
    }

    /** 详情：抬头 + 物料需求（需求/已领/剩余）+ 工序列表（状态与累计合格/不良/工时）+ DAG 网络图（Task 8 前端画布） */
    public function show(ProductionOrder $order)
    {
        // 工序一次取出：operations 与 graph.nodes 复用同一集合（§4.2.2 防重复查询）
        $ops = $order->operations()->with(['process', 'outputProduct'])->orderBy('seq')->get();
        // 边一次取出并预加载两端工序：operations.predecessors（按 to_operation_id 分组）与 graph.edges 复用（防 map 内懒加载 N+1）
        $edges = $order->edges()->with(['fromOperation.process', 'toOperation'])->get();
        $predsByTo = $edges->groupBy('to_operation_id');

        $operationItems = $ops->map(fn (WorkOrderOperation $op) => [
            'id' => $op->id,
            'seq' => $op->seq,
            'process_id' => $op->process_id,
            'process_name' => $op->process?->name,
            'process_code' => $op->process?->code,
            'node_no' => $op->node_no,
            'output_product_id' => $op->output_product_id,
            'output_product_name' => $op->outputProduct?->name,
            'is_outsourced' => (int) $op->is_outsourced,
            'status' => (int) $op->status,
            'status_label' => $this->orderService->operationStatusLabel((int) $op->status),
            'qualified_qty' => $op->qualified_qty,
            'defective_qty' => $op->defective_qty,
            'hours' => $op->hours,
            // 直接前驱（工序网络展示/前端推进判断；旧工单无边 → 空集合）
            'predecessors' => ($predsByTo->get($op->id) ?? collect())->map(fn ($e) => [
                'id' => $e->fromOperation->id,
                'node_no' => $e->fromOperation->node_no,
                'process_name' => $e->fromOperation?->process?->name,
            ])->values(),
        ]);

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
            // 路线快照锚定：null=旧逻辑展开（前端隐藏工序网络 tab）
            'routing_id' => $order->routing_id,
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
            'operations' => $operationItems,
            // 工序网络图（Vue Flow 渲染）：仅 DAG 工单返回，旧工单 null（前端隐藏该 tab）
            'graph' => $order->routing_id ? [
                'nodes' => $ops->map(fn (WorkOrderOperation $op) => [
                    'id' => $op->id,
                    'node_no' => $op->node_no,
                    'process_name' => $op->process?->name,
                    'status' => (int) $op->status,
                    'status_label' => $this->orderService->operationStatusLabel((int) $op->status),
                    'is_outsourced' => (int) $op->is_outsourced,
                    'qualified_qty' => $op->qualified_qty,
                    'defective_qty' => $op->defective_qty,
                    'hours' => $op->hours,
                ]),
                'edges' => $edges->map(fn (WorkOrderOperationEdge $e) => [
                    'from_operation_id' => $e->from_operation_id,
                    'to_operation_id' => $e->to_operation_id,
                    // 边模型 fromOperation/toOperation 未标泛型（返回 Model），节点号经 getAttribute 读
                    // （同 index join 别列传读惯用法；phpstan 无法静态识别 Model 动态属性）
                    'from_node_no' => $e->fromOperation?->getAttribute('node_no'),
                    'to_node_no' => $e->toOperation?->getAttribute('node_no'),
                ]),
            ] : null,
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

                // 取启用工艺路线（与 store 同口径，成品可改故随新成品重取）：有→DAG 展开重建；无→旧逻辑线性快照 + 告警
                $routing = RoutingHeader::where('product_id', $data['product_id'])->where('status', 1)->orderByDesc('id')->first();
                if ($routing) {
                    $rex = $this->orderService->expandRouting($routing);
                } else {
                    // 无启用工艺路线：沿用旧逻辑重建并记告警日志（更新无响应文案，行为与 store 回退一致）
                    Log::warning('工单更新：成品无启用工艺路线，回退全量工序快照', ['product_id' => $data['product_id'], 'order_id' => $locked->id]);
                    $rex = null;
                }

                $locked->update([
                    'product_id' => $data['product_id'],
                    'quantity' => $data['quantity'],
                    'plan_date' => $data['plan_date'],
                    'bom_id' => $bom->id,
                    // 路线快照随重建重挂（成品变更后锚定新成品的启用路线，无则回退 null）
                    'routing_id' => $routing?->id,
                    'remark' => $data['remark'] ?? $locked->remark,
                ]);
                // 物料快照/工序序列全量重建（草稿工单无流水引用，直接重建）
                // 重建前按 material_id 快照既有已领量：历史缺陷期草稿单可能已产生领料，
                // 清零会导致剩余量恢复全量 → 原料库存重复扣减、退料「≤已领」防线失效（防数据丢失）
                $issuedByMaterial = $locked->materials()->get()->keyBy('material_id');
                $locked->materials()->delete();
                $locked->materials()->createMany(array_map(fn ($m) => [
                    'material_id' => $m['material_id'],
                    'required_qty' => $m['required_qty'],
                    // 回填既有已领量（仅重算需求数量；该物料不再出现在新 BOM 时其已领记录随快照行一并移除）
                    // ?? 左值天然 null 安全（缺失时回退 0），nullsafe 显式多余故用 ->
                    'issued_qty' => (string) ($issuedByMaterial->get($m['material_id'])->issued_qty ?? '0'),
                    // 物料归属工序节点（仅唯一消费节点时落 node_no；无路线恒 null）
                    'node_no' => $rex['nodeOwners'][$m['material_id']] ?? null,
                ], $expansion['materials']));
                // 工序重建前先删旧行（边表 FK 级联随删，无需显式清）
                $locked->operations()->delete();
                if ($rex) {
                    // DAG 工序快照 + 边快照重建（同 store）
                    $createdOps = $locked->operations()->createMany(array_map(fn ($op) => [
                        'process_id' => $op['process_id'],
                        'seq' => $op['seq'],
                        'node_no' => $op['node_no'],
                        'output_product_id' => $op['output_product_id'],
                        'is_outsourced' => $op['is_outsourced'],
                        'status' => WorkOrderOperation::STATUS_PENDING,
                        'qualified_qty' => 0,
                        'defective_qty' => 0,
                        'hours' => 0,
                    ], $rex['operations']));
                    $opIdByNo = [];
                    foreach ($createdOps as $i => $op) {
                        $opIdByNo[$rex['operations'][$i]['node_no']] = $op->id;
                    }
                    $locked->edges()->createMany(array_map(fn ($e) => [
                        'from_operation_id' => $opIdByNo[$e['from']],
                        'to_operation_id' => $opIdByNo[$e['to']],
                    ], $rex['edges']));
                } else {
                    // 旧逻辑：全量启用工序线性快照
                    $locked->operations()->createMany(array_map(fn ($op) => [
                        'process_id' => $op['process_id'],
                        'seq' => $op['seq'],
                        'status' => WorkOrderOperation::STATUS_PENDING,
                        'qualified_qty' => 0,
                        'defective_qty' => 0,
                        'hours' => 0,
                    ], $expansion['operations']));
                }
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

    /**
     * 下达（草稿→已下达）：重复/非草稿 1505；物料库存不足仅返回 warnings 不阻断（缺料由领料环节控制）
     * 事务内锁工单行复查状态防并发；warnings 读全局库存快照（Σ 全仓余额，只读不锁）
     */
    public function release(ProductionOrder $order)
    {
        try {
            $result = null;
            DB::transaction(function () use ($order, &$result) {
                // 锁工单行：同一工单重复下达在此判重（幂等 1505）
                $locked = ProductionOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
                if ($locked->status === ProductionOrder::STATUS_RELEASED) {
                    throw new ProductionException('工单已下达', 1505);
                }
                if ($locked->status !== ProductionOrder::STATUS_DRAFT) {
                    throw new ProductionException('当前状态不可下达', 1505);
                }
                // 缺料警告：全仓余额汇总 vs 需求（bcadd 归一防浮点；只读快照，允许下达）
                // 物料一次预取（with('material')）+ 余额 SUM 下推 SQL groupBy——跨仓余额行在库端
                // 归并为每物料一行（SUM 标准 SQL 无方言差异），消除整表余额行传输与 PHP 侧跨仓累加
                $materials = $locked->materials()->with('material')->get();
                $stockRows = InventoryBalance::query()
                    ->whereIn('product_id', $materials->pluck('material_id'))
                    ->selectRaw('product_id, SUM(quantity) as total')
                    ->groupBy('product_id')
                    ->get()
                    ->keyBy('product_id');
                $warnings = [];
                foreach ($materials as $m) {
                    // SUM 跨库形态归一（SQLite int/float、MySQL decimal 字符串）→ 2 位小数字符串
                    $stock = bcadd((string) ($stockRows->get($m->material_id)?->getAttribute('total') ?? '0'), '0', 2);
                    if (bccomp($stock, (string) $m->required_qty, 2) < 0) {
                        $warnings[] = [
                            'material_name' => $m->material->name ?? ('#'.$m->material_id),
                            'material_code' => $m->material?->code,
                            'required' => $m->required_qty,
                            'stock' => $stock,
                        ];
                    }
                }
                $locked->status = ProductionOrder::STATUS_RELEASED;
                $locked->released_at = now();
                $locked->save();
                $result = ['warnings' => $warnings];
            });
        } catch (ProductionException $e) {
            // 1505 重复下达/状态非法流转
            return $this->fail($e->getCode() ?: 1505, $e->getMessage());
        }

        return $this->ok($result);
    }

    /**
     * 开工（已下达→生产中）：首工序（seq 最小）置进行中；重复/非已下达 1506。
     * 锁序 op(seq1)→order：与委外回收（outsourcing→op→order）/报工（op→next-op→order）在 op→order 段同序，
     * 消除「末批回收 vs 开工」并发 ABBA 死锁环（委外工序可为 seq1，系统无校验禁止）。
     */
    public function start(ProductionOrder $order)
    {
        try {
            DB::transaction(function () use ($order) {
                // 锁首工序行（锁 order 之前）：seq 最小工序；开工与报工/回收并发时按全局 op→order 锁序串行化
                $first = WorkOrderOperation::where('order_id', $order->id)
                    ->orderBy('seq')->lockForUpdate()->first();
                // 锁工单行复查状态（幂等 1506；失败回滚释放锁，行为与锁后校验等价）
                $locked = ProductionOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
                if ($locked->status !== ProductionOrder::STATUS_RELEASED) {
                    throw new ProductionException('当前状态不可开工', 1506);
                }
                $locked->status = ProductionOrder::STATUS_PRODUCING;
                $locked->save();
                // 首工序置进行中（seq 最小；行已提前锁定，直接更新不重复加锁）
                if ($first && $first->status === WorkOrderOperation::STATUS_PENDING) {
                    $first->status = WorkOrderOperation::STATUS_RUNNING;
                    $first->save();
                }
            });
        } catch (ProductionException $e) {
            // 1506 重复开工/状态非法流转
            return $this->fail($e->getCode() ?: 1506, $e->getMessage());
        }

        return $this->ok();
    }

    /**
     * 完工（生产中→已完成）：双前置校验——所有工序已完成（1507）+ 至少一次成品入库 completed_qty>0（1508）
     * 锁序 op→order：先锁全部工序行（升序），再锁工单行——与报工（op→next-op→order）/开工（op(seq1)→order）
     * 全局同序；若先锁 order 再锁工序行会引入 order→op 反序，与并发报工构成 ABBA 死锁环
     */
    public function complete(ProductionOrder $order)
    {
        try {
            DB::transaction(function () use ($order) {
                // 锁全部工序行（升序）：工序状态改为锁后一致读——与并发报工末批提交串行化
                // （此前为无锁一致性读，窗口内可能读到「全部 DONE」的同时末笔报工在途，方向安全但读不可靠）
                $operations = WorkOrderOperation::where('order_id', $order->id)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();
                // 锁工单行复查状态（幂等 1507）
                $locked = ProductionOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
                if ($locked->status !== ProductionOrder::STATUS_PRODUCING) {
                    throw new ProductionException('当前状态不可完工', 1507);
                }
                // 前置 1：所有工序必须已完成（直接用已锁工序行判定，存在待开工/进行中 → 1507）
                $hasUndone = $operations->contains(fn (WorkOrderOperation $op) => $op->status !== WorkOrderOperation::STATUS_DONE);
                if ($hasUndone) {
                    throw new ProductionException('存在未完成工序，无法完工', 1507);
                }
                // 前置 2：至少一次成品入库（completed_qty > 0，bcmath 比较）
                if (bccomp((string) $locked->completed_qty, '0', 2) <= 0) {
                    throw new ProductionException('无成品入库，无法完工', 1508);
                }
                $locked->status = ProductionOrder::STATUS_COMPLETED;
                $locked->completed_at = now();
                $locked->save();
            });
        } catch (ProductionException $e) {
            // 1507 状态/工序未完成 或 1508 无入库
            return $this->fail($e->getCode() ?: 1507, $e->getMessage());
        }

        return $this->ok();
    }

    /**
     * 关闭（已完成→关闭）：非已完成拒绝 1505「当前状态不可关闭」（spec 码段满，复用 1505，与 1405/1306 语义对齐）
     */
    public function close(ProductionOrder $order)
    {
        try {
            DB::transaction(function () use ($order) {
                // 锁工单行复查状态（幂等 1505 关闭族）
                $locked = ProductionOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
                if ($locked->status !== ProductionOrder::STATUS_COMPLETED) {
                    throw new ProductionException('当前状态不可关闭', 1505);
                }
                $locked->status = ProductionOrder::STATUS_CLOSED;
                $locked->closed_at = now();
                $locked->save();
            });
        } catch (ProductionException $e) {
            // 1505 当前状态不可关闭
            return $this->fail($e->getCode() ?: 1505, $e->getMessage());
        }

        return $this->ok();
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
