<?php

// 领料单控制器：分页列表/从工单预填/详情 读取 + 草稿 CRUD/审核/发料 薄壳（写流程全部下沉 PickListService）

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Production\SavePickListRequest;
use App\Models\PickList;
use App\Models\PickListItem;
use App\Models\ProductionOrder;
use App\Models\ProductionOrderMaterial;
use App\Services\PickListService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class PickListController extends Controller
{
    use ApiResponse;

    public function __construct(private PickListService $pickListService) {}

    /** 分页列表：单号/仓库/状态/日期范围 筛选；含工单单号/仓库名/状态与发料标签 */
    public function index(Request $request)
    {
        $query = PickList::query()
            ->join('production_orders', 'production_orders.id', '=', 'pick_lists.order_id')
            ->join('warehouses', 'warehouses.id', '=', 'pick_lists.warehouse_id')
            ->select(
                // 显式列出列表所需主表列（与下方 map 闭包字段一一对应），避免 select 通配拉取未列字段
                'pick_lists.id',
                'pick_lists.no',
                'pick_lists.order_id',
                'pick_lists.warehouse_id',
                'pick_lists.status',
                'pick_lists.issue_status',
                'pick_lists.approved_at',
                'pick_lists.operator',
                'pick_lists.created_at',
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
    public function store(SavePickListRequest $request)
    {
        $pick = $this->pickListService->create($request->validated());

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
    public function update(SavePickListRequest $request, PickList $pick)
    {
        $this->pickListService->update($pick, $request->validated());

        return $this->ok();
    }

    /** 删除草稿：仅草稿（1514）；事务内锁行复查防并发 */
    public function destroy(PickList $pick)
    {
        $this->pickListService->delete($pick);

        return $this->ok();
    }

    /**
     * 审核（核心）：事务内「锁单幂等 1516 → 批量预锁物料需求行复核 1513 → 批量预锁余额行校验充足 1515
     * → InventoryService 扣库存（pick, -1）→ 回写 issued_qty」任一步失败整体回滚
     */
    public function approve(PickList $pick)
    {
        return $this->ok($this->pickListService->approve($pick));
    }

    /** 发料：仅已审核可发（422）；V1 一次发完——issue_status 置「全部发料」，明细行 issued_qty 回写 */
    public function issue(PickList $pick)
    {
        return $this->ok($this->pickListService->issue($pick));
    }
}
