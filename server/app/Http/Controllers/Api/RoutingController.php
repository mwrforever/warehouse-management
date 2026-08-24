<?php

// 工艺路线控制器：列表/详情图/启停/删除薄壳；单头+DAG 保存（含 17xx 校验）经 RoutingService 事务落库

namespace App\Http\Controllers\Api;

use App\Exceptions\RoutingException;
use App\Http\Controllers\Controller;
use App\Models\RoutingHeader;
use App\Services\DocumentSequenceService;
use App\Services\RoutingService;
use App\Support\ApiResponse;
use App\Support\DeletionGuard;
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

    /** 新建：单头+DAG 一次提交（Service 内事务 + DAG 校验 + 启用唯一） */
    public function store(Request $request)
    {
        $data = $this->validatePayload($request);
        try {
            $routing = $this->routingService->persist($data, null, $this->sequenceService);
        } catch (RoutingException $e) {
            return $this->fail($e->getCode() ?: 1700, $e->getMessage());
        }

        return $this->ok(['id' => $routing->id, 'code' => $routing->code]);
    }

    /** 更新：DAG 全量替换；被工单引用仅可启停（1705） */
    public function update(Request $request, RoutingHeader $routing)
    {
        if (DeletionGuard::referenced('production_orders', 'routing_id', $routing->id)) {
            return $this->fail(1705, '工艺路线已被生产工单使用，仅可启用/停用');
        }
        $data = $this->validatePayload($request);
        try {
            $this->routingService->persist($data, $routing, $this->sequenceService);
        } catch (RoutingException $e) {
            return $this->fail($e->getCode() ?: 1700, $e->getMessage());
        }

        return $this->ok();
    }

    /** 删除：被工单引用不可删（1706）；级联清节点/材料/边 */
    public function destroy(RoutingHeader $routing)
    {
        if (DeletionGuard::referenced('production_orders', 'routing_id', $routing->id)) {
            return $this->fail(1706, '工艺路线已被生产工单使用，不可删除');
        }
        $this->routingService->delete($routing);

        return $this->ok();
    }

    /** 启用/停用：启用时自动停用同成品其他版本（同 BOM 口径；被引用也允许启停） */
    public function toggle(Request $request, RoutingHeader $routing)
    {
        $data = $request->validate(['status' => 'required|in:0,1']);
        $this->routingService->toggle($routing, (int) $data['status']);

        return $this->ok();
    }

    /** 完整 DAG（画布编辑回显/查看），含节点材料明细 */
    public function graph(RoutingHeader $routing)
    {
        return $this->ok($this->routingService->graph($routing));
    }

    // 载荷格式校验（422 仅格式层；业务校验 17xx 在 Service）
    private function validatePayload(Request $request): array
    {
        $data = $request->validate([
            'product_id' => 'required|integer|exists:products,id',
            'version' => 'required|string|max:20',
            'quantity' => 'nullable|numeric|regex:/^\d+(\.\d{1,2})?$/',
            'status' => 'nullable|in:0,1',
            'remark' => 'nullable|string|max:200',
            'nodes' => 'required|array|min:1',
            'nodes.*.node_no' => 'required|string|max:20|distinct',
            'nodes.*.process_id' => 'required|integer|exists:processes,id',
            'nodes.*.name' => 'required|string|max:50',
            'nodes.*.output_product_id' => 'required|integer|exists:products,id',
            'nodes.*.output_qty' => 'nullable|numeric|regex:/^\d+(\.\d{1,2})?$/',
            'nodes.*.is_outsourced' => 'nullable|in:0,1',
            'nodes.*.remark' => 'nullable|string|max:200',
            'nodes.*.materials' => 'nullable|array',
            'nodes.*.materials.*.material_id' => 'required|integer|exists:products,id',
            'nodes.*.materials.*.qty_per_unit' => 'required|numeric|regex:/^\d+(\.\d{1,2})?$/',
            'nodes.*.materials.*.unit_id' => 'required|integer|exists:units,id',
            'edges' => 'nullable|array',
            'edges.*.from_node_no' => 'required|string',
            'edges.*.to_node_no' => 'required|string',
        ]);

        // 边端点必须在节点集内 + 去重（重复边 422；同源多边合法，故不使用 distinct 规则）
        $nodeNos = array_column($data['nodes'], 'node_no');
        $seen = [];
        foreach ($data['edges'] ?? [] as $e) {
            if (! in_array($e['from_node_no'], $nodeNos, true) || ! in_array($e['to_node_no'], $nodeNos, true)) {
                abort($this->fail(422, '连线端点不存在于节点集中'));
            }
            $key = $e['from_node_no'].'>'.$e['to_node_no'];
            if (isset($seen[$key])) {
                abort($this->fail(422, '连线重复'));
            }
            $seen[$key] = true;
        }
        // 同节点材料去重（唯一索引兜底前的友好报错）
        foreach ($data['nodes'] as $n) {
            $ids = array_column($n['materials'] ?? [], 'material_id');
            if (count($ids) !== count(array_unique($ids))) {
                abort($this->fail(422, '工序['.$n['name'].']存在重复输入材料'));
            }
        }

        // 归一化默认值
        return [
            'product_id' => (int) $data['product_id'],
            'version' => $data['version'],
            'quantity' => (string) ($data['quantity'] ?? 1),
            'status' => (int) ($data['status'] ?? 1),
            'remark' => $data['remark'] ?? null,
            'nodes' => array_map(fn ($n) => [
                'node_no' => $n['node_no'],
                'process_id' => (int) $n['process_id'],
                'name' => $n['name'],
                'output_product_id' => (int) $n['output_product_id'],
                'output_qty' => (string) ($n['output_qty'] ?? 1),
                'is_outsourced' => (int) ($n['is_outsourced'] ?? 0),
                'remark' => $n['remark'] ?? null,
                'materials' => array_map(fn ($m) => [
                    'material_id' => (int) $m['material_id'],
                    'qty_per_unit' => (string) $m['qty_per_unit'],
                    'unit_id' => (int) $m['unit_id'],
                ], $n['materials'] ?? []),
            ], $data['nodes']),
            'edges' => array_map(fn ($e) => ['from' => $e['from_node_no'], 'to' => $e['to_node_no']], $data['edges'] ?? []),
        ];
    }
}
