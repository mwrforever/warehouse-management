// 格式化工具测试：formatThousand 千分位（报表金额/数量列）
import { describe, it, expect } from 'vitest'
import { formatThousand } from '../utils/format'

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
