<?php

// 金额分单位纯函数（R2 裁决落地）：全项目金额的唯一舍入口径——「四舍五入 half-up 到整数分」。
// 背景：数量列为 decimal(12,2)（2 位小数）、单价为 bigint 分单位整数，
// 行金额 = 数量 × 单价在数学上可能产生小数分（如 1.55 × 123 分 = 190.65 分），
// 而金额列一律 bigint 整数分，故统一在此处 half-up 取整，禁止各调用点自行实现舍入。
// 铁律：全程 bcmath 十进制字符串运算，禁止 float 参与金额计算（宪法 §4.1）。

namespace App\Support;

class Cents
{
    /**
     * 行金额 = 数量 × 单价（分），四舍五入 half-up 到整数分
     *
     * bcmul scale 2 先取精确乘积（数量 2 位小数 × 整数分，乘积至多 2 位小数，无精度损失），
     * 再 +0.5 截断到整数位实现 half-up（业务域数量恒正、价格非负，bccomp 截断方向无影响）。
     *
     * @param  string  $quantity  数量（decimal(12,2) 字符串，来源：前端入参/模型 decimal cast，允许 2 位小数，不允许为空）
     * @param  int|string  $priceCents  含税单价（分单位整数，来源：前端入参（integer 校验）或 bigint 列读取；允许 0，禁止负数——由上层业务校验拦截）
     * @return int 行金额（分单位整数，恒 >= 0；半分恰好进位，如 190.50 → 191）
     */
    public static function multiply(string $quantity, int|string $priceCents): int
    {
        // 数量 × 分单价取 2 位小数精确乘积（至多 2 位小数，scale 2 下无截断）
        $product = bcmul($quantity, (string) $priceCents, 2);

        // +0.5 后 scale 0 截断 = 四舍五入 half-up（190.65→191、190.49→190、190.50→191）
        return (int) bcadd($product, '0.5', 0);
    }

    /**
     * 任意分值（可含小数分）四舍五入 half-up 到整数分
     *
     * 供 SQL 聚合结果（如 SUM(数量×单价)）落 bigint 前的统一取整：
     * 先归一为 2 位小数字符串（跨库形态：SQLite 返回 int/float、MySQL 返回 decimal 字符串），
     * 再 +0.5 截断实现 half-up；聚合后单次取整，精度不低于逐行取整。
     *
     * @param  string  $cents  分值十进制字符串（允许 2 位小数，不允许为空；负数场景当前业务不存在，如出现按向零截断处理）
     * @return int 整数分
     */
    public static function round(string $cents): int
    {
        // 归一 2 位小数（剥离跨库返回形态差异，超出 2 位截断）
        $normalized = bcadd($cents, '0', 2);

        // +0.5 截断到整数位 = half-up 取整分
        return (int) bcadd($normalized, '0.5', 0);
    }
}
