<?php

// 商品控制器：分页筛选/扫码查询 读取 + CRUD 薄壳（写流程全部下沉 ProductService）

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Master\SaveProductRequest;
use App\Models\Product;
use App\Services\ProductService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    use ApiResponse;

    // 类型 → 中文标签映射（前端表格类型标签）
    private const TYPE_LABELS = ['raw_material' => '原料', 'semi_finished' => '半成品', 'finished' => '成品'];

    public function __construct(private ProductService $productService) {}

    /** 分页列表：编码/名称/条码模糊 + 类型/分类/状态过滤 */
    public function index(Request $request)
    {
        $query = Product::with(['category', 'unit'])->orderByDesc('id');
        if ($keyword = $request->input('keyword')) {
            $query->where(fn ($q) => $q->where('code', 'like', "%{$keyword}%")
                ->orWhere('name', 'like', "%{$keyword}%")
                ->orWhere('barcode', 'like', "%{$keyword}%"));
        }
        if ($request->filled('type')) {
            $query->where('type', $request->input('type'));
        }
        if ($request->filled('category_id')) {
            $query->where('category_id', $request->input('category_id'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        $rows = $query->paginate(max(1, min(100, (int) $request->input('per_page', 10))));

        return $this->ok([
            'items' => $rows->map(fn ($p) => $this->payload($p)),
            'total' => $rows->total(), 'page' => $rows->currentPage(), 'per_page' => $rows->perPage(),
        ]);
    }

    // 商品输出结构（列表与扫码复用：列表多 category_name/status）
    private function payload(Product $p): array
    {
        return [
            'id' => $p->id, 'name' => $p->name, 'code' => $p->code, 'type' => $p->type,
            'type_label' => self::TYPE_LABELS[$p->type],
            'category_id' => $p->category_id, 'category_name' => $p->category?->name,
            'unit_id' => $p->unit_id, 'unit_name' => $p->unit?->name,
            'spec' => $p->spec, 'barcode' => $p->barcode,
            'safety_min' => (float) $p->safety_min, 'safety_max' => (float) $p->safety_max,
            'status' => $p->status, 'remark' => $p->remark,
        ];
    }

    /** 新建商品：编码/条码留空自动生成（Spec 2）+ 唯一校验 + 安全库存上下限校验 */
    public function store(SaveProductRequest $request)
    {
        // 写流程下沉 ProductService（唯一 1114/1115、区间 1122、自动编码/条码由服务内部落实）
        $product = $this->productService->create($request->validated());

        // 响应回填自动生成的编码/条码（前端弹窗保存后可展示，spec §5）
        return $this->ok(['id' => $product->id, 'code' => $product->code, 'barcode' => $product->barcode]);
    }

    /** 更新商品：编码/条码唯一（排除自身） */
    public function update(SaveProductRequest $request, Product $product)
    {
        $this->productService->update($product, $request->validated());

        return $this->ok();
    }

    /** 删除商品：被 BOM 头/明细、库存流水、盘点明细、采购/销售明细、生产工单/工单物料/领退料/成品入库明细引用不可删 1116 */
    public function destroy(Product $product)
    {
        $this->productService->delete($product);

        return $this->ok();
    }

    /** 扫码查询：扫枪场景按条码即时校验，未匹配 1117 */
    public function byBarcode(string $barcode)
    {
        $product = Product::with('unit')->where('barcode', $barcode)->first();
        if (! $product) {
            return $this->fail(1117, '条码未匹配到商品');
        }
        $p = $this->payload($product);

        return $this->ok([
            'id' => $p['id'],
            'name' => $p['name'],
            'code' => $p['code'],
            'type' => $p['type'],
            'spec' => $p['spec'],
            'unit_name' => $p['unit_name'],
        ]);
    }
}
