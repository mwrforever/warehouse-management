<?php

// 退料单控制器：草稿 CRUD + 审核（核心：事务内锁物料需求行防超退 1517 + InventoryService 写 return 流水(+1) 冲销已领）

namespace App\Http\Controllers\Api;

use App\Exceptions\InventoryException;
use App\Exceptions\ProductionException;
use App\Http\Controllers\Controller;
use App\Models\DocumentSequence;
use App\Models\PickList;
use App\Models\ProductionOrder;
use App\Models\ProductionOrderMaterial;
use App\Models\ReturnList;
use App\Models\ReturnListItem;
use App\Services\DocumentSequenceService;
use App\Services\InventoryService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ReturnListController extends Controller
{
    use ApiResponse;

    public function __construct(
        private InventoryService $inventoryService,
        private DocumentSequenceService $sequenceService,
    ) {}

    /** 分页列表：单号/仓库/状态/日期范围 筛选；含工单单号与状态标签 */
    public function index(Request $request)
    {
        $query = ReturnList::query()
            ->join('production_orders', 'production_orders.id', '=', 'return_lists.order_id')
            ->select('return_lists.*', 'production_orders.no as order_no')
            ->orderByDesc('return_lists.id');

        if ($keyword = $request->input('keyword')) {
            $query->where('return_lists.no', 'like', "%{$keyword}%");
        }
        if ($request->filled('status')) {
            $query->where('return_lists.status', (int) $request->input('status'));
        }
        if ($request->filled('date_from')) {
            $query->whereDate('return_lists.created_at', '>=', $request->input('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('return_lists.created_at', '<=', $request->input('date_to'));
        }

        // 预加载仓库/库位：消除列表逐行懒加载 N+1（每页最多 100 行 → 2×N 条查询）
        $rows = $query->with(['warehouse', 'location'])->paginate(max(1, min(100, (int) $request->input('per_page', 10))));

        return $this->ok([
            // join 别名列经 getAttribute 读取（PHPStan 静态分析可识别）
            'items' => $rows->map(fn (ReturnList $r) => [
                'id' => $r->id,
                'no' => $r->no,
                'order_id' => $r->order_id,
                'order_no' => $r->getAttribute('order_no'),
                'warehouse_id' => $r->warehouse_id,
                'warehouse_name' => $r->warehouse?->name,
                'location_id' => $r->location_id,
                'location_name' => $r->location?->name,
                'status' => (int) $r->status,
                'status_label' => ReturnList::STATUS_LABELS[$r->status] ?? '未知',
                'approved_at' => $r->approved_at?->toDateTimeString(),
                'operator' => $r->operator,
                'created_at' => $r->created_at?->toDateTimeString(),
            ]),
            'total' => $rows->total(), 'page' => $rows->currentPage(), 'per_page' => $rows->perPage(),
        ]);
    }

    /** 新建草稿：明细非空/重复商品/数量>0/仓库库位 422；超已领总量 1517（草稿期即拦截） */
    public function store(Request $request)
    {
        $data = $this->validatePayload($request);
        if ($fail = $this->validateBusinessItems($data)) {
            return $fail;
        }
        if (! $request->filled('warehouse_id') || ! $request->filled('location_id')) {
            return $this->fail(422, '仓库与库位不能为空');
        }
        // 工单状态校验：退料允许生产中/已完成（完工余料退回，G1 放行）；草稿/已下达未领料、关闭后无操作（PRD-14）仍拒绝
        $order = ProductionOrder::find($data['order_id']);
        if (! $order || ! in_array($order->status, [ProductionOrder::STATUS_PRODUCING, ProductionOrder::STATUS_COMPLETED], true)) {
            return $this->fail(1517, '工单当前状态不可退料');
        }
        if ($fail = $this->validatePickBelongs($data)) {
            return $fail;
        }
        // 工单物料行一次预取：草稿期已领校验用（消除逐商品 N+1，P1-4）
        $materialMap = $this->materialMap((int) $data['order_id']);
        // 草稿期校验：逐行 ≤ 该商品已领总量（1517）
        if ($msg = $this->validateIssued($data['items'], $materialMap)) {
            return $this->fail(1517, $msg);
        }

        $return = DB::transaction(function () use ($data) {
            $return = $this->sequenceService->nextNo(
                DocumentSequence::TYPE_RL,
                'RL',
                fn (string $no) => ReturnList::create([
                    'no' => $no,
                    'order_id' => $data['order_id'],
                    'pick_id' => $data['pick_id'] ?? null,
                    'status' => ReturnList::STATUS_DRAFT,
                    'warehouse_id' => $data['warehouse_id'],
                    'location_id' => $data['location_id'],
                    'remark' => $data['remark'] ?? null,
                ]),
                // legacyMax 只取当日最大单号一行（orderByDesc+value 单查，P1-5：同日前缀字典序=序号序）
                fn () => ($no = ReturnList::where('no', 'like', 'RL'.date('Ymd').'-%')
                    ->orderByDesc('no')->value('no')) ? (int) substr($no, -3) : 0,
            );
            $return->items()->createMany(array_map(fn ($i) => [
                'product_id' => $i['product_id'],
                'quantity' => $i['quantity'],
            ], $data['items']));

            return $return;
        });

        return $this->ok(['no' => $return->no]);
    }

    /** 详情：头信息 + 明细（商品名/数量） */
    public function show(ReturnList $return)
    {
        return $this->ok([
            'id' => $return->id,
            'no' => $return->no,
            'order_id' => $return->order_id,
            'order_no' => $return->order?->no,
            'pick_id' => $return->pick_id,
            'pick_no' => $return->pick?->no,
            'status' => (int) $return->status,
            'status_label' => ReturnList::STATUS_LABELS[$return->status] ?? '未知',
            'warehouse_id' => $return->warehouse_id,
            'warehouse_name' => $return->warehouse?->name,
            'location_id' => $return->location_id,
            'location_name' => $return->location?->name,
            'approved_at' => $return->approved_at?->toDateTimeString(),
            'operator' => $return->operator,
            'remark' => $return->remark,
            'items' => $return->items()->with('product')->get()->map(fn (ReturnListItem $i) => [
                'id' => $i->id,
                'product_id' => $i->product_id,
                'product_name' => $i->product?->name,
                'product_code' => $i->product?->code,
                'quantity' => $i->quantity,
            ]),
        ]);
    }

    /** 更新草稿：仅草稿（1518）；校验同 store；事务内锁行复查防并发 */
    public function update(Request $request, ReturnList $return)
    {
        try {
            if ($return->status !== ReturnList::STATUS_DRAFT) {
                return $this->fail(1518, '已审核单据不可修改');
            }
            $data = $this->validatePayload($request);
            if ($fail = $this->validateBusinessItems($data)) {
                return $fail;
            }
            if (! $request->filled('warehouse_id') || ! $request->filled('location_id')) {
                return $this->fail(422, '仓库与库位不能为空');
            }
            // 工单状态校验：生产中/已完成可退料（同 store 口径，G1 完工余料退回放行）
            $order = ProductionOrder::find($data['order_id']);
            if (! $order || ! in_array($order->status, [ProductionOrder::STATUS_PRODUCING, ProductionOrder::STATUS_COMPLETED], true)) {
                return $this->fail(1517, '工单当前状态不可退料');
            }
            if ($fail = $this->validatePickBelongs($data)) {
                return $fail;
            }
            // 工单物料行一次预取（同 store 口径，P1-4）
            $materialMap = $this->materialMap((int) $data['order_id']);
            if ($msg = $this->validateIssued($data['items'], $materialMap)) {
                return $this->fail(1517, $msg);
            }

            DB::transaction(function () use ($return, $data) {
                // 锁退料单行复查状态（幂等 1518）
                $locked = ReturnList::whereKey($return->id)->lockForUpdate()->firstOrFail();
                if ($locked->status !== ReturnList::STATUS_DRAFT) {
                    throw new ProductionException('已审核单据不可修改', 1518);
                }
                $locked->update([
                    'order_id' => $data['order_id'],
                    'pick_id' => $data['pick_id'] ?? $locked->pick_id,
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
            // 1518 已审核（锁行复查与并发审核幂等拦截）
            return $this->fail($e->getCode() ?: 1518, $e->getMessage());
        }

        return $this->ok();
    }

    /** 删除草稿：仅草稿（1518）；事务内锁行复查防并发 */
    public function destroy(ReturnList $return)
    {
        try {
            if ($return->status !== ReturnList::STATUS_DRAFT) {
                return $this->fail(1518, '已审核单据不可删除');
            }
            DB::transaction(function () use ($return) {
                // 锁退料单行复查状态（幂等 1518）
                $locked = ReturnList::whereKey($return->id)->lockForUpdate()->firstOrFail();
                if ($locked->status !== ReturnList::STATUS_DRAFT) {
                    throw new ProductionException('已审核单据不可删除', 1518);
                }
                $locked->delete();
            });
        } catch (ProductionException $e) {
            // 1518 已审核（锁行复查与并发审核幂等拦截）
            return $this->fail($e->getCode() ?: 1518, $e->getMessage());
        }

        return $this->ok();
    }

    /**
     * 审核（核心）：事务内「锁单幂等 1519 → 逐行锁物料需求行复核 1517 → InventoryService 写 return 流水(+1)
     * → 冲销 issued_qty」任一步失败整体回滚（入库方向无需余额校验）
     */
    public function approve(ReturnList $return)
    {
        try {
            $result = null;
            DB::transaction(function () use ($return, &$result) {
                // 锁退料单行：同一单据重复审核在此判重（幂等 1519）
                $locked = ReturnList::whereKey($return->id)->lockForUpdate()->firstOrFail();
                if ($locked->status === ReturnList::STATUS_APPROVED) {
                    throw new ProductionException('该退料单已审核', 1519);
                }
                // 锁工单行校验状态：生产中/已完成可退料（同 store 口径，G1 完工余料退回放行）；
                // 锁序 单据行→工单行→物料行（全局无「物料→工单」反向路径，与领料审核同构，无 ABBA 环）
                $order = ProductionOrder::whereKey($locked->order_id)->lockForUpdate()->firstOrFail();
                if (! in_array($order->status, [ProductionOrder::STATUS_PRODUCING, ProductionOrder::STATUS_COMPLETED], true)) {
                    throw new ProductionException('工单当前状态不可退料', 1519);
                }
                $movements = [];
                $writeOff = []; // [material_id => 本次冲销量] 待回写
                /** @var ReturnListItem $item */
                foreach ($locked->items as $item) {
                    // 锁物料需求行：防并发超退（多张退料单同时审同一物料时串行化）
                    $pm = ProductionOrderMaterial::where('order_id', $locked->order_id)
                        ->where('material_id', $item->product_id)
                        ->lockForUpdate()
                        ->first();
                    if (! $pm) {
                        throw new ProductionException('退料数量超过已领数量', 1517);
                    }
                    // 本次退料 ≤ 当前已领（草稿期校验后已领可能被并发冲销，审核期锁行复核）
                    if (bccomp((string) $item->quantity, (string) $pm->issued_qty, 2) > 0) {
                        throw new ProductionException('退料数量超过已领数量', 1517);
                    }
                    $writeOff[$item->product_id] = bcadd((string) ($writeOff[$item->product_id] ?? '0'), (string) $item->quantity, 2);
                    $movements[] = [
                        'product_id' => $item->product_id,
                        'warehouse_id' => $locked->warehouse_id,
                        'location_id' => $locked->location_id,
                        'direction' => 1,
                        'quantity' => $item->quantity,
                        'source_type' => 'return',
                        'source_id' => $locked->id,
                        'source_no' => $locked->no,
                        'remark' => '生产退料',
                    ];
                }
                // 统一引擎写流水+加余额（同事务双写）
                $this->inventoryService->apply($movements, auth()->id());
                // 冲销工单物料需求 issued_qty（bcmath 减法）
                foreach ($writeOff as $materialId => $qty) {
                    $pm = ProductionOrderMaterial::where('order_id', $locked->order_id)
                        ->where('material_id', $materialId)->firstOrFail();
                    $pm->issued_qty = bcsub((string) $pm->issued_qty, $qty, 2);
                    $pm->save();
                }
                // 置已审核 + 审核人/时间
                $locked->status = ReturnList::STATUS_APPROVED;
                $locked->operator = auth()->user()->name ?? '';
                $locked->approved_at = now();
                $locked->save();
                $result = ['no' => $locked->no];
            });
        } catch (ProductionException $e) {
            // 1519 幂等 / 1517 超已领（事务整体回滚）
            return $this->fail($e->getCode() ?: 1517, $e->getMessage());
        } catch (InventoryException $e) {
            // 余额引擎兜底（入库方向理论不触发，防御路径）
            return $this->fail(1517, '退料失败，请重试');
        }

        return $this->ok($result);
    }

    // 载荷格式校验（422 仅格式层）；业务码在方法内检查
    private function validatePayload(Request $request): array
    {
        return $request->validate([
            'order_id' => 'required|integer|exists:production_orders,id',
            'pick_id' => 'nullable|integer|exists:pick_lists,id',
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
                return $this->fail(422, '退料数量必须大于 0');
            }
            if (isset($seen[$item['product_id']])) {
                return $this->fail(422, '明细存在重复商品');
            }
            $seen[$item['product_id']] = true;
        }

        return null;
    }

    // pick_id 归属校验：领料单必须属于同一工单（防跨工单挂单，追溯语义错乱；
    // 与 OutsourcingController「工序不属于该工单」同款 422 惯例，spec 码段满）
    private function validatePickBelongs(array $data): ?JsonResponse
    {
        if (! empty($data['pick_id'])) {
            $belongs = PickList::whereKey($data['pick_id'])->where('order_id', $data['order_id'])->exists();
            if (! $belongs) {
                return $this->fail(422, '领料单不属于该工单');
            }
        }

        return null;
    }

    // 工单物料行预取（P1-4）：store/update 的草稿期已领校验共用一次查询，消除逐商品 N+1
    private function materialMap(int $orderId): Collection
    {
        return ProductionOrderMaterial::where('order_id', $orderId)->get()->keyBy('material_id');
    }

    // 草稿期已领校验：逐行 ≤ 该商品已领总量（1517），返回错误文案或 null（物料行取自预取 map）
    private function validateIssued(array $items, Collection $materialMap): ?string
    {
        foreach ($items as $item) {
            $pm = $materialMap->get($item['product_id']);
            if (! $pm || bccomp((string) $item['quantity'], (string) $pm->issued_qty, 2) > 0) {
                return '退料数量超过已领数量';
            }
        }

        return null;
    }
}
