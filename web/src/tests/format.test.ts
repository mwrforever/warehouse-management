// 格式化工具测试：formatThousand 千分位（报表金额/数量列）、formatPercent 百分比（报表占比/KPI）
import { describe, it, expect } from 'vitest'
import { formatPercent, formatThousand } from '../utils/format'

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
