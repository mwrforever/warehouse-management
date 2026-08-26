// 金额/日期格式化工具：分单位金额（后端 R2 契约：整数分）转元展示与换算；本地日期拼接（toISOString 为 UTC 会偏移一天）

/** 十进制数解析结果（BigInt 精确运算前的规范化；符号 / 整数部 / 小数部） */
interface ParsedDecimal {
  negative: boolean
  intPart: string
  fracPart: string
}

/**
 * 解析十进制数值为精确组成部分（number 先 toString；支持 '123'/'123.45'/'.5' 形态）
 * 非规格化输入（空串/NaN/科学计数法等）返回 null，由调用方按 0 兜底——防御历史脏数据，不抛错打断渲染
 */
function parseDecimal(value: number | string): ParsedDecimal | null {
  const s = typeof value === 'number' ? value.toString() : value.trim()
  const m = /^([+-]?)(\d*)(?:\.(\d*))?$/.exec(s)
  if (!m || (m[2] === '' && !m[3])) return null
  return { negative: m[1] === '-', intPart: m[2] || '0', fracPart: m[3] ?? '' }
}

/**
 * 分 → 元 展示：12345 → '123.45'、0 → '0.00'、100000 → '1,000.00'（千分位 + 2 位小数；负数带符号；NaN 防御返回 '0.00'）
 * 补零字符串拆分替代 n/100 浮点除法，杜绝大额分值的尾差；全前端金额展示统一入口（宪法 5.5）
 * @param fen 后端金额（分，整数分 number；容忍历史 decimal 字符串形态）
 */
export function formatYuan(fen: number | string): string {
  const n = Number(fen)
  if (Number.isNaN(n)) return '0.00'
  // 历史契约可能带 '.00' 小数尾巴：先归一到整数分；-0.4 之类取整后为 0，不输出 '-0.00'
  const rounded = Math.round(n)
  const sign = rounded < 0 ? '-' : ''
  // 末 2 位作小数部分，不足 3 位左补零（5 → '0.05'、500 → '5.00'）；整数部插千分位逗号
  const abs = BigInt(Math.abs(rounded)).toString().padStart(3, '0')
  const intPart = abs.slice(0, -2).replace(/\B(?=(\d{3})+(?!\d))/g, ',')
  return `${sign}${intPart}.${abs.slice(-2)}`
}

/**
 * 分 → 元 数值：12345 → 123.45、5 → 0.05（价格输入框回填专用；展示场景一律用 formatYuan）
 * 字符串补零拆分规避 /100 浮点尾差；NaN/脏输入防御返回 0
 * @param fen 后端金额（分，整数分 number；容忍历史 decimal 字符串形态）
 */
export function fenToYuan(fen: number | string): number {
  const n = Number(fen)
  if (Number.isNaN(n)) return 0
  const rounded = Math.round(n)
  const sign = rounded < 0 ? '-' : ''
  const abs = BigInt(Math.abs(rounded)).toString().padStart(3, '0')
  return Number(`${sign}${abs.slice(0, -2)}.${abs.slice(-2)}`)
}

/**
 * 元 → 分：123.45 → 12345、0.005 → 1（第 3 位小数 ≥5 进一分，half-up；替代 Math.round(元×100) 浮点乘法路径）
 * 字符串精确解析，正数口径与后端入参校验（整数分）对齐；NaN/脏输入防御返回 0；负价由表单校验与后端 1311/1411 拦截
 * @param yuan 用户输入价格（元，number 或 decimal 字符串，表单 precision=2 保证至多 2 位小数）
 */
export function yuanToFen(yuan: number | string): number {
  const parsed = parseDecimal(yuan)
  if (!parsed) return 0
  // 整数部×100 + 两位小数直接拼接；超 2 位小数按第 3 位 half-up 进位（表单 precision=2 下不可达，纯防御）
  let cents = BigInt(parsed.intPart + parsed.fracPart.slice(0, 2).padEnd(2, '0'))
  if (Number(parsed.fracPart[2] ?? 0) >= 5) cents += 1n
  const result = Number(cents)
  return parsed.negative ? -result : result
}

/**
 * 数量 × 分单价 → 整数分：multiplyCents(1.55, 123) = 191（half-up：190.65→191、190.49→190）
 * 与后端 Cents::multiply 同口径：全精度求积后先截断到 2 位小数（等价 bcmul scale 2，2 位小数数量下为精确值），
 * 再 +0.5 截断到整数分；全程 BigInt 运算，禁 Number 乘法浮点路径
 * @param qty 数量（number 或后端 decimal 字符串；脏输入按 0 兜底）
 * @param priceCents 分单价（整数分；小数被截断——表单换算已保证整数）
 */
export function multiplyCents(qty: string | number, priceCents: number): number {
  const parsed = parseDecimal(qty)
  if (!parsed) return 0
  // 数量全精度展开为整数（'1.905' → 1905，记小数位 n），符号随数量带入（业务量为正，防御负量）
  const qtyAbs = BigInt(parsed.intPart + parsed.fracPart)
  const qtyScaled = parsed.negative ? -qtyAbs : qtyAbs
  const scale = parsed.fracPart.length
  // 全精度乘积（单位 10^-n 分）：先截断到 2 位小数（与后端 bcmul scale 2 同口径，2 位小数数量下为精确值），
  // 再 (积 + 50) 整除 100 取整 = half-up；BigInt 整除向零截断，正负两向均与 bcmath 截断语义一致
  const productFull = qtyScaled * BigInt(Math.trunc(priceCents))
  const product2 =
    scale <= 2 ? productFull * 10n ** BigInt(2 - scale) : productFull / 10n ** BigInt(scale - 2)
  return Number((product2 + 50n) / 100n)
}

/** 本地日期 YYYY-MM-DD（toISOString 为 UTC，东八区凌晨会偏移一天） */
export function toLocalDateString(d: Date): string {
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

/** 千分位格式化（数量列，2 位小数）：'1234567.89' → '1,234,567.89'（NaN 防御返回 '0.00'）；金额展示已统一走 formatYuan（分→元） */
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
