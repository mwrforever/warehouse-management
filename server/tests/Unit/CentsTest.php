<?php

// 金额分单位纯函数单测（R2）：half-up 舍入是全项目金额唯一取整口径——
// 数量（2 位小数）× 分单价（整数）产生小数分时必须四舍五入到整数分；
// 纯函数无依赖直测，覆盖正常/边界/异常三类场景

namespace Tests\Unit;

use App\Support\Cents;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class CentsTest extends TestCase
{
    #[Test]
    public function test_multiply_integer_product_needs_no_rounding(): void
    {
        // 正常路径：乘积本为整数分（10.50 × 1234 分 = 12957 分），舍入不引入偏差
        $this->assertSame(12957, Cents::multiply('10.50', '1234'));
        $this->assertSame(0, Cents::multiply('10.50', '0'));
    }

    #[Test]
    public function test_multiply_rounds_fractional_cents_half_up(): void
    {
        // 正常路径：1.55 × 123 分 = 190.65 分 → half-up 取整 191 分
        $this->assertSame(191, Cents::multiply('1.55', '123'));
        // 边界路径：恰好半分（2.55 × 150 = 382.50）→ 进位 383（四舍五入方向）
        $this->assertSame(383, Cents::multiply('2.55', '150'));
        // 边界路径：不足半分（0.01 × 124 = 1.24）→ 舍去 1；恰好半分（0.01 × 150 = 1.50）→ 进位 2
        $this->assertSame(1, Cents::multiply('0.01', '124'));
        $this->assertSame(2, Cents::multiply('0.01', '150'));
        // 边界路径：最小数量最小单价（0.01 × 1 = 0.01 分）→ 舍为 0 分
        $this->assertSame(0, Cents::multiply('0.01', '1'));
    }

    #[Test]
    public function test_multiply_accepts_int_price_form(): void
    {
        // 边界路径：分单价以 int 形态传入（前端 JSON 整数）与字符串形态结果一致
        $this->assertSame(Cents::multiply('2.55', '150'), Cents::multiply('2.55', 150));
    }

    #[Test]
    public function test_round_normalizes_cross_db_sum_shapes(): void
    {
        // 正常路径：聚合 SUM 结果跨库形态归一（SQLite int/float、MySQL decimal 字符串）后 half-up
        $this->assertSame(1500, Cents::round('1500'));        // SQLite 整数形态
        $this->assertSame(1500, Cents::round('1500.0'));      // SQLite 浮点字符串形态
        $this->assertSame(1500, Cents::round('1500.00'));     // MySQL decimal 字符串形态
        // 边界路径：聚合含小数分（190.65 + 190.85 = 381.50）→ 进位 382
        $this->assertSame(382, Cents::round('381.50'));
        $this->assertSame(381, Cents::round('381.49'));
        // 边界路径：零值归零
        $this->assertSame(0, Cents::round('0'));
        $this->assertSame(0, Cents::round('0.49'));
    }
}
