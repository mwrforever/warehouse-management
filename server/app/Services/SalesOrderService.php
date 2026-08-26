<?php

// 销售订单服务：金额分单位整数运算（R2：bigint 分列 + Cents 统一 half-up 舍入，禁浮点）+ 出库后订单状态重算

namespace App\Services;

use App\Models\SalesOrder;
use App\Support\Cents;

class SalesOrderService
{
    /**
     * 行金额 = 数量 × 单价（分），half-up 取整到整数分
     *
     * 数量为 decimal(12,2)（2 位小数）、单价为 bigint 分整数，乘积可能产生小数分
     * （如 1.55 × 123 分 = 190.65 分）——统一走 Cents::multiply 四舍五入到整数分落 bigint 列。
     *
     * @param  string  $quantity  数量（decimal(12,2) 字符串，来源前端入参/模型 cast，可含 2 位小数）
     * @param  int|string  $priceCents  单价（分单位整数，来源前端 integer 校验入参或 bigint 列读取；允许 0，负数由控制器业务校验拦截）
     * @return int 行金额（分单位整数，恒 >= 0，半分进位）
     */
    public function lineAmount(string $quantity, int|string $priceCents): int
    {
        return Cents::multiply($quantity, $priceCents);
    }

    /**
     * 明细金额合计 = Σ 行金额（整数分逐行累加——行金额已 half-up 为整数分，无浮点参与）
     *
     * @param  array  $items  明细行数组，每行含 quantity/price
     * @return int 合计金额（分单位整数）
     */
    public function calculateTotal(array $items): int
    {
        $total = 0;
        foreach ($items as $item) {
            // 整数分累加（PHP int 精确，金额域远低于 int64 上限）
            $total += $this->lineAmount((string) $item['quantity'], $item['price']);
        }

        return $total;
    }

    /**
     * 重算订单状态：全部订单行 shipped_qty >= quantity → 已完成；否则 → 部分出库
     *
     * 仅对 已审核/部分出库 的订单生效（已完成/关闭/草稿不扰动）。
     * 由销售出库单审核在事务内调用（回写 shipped_qty 之后）。
     */
    public function syncStatus(?int $orderId): void
    {
        if (! $orderId) {
            return;
        }
        $order = SalesOrder::whereKey($orderId)->firstOrFail();
        if (! in_array($order->status, [SalesOrder::STATUS_APPROVED, SalesOrder::STATUS_PARTIAL], true)) {
            return;
        }
        // 全部行已出完才可判「已完成」（数量带 2 位小数，bcmath 比较防浮点）
        $allDone = $order->items()->get()->every(
            fn ($i) => bccomp((string) $i->shipped_qty, (string) $i->quantity, 2) >= 0
        );
        $order->status = $allDone ? SalesOrder::STATUS_COMPLETED : SalesOrder::STATUS_PARTIAL;
        $order->save();
    }
}
