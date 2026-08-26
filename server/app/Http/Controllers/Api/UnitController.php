<?php

// 计量单位控制器：分页列表 读取 + CRUD 薄壳（写流程全部下沉 UnitService）

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Master\SaveUnitRequest;
use App\Models\Unit;
use App\Services\UnitService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class UnitController extends Controller
{
    use ApiResponse;

    public function __construct(private UnitService $unitService) {}

    /** 分页列表（per_page 钳制 1-100） */
    public function index(Request $request)
    {
        $units = Unit::orderByDesc('id')->paginate(max(1, min(100, (int) $request->input('per_page', 10))));

        return $this->ok([
            'items' => $units->map(fn ($u) => [
                'id' => $u->id,
                'name' => $u->name,
                'code' => $u->code,
                'status' => $u->status,
            ]),
            'total' => $units->total(), 'page' => $units->currentPage(), 'per_page' => $units->perPage(),
        ]);
    }

    /** 新建单位：编码重复 1103 */
    public function store(SaveUnitRequest $request)
    {
        // 写流程下沉 UnitService（编码唯一 1103、被商品引用保护 1104 由其抛出）
        return $this->ok(['id' => $this->unitService->create($request->validated())->id]);
    }

    /** 更新单位：编码唯一（排除自身） */
    public function update(SaveUnitRequest $request, Unit $unit)
    {
        $this->unitService->update($unit, $request->validated());

        return $this->ok();
    }

    /** 删除单位：被商品引用 1104 */
    public function destroy(Unit $unit)
    {
        $this->unitService->delete($unit);

        return $this->ok();
    }
}
