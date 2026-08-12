<?php

// 库存引擎：全系统唯一库存变动入口（采购入库/销售出库/领退料/成品入库/委外收发/盘点统一调用）

namespace App\Services;

use App\Exceptions\InventoryException;
use App\Models\InventoryBalance;
use App\Models\InventoryMovement;
use App\Models\Product;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class InventoryService
{
    /**
     * 统一库存变动入口：事务内写流水 + 更新余额 + 冗余上下限同步
     *
     * @param  array  $movements  变动列表，每条含：product_id/warehouse_id/location_id(int)、
     *                            direction(int 1=入库 -1=出库)、quantity(float 恒正)、
     *                            source_type(InventoryMovement::SOURCE_TYPES 枚举)、source_id(int 来源单据ID)、
     *                            source_no(string 来源单号)、remark(?string 备注)
     * @param  int|null  $operatorId  操作人ID（写入流水 operator_id）
     *
     * @throws InventoryException 出库时余额行不存在或余额不足；任一失败整体回滚
     */
    public function apply(array $movements, ?int $operatorId = null): void
    {
        DB::transaction(function () use ($movements, $operatorId) {
            foreach ($movements as $m) {
                $this->applyOne($m, $operatorId);
            }
        });
    }

    // 单笔变动：行锁余额行 → 出库校验 → 写流水 + 更新余额（与调用方同事务）
    private function applyOne(array $m, ?int $operatorId): void
    {
        $direction = (int) $m['direction'];
        $quantity = (float) $m['quantity'];

        // 行锁：同商品×仓库×库位的并发变动在此串行化
        $balance = InventoryBalance::where('product_id', $m['product_id'])
            ->where('warehouse_id', $m['warehouse_id'])
            ->where('location_id', $m['location_id'])
            ->lockForUpdate()
            ->first();

        // 出库：余额行必须存在且充足（余额允许 0 不允许负，超卖被业务层拒绝）
        if ($direction === -1 && (! $balance || (float) $balance->quantity < $quantity)) {
            $msg = '库存不足：商品 '.$m['product_id'].' 当前余额 '.($balance->quantity ?? 0).'，出库 '.$quantity;
            throw new InventoryException($msg);
        }

        // 入库且余额行不存在：创建（并发首次入库靠联合唯一索引兜底，冲突后重查加锁）
        if (! $balance) {
            try {
                $balance = InventoryBalance::create([
                    'product_id' => $m['product_id'],
                    'warehouse_id' => $m['warehouse_id'],
                    'location_id' => $m['location_id'],
                    'quantity' => 0,
                    'safety_min' => 0,
                    'safety_max' => 0,
                ]);
            } catch (QueryException $e) {
                // 唯一索引冲突（1062）：并发创建同一余额行，重查并加锁串行化
                if (($e->errorInfo[1] ?? null) === 1062) {
                    $balance = InventoryBalance::where('product_id', $m['product_id'])
                        ->where('warehouse_id', $m['warehouse_id'])
                        ->where('location_id', $m['location_id'])
                        ->lockForUpdate()
                        ->firstOrFail();
                } else {
                    throw $e;
                }
            }
        }

        $product = Product::findOrFail($m['product_id']);
        // 余额累加 + 上下限冗余同步（预警计算以商品实时值为准，此冗余仅作快照）
        $balance->quantity = (float) $balance->quantity + $direction * $quantity;
        // 上下限冗余同步（decimal cast 为字符串，显式转 float 保证类型一致）
        $balance->safety_min = (float) $product->safety_min;
        $balance->safety_max = (float) $product->safety_max;
        $balance->save();

        // 流水只增不改不删（审计要求）：每笔变动完整落库
        InventoryMovement::create([
            'product_id' => $m['product_id'],
            'warehouse_id' => $m['warehouse_id'],
            'location_id' => $m['location_id'],
            'direction' => $direction,
            'quantity' => $quantity,
            'balance_after' => $balance->quantity,
            'source_type' => $m['source_type'],
            'source_id' => $m['source_id'],
            'source_no' => $m['source_no'],
            'remark' => $m['remark'] ?? null,
            'operator_id' => $operatorId,
        ]);
    }
}
