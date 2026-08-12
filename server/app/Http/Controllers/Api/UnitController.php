<?php
// 计量单位控制器：CRUD + 编码唯一 + 被商品引用保护
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Unit;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class UnitController extends Controller
{
    use ApiResponse;

    /** 分页列表（per_page 钳制 1-100） */
    public function index(Request $request)
    {
        $units = Unit::orderByDesc('id')->paginate(max(1, min(100, (int) $request->input('per_page', 10))));
        return $this->ok([
            'items' => $units->map(fn ($u) => ['id' => $u->id, 'name' => $u->name, 'code' => $u->code, 'status' => $u->status]),
            'total' => $units->total(), 'page' => $units->currentPage(), 'per_page' => $units->perPage(),
        ]);
    }

    /** 新建单位：编码重复 1103 */
    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:50',
            'code' => 'required|string|max:20',
            'status' => 'nullable|in:0,1',
        ]);
        if (Unit::where('code', $data['code'])->exists()) {
            return $this->fail(1103, '单位编码已存在');
        }
        $unit = Unit::create(['name' => $data['name'], 'code' => $data['code'], 'status' => $data['status'] ?? 1]);
        return $this->ok(['id' => $unit->id]);
    }

    /** 更新单位：编码唯一（排除自身） */
    public function update(Request $request, Unit $unit)
    {
        $data = $request->validate([
            'name' => 'required|string|max:50',
            'code' => 'required|string|max:20',
            'status' => 'nullable|in:0,1',
        ]);
        if (Unit::where('code', $data['code'])->where('id', '!=', $unit->id)->exists()) {
            return $this->fail(1103, '单位编码已存在');
        }
        $unit->update(['name' => $data['name'], 'code' => $data['code'], 'status' => $data['status'] ?? $unit->status]);
        return $this->ok();
    }

    /** 删除单位：被商品引用 1104 */
    public function destroy(Unit $unit)
    {
        if (Product::where('unit_id', $unit->id)->exists()) {
            return $this->fail(1104, '单位已被商品使用，不可删除');
        }
        $unit->delete();
        return $this->ok();
    }
}
