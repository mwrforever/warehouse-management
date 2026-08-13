<?php

// 仓库/库位控制器：仓库 CRUD + 库位子资源 + 删除保护（有库存不可删）

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Location;
use App\Models\Warehouse;
use App\Support\ApiResponse;
use App\Support\DeletionGuard;
use Illuminate\Http\Request;

class WarehouseController extends Controller
{
    use ApiResponse;

    /** 仓库分页列表：名称/编码模糊搜索 + 状态过滤 */
    public function index(Request $request)
    {
        $query = Warehouse::orderByDesc('id');
        if ($keyword = $request->input('keyword')) {
            $query->where(fn ($q) => $q->where('name', 'like', "%{$keyword}%")
                ->orWhere('code', 'like', "%{$keyword}%"));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        $rows = $query->paginate(max(1, min(100, (int) $request->input('per_page', 10))));

        return $this->ok([
            'items' => $rows->map(fn ($w) => [
                'id' => $w->id,
                'name' => $w->name,
                'code' => $w->code,
                'address' => $w->address,
                'manager' => $w->manager,
                'status' => $w->status,
            ]),
            'total' => $rows->total(), 'page' => $rows->currentPage(), 'per_page' => $rows->perPage(),
        ]);
    }

    /** 新建仓库：编码重复 1105 */
    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:50',
            'code' => 'required|string|max:20',
            'address' => 'nullable|string',
            'manager' => 'nullable|string|max:50',
            'status' => 'nullable|in:0,1',
        ]);
        if (Warehouse::where('code', $data['code'])->exists()) {
            return $this->fail(1105, '仓库编码已存在');
        }
        $warehouse = Warehouse::create([
            'name' => $data['name'],
            'code' => $data['code'],
            'address' => $data['address'] ?? null,
            'manager' => $data['manager'] ?? null,
            'status' => $data['status'] ?? 1,
        ]);

        return $this->ok(['id' => $warehouse->id]);
    }

    /** 更新仓库 */
    public function update(Request $request, Warehouse $warehouse)
    {
        $data = $request->validate([
            'name' => 'required|string|max:50',
            'code' => 'required|string|max:20',
            'address' => 'nullable|string',
            'manager' => 'nullable|string|max:50',
            'status' => 'nullable|in:0,1',
        ]);
        if (Warehouse::where('code', $data['code'])->where('id', '!=', $warehouse->id)->exists()) {
            return $this->fail(1105, '仓库编码已存在');
        }
        $warehouse->update([
            'name' => $data['name'], 'code' => $data['code'],
            'address' => $data['address'] ?? $warehouse->address, 'manager' => $data['manager'] ?? $warehouse->manager,
            'status' => $data['status'] ?? $warehouse->status,
        ]);

        return $this->ok();
    }

    /** 删除仓库：存在库存余额或入库单引用 1106（余额表由库存模块创建，未建时守卫自动放行） */
    public function destroy(Warehouse $warehouse)
    {
        // 库存余额 + 采购入库单引用均受保护（同码 1106）
        if (
            DeletionGuard::referenced('inventory_balances', 'warehouse_id', $warehouse->id)
            || DeletionGuard::referenced('purchase_inbounds', 'warehouse_id', $warehouse->id)
        ) {
            return $this->fail(1106, '仓库存在库存，不可删除');
        }
        $warehouse->delete();

        return $this->ok();
    }

    /** 库位列表（按仓库过滤，全量返回供库位弹窗） */
    public function locations(Warehouse $warehouse)
    {
        $items = $warehouse->locations()->orderBy('id')->get()
            ->map(fn ($l) => ['id' => $l->id, 'name' => $l->name, 'code' => $l->code, 'status' => $l->status]);

        return $this->ok(['items' => $items]);
    }

    /** 新建库位：编码全局唯一（重复 422，格式层校验） */
    public function storeLocation(Request $request, Warehouse $warehouse)
    {
        $data = $request->validate([
            'name' => 'required|string|max:50',
            'code' => 'required|string|max:50|unique:locations,code',
            'status' => 'nullable|in:0,1',
        ]);
        $location = $warehouse->locations()->create([
            'name' => $data['name'],
            'code' => $data['code'],
            'status' => $data['status'] ?? 1,
        ]);

        return $this->ok(['id' => $location->id]);
    }

    /** 更新库位 */
    public function updateLocation(Request $request, Location $location)
    {
        $data = $request->validate([
            'name' => 'required|string|max:50',
            'code' => 'required|string|max:50|unique:locations,code,'.$location->id,
            'status' => 'nullable|in:0,1',
        ]);
        $location->update([
            'name' => $data['name'],
            'code' => $data['code'],
            'status' => $data['status'] ?? $location->status,
        ]);

        return $this->ok();
    }

    /** 删除库位：存在库存余额或入库单引用 1107 */
    public function destroyLocation(Location $location)
    {
        // 库存余额 + 采购入库单引用均受保护（同码 1107）
        if (
            DeletionGuard::referenced('inventory_balances', 'location_id', $location->id)
            || DeletionGuard::referenced('purchase_inbounds', 'location_id', $location->id)
        ) {
            return $this->fail(1107, '库位存在库存，不可删除');
        }
        $location->delete();

        return $this->ok();
    }
}
