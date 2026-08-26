<?php

// 成品入库单控制器：分页列表/详情 读取 + 草稿 CRUD/审核 薄壳（写流程全部下沉 FinishedInboundService）

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Production\SaveFinishedInboundRequest;
use App\Models\FinishedInbound;
use App\Models\FinishedInboundItem;
use App\Services\FinishedInboundService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class FinishedInboundController extends Controller
{
    use ApiResponse;

    public function __construct(private FinishedInboundService $finishedInboundService) {}

    /** 分页列表：单号/仓库/状态 筛选；含工单单号/成品名与状态标签 */
    public function index(Request $request)
    {
        $query = FinishedInbound::query()
            ->join('production_orders', 'production_orders.id', '=', 'finished_inbounds.order_id')
            ->join('products', 'products.id', '=', 'production_orders.product_id')
            ->select(
                // 显式列出列表所需主表列（与下方 map 闭包字段一一对应），避免 select 通配拉取未列字段
                'finished_inbounds.id',
                'finished_inbounds.no',
                'finished_inbounds.order_id',
                'finished_inbounds.warehouse_id',
                'finished_inbounds.location_id',
                'finished_inbounds.status',
                'finished_inbounds.approved_at',
                'finished_inbounds.operator',
                'finished_inbounds.created_at',
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

        // 预加载工单/仓库/库位 + withSum 明细数量 SQL 聚合：消除列表逐行懒加载与内存 SUM
        // N+1（每页最多 100 行 → 3×N 次关联查询 + N 次内存 float 累加——D-3 铁律禁浮点参与数量求和）
        $rows = $query->with(['order', 'warehouse', 'location'])
            ->withSum('items', 'quantity')
            ->paginate(max(1, min(100, (int) $request->input('per_page', 10))));

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
                // 数量 = Σ 明细行（withSum SQL 聚合下推，D-18）；聚合列经 getAttribute 读取
                // （join 别名列同模式）；跨库 SUM 形态经 bcmath 归一为两位小数字符串（无明细行防御归零）
                'quantity' => bcadd((string) ($f->getAttribute('items_sum_quantity') ?? '0'), '0', 2),
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
    public function store(SaveFinishedInboundRequest $request)
    {
        $fi = $this->finishedInboundService->create($request->validated());

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
    public function update(SaveFinishedInboundRequest $request, FinishedInbound $finishedInbound)
    {
        $this->finishedInboundService->update($finishedInbound, $request->validated());

        return $this->ok();
    }

    /** 删除草稿：仅草稿（1527）；事务内锁行复查防并发 */
    public function destroy(FinishedInbound $finishedInbound)
    {
        $this->finishedInboundService->delete($finishedInbound);

        return $this->ok();
    }

    /**
     * 审核（核心）：事务内「锁单幂等 1528 → 锁工单行复核剩余产量 1525 → InventoryService 写 finished_inbound 流水(+qty)
     * → completed_qty 累计 → 末工序已完成且满产 → 工单自动已完成」任一步失败整体回滚
     */
    public function approve(FinishedInbound $finishedInbound)
    {
        return $this->ok($this->finishedInboundService->approve($finishedInbound));
    }
}
