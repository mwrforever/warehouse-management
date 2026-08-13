<?php

// 领料单控制器：草稿 CRUD + from-order 预填 + 审核（核心：事务内锁物料需求行防超领 1513 + 锁余额行防超卖 1515）+ 发料

namespace App\Http\Controllers\Api;

use App\Exceptions\InventoryException;
use App\Exceptions\ProductionException;
use App\Http\Controllers\Controller;
use App\Models\DocumentSequence;
use App\Models\InventoryBalance;
use App\Models\PickList;
use App\Models\PickListItem;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\ProductionOrderMaterial;
use App\Services\DocumentSequenceService;
use App\Services\InventoryService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PickListController extends Controller
{
    use ApiResponse;

    public function __construct(
        private InventoryService $inventoryService,
        private DocumentSequenceService $sequenceService,
    ) {}

    /** 分页列表：单号/仓库/状态/日期范围 筛选；含工单单号/仓库名/状态与发料标签 */
    public function index(Request $request)
    {
        $query = PickList::query()
            ->join('production_orders', 'production_orders.id', '=', 'pick_lists.order_id')
            ->join('warehouses', 'warehouses.id', '=', 'pick_lists.warehouse_id')
            ->select(
                'pick_lists.*',
                'production_orders.no as order_no',
                'warehouses.name as warehouse_name',
            )
            ->orderByDesc('pick_lists.id');

        if ($keyword = $request->input('keyword')) {
            $query->where('pick_lists.no', 'like', "%{$keyword}%");
        }
        if ($request->filled('status')) {
            $query->where('pick_lists.status', (int) $request->input('status'));
        }
        if ($request->filled('date_from')) {
            $query->whereDate('pick_lists.created_at', '>=', $request->input('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('pick_lists.created_at', '<=', $request->input('date_to'));
        }

        $rows = $query->paginate(max(1, min(100, (int) $request->input('per_page', 10))));

        return $this->ok([
            // join 别名列经 getAttribute 读取（PHPStan 静态分析可识别；多 join 分页泛型不可解析，闭包不写类型提示）
            'items' => $rows->map(fn ($p) => [
                'id' => $p->id,
                'no' => $p->no,
                'order_id' => $p->order_id,
                'order_no' => $p->getAttribute('order_no'),
                'warehouse_id' => $p->warehouse_id,
                'warehouse_name' => $p->getAttribute('warehouse_name'),
                'status' => (int) $p->status,
                'status_label' => PickList::STATUS_LABELS[$p->status] ?? '未知',
                'issue_status' => (int) $p->issue_status,
                'issue_status_label' => PickList::ISSUE_LABELS[$p->issue_status] ?? '未知',
                'approved_at' => $p->approved_at?->toDateTimeString(),
                'operator' => $p->operator,
                'created_at' => $p->created_at?->toDateTimeString(),
            ]),
            'total' => $rows->total(), 'page' => $rows->currentPage(), 'per_page' => $rows->perPage(),
        ]);
    }

    /** 「从工单生成」预填：工单头 + 物料需求（剩余量 = 需求 - 已领） */
    public function fromOrder(int $orderId)
    {
        $order = ProductionOrder::with('product')->find($orderId);
        if (! $order) {
            return $this->fail(422, '工单不存在');
        }
        $items = $order->materials()->with('material')->orderBy('id')->get()
            ->map(fn (ProductionOrderMaterial $m) => [
                'product_id' => $m->material_id,
                'product_name' => $m->material?->name,
                'product_code' => $m->material?->code,
                'required_qty' => $m->required_qty,
                'issued_qty' => $m->issued_qty,
                // 剩余量 = 需求 - 已领（bcmath 精确）
                'remaining_qty' => bcsub((string) $m->required_qty, (string) $m->issued_qty, 2),
            ]);

        return $this->ok([
            'order_id' => $order->id,
            'order_no' => $order->no,
            'product_id' => $order->product_id,
            'product_name' => $order->product?->name,
            'items' => $items,
        ]);
    }

    /** 新建草稿：明细非空/重复商品/数量>0 走 422；超需求剩余 1513（草稿期即拦截） */
    public function store(Request $request)
    {
        $data = $this->validatePayload($request);
        // 明细业务校验（422 格式层：空明细/重复商品/数量≤0/仓库库位缺失）
        if ($fail = $this->validateBusinessItems($data)) {
            return $fail;
        }
        if (! $request->filled('warehouse_id') || ! $request->filled('location_id')) {
            return $this->fail(422, '仓库与库位不能为空');
        }
        // 草稿期校验：逐行 ≤ 需求剩余（1513）
        if ($msg = $this->validateRemaining((int) $data['order_id'], $data['items'])) {
            return $this->fail(1513, $msg);
        }

        $pick = DB::transaction(function () use ($data) {
            $pick = $this->sequenceService->nextNo(
                DocumentSequence::TYPE_PL,
                'PL',
                fn (string $no) => PickList::create([
                    'no' => $no,
                    'order_id' => $data['order_id'],
                    'status' => PickList::STATUS_DRAFT,
                    'issue_status' => PickList::ISSUE_NONE,
                    'warehouse_id' => $data['warehouse_id'],
                    'location_id' => $data['location_id'],
                    'remark' => $data['remark'] ?? null,
                ]),
                fn () => (int) (PickList::where('no', 'like', 'PL'.date('Ymd').'-%')
                    ->get('no')->map(fn ($p) => (int) substr((string) $p->no, -3))->max() ?? 0),
            );
            // 明细行：需求快照 + 本次领用量
            $pick->items()->createMany(array_map(fn ($i) => [
                'product_id' => $i['product_id'],
                'required_qty' => $this->requiredQty((int) $data['order_id'], (int) $i['product_id']),
                'pick_qty' => $i['pick_qty'],
                'issued_qty' => 0,
            ], $data['items']));

            return $pick;
        });

        return $this->ok(['no' => $pick->no]);
    }

    /** 详情：头信息 + 明细（商品名/需求/本次领用/已发） */
    public function show(PickList $pick)
    {
        return $this->ok([
            'id' => $pick->id,
            'no' => $pick->no,
            'order_id' => $pick->order_id,
            'order_no' => $pick->order?->no,
            'status' => (int) $pick->status,
            'status_label' => PickList::STATUS_LABELS[$pick->status] ?? '未知',
            'issue_status' => (int) $pick->issue_status,
            'issue_status_label' => PickList::ISSUE_LABELS[$pick->issue_status] ?? '未知',
            'warehouse_id' => $pick->warehouse_id,
            'warehouse_name' => $pick->warehouse?->name,
            'location_id' => $pick->location_id,
            'location_name' => $pick->location?->name,
            'approved_at' => $pick->approved_at?->toDateTimeString(),
            'operator' => $pick->operator,
            'remark' => $pick->remark,
            'items' => $pick->items()->with('product')->get()->map(fn (PickListItem $i) => [
                'id' => $i->id,
                'product_id' => $i->product_id,
                'product_name' => $i->product?->name,
                'product_code' => $i->product?->code,
                'required_qty' => $i->required_qty,
                'pick_qty' => $i->pick_qty,
                'issued_qty' => $i->issued_qty,
            ]),
        ]);
    }

    /** 更新草稿：仅草稿（1514）；校验同 store；事务内锁行复查防并发 */
    public function update(Request $request, PickList $pick)
    {
        try {
            if ($pick->status !== PickList::STATUS_DRAFT) {
                return $this->fail(1514, '已审核单据不可修改');
            }
            $data = $this->validatePayload($request);
            if ($fail = $this->validateBusinessItems($data)) {
                return $fail;
            }
            if (! $request->filled('warehouse_id') || ! $request->filled('location_id')) {
                return $this->fail(422, '仓库与库位不能为空');
            }
            if ($msg = $this->validateRemaining((int) $data['order_id'], $data['items'])) {
                return $this->fail(1513, $msg);
            }

            DB::transaction(function () use ($pick, $data) {
                // 锁领料单行复查状态：与审核并发时防止改到正在审核的单（幂等 1514）
                $locked = PickList::whereKey($pick->id)->lockForUpdate()->firstOrFail();
                if ($locked->status !== PickList::STATUS_DRAFT) {
                    throw new ProductionException('已审核单据不可修改', 1514);
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
                    'required_qty' => $this->requiredQty((int) $data['order_id'], (int) $i['product_id']),
                    'pick_qty' => $i['pick_qty'],
                    'issued_qty' => 0,
                ], $data['items']));
            });
        } catch (ProductionException $e) {
            // 1514 已审核（锁行复查与并发审核幂等拦截）
            return $this->fail($e->getCode() ?: 1514, $e->getMessage());
        }

        return $this->ok();
    }

    /** 删除草稿：仅草稿（1514）；事务内锁行复查防并发 */
    public function destroy(PickList $pick)
    {
        try {
            if ($pick->status !== PickList::STATUS_DRAFT) {
                return $this->fail(1514, '已审核单据不可删除');
            }
            DB::transaction(function () use ($pick) {
                // 锁领料单行复查状态（幂等 1514）
                $locked = PickList::whereKey($pick->id)->lockForUpdate()->firstOrFail();
                if ($locked->status !== PickList::STATUS_DRAFT) {
                    throw new ProductionException('已审核单据不可删除', 1514);
                }
                $locked->delete();
            });
        } catch (ProductionException $e) {
            // 1514 已审核（锁行复查与并发审核幂等拦截）
            return $this->fail($e->getCode() ?: 1514, $e->getMessage());
        }

        return $this->ok();
    }

    /**
     * 审核（核心）：事务内「锁单幂等 1516 → 逐行锁物料需求行复核 1513 → 逐行锁余额行校验充足 1515
     * → InventoryService 扣库存（pick, -1）→ 回写 issued_qty」任一步失败整体回滚
     */
    public function approve(PickList $pick)
    {
        try {
            $result = null;
            DB::transaction(function () use ($pick, &$result) {
                // 锁领料单行：同一单据重复审核在此判重（幂等 1516）
                $locked = PickList::whereKey($pick->id)->lockForUpdate()->firstOrFail();
                if ($locked->status === PickList::STATUS_APPROVED) {
                    throw new ProductionException('该领料单已审核', 1516);
                }
                $movements = [];
                $issueMap = []; // [material_id => 本次领用累计] 待回写
                /** @var PickListItem $item */
                foreach ($locked->items as $item) {
                    // 锁物料需求行：防并发超领（两张领料单同时审同一物料时串行化）
                    $pm = ProductionOrderMaterial::where('order_id', $locked->order_id)
                        ->where('material_id', $item->product_id)
                        ->lockForUpdate()
                        ->first();
                    if (! $pm) {
                        throw new ProductionException('领料数量超过需求数量', 1513);
                    }
                    // 剩余 = 需求 - 已领；本次超剩余 → 1513 整体回滚（防超领）
                    $remaining = bcsub((string) $pm->required_qty, (string) $pm->issued_qty, 2);
                    if (bccomp((string) $item->pick_qty, $remaining, 2) > 0) {
                        throw new ProductionException('领料数量超过需求数量', 1513);
                    }
                    $issueMap[$item->product_id] = bcadd((string) ($issueMap[$item->product_id] ?? '0'), (string) $item->pick_qty, 2);
                    // 防超卖：锁余额行校验（并发审核同一商品在此串行化；消息含商品编码与精确库存快照）
                    $balance = InventoryBalance::where('product_id', $item->product_id)
                        ->where('warehouse_id', $locked->warehouse_id)
                        ->where('location_id', $locked->location_id)
                        ->lockForUpdate()
                        ->first();
                    $current = $balance ? (string) $balance->quantity : '0';
                    if (bccomp((string) $item->pick_qty, $current, 2) > 0) {
                        // 库存快照去掉小数尾零展示（14.00 → 14；0.00 → 0），消息用商品编码（E2E 断言 MAT-001）
                        $qtyText = rtrim(rtrim($current, '0'), '.');
                        // ?? 左值天然 null 安全（find 无结果时回退 #id 展示），nullsafe 显式多余故用 ->
                        $code = Product::find($item->product_id)->code ?? ('#'.$item->product_id);
                        throw new ProductionException("商品[{$code}]库存不足", 1515);
                    }
                    $movements[] = [
                        'product_id' => $item->product_id,
                        'warehouse_id' => $locked->warehouse_id,
                        'location_id' => $locked->location_id,
                        'direction' => -1,
                        'quantity' => $item->pick_qty,
                        'source_type' => 'pick',
                        'source_id' => $locked->id,
                        'source_no' => $locked->no,
                        'remark' => '生产领料',
                    ];
                }
                // 统一引擎写流水+扣余额（同事务双写；余额行已被本事务锁定，引擎内重复加锁幂等）
                $this->inventoryService->apply($movements, auth()->id());
                // 回写工单物料需求 issued_qty（bcmath 累加）
                foreach ($issueMap as $materialId => $qty) {
                    $pm = ProductionOrderMaterial::where('order_id', $locked->order_id)
                        ->where('material_id', $materialId)->firstOrFail();
                    $pm->issued_qty = bcadd((string) $pm->issued_qty, $qty, 2);
                    $pm->save();
                }
                // 置已审核 + 审核人/时间
                $locked->status = PickList::STATUS_APPROVED;
                $locked->operator = auth()->user()->name ?? '';
                $locked->approved_at = now();
                $locked->save();
                $result = ['no' => $locked->no];
            });
        } catch (ProductionException $e) {
            // 1516 幂等 / 1513 超需求剩余 / 1515 库存不足（事务整体回滚）
            return $this->fail($e->getCode() ?: 1513, $e->getMessage());
        } catch (InventoryException $e) {
            // 余额引擎兜底拒绝（理论上被预校验拦截，防御路径）
            return $this->fail(1515, '库存不足，领料被拒绝');
        }

        return $this->ok($result);
    }

    /** 发料：仅已审核可发（422）；V1 一次发完——issue_status 置「全部发料」，明细行 issued_qty 回写 */
    public function issue(PickList $pick)
    {
        if ($pick->status !== PickList::STATUS_APPROVED) {
            return $this->fail(422, '请先审核领料单');
        }
        // 防重复发料：已全部发料直接返回当前状态（幂等）
        if ($pick->issue_status === PickList::ISSUE_ALL) {
            return $this->ok(['issue_status' => PickList::ISSUE_LABELS[$pick->issue_status]]);
        }
        DB::transaction(function () use ($pick) {
            // 锁领料单行复查状态（并发审核/发料串行化）
            $locked = PickList::whereKey($pick->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== PickList::STATUS_APPROVED) {
                throw new ProductionException('请先审核领料单', 422);
            }
            $locked->issue_status = PickList::ISSUE_ALL;
            $locked->save();
            // 明细行已发量 = 本次领用（一次发完语义）
            foreach ($locked->items as $item) {
                $item->issued_qty = $item->pick_qty;
                $item->save();
            }
        });

        return $this->ok(['issue_status' => PickList::ISSUE_LABELS[PickList::ISSUE_ALL]]);
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
            // 数量限两位小数（正则防科学计数法；负值形态放行到方法内 422）
            'items.*.pick_qty' => 'required|numeric|regex:/^-?\d+(\.\d{1,2})?$/',
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
            if ((float) $item['pick_qty'] <= 0) {
                return $this->fail(422, '领料数量必须大于 0');
            }
            if (isset($seen[$item['product_id']])) {
                return $this->fail(422, '明细存在重复商品');
            }
            $seen[$item['product_id']] = true;
        }

        return null;
    }

    // 草稿期剩余量校验：逐行 ≤ 需求剩余（1513），返回错误文案或 null
    private function validateRemaining(int $orderId, array $items): ?string
    {
        foreach ($items as $item) {
            $pm = ProductionOrderMaterial::where('order_id', $orderId)
                ->where('material_id', $item['product_id'])->first();
            if (! $pm) {
                return '领料数量超过需求数量';
            }
            $remaining = bcsub((string) $pm->required_qty, (string) $pm->issued_qty, 2);
            if (bccomp((string) $item['pick_qty'], $remaining, 2) > 0) {
                return '领料数量超过需求数量';
            }
        }

        return null;
    }

    // 物料需求数量（明细行快照：生成时点工单物料需求）
    private function requiredQty(int $orderId, int $productId): string
    {
        $pm = ProductionOrderMaterial::where('order_id', $orderId)->where('material_id', $productId)->first();

        return $pm ? (string) $pm->required_qty : '0';
    }
}
