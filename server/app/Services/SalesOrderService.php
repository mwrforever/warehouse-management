<?php

// 销售订单服务：金额 bcmath 整数运算（分单位禁浮点）+ 出库后订单状态重算

namespace App\Services;

use App\Models\SalesOrder;

class SalesOrderService
{
    /**
     * 行金额 = 数量 × 单价（bcmath 2 位小数，整数分运算防浮点误差）
     *
     * @param  string  $quantity  数量（decimal(12,2) 字符串，可含小数）
     * @param  string  $price  单价（分，整数）
     * @return string 行金额（分，2 位小数字符串）
     */
    public function lineAmount(string $quantity, string $price): string
    {
        return bcmul($quantity, $price, 2);
    }

    /**
     * 明细金额合计 = Σ 行金额（bcadd 逐行累加，禁止浮点累加）
     *
     * @param  array  $items  明细行数组，每行含 quantity/price
     * @return string 合计金额（分，2 位小数字符串）
     */
    public function calculateTotal(array $items): string
    {
        $total = '0';
        foreach ($items as $item) {
            $total = bcadd($total, $this->lineAmount((string) $item['quantity'], (string) $item['price']), 2);
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
