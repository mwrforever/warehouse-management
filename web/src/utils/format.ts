// 金额/日期格式化工具：分单位金额转元展示；本地日期拼接（toISOString 为 UTC 会偏移一天）

/**
 * 分 → 元 展示：100000 → '1,000.00'（千分位 + 2 位小数；NaN 防御返回 '0.00'）
 * @param fen 后端金额（分，decimal 字符串或数字）
 */
export function formatYuan(fen: number | string): string {
  const n = Number(fen)
  if (Number.isNaN(n)) return '0.00'
  return (n / 100).toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

/** 本地日期 YYYY-MM-DD（toISOString 为 UTC，东八区凌晨会偏移一天） */
export function toLocalDateString(d: Date): string {
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

/** 千分位格式化（元金额/数量，后端已分转元）：'1234567.89' → '1,234,567.89'（NaN 防御返回 '0.00'） */
export function formatThousand(value: number | string): string {
  const n = Number(value)
  if (Number.isNaN(n)) return '0.00'
  return n.toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

/** 百分比格式化（比率口径）：0.666678 → '66.67'（两位小数，不附 % 号——宽度样式/文案拼接方按需追加；NaN 防御返回 '0.00'） */
export function formatPercent(ratio: number): string {
  const n = Number(ratio)
  if (Number.isNaN(n)) return '0.00'
  return (n * 100).toFixed(2)
}
