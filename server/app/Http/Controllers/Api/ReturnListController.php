<?php

// 退料单控制器：分页列表/详情 读取 + 草稿 CRUD/审核 薄壳（写流程全部下沉 ReturnListService）

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Production\SaveReturnListRequest;
use App\Models\ReturnList;
use App\Models\ReturnListItem;
use App\Services\ReturnListService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class ReturnListController extends Controller
{
    use ApiResponse;

    public function __construct(private ReturnListService $returnListService) {}

    /** 分页列表：单号/仓库/状态/日期范围 筛选；含工单单号与状态标签 */
    public function index(Request $request)
    {
        $query = ReturnList::query()
            ->join('production_orders', 'production_orders.id', '=', 'return_lists.order_id')
            // 显式列出列表所需主表列（与下方 map 闭包字段一一对应），避免 select 通配拉取未列字段
            ->select(
                'return_lists.id',
                'return_lists.no',
                'return_lists.order_id',
                'return_lists.warehouse_id',
                'return_lists.location_id',
                'return_lists.status',
                'return_lists.approved_at',
                'return_lists.operator',
                'return_lists.created_at',
                'production_orders.no as order_no',
            )
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
    public function store(SaveReturnListRequest $request)
    {
        $return = $this->returnListService->create($request->validated());

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
    public function update(SaveReturnListRequest $request, ReturnList $return)
    {
        $this->returnListService->update($return, $request->validated());

        return $this->ok();
    }

    /** 删除草稿：仅草稿（1518）；事务内锁行复查防并发 */
    public function destroy(ReturnList $return)
    {
        $this->returnListService->delete($return);

        return $this->ok();
    }

    /**
     * 审核（核心）：事务内「锁单幂等 1519 → 批量预锁物料需求行复核 1517 → InventoryService 写 return 流水(+1)
     * → 冲销 issued_qty」任一步失败整体回滚（入库方向无需余额校验）
     */
    public function approve(ReturnList $return)
    {
        return $this->ok($this->returnListService->approve($return));
    }
}
