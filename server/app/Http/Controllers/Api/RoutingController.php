<?php

// 工艺路线控制器：列表/详情图薄壳；单头+DAG 保存（格式校验 FormRequest + 17xx/结构校验经 RoutingService 事务落库）

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Production\SaveRoutingRequest;
use App\Http\Requests\Production\ToggleStatusRequest;
use App\Models\RoutingHeader;
use App\Services\DocumentSequenceService;
use App\Services\RoutingService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class RoutingController extends Controller
{
    use ApiResponse;

    public function __construct(
        private RoutingService $routingService,
        private DocumentSequenceService $sequenceService,
    ) {}

    /** 分页列表：成品/关键字/状态筛选，含成品名 */
    public function index(Request $request)
    {
        $query = RoutingHeader::query()->with('product')->orderByDesc('id');
        if ($request->filled('product_id')) {
            $query->where('product_id', $request->input('product_id'));
        }
        if ($keyword = $request->input('keyword')) {
            $query->where('code', 'like', "%{$keyword}%");
        }
        if ($request->filled('status')) {
            $query->where('status', (int) $request->input('status'));
        }
        $rows = $query->paginate(max(1, min(100, (int) $request->input('per_page', 10))));

        return $this->ok([
            'items' => $rows->map(fn (RoutingHeader $r) => [
                'id' => $r->id, 'code' => $r->code, 'product_id' => $r->product_id,
                'product_name' => $r->product?->name, 'version' => $r->version,
                'quantity' => (float) $r->quantity, 'status' => (int) $r->status,
                'status_label' => RoutingHeader::STATUS_LABELS[$r->status] ?? '未知',
                'remark' => $r->remark,
            ]),
            'total' => $rows->total(), 'page' => $rows->currentPage(), 'per_page' => $rows->perPage(),
        ]);
    }

    /** 新建：格式校验 422 → Service 内归一化/结构校验/DAG 校验（17xx）+ 事务落库 + 启用唯一 */
    public function store(SaveRoutingRequest $request)
    {
        $routing = $this->routingService->persist($request->validated(), null, $this->sequenceService);

        return $this->ok(['id' => $routing->id, 'code' => $routing->code]);
    }

    /** 更新：格式校验 422 → Service 内归一化/结构校验 + 被引用保护（1705）；DAG 全量替换 */
    public function update(SaveRoutingRequest $request, RoutingHeader $routing)
    {
        $this->routingService->persist($request->validated(), $routing, $this->sequenceService);

        return $this->ok();
    }

    /** 删除：被工单引用不可删（1706，Service 内检查）；级联清节点/材料/边 */
    public function destroy(RoutingHeader $routing)
    {
        $this->routingService->delete($routing);

        return $this->ok();
    }

    /** 启用/停用：启用时自动停用同成品其他版本（同 BOM 口径；被引用也允许启停） */
    public function toggle(ToggleStatusRequest $request, RoutingHeader $routing)
    {
        $this->routingService->toggle($routing, (int) $request->validated()['status']);

        return $this->ok();
    }

    /** 完整 DAG（画布编辑回显/查看），含节点材料明细 */
    public function graph(RoutingHeader $routing)
    {
        return $this->ok($this->routingService->graph($routing));
    }
}
