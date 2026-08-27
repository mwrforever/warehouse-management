<?php

// BOM 控制器：列表/明细读取 + 单头+明细维护/启用切换/删除保护薄壳（写流程全部下沉 BomService）

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Production\SaveBomRequest;
use App\Http\Requests\Production\ToggleStatusRequest;
use App\Models\BomHeader;
use App\Services\BomService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class BomController extends Controller
{
    use ApiResponse;

    // 商品类型 → 中文标签映射（列表「商品类型」列展示，与 ProductController 口径一致）
    private const TYPE_LABELS = ['raw_material' => '原料', 'semi_finished' => '半成品', 'finished' => '成品'];

    public function __construct(private BomService $bomService) {}

    /** 分页列表：单号模糊，含商品名称与商品类型标签（BOM 主商品已放宽为成品/半成品） */
    public function index(Request $request)
    {
        $query = BomHeader::with('product')->orderByDesc('id');
        if ($request->filled('product_id')) {
            $query->where('product_id', $request->input('product_id'));
        }
        if ($keyword = $request->input('keyword')) {
            $query->where('code', 'like', "%{$keyword}%");
        }
        $rows = $query->paginate(max(1, min(100, (int) $request->input('per_page', 10))));

        return $this->ok([
            'items' => $rows->map(fn ($b) => [
                'id' => $b->id, 'code' => $b->code, 'product_id' => $b->product_id,
                'product_name' => $b->product?->name,
                'type_label' => $b->product ? (self::TYPE_LABELS[$b->product->type] ?? $b->product->type) : null,
                'version' => $b->version,
                'quantity' => (float) $b->quantity, 'status' => $b->status, 'remark' => $b->remark,
            ]),
            'total' => $rows->total(), 'page' => $rows->currentPage(), 'per_page' => $rows->perPage(),
        ]);
    }

    /**
     * 新建 BOM：格式校验 422 → BomService::create（业务码 1118/1119/1120/1123 + 事务 + 启用版本唯一 + 单号持久序列）
     * 响应含 id/code：前端新建成功后以 code 回显列表
     */
    public function store(SaveBomRequest $request)
    {
        $bom = $this->bomService->create($request->validated());

        return $this->ok(['id' => $bom->id, 'code' => $bom->code]);
    }

    /** 更新 BOM：格式校验 422 → BomService::update（业务码校验 + 事务内启用版本唯一排除自身 + 明细全量替换） */
    public function update(SaveBomRequest $request, BomHeader $bom)
    {
        $this->bomService->update($bom, $request->validated());

        return $this->ok();
    }

    /** 删除 BOM：被生产工单引用 1121（Service 内检查；工单表由生产模块创建，未建时守卫自动放行） */
    public function destroy(BomHeader $bom)
    {
        $this->bomService->delete($bom);

        return $this->ok();
    }

    /** 明细列表：物料名/单位名联查 */
    public function items(BomHeader $bom)
    {
        $items = $bom->items()->with(['material', 'unit'])->orderBy('id')->get()
            ->map(fn ($i) => [
                'id' => $i->id, 'material_id' => $i->material_id, 'material_name' => $i->material?->name,
                'quantity' => (float) $i->quantity, 'unit_id' => $i->unit_id, 'unit_name' => $i->unit?->name,
            ]);

        return $this->ok(['items' => $items]);
    }

    /** 启用/停用切换：启用时自动停用同成品其他版本（BomService 事务内锁成品行串行化） */
    public function toggle(ToggleStatusRequest $request, BomHeader $bom)
    {
        $this->bomService->toggle($bom, (int) $request->validated()['status']);

        return $this->ok();
    }
}
