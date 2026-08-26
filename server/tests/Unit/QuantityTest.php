<?php

// 数量折算舍入纯函数单测（R4-5/B-4 裁决落地）：half-up 2 位小数是全项目折算唯一取整口径——
// BOM/用量折算终点统一经本函数，覆盖正常/边界/异常三类场景

namespace Tests\Unit;

use App\Support\Quantity;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class QuantityTest extends TestCase
{
    #[Test]
    public function test_round_keeps_exact_two_decimal_values_unchanged(): void
    {
        // 正常路径：折算结果本就整除（10÷2×2=10.00），舍入不引入偏差
        $this->assertSame('10.00', Quantity::round('10.00'));
        $this->assertSame('0.00', Quantity::round('0'));
        $this->assertSame('2.50', Quantity::round('2.5'));
    }

    #[Test]
    public function test_round_raises_non_divisible_fraction_half_up(): void
    {
        // 正常路径（B-4 核心）：非整除折算原截断 0.6667→0.66，裁决后 half-up → 0.67
        $this->assertSame('0.67', Quantity::round('0.6667'));
        // 2/3 折算（数量 1 ÷ 基准 3 × 用量 2）链路形态：4 位中间值 → 折算终点 2 位
        $this->assertSame('0.67', Quantity::round(bcmul(bcdiv('1', '3', 4), '2', 4)));
        // 向下场景：0.3333 → 0.33（不足半分不上进位）
        $this->assertSame('0.33', Quantity::round('0.3333'));
    }

    #[Test]
    public function test_round_rounds_exactly_half_unit_upward(): void
    {
        // 边界路径：恰好半单位（1.005 = 半分之界）→ 进位 1.01（四舍五入方向权威）
        $this->assertSame('1.01', Quantity::round('1.005'));
        // 边界路径：恰好半单位整值（2.5 → 2 位小数半单位 0.005 不足以进位，值保持）
        $this->assertSame('2.50', Quantity::round('2.5'));
        // 最小正增量 0.005 → 进位 0.01（防止折算尾数被归零）
        $this->assertSame('0.01', Quantity::round('0.005'));
    }

    #[Test]
    public function test_round_drops_below_half_unit(): void
    {
        // 边界路径：不足半单位（1.004）→ 舍为 1.00；0.004 → 0.00
        $this->assertSame('1.00', Quantity::round('1.004'));
        $this->assertSame('0.00', Quantity::round('0.004'));
    }

    #[Test]
    public function test_round_is_idempotent_for_serial_conversions(): void
    {
        // 边界路径（幂等性）：折算终点已 2 位后再次入库/比对不改变语义——
        // 链路中串行两处折算（如工单快照 → 领料校验）不产生舍入累积
        $this->assertSame(Quantity::round('0.6667'), Quantity::round(Quantity::round('0.6667')));
        $this->assertSame(Quantity::round('1.005'), Quantity::round(Quantity::round('1.005')));
    }
}
