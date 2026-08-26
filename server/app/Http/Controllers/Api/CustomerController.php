<?php

// 客户控制器：分页搜索 读取 + CRUD 薄壳（写流程全部下沉 CustomerService）

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Master\SaveCustomerRequest;
use App\Models\Customer;
use App\Services\CustomerService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    use ApiResponse;

    public function __construct(private CustomerService $customerService) {}

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
    public function store(SaveCustomerRequest $request)
    {
        // 写流程下沉 CustomerService（编码唯一 1110、删除引用保护 1111 由其抛出）
        return $this->ok(['id' => $this->customerService->create($request->validated())->id]);
    }

    /** 更新客户：编码唯一（排除自身） */
    public function update(SaveCustomerRequest $request, Customer $customer)
    {
        $this->customerService->update($customer, $request->validated());

        return $this->ok();
    }

    /** 删除客户：被销售单据引用 1111（订单 + 出库单；销售表由销售模块创建，未建时守卫自动放行） */
    public function destroy(Customer $customer)
    {
        $this->customerService->delete($customer);

        return $this->ok();
    }
}
