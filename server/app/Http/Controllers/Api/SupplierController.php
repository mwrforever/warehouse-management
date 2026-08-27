<?php

// 供应商控制器：分页搜索 读取 + CRUD 薄壳（写流程全部下沉 SupplierService）

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Master\SaveSupplierRequest;
use App\Models\Supplier;
use App\Services\SupplierService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class SupplierController extends Controller
{
    use ApiResponse;

    public function __construct(private SupplierService $supplierService) {}

    /** 分页列表：名称/编码/联系人模糊搜索 + 状态过滤 */
    public function index(Request $request)
    {
        $query = Supplier::orderByDesc('id');
        if ($keyword = $request->input('keyword')) {
            $query->where(fn ($q) => $q->where('name', 'like', "%{$keyword}%")
                ->orWhere('code', 'like', "%{$keyword}%")
                ->orWhere('contact', 'like', "%{$keyword}%"));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        $rows = $query->paginate(max(1, min(100, (int) $request->input('per_page', 10))));

        return $this->ok([
            'items' => $rows->map(fn ($s) => [
                'id' => $s->id, 'name' => $s->name, 'code' => $s->code, 'contact' => $s->contact,
                'phone' => $s->phone, 'province' => $s->province, 'city' => $s->city,
                'district' => $s->district, 'town' => $s->town, 'address' => $s->address,
                'remark' => $s->remark, 'status' => $s->status,
            ]),
            'total' => $rows->total(), 'page' => $rows->currentPage(), 'per_page' => $rows->perPage(),
        ]);
    }

    /** 新建供应商：编码重复 1108 */
    public function store(SaveSupplierRequest $request)
    {
        // 写流程下沉 SupplierService（编码唯一 1108、删除引用保护 1109 由其抛出）
        return $this->ok(['id' => $this->supplierService->create($request->validated())->id]);
    }

    /** 更新供应商：编码唯一（排除自身） */
    public function update(SaveSupplierRequest $request, Supplier $supplier)
    {
        $this->supplierService->update($supplier, $request->validated());

        return $this->ok();
    }

    /** 删除供应商：被采购订单/入库单或委外加工单引用 1109（生产表未建自动放行，建后自动生效） */
    public function destroy(Supplier $supplier)
    {
        $this->supplierService->delete($supplier);

        return $this->ok();
    }
}
