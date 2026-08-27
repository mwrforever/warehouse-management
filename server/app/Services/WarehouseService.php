<?php

// 仓库/库位服务：仓库 CRUD（编码自动生成 + 删除引用保护 1106）与库位子资源 CRUD
// （库位编码唯一走格式层 422 unique 规则；删除引用保护 1107）
// 创建路径为单表写但保留事务只为编码序列死锁重试语义（与 ProductService 同款，见 create 注释）

namespace App\Services;

use App\Exceptions\MasterDataException;
use App\Models\DocumentSequence;
use App\Models\Location;
use App\Models\Warehouse;
use App\Support\DeletionGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class WarehouseService
{
    public function __construct(private DocumentSequenceService $sequenceService) {}

    /**
     * 新建仓库（原控制器 store 下沉）：编码留空自动生成（DocumentSequenceService 驱动，老库 WH01/WH02 衔接）
     *
     * @param  array  $data  已过 SaveWarehouseRequest 格式校验的载荷（不含 code）
     * @return Warehouse 新建仓库（含自动生成的编码，供控制器响应回填）
     */
    public function create(array $data): Warehouse
    {
        // 事务第 2 参数为死锁(1213)重试次数（机理同 ProductService：库房编码序列行首建
        // 间隙锁死锁败方整体回滚后重跑闭包重新取号，幂等安全）
        $warehouse = DB::transaction(function () use ($data) {
            // 除编码外的仓库属性（本地与自动两条创建路径共用，避免字段清单两份漂移）
            $attributes = [
                'name' => $data['name'],
                'address' => $data['address'] ?? null,
                'province' => $data['province'] ?? null,
                'city' => $data['city'] ?? null,
                'district' => $data['district'] ?? null,
                'town' => $data['town'] ?? null,
                'manager' => $data['manager'] ?? null,
                'status' => $data['status'] ?? Warehouse::STATUS_ENABLED,
            ];

            // 编码不再从载荷接收：走编号配置自动生成（配置缺失按 type 大写兜底 → WH 前缀）。
            // Warehouse::create 必须封装在持久闭包内（B-1 同款）：撞 warehouses 唯一索引的
            // 1062/19 才能被服务的换号重试消化；若 create 落在闭包外，异常直接 500 且序列
            // 自增随事务回滚，自动编码路径将每次取同一号反复失败、永久不可用。
            // legacyMax 兼容历史 WH01/WH02 字母+数字无分隔符样式（seqFromNo 剥离前缀后取序号，
            // 解析失败返回 0 走全新号段）
            return $this->sequenceService->nextNoByConfig(
                DocumentSequence::TYPE_WH,
                fn (string $no) => Warehouse::create($attributes + ['code' => $no]),
                fn (string $prefix, string $dateKey) => ($no = Warehouse::where('code', 'like', $prefix.'%')
                    ->orderByDesc('code')->value('code'))
                    ? DocumentSequenceService::seqFromNo($no, $prefix, $dateKey)
                    : 0,
            );
        }, 2);

        // 创建审计日志：记录自动生成后的编码供追溯
        Log::info('仓库创建成功', ['warehouse_id' => $warehouse->id, 'code' => $warehouse->code, 'operator' => auth()->id()]);

        return $warehouse;
    }

    /**
     * 更新仓库（原控制器 update 下沉）：编码不可改（保持原 code 不变），仅更新业务属性
     *
     * @param  Warehouse  $warehouse  路由绑定的仓库模型
     * @param  array  $data  已过 SaveWarehouseRequest 格式校验的载荷（不含 code）
     */
    public function update(Warehouse $warehouse, array $data): void
    {
        // 编码由系统号段统一管理，改号会造成单据外键展示错位，更新不触碰 code（载荷亦无此字段）
        $warehouse->update([
            'name' => $data['name'],
            'address' => $data['address'] ?? $warehouse->address,
            'province' => $data['province'] ?? $warehouse->province,
            'city' => $data['city'] ?? $warehouse->city,
            'district' => $data['district'] ?? $warehouse->district,
            'town' => $data['town'] ?? $warehouse->town,
            'manager' => $data['manager'] ?? $warehouse->manager,
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
