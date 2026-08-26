<?php

// 仓库/库位服务：仓库 CRUD（编码唯一 1105 + 删除引用保护 1106）与库位子资源 CRUD
// （编码唯一走格式层 422 unique 规则；删除引用保护 1107）
// 写操作均为单表原子写，无跨表事务，不包 DB::transaction（原子性由单条 insert/update/delete 保证）

namespace App\Services;

use App\Exceptions\MasterDataException;
use App\Models\Location;
use App\Models\Warehouse;
use App\Support\DeletionGuard;
use Illuminate\Support\Facades\Log;

class WarehouseService
{
    /**
     * 新建仓库（原控制器 store 下沉）：编码重复 1105
     *
     * @param  array  $data  已过 SaveWarehouseRequest 格式校验的载荷
     * @return Warehouse 新建仓库（供控制器回显 id）
     *
     * @throws MasterDataException 编码重复 1105
     */
    public function create(array $data): Warehouse
    {
        // 编码唯一 1105（读检查无需持锁；数据库 unique 约束兜底并发场景）
        if (Warehouse::where('code', $data['code'])->exists()) {
            throw new MasterDataException('仓库编码已存在', 1105);
        }
        $warehouse = Warehouse::create([
            'name' => $data['name'],
            'code' => $data['code'],
            'address' => $data['address'] ?? null,
            'manager' => $data['manager'] ?? null,
            'status' => $data['status'] ?? Warehouse::STATUS_ENABLED,
        ]);

        Log::info('仓库创建成功', ['warehouse_id' => $warehouse->id, 'code' => $warehouse->code, 'operator' => auth()->id()]);

        return $warehouse;
    }

    /**
     * 更新仓库（原控制器 update 下沉）：编码唯一（排除自身）1105
     *
     * @param  Warehouse  $warehouse  路由绑定的仓库模型
     * @param  array  $data  已过 SaveWarehouseRequest 格式校验的载荷
     *
     * @throws MasterDataException 编码重复 1105
     */
    public function update(Warehouse $warehouse, array $data): void
    {
        if (Warehouse::where('code', $data['code'])->where('id', '!=', $warehouse->id)->exists()) {
            throw new MasterDataException('仓库编码已存在', 1105);
        }
        $warehouse->update([
            'name' => $data['name'], 'code' => $data['code'],
            'address' => $data['address'] ?? $warehouse->address, 'manager' => $data['manager'] ?? $warehouse->manager,
            'status' => $data['status'] ?? $warehouse->status,
        ]);

        Log::info('仓库更新成功', ['warehouse_id' => $warehouse->id, 'code' => $warehouse->code, 'operator' => auth()->id()]);
    }

    /**
     * 删除仓库（原控制器 destroy 下沉）：被库存余额/流水、盘点单、采购入库/销售出库、
     * 生产单据（领退料/委外/成品入库）引用 1106
     *
     * @param  Warehouse  $warehouse  路由绑定的仓库模型（库位由 FK cascade 级联删除）
     *
     * @throws MasterDataException 存在库存 1106
     */
    public function delete(Warehouse $warehouse): void
    {
        // 覆盖全部 restrictOnDelete 引用表（未建自动放行，建后自动生效）
        if (
            DeletionGuard::referenced('inventory_balances', 'warehouse_id', $warehouse->id)
            || DeletionGuard::referenced('inventory_movements', 'warehouse_id', $warehouse->id)
            || DeletionGuard::referenced('inventory_checks', 'warehouse_id', $warehouse->id)
            || DeletionGuard::referenced('purchase_inbounds', 'warehouse_id', $warehouse->id)
            || DeletionGuard::referenced('sales_outbounds', 'warehouse_id', $warehouse->id)
            || DeletionGuard::referenced('pick_lists', 'warehouse_id', $warehouse->id)
            || DeletionGuard::referenced('return_lists', 'warehouse_id', $warehouse->id)
            || DeletionGuard::referenced('outsourcing_orders', 'warehouse_id', $warehouse->id)
            || DeletionGuard::referenced('outsourcing_receipts', 'warehouse_id', $warehouse->id)
            || DeletionGuard::referenced('finished_inbounds', 'warehouse_id', $warehouse->id)
        ) {
            throw new MasterDataException('仓库存在库存，不可删除', 1106);
        }
        $warehouse->delete();

        Log::info('仓库删除成功', ['warehouse_id' => $warehouse->id, 'code' => $warehouse->code, 'operator' => auth()->id()]);
    }

    /**
     * 新建库位（原控制器 storeLocation 下沉）：编码全局唯一已由格式层 unique 规则把关（422）
     *
     * @param  Warehouse  $warehouse  路由绑定的仓库模型
     * @param  array  $data  已过 SaveLocationRequest 格式校验的载荷
     * @return Location 新建库位（供控制器回显 id）
     */
    public function createLocation(Warehouse $warehouse, array $data): Location
    {
        $location = $warehouse->locations()->create([
            'name' => $data['name'],
            'code' => $data['code'],
            'status' => $data['status'] ?? Location::STATUS_ENABLED,
        ]);

        Log::info('库位创建成功', ['location_id' => $location->id, 'code' => $location->code, 'warehouse_id' => $warehouse->id, 'operator' => auth()->id()]);

        return $location;
    }

    /**
     * 更新库位（原控制器 updateLocation 下沉）
     *
     * @param  Location  $location  路由绑定的库位模型
     * @param  array  $data  已过 SaveLocationRequest 格式校验的载荷
     */
    public function updateLocation(Location $location, array $data): void
    {
        $location->update([
            'name' => $data['name'],
            'code' => $data['code'],
            'status' => $data['status'] ?? $location->status,
        ]);

        Log::info('库位更新成功', ['location_id' => $location->id, 'code' => $location->code, 'operator' => auth()->id()]);
    }

    /**
     * 删除库位（原控制器 destroyLocation 下沉）：被库存余额/流水、盘点明细、采购入库/销售出库、
     * 生产单据（领退料/委外/成品入库）引用 1107
     *
     * @param  Location  $location  路由绑定的库位模型
     *
     * @throws MasterDataException 存在库存 1107
     */
    public function deleteLocation(Location $location): void
    {
        // 覆盖全部 restrictOnDelete 引用表（未建自动放行，建后自动生效）
        if (
            DeletionGuard::referenced('inventory_balances', 'location_id', $location->id)
            || DeletionGuard::referenced('inventory_movements', 'location_id', $location->id)
            || DeletionGuard::referenced('inventory_check_items', 'location_id', $location->id)
            || DeletionGuard::referenced('purchase_inbounds', 'location_id', $location->id)
            || DeletionGuard::referenced('sales_outbounds', 'location_id', $location->id)
            || DeletionGuard::referenced('pick_lists', 'location_id', $location->id)
            || DeletionGuard::referenced('return_lists', 'location_id', $location->id)
            || DeletionGuard::referenced('outsourcing_orders', 'location_id', $location->id)
            || DeletionGuard::referenced('outsourcing_receipts', 'location_id', $location->id)
            || DeletionGuard::referenced('finished_inbounds', 'location_id', $location->id)
        ) {
            throw new MasterDataException('库位存在库存，不可删除', 1107);
        }
        $location->delete();

        Log::info('库位删除成功', ['location_id' => $location->id, 'code' => $location->code, 'operator' => auth()->id()]);
    }
}
