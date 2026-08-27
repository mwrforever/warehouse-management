<?php

// 仓库/库位控制器：仓库/库位列表 读取 + 管理薄壳（写流程全部下沉 WarehouseService）

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Master\SaveLocationRequest;
use App\Http\Requests\Master\SaveWarehouseRequest;
use App\Models\Location;
use App\Models\Warehouse;
use App\Services\WarehouseService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class WarehouseController extends Controller
{
    use ApiResponse;

    public function __construct(private WarehouseService $warehouseService) {}

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
                'province' => $w->province,
                'city' => $w->city,
                'district' => $w->district,
                'town' => $w->town,
                'manager' => $w->manager,
                'status' => $w->status,
            ]),
            'total' => $rows->total(), 'page' => $rows->currentPage(), 'per_page' => $rows->perPage(),
        ]);
    }

    /** 新建仓库：编码由服务自动生成（响应回填供前端展示） */
    public function store(SaveWarehouseRequest $request)
    {
        // 写流程下沉 WarehouseService（编码自动生成/1106/1107 引用保护由其抛出）
        $warehouse = $this->warehouseService->create($request->validated());

        return $this->ok(['id' => $warehouse->id, 'code' => $warehouse->code]);
    }

    /** 更新仓库 */
    public function update(SaveWarehouseRequest $request, Warehouse $warehouse)
    {
        $this->warehouseService->update($warehouse, $request->validated());

        return $this->ok();
    }

    /** 删除仓库：被库存余额/流水、盘点单、采购入库/销售出库、生产单据（领退料/委外/成品入库）引用 1106 */
    public function destroy(Warehouse $warehouse)
    {
        $this->warehouseService->delete($warehouse);

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
    public function storeLocation(SaveLocationRequest $request, Warehouse $warehouse)
    {
        // 写流程下沉 WarehouseService（编码唯一由格式层 unique 规则把关）
        return $this->ok(['id' => $this->warehouseService->createLocation($warehouse, $request->validated())->id]);
    }

    /** 更新库位 */
    public function updateLocation(SaveLocationRequest $request, Location $location)
    {
        $this->warehouseService->updateLocation($location, $request->validated());

        return $this->ok();
    }

    /** 删除库位：被库存余额/流水、盘点明细、采购入库/销售出库、生产单据（领退料/委外/成品入库）引用 1107 */
    public function destroyLocation(Location $location)
    {
        $this->warehouseService->deleteLocation($location);

        return $this->ok();
    }
}
