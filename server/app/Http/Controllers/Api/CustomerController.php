<?php

// 客户控制器：CRUD + 搜索 + 编码唯一 + 被销售单据引用保护

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Support\ApiResponse;
use App\Support\DeletionGuard;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    use ApiResponse;

    /** 分页列表：名称/编码/联系人模糊搜索 + 状态过滤 */
    public function index(Request $request)
    {
        $query = Customer::orderByDesc('id');
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
            'items' => $rows->map(fn ($c) => [
                'id' => $c->id, 'name' => $c->name, 'code' => $c->code, 'contact' => $c->contact,
                'phone' => $c->phone, 'address' => $c->address, 'remark' => $c->remark, 'status' => $c->status,
            ]),
            'total' => $rows->total(), 'page' => $rows->currentPage(), 'per_page' => $rows->perPage(),
        ]);
    }

    /** 新建客户：编码重复 1110 */
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
        if (Customer::where('code', $data['code'])->exists()) {
            return $this->fail(1110, '客户编码已存在');
        }
        $customer = Customer::create([
            'name' => $data['name'], 'code' => $data['code'], 'contact' => $data['contact'] ?? null,
            'phone' => $data['phone'] ?? null, 'address' => $data['address'] ?? null,
            'remark' => $data['remark'] ?? null, 'status' => $data['status'] ?? Customer::STATUS_ENABLED,
        ]);

        return $this->ok(['id' => $customer->id]);
    }

    /** 更新客户：编码唯一（排除自身） */
    public function update(Request $request, Customer $customer)
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
        if (Customer::where('code', $data['code'])->where('id', '!=', $customer->id)->exists()) {
            return $this->fail(1110, '客户编码已存在');
        }
        $customer->update([
            'name' => $data['name'], 'code' => $data['code'], 'contact' => $data['contact'] ?? $customer->contact,
            'phone' => $data['phone'] ?? $customer->phone, 'address' => $data['address'] ?? $customer->address,
            'remark' => $data['remark'] ?? $customer->remark, 'status' => $data['status'] ?? $customer->status,
        ]);

        return $this->ok();
    }

    /** 删除客户：被销售单据引用 1111（订单 + 出库单；销售表由销售模块创建，未建时守卫自动放行） */
    public function destroy(Customer $customer)
    {
        if (
            DeletionGuard::referenced('sales_orders', 'customer_id', $customer->id)
            || DeletionGuard::referenced('sales_outbounds', 'customer_id', $customer->id)
        ) {
            return $this->fail(1111, '客户已被销售单据使用，不可删除');
        }
        $customer->delete();

        return $this->ok();
    }
}
