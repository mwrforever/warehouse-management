<?php

// 商品控制器：分页筛选 + CRUD + 扫码查询 + 删除保护（被 BOM/业务单据引用）

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BomHeader;
use App\Models\BomItem;
use App\Models\Product;
use App\Support\ApiResponse;
use App\Support\DeletionGuard;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProductController extends Controller
{
    use ApiResponse;

    // 类型 → 中文标签映射（前端表格类型标签）
    private const TYPE_LABELS = ['raw_material' => '原料', 'semi_finished' => '半成品', 'finished' => '成品'];

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

    /** 新建商品：编码/条码唯一 + 安全库存上下限校验 */
    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'code' => 'required|string|max:50',
            'type' => ['required', Rule::in(['raw_material', 'semi_finished', 'finished'])],
            'category_id' => 'required|exists:categories,id',
            'unit_id' => 'required|exists:units,id',
            'spec' => 'nullable|string|max:100',
            'barcode' => 'nullable|string|max:50',
            'safety_min' => 'nullable|numeric|min:0',
            'safety_max' => 'nullable|numeric|min:0',
            'status' => 'nullable|in:0,1',
            'remark' => 'nullable|string',
        ]);

        // 编码唯一 1114；条码非空时唯一 1115
        if (Product::where('code', $data['code'])->exists()) {
            return $this->fail(1114, '商品编码已存在');
        }
        if (! empty($data['barcode']) && Product::where('barcode', $data['barcode'])->exists()) {
            return $this->fail(1115, '条码已存在');
        }
        // 安全库存下限不能大于上限 1122
        $min = (float) ($data['safety_min'] ?? 0);
        $max = (float) ($data['safety_max'] ?? 0);
        if ($max > 0 && $min > $max) {
            return $this->fail(1122, '安全库存下限不能大于上限');
        }

        $product = Product::create([
            'name' => $data['name'], 'code' => $data['code'], 'type' => $data['type'],
            'category_id' => $data['category_id'], 'unit_id' => $data['unit_id'],
            'spec' => $data['spec'] ?? null, 'barcode' => $data['barcode'] ?? null,
            'safety_min' => $min, 'safety_max' => $max,
            'status' => $data['status'] ?? 1, 'remark' => $data['remark'] ?? null,
        ]);

        return $this->ok(['id' => $product->id]);
    }

    /** 更新商品：编码/条码唯一（排除自身） */
    public function update(Request $request, Product $product)
    {
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'code' => 'required|string|max:50',
            'type' => ['required', Rule::in(['raw_material', 'semi_finished', 'finished'])],
            'category_id' => 'required|exists:categories,id',
            'unit_id' => 'required|exists:units,id',
            'spec' => 'nullable|string|max:100',
            'barcode' => 'nullable|string|max:50',
            'safety_min' => 'nullable|numeric|min:0',
            'safety_max' => 'nullable|numeric|min:0',
            'status' => 'nullable|in:0,1',
            'remark' => 'nullable|string',
        ]);

        if (Product::where('code', $data['code'])->where('id', '!=', $product->id)->exists()) {
            return $this->fail(1114, '商品编码已存在');
        }
        if (
            ! empty($data['barcode']) && Product::where('barcode', $data['barcode'])
                ->where('id', '!=', $product->id)->exists()
        ) {
            return $this->fail(1115, '条码已存在');
        }
        $min = (float) ($data['safety_min'] ?? 0);
        $max = (float) ($data['safety_max'] ?? 0);
        if ($max > 0 && $min > $max) {
            return $this->fail(1122, '安全库存下限不能大于上限');
        }

        $product->update([
            'name' => $data['name'], 'code' => $data['code'], 'type' => $data['type'],
            'category_id' => $data['category_id'], 'unit_id' => $data['unit_id'],
            'spec' => $data['spec'] ?? null, 'barcode' => $data['barcode'] ?? null,
            'safety_min' => $min, 'safety_max' => $max,
            'status' => $data['status'] ?? $product->status, 'remark' => $data['remark'] ?? null,
        ]);

        return $this->ok();
    }

    /** 删除商品：被 BOM 头/明细、库存流水、采购/销售明细、生产工单引用不可删 1116 */
    public function destroy(Product $product)
    {
        // 本模块表（BOM）直接检查；下游模块表经守卫（未建自动放行，建后自动生效）
        $referencedByBom = BomItem::where('material_id', $product->id)->exists()
            || BomHeader::where('product_id', $product->id)->exists();
        $referencedByOther = DeletionGuard::referenced('inventory_movements', 'product_id', $product->id)
            || DeletionGuard::referenced('purchase_order_items', 'product_id', $product->id)
            || DeletionGuard::referenced('sales_order_items', 'product_id', $product->id)
            || DeletionGuard::referenced('production_orders', 'product_id', $product->id);
        if ($referencedByBom || $referencedByOther) {
            return $this->fail(1116, '商品已被业务单据使用，不可删除');
        }
        $product->delete();

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
