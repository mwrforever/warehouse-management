<?php

// 供应商服务：供应商创建/更新/删除（编码唯一 1108 + 被采购单据引用保护 1109）
// 写操作均为单表原子写，无跨表事务，不包 DB::transaction（原子性由单条 insert/update/delete 保证）

namespace App\Services;

use App\Exceptions\MasterDataException;
use App\Models\Supplier;
use App\Support\DeletionGuard;
use Illuminate\Support\Facades\Log;

class SupplierService
{
    /**
     * 新建供应商（原控制器 store 下沉）：编码重复 1108
     *
     * @param  array  $data  已过 SaveSupplierRequest 格式校验的载荷
     * @return Supplier 新建供应商（供控制器回显 id）
     *
     * @throws MasterDataException 编码重复 1108
     */
    public function create(array $data): Supplier
    {
        // 编码唯一 1108（读检查无需持锁；数据库 unique 约束兜底并发场景）
        if (Supplier::where('code', $data['code'])->exists()) {
            throw new MasterDataException('供应商编码已存在', 1108);
        }
        $supplier = Supplier::create([
            'name' => $data['name'], 'code' => $data['code'], 'contact' => $data['contact'] ?? null,
            'phone' => $data['phone'] ?? null,
            'province' => $data['province'] ?? null, 'city' => $data['city'] ?? null,
            'district' => $data['district'] ?? null, 'town' => $data['town'] ?? null,
            'address' => $data['address'] ?? null,
            'remark' => $data['remark'] ?? null, 'status' => $data['status'] ?? Supplier::STATUS_ENABLED,
        ]);

        Log::info('供应商创建成功', ['supplier_id' => $supplier->id, 'code' => $supplier->code, 'operator' => auth()->id()]);

        return $supplier;
    }

    /**
     * 更新供应商（原控制器 update 下沉）：编码唯一（排除自身）1108
     *
     * @param  Supplier  $supplier  路由绑定的供应商模型
     * @param  array  $data  已过 SaveSupplierRequest 格式校验的载荷
     *
     * @throws MasterDataException 编码重复 1108
     */
    public function update(Supplier $supplier, array $data): void
    {
        if (Supplier::where('code', $data['code'])->where('id', '!=', $supplier->id)->exists()) {
            throw new MasterDataException('供应商编码已存在', 1108);
        }
        $supplier->update([
            'name' => $data['name'], 'code' => $data['code'], 'contact' => $data['contact'] ?? $supplier->contact,
            'phone' => $data['phone'] ?? $supplier->phone,
            'province' => $data['province'] ?? $supplier->province, 'city' => $data['city'] ?? $supplier->city,
            'district' => $data['district'] ?? $supplier->district, 'town' => $data['town'] ?? $supplier->town,
            'address' => $data['address'] ?? $supplier->address,
            'remark' => $data['remark'] ?? $supplier->remark, 'status' => $data['status'] ?? $supplier->status,
        ]);

        Log::info('供应商更新成功', ['supplier_id' => $supplier->id, 'code' => $supplier->code, 'operator' => auth()->id()]);
    }

    /**
     * 删除供应商（原控制器 destroy 下沉）：被采购订单/入库单或委外加工单引用 1109
     * （生产表未建自动放行，建后自动生效）
     *
     * @param  Supplier  $supplier  路由绑定的供应商模型
     *
     * @throws MasterDataException 被采购单据引用 1109
     */
    public function delete(Supplier $supplier): void
    {
        // 采购订单/采购入库单/委外加工单引用均受保护（同码 1109）
        if (
            DeletionGuard::referenced('purchase_orders', 'supplier_id', $supplier->id)
            || DeletionGuard::referenced('purchase_inbounds', 'supplier_id', $supplier->id)
            || DeletionGuard::referenced('outsourcing_orders', 'supplier_id', $supplier->id)
        ) {
            throw new MasterDataException('供应商已被采购单据使用，不可删除', 1109);
        }
        $supplier->delete();

        Log::info('供应商删除成功', ['supplier_id' => $supplier->id, 'code' => $supplier->code, 'operator' => auth()->id()]);
    }
}
