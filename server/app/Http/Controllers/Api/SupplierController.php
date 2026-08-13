<?php

// 供应商控制器：CRUD + 搜索 + 编码唯一 + 被采购单据引用保护

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Supplier;
use App\Support\ApiResponse;
use App\Support\DeletionGuard;
use Illuminate\Http\Request;

class SupplierController extends Controller
{
    use ApiResponse;

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
                'phone' => $s->phone, 'address' => $s->address, 'remark' => $s->remark, 'status' => $s->status,
            ]),
            'total' => $rows->total(), 'page' => $rows->currentPage(), 'per_page' => $rows->perPage(),
        ]);
    }

    /** 新建供应商：编码重复 1108 */
    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'code' => 'required|string|max:50',
            'contact' => 'nullable|string|max:50',
            'phone' => 'nullable|string|max:30',
            'address' => 'nullable|string',
            'remark' => 'nullable|string',
            'status' => 'nullable|in:0,1',
        ]);
        if (Supplier::where('code', $data['code'])->exists()) {
            return $this->fail(1108, '供应商编码已存在');
        }
        $supplier = Supplier::create([
            'name' => $data['name'], 'code' => $data['code'], 'contact' => $data['contact'] ?? null,
            'phone' => $data['phone'] ?? null, 'address' => $data['address'] ?? null,
            'remark' => $data['remark'] ?? null, 'status' => $data['status'] ?? 1,
        ]);

        return $this->ok(['id' => $supplier->id]);
    }

    /** 更新供应商：编码唯一（排除自身） */
    public function update(Request $request, Supplier $supplier)
    {
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'code' => 'required|string|max:50',
            'contact' => 'nullable|string|max:50',
            'phone' => 'nullable|string|max:30',
            'address' => 'nullable|string',
            'remark' => 'nullable|string',
            'status' => 'nullable|in:0,1',
        ]);
        if (Supplier::where('code', $data['code'])->where('id', '!=', $supplier->id)->exists()) {
            return $this->fail(1108, '供应商编码已存在');
        }
        $supplier->update([
            'name' => $data['name'], 'code' => $data['code'], 'contact' => $data['contact'] ?? $supplier->contact,
            'phone' => $data['phone'] ?? $supplier->phone, 'address' => $data['address'] ?? $supplier->address,
            'remark' => $data['remark'] ?? $supplier->remark, 'status' => $data['status'] ?? $supplier->status,
        ]);

        return $this->ok();
    }

    /** 删除供应商：被采购单据引用 1109（采购表由采购模块创建，未建时守卫自动放行） */
    public function destroy(Supplier $supplier)
    {
        // 采购订单/采购入库单引用均受保护（订单+入库单同码 1109）
        if (
            DeletionGuard::referenced('purchase_orders', 'supplier_id', $supplier->id)
            || DeletionGuard::referenced('purchase_inbounds', 'supplier_id', $supplier->id)
        ) {
            return $this->fail(1109, '供应商已被采购单据使用，不可删除');
        }
        $supplier->delete();

        return $this->ok();
    }
}
