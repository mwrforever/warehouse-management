<?php

// 数量折算舍入口径纯函数（R4-5 裁决落地）：全项目「数量按比例折算」结果的唯一取整口径——
// 四舍五入 half-up 到 2 位小数（与金额 Cents 同哲学）。
// 背景：B-4 BOM 非整除折算原依赖 bcdiv/bcmul 的 scale 参数截断（向下取整），
// 折算 1/3 类场景需求量系统性偏小（0.6667→0.66）；裁决统一改为四舍五入，
// 折算终点（落库/落返回值的最终 2 位数量）必须经本函数，禁止调用点自行实现舍入。
// 铁律：全程 bcmath 十进制字符串运算，禁止 float 参与数量折算（宪法 §4.1）。

namespace App\Support;

class Quantity
{
    /**
     * 数量四舍五入 half-up 到指定小数位
     *
     * 实现与 Cents::round 同法：加半个目标单位后按 scale 截断——
     * bcmath 十进制精确表示下「x + 半单位」后截断恒等于 half-up
     * （1.005 → 1.01、1.004 → 1.00、0.6667 → 0.67）。输入为任意小数位
     * 的 decimal 字符串（如 bcdiv 4 位中间值），输出固定 scale 位小数。
     *
     * @param  string  $qty  数量（decimal 字符串，来源：模型 decimal cast / bcmath 中间折算值；允许任意小数位，不允许为空。业务域数量恒正，负值按向零截断处理——与 Cents 同语义，当前折算场景不出现）
     * @param  int  $scale  目标小数位（折算结果落 decimal(12,2) 恒为 2；参数留待未来调整，当前禁止业务调用点传入非 2 值）
     * @return string 舍入后数量（decimal 字符串，固定 scale 位小数，尾零保留）
     */
    public static function round(string $qty, int $scale = 2): string
    {
        // 加半个目标单位（scale=2 时 0.005）后按 scale 截断 = half-up；恒正数量下截断方向无影响
        return bcadd($qty, '0.'.str_repeat('0', $scale).'5', $scale);
    }
}
