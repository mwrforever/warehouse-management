<?php

// 委外加工控制器：草稿 CRUD + 发出（审核：锁余额行防超卖 1522）+ 回收（创建即审核回收单 + 工序联动）
// 委外商品 = 工单成品（spec 数据模型无 product_id，E2E TC-PRD-06 锁定）

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
use App\Services\DocumentSequenceService;
use App\Services\InventoryService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OutsourcingController extends Controller
{
    use ApiResponse;

    public function __construct(
        private InventoryService $inventoryService,
        private DocumentSequenceService $sequenceService,
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

    /** 新建草稿：委外量 ≤ 工单计划数（1520）；工序必须属于该工单（422） */
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
        // 草稿期校验：委外量 ≤ 工单计划数（1520）
        $order = ProductionOrder::find($data['order_id']);
        if (! $order || bccomp((string) $data['quantity'], (string) $order->quantity, 2) > 0) {
            return $this->fail(1520, '委外数量超过工单计划数量');
        }
        // 工序必须属于该工单（防跨单挂工序）
        $op = WorkOrderOperation::where('id', $data['operation_id'])->where('order_id', $data['order_id'])->first();
        if (! $op) {
            return $this->fail(422, '工序不属于该工单');
        }

        $os = DB::transaction(function () use ($data) {
            $os = $this->sequenceService->nextNo(
                DocumentSequence::TYPE_OS,
                'OS',
                fn (string $no) => OutsourcingOrder::create([
                    'no' => $no,
                    'order_id' => $data['order_id'],
                    'operation_id' => $data['operation_id'],
                    'supplier_id' => $data['supplier_id'],
                    'status' => OutsourcingOrder::STATUS_DRAFT,
                    'warehouse_id' => $data['warehouse_id'],
                    'location_id' => $data['location_id'],
                    'quantity' => $data['quantity'],
                    'remark' => $data['remark'] ?? null,
                ]),
                // legacyMax 只取当日最大单号一行（orderByDesc+value 单查，P1-5：同日前缀字典序=序号序）
                fn () => ($no = OutsourcingOrder::where('no', 'like', 'OS'.date('Ymd').'-%')
                    ->orderByDesc('no')->value('no')) ? (int) substr($no, -3) : 0,
            );

            return $os;
        });

        return $this->ok(['no' => $os->no]);
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

    /** 更新草稿：仅草稿（1521）；校验同 store；事务内锁行复查防并发 */
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
            $order = ProductionOrder::find($data['order_id']);
            if (! $order || bccomp((string) $data['quantity'], (string) $order->quantity, 2) > 0) {
                return $this->fail(1520, '委外数量超过工单计划数量');
            }
            $op = WorkOrderOperation::where('id', $data['operation_id'])->where('order_id', $data['order_id'])->first();
            if (! $op) {
                return $this->fail(422, '工序不属于该工单');
            }

            DB::transaction(function () use ($outsourcing, $data) {
                // 锁委外单行复查状态（幂等 1521）
                $locked = OutsourcingOrder::whereKey($outsourcing->id)->lockForUpdate()->firstOrFail();
                if ($locked->status !== OutsourcingOrder::STATUS_DRAFT) {
                    throw new ProductionException('已审核单据不可修改', 1521);
                }
                $locked->update([
                    'order_id' => $data['order_id'],
                    'operation_id' => $data['operation_id'],
                    'supplier_id' => $data['supplier_id'],
                    'warehouse_id' => $data['warehouse_id'],
                    'location_id' => $data['location_id'],
                    'quantity' => $data['quantity'],
                    'remark' => $data['remark'] ?? $locked->remark,
                ]);
            });
        } catch (ProductionException $e) {
            // 1521 已审核（锁行复查与并发审核幂等拦截）
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
     * 回收：事务内「锁委外单（草稿不可回收 422；累计+本次 ≤ 委外量 1524，已回收单再回收必超收）→ 锁委外工序行
     * → 锁工单行取成品 → InventoryService 写 outsourcing_in 流水(+qty) → 创建回收单（创建即审核）
     * → 累计 ≥ 委外量 → 委外单已回收 + 工序标记完成」任一步失败整体回滚；
     * 锁序 outsourcing→op→order 与报工（op→order）在 op→order 段同序，消除 ABBA 死锁环
     */
    public function storeReceipt(Request $request, OutsourcingOrder $outsourcing)
    {
        $data = $this->validatePayloadReceipt($request);
        if ((float) $data['quantity'] <= 0) {
            return $this->fail(422, '回收数量必须大于 0');
        }

        try {
            $result = null;
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
                // 锁委外工序行（锁 order 之前无条件获取）：锁序 outsourcing→op→order 与报工（op→order）
                // 在 op→order 段同序，消除「末批回收 vs 工序报工」并发 ABBA 死锁环；工序行防御性存在检查
                $op = WorkOrderOperation::whereKey($locked->operation_id)->lockForUpdate()->first();
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
                $receipt = $this->sequenceService->nextNo(
                    DocumentSequence::TYPE_OSR,
                    'OSR',
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
                    fn () => ($no = OutsourcingReceipt::where('no', 'like', 'OSR'.date('Ymd').'-%')
                        ->orderByDesc('no')->value('no')) ? (int) substr($no, -3) : 0,
                );
                // 流水单号回补（流水创建时回收单号未定，先以委外单号占位后回补——审计链完整）
                DB::table('inventory_movements')
                    ->where('source_type', 'outsourcing_in')
                    ->where('source_id', $locked->id)
                    ->where('source_no', '')
                    ->update(['source_no' => $receipt->no]);

                // 累计回收 ≥ 委外量 → 委外单已回收 + 委外工序标记完成（spec §6；回收只对未完成工序生效）
                $receivedNow = bcadd($received, (string) $data['quantity'], 2);
                if (bccomp($receivedNow, (string) $locked->quantity, 2) >= 0) {
                    $locked->status = OutsourcingOrder::STATUS_RECEIVED;
                    $locked->save();
                    // 工序行已在事务内先行锁定（锁序 outsourcing→op→order），直接更新不重复加锁（缺失防御性跳过）
                    if ($op && $op->status !== WorkOrderOperation::STATUS_DONE) {
                        $op->status = WorkOrderOperation::STATUS_DONE;
                        $op->save();
                    }
                }
                $result = ['no' => $receipt->no];
            });
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

    // 委外单载荷格式校验（422 仅格式层）；业务码在方法内检查
    private function validatePayload(Request $request): array
    {
        return $request->validate([
            'order_id' => 'required|integer|exists:production_orders,id',
            'operation_id' => 'required|integer|exists:work_order_operations,id',
            'supplier_id' => 'nullable|integer|exists:suppliers,id',
            'warehouse_id' => 'nullable|integer|exists:warehouses,id',
            'location_id' => 'nullable|integer|exists:locations,id',
            'quantity' => 'required|numeric|regex:/^-?\d+(\.\d{1,2})?$/',
            'remark' => 'nullable|string|max:200',
        ]);
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
