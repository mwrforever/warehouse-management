<?php

// 客户服务：客户创建/更新/删除（编码唯一 1110 + 被销售单据引用保护 1111）
// 写操作均为单表原子写，无跨表事务，不包 DB::transaction（原子性由单条 insert/update/delete 保证）

namespace App\Services;

use App\Exceptions\MasterDataException;
use App\Models\Customer;
use App\Support\DeletionGuard;
use Illuminate\Support\Facades\Log;

class CustomerService
{
    /**
     * 新建客户（原控制器 store 下沉）：编码重复 1110
     *
     * @param  array  $data  已过 SaveCustomerRequest 格式校验的载荷
     * @return Customer 新建客户（供控制器回显 id）
     *
     * @throws MasterDataException 编码重复 1110
     */
    public function create(array $data): Customer
    {
        // 编码唯一 1110（读检查无需持锁；数据库 unique 约束兜底并发场景）
        if (Customer::where('code', $data['code'])->exists()) {
            throw new MasterDataException('客户编码已存在', 1110);
        }
        $customer = Customer::create([
            'name' => $data['name'], 'code' => $data['code'], 'contact' => $data['contact'] ?? null,
            'phone' => $data['phone'] ?? null, 'address' => $data['address'] ?? null,
            'remark' => $data['remark'] ?? null, 'status' => $data['status'] ?? Customer::STATUS_ENABLED,
        ]);

        Log::info('客户创建成功', ['customer_id' => $customer->id, 'code' => $customer->code, 'operator' => auth()->id()]);

        return $customer;
    }

    /**
     * 更新客户（原控制器 update 下沉）：编码唯一（排除自身）1110
     *
     * @param  Customer  $customer  路由绑定的客户模型
     * @param  array  $data  已过 SaveCustomerRequest 格式校验的载荷
     *
     * @throws MasterDataException 编码重复 1110
     */
    public function update(Customer $customer, array $data): void
    {
        if (Customer::where('code', $data['code'])->where('id', '!=', $customer->id)->exists()) {
            throw new MasterDataException('客户编码已存在', 1110);
        }
        $customer->update([
            'name' => $data['name'], 'code' => $data['code'], 'contact' => $data['contact'] ?? $customer->contact,
            'phone' => $data['phone'] ?? $customer->phone, 'address' => $data['address'] ?? $customer->address,
            'remark' => $data['remark'] ?? $customer->remark, 'status' => $data['status'] ?? $customer->status,
        ]);

        Log::info('客户更新成功', ['customer_id' => $customer->id, 'code' => $customer->code, 'operator' => auth()->id()]);
    }

    /**
     * 删除客户（原控制器 destroy 下沉）：被销售单据引用 1111（订单 + 出库单；
     * 销售表由销售模块创建，未建时守卫自动放行）
     *
     * @param  Customer  $customer  路由绑定的客户模型
     *
     * @throws MasterDataException 被销售单据引用 1111
     */
    public function delete(Customer $customer): void
    {
        if (
            DeletionGuard::referenced('sales_orders', 'customer_id', $customer->id)
            || DeletionGuard::referenced('sales_outbounds', 'customer_id', $customer->id)
        ) {
            throw new MasterDataException('客户已被销售单据使用，不可删除', 1111);
        }
        $customer->delete();

        Log::info('客户删除成功', ['customer_id' => $customer->id, 'code' => $customer->code, 'operator' => auth()->id()]);
    }
}
