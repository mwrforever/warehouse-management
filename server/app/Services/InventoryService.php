<?php

// 库存引擎：全系统唯一库存变动入口（采购入库/销售出库/领退料/成品入库/委外收发/盘点统一调用）

namespace App\Services;

use App\Exceptions\InventoryException;
use App\Models\InventoryBalance;
use App\Models\InventoryMovement;
use App\Models\Product;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class InventoryService
{
    /**
     * 统一库存变动入口：事务内写流水 + 更新余额 + 冗余上下限同步；
     * 余额行加锁前按 (product_id, warehouse_id, location_id) 升序规范化——调用方传入顺序无关
     *
     * @param  array  $movements  变动列表，每条含：product_id/warehouse_id/location_id(int)、
     *                            direction(int 1=入库 -1=出库)、quantity(string|int|float 恒正，
     *                            引擎内统一 bcadd 归一为两位小数十进制字符串——D-3 铁律禁浮点参与数量运算)、
     *                            source_type(InventoryMovement::SOURCE_TYPES 枚举)、source_id(int 来源单据ID)、
     *                            source_no(string 来源单号)、remark(?string 备注)
     * @param  int|null  $operatorId  操作人ID（写入流水 operator_id）
     *
     * @throws InventoryException 出库时余额行不存在或余额不足；任一失败整体回滚
     */
    public function apply(array $movements, ?int $operatorId = null): void
    {
        // 锁序规范化（B-3）：全项目库存行锁统一按 (product_id, warehouse_id, location_id) 升序获取。
        // 调用方传入序五花八门（明细序/索引序/部分路径已自排序），乱序逐行 lockForUpdate 在跨单据
        // 并发审核时（如采购入库 [P1,P2] 与退料/盘点 [P2,P1] 同仓同位）交叉持锁互等 → InnoDB 死锁
        // 1213 回滚一方（败方 500）；在引擎入口统一排序后，任意调用组合的加锁序列全序单调，
        // 死锁环不可能成立（与报工路径「全集升序获取」同思路，见 OperationReportController 锁序注释）。
        // usort 稳定排序：同余额行多笔变动保持传入相对序（balance_after 快照语义不变）
        usort($movements, fn (array $a, array $b) => [
            (int) $a['product_id'], (int) $a['warehouse_id'], (int) $a['location_id'],
        ] <=> [
            (int) $b['product_id'], (int) $b['warehouse_id'], (int) $b['location_id'],
        ]);

        // 商品预取（P1-2）：N 条明细 → 1 次 whereIn 单查上下限快照（只读，余额行锁语义不涉及商品行，
        // 与逐笔 findOrFail 行为一致仅减查询次数；缺失商品在 applyOne 内回退 findOrFail 保持 404 语义）
        $productIds = array_values(array_unique(array_map(fn ($m) => (int) $m['product_id'], $movements)));
        $productMap = $productIds === []
            ? collect()
            : Product::whereIn('id', $productIds)->select(['id', 'safety_min', 'safety_max'])->get()->keyBy('id');

        DB::transaction(function () use ($movements, $operatorId, $productMap) {
            foreach ($movements as $m) {
                $this->applyOne($m, $operatorId, $productMap);
            }
        });
    }

    // 单笔变动：行锁余额行 → 出库校验 → 写流水 + 更新余额（与调用方同事务）
    private function applyOne(array $m, ?int $operatorId, Collection $productMap): void
    {
        $direction = (int) $m['direction'];
        // 数量统一 bcadd 归一为两位小数十进制字符串（D-3 铁律：数量比较与累加禁止浮点参与；
        // 调用方入参混杂 int/float/decimal 字符串，字符串化后全链路 bcmath 构造性精确）
        $quantity = bcadd((string) $m['quantity'], '0', 2);

        // 行锁：同商品×仓库×库位的并发变动在此串行化
        $balance = InventoryBalance::where('product_id', $m['product_id'])
            ->where('warehouse_id', $m['warehouse_id'])
            ->where('location_id', $m['location_id'])
            ->lockForUpdate()
            ->first();

        // 出库：余额行必须存在且充足（余额允许 0 不允许负，超卖被业务层拒绝；bccomp 字符串比较无浮点误差窗口）
        if ($direction === -1 && (! $balance || bccomp((string) $balance->quantity, $quantity, 2) < 0)) {
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

        // 预取 map 命中即用；缺失（商品不存在）回退 findOrFail 保持既有异常语义
        $product = $productMap[$m['product_id']] ?? Product::findOrFail($m['product_id']);
        // 余额累加 + 上下限冗余同步（预警计算以商品实时值为准，此冗余仅作快照）
        // 累加走 bcadd + bcmul 符号乘（与原浮点 direction*quantity 语义严格一致，任意 direction 值行为等价），
        // bcadd(...,2) 落库前即归一两位小数
        $balance->quantity = bcadd((string) $balance->quantity, bcmul($quantity, (string) $direction, 2), 2);
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
