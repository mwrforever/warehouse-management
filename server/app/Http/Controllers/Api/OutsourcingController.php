<?php

// 委外加工控制器：from-operation 预填 + 草稿 CRUD（组件载荷校验，委外量 ≤ 节点剩余计划量 1520）+ 发出
// （审核：锁余额行防超卖 1522）+ 回收（创建即审核回收单 + 工序联动）
// 委外商品 = 工单成品（spec 数据模型无 product_id，E2E TC-PRD-06 锁定——Task 3 起改组件口径）

namespace App\Http\Controllers\Api;

use App\Exceptions\InventoryException;
use App\Exceptions\ProductionException;
use App\Http\Controllers\Controller;
use App\Models\DocumentSequence;
use App\Models\InventoryBalance;
use App\Models\OutsourcingOrder;
use App\Models\OutsourcingReceipt;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\WorkOrderOperation;
use App\Models\WorkOrderOperationEdge;
use App\Services\DocumentSequenceService;
use App\Services\InventoryService;
use App\Services\OutsourcingService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OutsourcingController extends Controller
{
    use ApiResponse;

    public function __construct(
        private InventoryService $inventoryService,
        private DocumentSequenceService $sequenceService,
        private OutsourcingService $outsourcingService,
    ) {}

    /** 分页列表：单号/供应商/状态 筛选；含工单单号/供应商名/工序名与状态标签 */
    public function index(Request $request)
    {
        $query = OutsourcingOrder::query()
            ->join('production_orders', 'production_orders.id', '=', 'outsourcing_orders.order_id')
            ->join('suppliers', 'suppliers.id', '=', 'outsourcing_orders.supplier_id')
            ->join('work_order_operations', 'work_order_operations.id', '=', 'outsourcing_orders.operation_id')
            ->join('processes', 'processes.id', '=', 'work_order_operations.process_id')
            ->select(
                'outsourcing_orders.*',
                'production_orders.no as order_no',
                'suppliers.name as supplier_name',
                'processes.name as process_name',
            )
            ->orderByDesc('outsourcing_orders.id');

        if ($keyword = $request->input('keyword')) {
            $query->where('outsourcing_orders.no', 'like', "%{$keyword}%");
        }
        if ($request->filled('status')) {
            $query->where('outsourcing_orders.status', (int) $request->input('status'));
        }

        $rows = $query->paginate(max(1, min(100, (int) $request->input('per_page', 10))));

        return $this->ok([
            // join 别名列经 getAttribute 读取（PHPStan 静态分析可识别）
            'items' => $rows->map(fn (OutsourcingOrder $o) => [
                'id' => $o->id,
                'no' => $o->no,
                'order_id' => $o->order_id,
                'order_no' => $o->getAttribute('order_no'),
                'operation_id' => $o->operation_id,
                'process_name' => $o->getAttribute('process_name'),
                'supplier_id' => $o->supplier_id,
                'supplier_name' => $o->getAttribute('supplier_name'),
                'quantity' => $o->quantity,
                'status' => (int) $o->status,
                'status_label' => OutsourcingOrder::STATUS_LABELS[$o->status] ?? '未知',
                'approved_at' => $o->approved_at?->toDateTimeString(),
                'operator' => $o->operator,
                'created_at' => $o->created_at?->toDateTimeString(),
            ]),
            'total' => $rows->total(), 'page' => $rows->currentPage(), 'per_page' => $rows->perPage(),
        ]);
    }

    /** 新建草稿：委外量 ≤ 节点剩余计划量（1520）；工序必须属于该工单（422）；组件载荷检单元节点口径（422） */
    public function store(Request $request)
    {
        $data = $this->validatePayload($request);
        if ((float) $data['quantity'] <= 0) {
            return $this->fail(422, '委外数量必须大于 0');
        }
        if (! $request->filled('supplier_id')) {
            return $this->fail(422, '供应商不能为空');
        }
        if (! $request->filled('warehouse_id') || ! $request->filled('location_id')) {
            return $this->fail(422, '仓库与库位不能为空');
        }
        // 工序必须属于该工单（防跨单挂工序）
        $op = WorkOrderOperation::where('id', $data['operation_id'])->where('order_id', $data['order_id'])->first();
        if (! $op) {
            return $this->fail(422, '工序不属于该工单');
        }
        // 委外对象=工艺路线节点（无路线旧线性工单返回 null，走「委外=工单成品」兼容口径，Task 5 迁移后收敛）
        $node = $this->outsourcingService->routingNodeForOperation($data['operation_id']);
        if ($node) {
            if ((int) $op->is_outsourced !== 1) {
                return $this->fail(422, '该工序不是委外工序');
            }
            if ((int) $op->status === WorkOrderOperation::STATUS_DONE) {
                return $this->fail(422, '该工序已完成，不可委外');
            }
        }
        // 草稿期校验：委外量 ≤ 剩余可委外量 = 工单数量 − Σ同节点非草稿委外单（1520，bcmath）
        $order = ProductionOrder::find($data['order_id']);
        $outsourced = bcadd((string) OutsourcingOrder::where('operation_id', $data['operation_id'])
            ->where('status', '!=', OutsourcingOrder::STATUS_DRAFT)->sum('quantity'), '0', 2);
        if (! $order || bccomp((string) $data['quantity'], bcsub((string) $order->quantity, $outsourced, 2), 2) > 0) {
            return $this->fail(1520, '委外数量超过工单计划数量');
        }

        try {
            // 事务第 2 参数为死锁(1213)重试次数（机理同 BomController::store：序列行首建间隙锁
            // 死锁败方整体回滚后重跑闭包重新取号，幂等安全）
            $os = DB::transaction(function () use ($data, $node) {
                $os = $this->sequenceService->nextNoByConfig(
                    DocumentSequence::TYPE_OS,
                    fn (string $no) => OutsourcingOrder::create([
                        'no' => $no,
                        'order_id' => $data['order_id'],
                        'operation_id' => $data['operation_id'],
                        'supplier_id' => $data['supplier_id'],
                        'status' => OutsourcingOrder::STATUS_DRAFT,
                        'warehouse_id' => $data['warehouse_id'],
                        'location_id' => $data['location_id'],
                        'quantity' => $data['quantity'],
                        // 回收品=节点输出产品快照（旧线性工单无节点保持空，废弃口径）
                        'output_product_id' => $node?->output_product_id,
                        'remark' => $data['remark'] ?? null,
                    ]),
                    // legacyMax 只取当日最大单号一行（orderByDesc+value 单查，P1-5：同日前缀字典序=序号序）
                    fn (string $prefix, string $dateKey) => ($no = OutsourcingOrder::where('no', 'like', $prefix.date('Ymd').'%')
                        ->orderByDesc('no')->value('no')) ? DocumentSequenceService::seqFromNo($no, $prefix, $dateKey) : 0,
                );
                // 组件行落库：DAG 节点逐行校验（应发 ≤ 单位用量×委外量，bcmath 权威）；旧线性工单直落兼容
                $items = $node
                    ? $this->outsourcingService->validateItems($data['items'], $node, (string) $data['quantity'])
                    : $data['items'];
                $os->items()->createMany($items);

                return $os;
            }, 2);
        } catch (ProductionException $e) {
            // 422 组件载荷不符（应发超上限/重复/非节点材料，事务整体回滚）
            return $this->fail($e->getCode() ?: 422, $e->getMessage());
        }

        return $this->ok(['no' => $os->no]);
    }

    /** from-operation 预填：节点输入材料清单×单位用量 + 回收品 + 计划/已委外/剩余量（结构不符 422） */
    public function fromOperation(int $operationId)
    {
        try {
            return $this->ok($this->outsourcingService->fromOperation($operationId));
        } catch (ProductionException $e) {
            // 422：工单无路线/节点非委外/节点已完成等结构不符（预填不可用）
            return $this->fail($e->getCode() ?: 422, $e->getMessage());
        }
    }

    /** 详情：头信息 + 回收记录摘要 */
    public function show(OutsourcingOrder $outsourcing)
    {
        return $this->ok([
            'id' => $outsourcing->id,
            'no' => $outsourcing->no,
            'order_id' => $outsourcing->order_id,
            'order_no' => $outsourcing->order?->no,
            'operation_id' => $outsourcing->operation_id,
            'process_name' => $outsourcing->operation?->process?->name,
            'supplier_id' => $outsourcing->supplier_id,
            'supplier_name' => $outsourcing->supplier?->name,
            'status' => (int) $outsourcing->status,
            'status_label' => OutsourcingOrder::STATUS_LABELS[$outsourcing->status] ?? '未知',
            'warehouse_id' => $outsourcing->warehouse_id,
            'warehouse_name' => $outsourcing->warehouse?->name,
            'location_id' => $outsourcing->location_id,
            'location_name' => $outsourcing->location?->name,
            'quantity' => $outsourcing->quantity,
            'approved_at' => $outsourcing->approved_at?->toDateTimeString(),
            'operator' => $outsourcing->operator,
            'remark' => $outsourcing->remark,
            // 已回收累计（回收弹窗剩余量数据源）
            'received_qty' => $this->receivedQty($outsourcing->id),
        ]);
    }

    /** 更新草稿：仅草稿（1521）；校验同 store；items 全量替换；事务内锁行复查防并发 */
    public function update(Request $request, OutsourcingOrder $outsourcing)
    {
        try {
            if ($outsourcing->status !== OutsourcingOrder::STATUS_DRAFT) {
                return $this->fail(1521, '已审核单据不可修改');
            }
            $data = $this->validatePayload($request);
            if ((float) $data['quantity'] <= 0) {
                return $this->fail(422, '委外数量必须大于 0');
            }
            if (! $request->filled('supplier_id')) {
                return $this->fail(422, '供应商不能为空');
            }
            if (! $request->filled('warehouse_id') || ! $request->filled('location_id')) {
                return $this->fail(422, '仓库与库位不能为空');
            }
            $op = WorkOrderOperation::where('id', $data['operation_id'])->where('order_id', $data['order_id'])->first();
            if (! $op) {
                return $this->fail(422, '工序不属于该工单');
            }
            // 委外对象=工艺路线节点（无路线旧线性工单返回 null，走「委外=工单成品」兼容口径，Task 5 迁移后收敛）
            $node = $this->outsourcingService->routingNodeForOperation($data['operation_id']);
            if ($node) {
                if ((int) $op->is_outsourced !== 1) {
                    return $this->fail(422, '该工序不是委外工序');
                }
                if ((int) $op->status === WorkOrderOperation::STATUS_DONE) {
                    return $this->fail(422, '该工序已完成，不可委外');
                }
            }
            // 草稿期校验：委外量 ≤ 剩余可委外量（排除本次编辑自身——草稿本不在非草稿口径，双保险）
            $order = ProductionOrder::find($data['order_id']);
            $outsourced = bcadd((string) OutsourcingOrder::where('operation_id', $data['operation_id'])
                ->where('id', '!=', $outsourcing->id)
                ->where('status', '!=', OutsourcingOrder::STATUS_DRAFT)->sum('quantity'), '0', 2);
            if (! $order || bccomp((string) $data['quantity'], bcsub((string) $order->quantity, $outsourced, 2), 2) > 0) {
                return $this->fail(1520, '委外数量超过工单计划数量');
            }

            DB::transaction(function () use ($outsourcing, $data, $node) {
                // 锁委外单行复查状态（幂等 1521）
                $locked = OutsourcingOrder::whereKey($outsourcing->id)->lockForUpdate()->firstOrFail();
                if ($locked->status !== OutsourcingOrder::STATUS_DRAFT) {
                    throw new ProductionException('已审核单据不可修改', 1521);
                }
                // 组件载荷校验（DAG 节点口径；旧线性工单直落兼容）——校验失败整单回滚
                $items = $node
                    ? $this->outsourcingService->validateItems($data['items'], $node, (string) $data['quantity'])
                    : $data['items'];
                $locked->update([
                    'order_id' => $data['order_id'],
                    'operation_id' => $data['operation_id'],
                    'supplier_id' => $data['supplier_id'],
                    'warehouse_id' => $data['warehouse_id'],
                    'location_id' => $data['location_id'],
                    'quantity' => $data['quantity'],
                    // 回收品=节点输出产品快照（旧线性工单无节点置空，废弃口径）
                    'output_product_id' => $node?->output_product_id,
                    'remark' => $data['remark'] ?? $locked->remark,
                ]);
                // 组件全量替换（草稿单无流水引用，直接重建；唯一键防重复）
                $locked->items()->delete();
                $locked->items()->createMany($items);
            });
        } catch (ProductionException $e) {
            // 1521 已审核（锁行复查与并发审核幂等拦截）/ 422 组件载荷不符
            return $this->fail($e->getCode() ?: 1521, $e->getMessage());
        }

        return $this->ok();
    }

    /** 删除草稿：仅草稿（1521）；事务内锁行复查防并发 */
    public function destroy(OutsourcingOrder $outsourcing)
    {
        try {
            if ($outsourcing->status !== OutsourcingOrder::STATUS_DRAFT) {
                return $this->fail(1521, '已审核单据不可删除');
            }
            DB::transaction(function () use ($outsourcing) {
                // 锁委外单行复查状态（幂等 1521）
                $locked = OutsourcingOrder::whereKey($outsourcing->id)->lockForUpdate()->firstOrFail();
                if ($locked->status !== OutsourcingOrder::STATUS_DRAFT) {
                    throw new ProductionException('已审核单据不可删除', 1521);
                }
                $locked->delete();
            });
        } catch (ProductionException $e) {
            // 1521 已审核（锁行复查与并发审核幂等拦截）
            return $this->fail($e->getCode() ?: 1521, $e->getMessage());
        }

        return $this->ok();
    }

    /**
     * 发出（审核）：事务内「锁单幂等 1523 → 锁工单行取成品 → 锁余额行校验充足 1522
     * → InventoryService 写 outsourcing_out 流水(-qty) 扣成品库存」任一步失败整体回滚
     */
    public function approve(OutsourcingOrder $outsourcing)
    {
        try {
            $result = null;
            DB::transaction(function () use ($outsourcing, &$result) {
                // 锁委外单行：同一单据重复审核在此判重（幂等 1523）
                $locked = OutsourcingOrder::whereKey($outsourcing->id)->lockForUpdate()->firstOrFail();
                if ($locked->status === OutsourcingOrder::STATUS_APPROVED) {
                    throw new ProductionException('该委外单已审核', 1523);
                }
                if ($locked->status === OutsourcingOrder::STATUS_RECEIVED) {
                    throw new ProductionException('该委外单已回收', 1523);
                }
                // 锁工单行：取委外商品（= 工单成品）并校验工单状态（草稿/关闭不可发出）
                $order = ProductionOrder::whereKey($locked->order_id)->lockForUpdate()->firstOrFail();
                if (! in_array($order->status, [ProductionOrder::STATUS_RELEASED, ProductionOrder::STATUS_PRODUCING], true)) {
                    throw new ProductionException('工单当前状态不可委外', 1523);
                }
                // 防超卖：锁余额行校验（消息含商品编码与精确库存快照）
                $balance = InventoryBalance::where('product_id', $order->product_id)
                    ->where('warehouse_id', $locked->warehouse_id)
                    ->where('location_id', $locked->location_id)
                    ->lockForUpdate()
                    ->first();
                $current = $balance ? (string) $balance->quantity : '0';
                if (bccomp((string) $locked->quantity, $current, 2) > 0) {
                    $code = Product::find($order->product_id)->code ?? ('#'.$order->product_id);
                    throw new ProductionException("商品[{$code}]库存不足", 1522);
                }
                // 统一引擎写流水+扣余额（同事务双写；余额行已被本事务锁定，引擎内重复加锁幂等）
                $this->inventoryService->apply([[
                    'product_id' => $order->product_id,
                    'warehouse_id' => $locked->warehouse_id,
                    'location_id' => $locked->location_id,
                    'direction' => -1,
                    'quantity' => $locked->quantity,
                    'source_type' => 'outsourcing_out',
                    'source_id' => $locked->id,
                    'source_no' => $locked->no,
                    'remark' => '委外发出',
                ]], auth()->id());
                // 置已审核（已发出）+ 操作人/时间
                $locked->status = OutsourcingOrder::STATUS_APPROVED;
                $locked->operator = auth()->user()->name ?? '';
                $locked->approved_at = now();
                $locked->save();
                $result = ['no' => $locked->no];
            });
        } catch (ProductionException $e) {
            // 1523 幂等/工单状态不符 / 1522 库存不足（事务整体回滚）
            return $this->fail($e->getCode() ?: 1523, $e->getMessage());
        } catch (InventoryException $e) {
            // 余额引擎兜底拒绝（理论上被预校验拦截，防御路径）
            return $this->fail(1522, '库存不足，委外发出被拒绝');
        }

        return $this->ok($result);
    }

    /**
     * 回收：事务内「锁委外单（草稿不可回收 422；累计+本次 ≤ 委外量 1524，已回收单再回收必超收）→ 锁同单全部工序行
     * （id 升序，含委外工序：DAG 后继就绪判定需读其它前驱状态，与报工/完工在行级全序上单调同向）→ 锁工单行取成品 →
     * InventoryService 写 outsourcing_in 流水(+qty) → 创建回收单（创建即审核）→ 累计 ≥ 委外量 → 委外单已回收 +
     * 工序标记完成 + DAG 工单（routing_id 非空）推进「直接后继中全部前驱已完成」的待开工节点（并行分支独立推进，
     * 与 OperationReportController::store 同口径；旧工单仅置 DONE 不推进）」任一步失败整体回滚；
     * 锁序 outsourcing→全部工序(升序)→order 与报工（op 全集→order）/完工（全工序→order）行级单调同向，
     * 消除「末批回收 vs 工序报工」并发 ABBA 死锁环
     */
    public function storeReceipt(Request $request, OutsourcingOrder $outsourcing)
    {
        $data = $this->validatePayloadReceipt($request);
        if ((float) $data['quantity'] <= 0) {
            return $this->fail(422, '回收数量必须大于 0');
        }

        try {
            $result = null;
            // 事务第 2 参数为死锁(1213)重试次数（机理同 BomController::store：OSR 序列行首建
            // 间隙锁死锁败方整体回滚后重跑闭包重新取号+重发库存流水，幂等安全）
            DB::transaction(function () use ($outsourcing, $data, &$result) {
                // 锁委外单行：回收并发串行化（累计回收判定一致）；仅草稿不可回收（422）——
                // 已回收单放行到超收校验：再回收必然超收 → 1524（E2E TC-PRD-06 锁定）
                $locked = OutsourcingOrder::whereKey($outsourcing->id)->lockForUpdate()->firstOrFail();
                if ($locked->status === OutsourcingOrder::STATUS_DRAFT) {
                    throw new ProductionException('当前委外单不可回收', 422);
                }
                // 累计回收 + 本次 ≤ 委外量（超收 1524 整体回滚）
                $received = $this->receivedQty($locked->id);
                if (bccomp(bcadd($received, (string) $data['quantity'], 2), (string) $locked->quantity, 2) > 0) {
                    throw new ProductionException('回收数量超过委外数量', 1524);
                }
                // 锁同单全部工序行（id 升序，含委外工序，单条语句获取）：DAG 后继就绪判定需读其它前驱状态，
                // 与报工（op 全集→order）/完工（全工序→order）在行级全序上单调同向——若仅锁委外工序行，
                // 并发「末批回收 vs 分支报工」的获取序列非单调会构成 ABBA 死锁环（修复：RTG 委外推进）
                $allOps = WorkOrderOperation::where('order_id', $locked->order_id)
                    ->orderBy('id')->lockForUpdate()->get();
                // 委外工序行从已锁全集取（与报工控制器同构；行缺失防御性跳过）
                $op = $allOps->find($locked->operation_id);
                // 锁工单行取委外商品（= 工单成品）
                $order = ProductionOrder::whereKey($locked->order_id)->lockForUpdate()->firstOrFail();
                // 工单状态校验：与发出 approve 同口径 [RELEASED, PRODUCING]（spec §5.1 生产中→委外）
                if (! in_array($order->status, [ProductionOrder::STATUS_RELEASED, ProductionOrder::STATUS_PRODUCING], true)) {
                    throw new ProductionException('工单当前状态不可委外', 1523);
                }
                // 统一引擎写流水+加余额（同事务双写）
                $this->inventoryService->apply([[
                    'product_id' => $order->product_id,
                    'warehouse_id' => $data['warehouse_id'],
                    'location_id' => $data['location_id'],
                    'direction' => 1,
                    'quantity' => $data['quantity'],
                    'source_type' => 'outsourcing_in',
                    'source_id' => $locked->id,
                    'source_no' => '',
                    'remark' => '委外回收',
                ]], auth()->id());
                // 创建回收单（创建即审核）：单号 OSR 先占号再补流水单号（先建单号引用唯一）
                $receipt = $this->sequenceService->nextNoByConfig(
                    DocumentSequence::TYPE_OSR,
                    fn (string $no) => OutsourcingReceipt::create([
                        'no' => $no,
                        'outsourcing_id' => $locked->id,
                        'quantity' => $data['quantity'],
                        'warehouse_id' => $data['warehouse_id'],
                        'location_id' => $data['location_id'],
                        'status' => OutsourcingReceipt::STATUS_APPROVED,
                        'received_at' => now(),
                        'operator' => auth()->user()->name ?? '',
                        'remark' => $data['remark'] ?? null,
                    ]),
                    // legacyMax 只取当日最大单号一行（orderByDesc+value 单查，P1-5：同日前缀字典序=序号序）
                    fn (string $prefix, string $dateKey) => ($no = OutsourcingReceipt::where('no', 'like', $prefix.date('Ymd').'%')
                        ->orderByDesc('no')->value('no')) ? DocumentSequenceService::seqFromNo($no, $prefix, $dateKey) : 0,
                );
                // 流水单号回补（流水创建时回收单号未定，先以委外单号占位后回补——审计链完整）
                DB::table('inventory_movements')
                    ->where('source_type', 'outsourcing_in')
                    ->where('source_id', $locked->id)
                    ->where('source_no', '')
                    ->update(['source_no' => $receipt->no]);

                // 累计回收 ≥ 委外量 → 委外单已回收 + 委外工序标记完成（spec §6；回收只对未完成工序生效）；
                // DAG 工单（routing_id 非空）追加推进：直接后继中「全部前驱已完成」的待开工节点置进行中
                // （并行分支独立推进，与报工控制器同口径）；旧工单（routing_id 为空）仅置 DONE 不推进
                $receivedNow = bcadd($received, (string) $data['quantity'], 2);
                if (bccomp($receivedNow, (string) $locked->quantity, 2) >= 0) {
                    $locked->status = OutsourcingOrder::STATUS_RECEIVED;
                    $locked->save();
                    if ($op && $op->status !== WorkOrderOperation::STATUS_DONE) {
                        $op->status = WorkOrderOperation::STATUS_DONE;
                        if ($order->routing_id) {
                            // 边一次取出内存建邻接、前驱状态用已锁定的工序全集判定（§4.2.2 禁循环内查询）
                            $edges = WorkOrderOperationEdge::where('order_id', $order->id)->get();
                            // 已完成集合：全集中已 DONE 的行 + 本工序（本轮即将落 DONE，对后继就绪判定等效已完成）
                            $doneIds = [$op->id => true];
                            foreach ($allOps as $s) {
                                if ($s->status === WorkOrderOperation::STATUS_DONE) {
                                    $doneIds[$s->id] = true;
                                }
                            }
                            $byId = $allOps->keyBy('id');
                            $predsByTo = $edges->groupBy('to_operation_id');
                            foreach ($edges->where('from_operation_id', $op->id) as $edge) {
                                $succ = $byId->get($edge->to_operation_id);
                                if (! $succ || $succ->status !== WorkOrderOperation::STATUS_PENDING) {
                                    continue;
                                }
                                // 后继就绪判定：全部前驱均在已完成集合（空前驱不会出现——本节点即其前驱）
                                $allPredsDone = ($predsByTo->get($edge->to_operation_id) ?? collect())
                                    ->every(fn (WorkOrderOperationEdge $e) => isset($doneIds[$e->from_operation_id]));
                                if ($allPredsDone) {
                                    $succ->status = WorkOrderOperation::STATUS_RUNNING;
                                    $succ->save();
                                }
                            }
                        }
                        $op->save();
                    }
                }
                $result = ['no' => $receipt->no];
            }, 2);
        } catch (ProductionException $e) {
            // 1524 超收 / 422 状态不符（事务整体回滚）
            return $this->fail($e->getCode() ?: 422, $e->getMessage());
        } catch (InventoryException $e) {
            // 余额引擎兜底（入库方向理论不触发，防御路径）
            return $this->fail(422, '回收失败，请重试');
        }

        return $this->ok($result);
    }

    /** 回收记录列表：该委外单全部回收单（按回收时间倒序） */
    public function receipts(OutsourcingOrder $outsourcing)
    {
        $rows = $outsourcing->receipts()->orderByDesc('received_at')->paginate(max(1, min(100, (int) request('per_page', 10))));

        return $this->ok([
            'items' => $rows->map(fn (OutsourcingReceipt $r) => [
                'id' => $r->id,
                'no' => $r->no,
                'quantity' => $r->quantity,
                'warehouse_id' => $r->warehouse_id,
                'warehouse_name' => $r->warehouse?->name,
                'location_id' => $r->location_id,
                'location_name' => $r->location?->name,
                'received_at' => $r->received_at->toDateTimeString(),
                'operator' => $r->operator,
                'remark' => $r->remark,
            ]),
            'total' => $rows->total(), 'page' => $rows->currentPage(), 'per_page' => $rows->perPage(),
        ]);
    }

    // 已回收累计（Σ 回收单数量；SQL SUM 聚合——回收单创建即审核 status 恒 1，SUM 与逐行 bcadd 语义等价，
    // 跨库 SUM 返回形态不一（MySQL 字符串 / SQLite 数值）统一 bcmath 归一；P1-3）
    private function receivedQty(int $outsourcingId): string
    {
        $total = OutsourcingReceipt::where('outsourcing_id', $outsourcingId)
            ->selectRaw('SUM(quantity) as total')
            ->value('total');

        return $total === null ? '0' : bcadd((string) $total, '0', 2);
    }

    // 委外单载荷格式校验（422 仅格式层）；业务码在方法内检查；items 组件行归一化字符串供 bcmath 校验
    private function validatePayload(Request $request): array
    {
        $data = $request->validate([
            'order_id' => 'required|integer|exists:production_orders,id',
            'operation_id' => 'required|integer|exists:work_order_operations,id',
            'supplier_id' => 'nullable|integer|exists:suppliers,id',
            'warehouse_id' => 'nullable|integer|exists:warehouses,id',
            'location_id' => 'nullable|integer|exists:locations,id',
            'quantity' => 'required|numeric|regex:/^-?\d+(\.\d{1,2})?$/',
            'remark' => 'nullable|string|max:200',
            // 发料组件必填（载荷重构后草稿必带组件）；数量限两位小数，正则按字符串形态校验拦 1e2 科学计数
            'items' => 'required|array|min:1',
            'items.*.material_id' => 'required|integer|exists:products,id',
            'items.*.required_qty' => 'required|numeric|regex:/^\d+(\.\d{1,2})?$/',
            'items.*.unit_id' => 'required|integer|exists:units,id',
        ]);

        // 组件行类型归一：应发数量统一字符串（bcmath 权威比较），物料/单位回整型
        $data['items'] = array_map(
            fn (array $item) => [
                'material_id' => (int) $item['material_id'],
                'required_qty' => (string) $item['required_qty'],
                'unit_id' => (int) $item['unit_id'],
            ],
            (array) $data['items'],
        );

        return $data;
    }

    // 回收载荷格式校验（422 仅格式层）
    private function validatePayloadReceipt(Request $request): array
    {
        return $request->validate([
            'quantity' => 'required|numeric|regex:/^-?\d+(\.\d{1,2})?$/',
            'warehouse_id' => 'required|integer|exists:warehouses,id',
            'location_id' => 'required|integer|exists:locations,id',
            'remark' => 'nullable|string|max:200',
        ]);
    }
}
