// 金额格式化/换算工具测试：formatYuan 分转元展示、multiplyCents 精确求积、yuanToFen/fenToYuan 换算、formatThousand 千分位、formatPercent 百分比
import { describe, it, expect } from 'vitest'
import {
  fenToYuan,
  formatPercent,
  formatThousand,
  formatYuan,
  multiplyCents,
  yuanToFen,
} from '../utils/format'

describe('formatYuan', () => {
  it('正常路径：整数分转元两位小数展示', () => {
    // 12345 分 → 123.45 元；0 → 0.00
    expect(formatYuan(12345)).toBe('123.45')
    expect(formatYuan(0)).toBe('0.00')
  })

  it('边界路径：不足两位分位补零、千分位与负数', () => {
    // 5 分 → 0.05；500 分 → 5.00；100000 分 → 1,000.00；-7345 分 → -73.45
    expect(formatYuan(5)).toBe('0.05')
    expect(formatYuan(500)).toBe('5.00')
    expect(formatYuan(100000)).toBe('1,000.00')
    expect(formatYuan(-7345)).toBe('-73.45')
  })

  it('边界路径：大额分值无浮点尾差（/100 浮点路径会失真的量级）', () => {
    // 90071992547409.93 级别（分）远超业务量级，验证 BigInt 字符串路径稳定性
    expect(formatYuan(12345678901)).toBe('123,456,789.01')
  })

  it('异常路径：非法输入防御返回 0.00', () => {
    expect(formatYuan('abc')).toBe('0.00')
  })
})

describe('multiplyCents（与后端 Cents::multiply 同口径 half-up）', () => {
  it('正常路径：数量 × 分单价 → 整数分', () => {
    // 1.55 × 123 分 = 190.65 → half-up 191（裁决示例口径）
    expect(multiplyCents(1.55, 123)).toBe(191)
    // 0.10 × 10 分 = 1 分
    expect(multiplyCents(0.1, 10)).toBe(1)
    // 整数数量直乘：3 × 10 = 30
    expect(multiplyCents(3, 10)).toBe(30)
  })

  it('边界路径：half-up 舍入边界（.49 舍 / .50 入 / .65 入）', () => {
    // 1.9049 × 100 分 = 190.49 → 190（舍）
    expect(multiplyCents('1.9049', 100)).toBe(190)
    // 1.905 × 100 分 = 190.50 → 191（入）
    expect(multiplyCents('1.905', 100)).toBe(191)
    expect(multiplyCents('1.9065', 100)).toBe(191)
  })

  it('边界路径：零与后端 decimal 字符串形态数量', () => {
    // 后端 decimal(12,2) 字符串与 0 值边界
    expect(multiplyCents('15.50', 200)).toBe(3100)
    expect(multiplyCents(0, 9999)).toBe(0)
    expect(multiplyCents('3', 0)).toBe(0)
  })

  it('异常路径：脏输入防御按 0 兜底', () => {
    expect(multiplyCents('abc', 100)).toBe(0)
    expect(multiplyCents('', 100)).toBe(0)
  })
})

describe('yuanToFen（元→分，替代 Math.round(元×100) 浮点路径）', () => {
  it('正常路径：两位小数元转分', () => {
    expect(yuanToFen('123.45')).toBe(12345)
    expect(yuanToFen(123.45)).toBe(12345)
    expect(yuanToFen('0.10')).toBe(10)
  })

  it('边界路径：整数元补足分位与零', () => {
    expect(yuanToFen('123')).toBe(12300)
    expect(yuanToFen(0)).toBe(0)
    expect(yuanToFen('.5')).toBe(50)
  })

  it('边界路径：第 3 位小数 half-up 进位（表单 precision=2 下不可达，防御口径）', () => {
    expect(yuanToFen('0.005')).toBe(1)
    expect(yuanToFen('0.004')).toBe(0)
  })

  it('异常路径：非法输入防御返回 0', () => {
    expect(yuanToFen('abc')).toBe(0)
    expect(yuanToFen('')).toBe(0)
  })
})

describe('fenToYuan（分→元数值，价格输入框回填）', () => {
  it('正常路径：整数分转元数值', () => {
    // 12345 分 → 123.45 元；500 分 → 5（数值形态，输入框展示 5）
    expect(fenToYuan(12345)).toBe(123.45)
    expect(fenToYuan(500)).toBe(5)
  })

  it('边界路径：不足两位分位补零与负数', () => {
    expect(fenToYuan(5)).toBe(0.05)
    expect(fenToYuan(0)).toBe(0)
    expect(fenToYuan(-7345)).toBe(-73.45)
  })

  it('异常路径：非法输入防御返回 0', () => {
    expect(fenToYuan('abc')).toBe(0)
  })
})

describe('formatThousand', () => {
  it('正常路径：数字字符串千分位 + 2 位小数', () => {
    // '1234567.89' → '1,234,567.89'（zh-CN 千分位）
    expect(formatThousand('1234567.89')).toBe('1,234,567.89')
  })

  it('边界路径：整数补齐 2 位小数', () => {
    expect(formatThousand('100')).toBe('100.00')
  })

  it('异常路径：非法输入防御返回 0.00', () => {
    expect(formatThousand('abc')).toBe('0.00')
  })
})

describe('formatPercent', () => {
  it('正常路径：比率转两位小数百分比（不附 % 号）', () => {
    expect(formatPercent(0.666678)).toBe('66.67')
  })

  it('边界路径：0/1/超 100% 比率与整数比率', () => {
    expect(formatPercent(0)).toBe('0.00')
    expect(formatPercent(1)).toBe('100.00')
    expect(formatPercent(1.05)).toBe('105.00')
  })

  it('异常路径：非法输入防御返回 0.00', () => {
    expect(formatPercent(Number('abc'))).toBe('0.00')
  })
})
