// 扫码录入逻辑：逐件/累加四态 + 条码解析 + 数量校验。
// mergeScannedItem 为纯函数核心（可单测）；useScanInbound 包装弹窗交互状态。
import { ref } from 'vue'
import { productApi } from '../api/product'

export interface ScanProduct {
  id: number
  name: string
  code: string
  type: string
  spec: string | null
  unit_name: string | null
}

export interface ScanItem {
  product_id: number
  quantity: number
  name: string
  code: string
  type: string
  [key: string]: unknown
}

export interface MergeOptions {
  /** 宿主明细已存在的商品 id（累加关时视为已在列表） */
  excludedIds: number[]
  autoAccumulate: boolean
  /** 数量上限（宿主页传入并换算好的本次可扫入量，如订单剩余量−表单已填量）；缺省 Infinity 表示不限 */
  maxQuantity?: number
}

// 纯函数：订单场景换算本次可扫入量 = 订单行剩余量 − 表单该商品已填数量（spec §4.4 由宿主页传入）。
// 表单已超剩余量时差值为负：钳制为 0 表示本次不可再扫入，避免提示"不能超过 -2"之类费解文案；
// 无映射商品（独立建单/订单外商品）返回 Infinity 维持原无上限行为。结果按 2 位小数（数量 decimal(12,2)）钳制。
export function calcMaxQuantity(remaining: number | undefined, inForm: number): number {
  if (remaining === undefined) return Infinity
  return Math.max(0, Number((remaining - inForm).toFixed(2)))
}

// 纯函数：按四态语义把扫码商品并入行列表。返回 error 表示拒绝（不修改 rows）。
export function mergeScannedItem(
  rows: ScanItem[],
  item: ScanItem,
  opts: MergeOptions,
): { rows: ScanItem[]; error: string | null } {
  const max = opts.maxQuantity ?? Infinity
  const dup =
    opts.excludedIds.includes(item.product_id) || rows.some((r) => r.product_id === item.product_id)
  if (dup && !opts.autoAccumulate) {
    // 累加关：同条码已在列表则报错提醒，不合并也不加行（spec §4.4）
    return { rows, error: '该商品已在列表中' }
  }
  const existing = rows.find((r) => r.product_id === item.product_id)
  if (existing) {
    // 累加开：合并到同一行、数量相加（数量为 decimal(12,2)，保留 2 位）；
    // 先算合并总量再落值：单次合规但累计超上限时拒绝，原行保持不变（BUG-03）。
    // max 是宿主换算后的本次还可扫入量而非订单剩余量本身，文案保持中性不烤死业务名词
    const merged = Number((existing.quantity + item.quantity).toFixed(2))
    if (merged > max) {
      return { rows, error: `本次累计数量不能超过 ${max}` }
    }
    existing.quantity = merged
    return { rows, error: null }
  }
  // 新行单次校验：本次数量即行总量，超上限直接拒绝
  if (item.quantity > max) {
    return { rows, error: `数量不能超过 ${max}` }
  }
  rows.push({ ...item })
  return { rows, error: null }
}

export interface UseScanInboundOptions {
  /** 宿主已存在明细行的商品 id（累加关判重用；函数形式以读取最新值） */
  excludedIds: () => number[]
  /** 数量上限（宿主页换算好的本次可扫入量，如订单剩余量−表单已填量），返回 Infinity 表示不限 */
  maxQuantity?: (item: ScanItem) => number
  /** 禁扫商品类型（销售场景 raw_material） */
  blockedType?: string
  /** 自定义商品→行解析（盘点账面校验等宿主特有逻辑）；返回 null 表示拒绝 */
  resolveProduct?: (p: ScanProduct) => ScanItem | null
  onError: (msg: string) => void
}

export function useScanInbound(opts: UseScanInboundOptions) {
  const rows = ref<ScanItem[]>([])
  const barcode = ref('')
  const inputRef = ref<{ focus: () => void } | null>(null)
  // 两个开关（spec §4.4）：逐件扫描默认关、自动累加默认开
  const perItem = ref(false)
  const autoAccumulate = ref(true)
  // 逐件关时的待填数量态
  const pending = ref<ScanItem | null>(null)
  const pendingQty = ref(1)
  // 会话序号（BUG-02）：reset（关窗）时递增作废会话，在途条码请求返回后据此丢弃回写，
  // 防止迟到响应写入"幽灵行"在下次开窗时经 add-items 并入单据（spec §7 要求关窗取消在途请求）
  let sessionId = 0

  async function handleScan() {
    const code = barcode.value.trim()
    if (!code) return
    // 捕获发起时的会话序号，await 归来后据此判断会话是否已被 reset 作废
    const seq = sessionId
    try {
      const p = await productApi.byBarcode(code)
      // 迟到守卫：关窗 reset 已递增序号，丢弃本次回写（含 blockedType/解析分支，一律不落地）
      if (seq !== sessionId) return
      if (opts.blockedType && p.type === opts.blockedType) {
        opts.onError('原料商品不可销售')
        return
      }
      const resolved = opts.resolveProduct
        ? opts.resolveProduct(p)
        : ({ product_id: p.id, quantity: 1, name: p.name, code: p.code, type: p.type } as ScanItem)
      if (resolved === null) return // 自定义解析已通过 onError 提示原因（如无账面库存）
      if (perItem.value) {
        // 逐件开：扫一次直接 +1，同款继续扫继续 +1（散件场景）
        addItem({ ...resolved, quantity: 1 })
        barcode.value = ''
        inputRef.value?.focus()
      } else {
        // 逐件关：扫一次聚焦数量框填本次数量
        pending.value = { ...resolved, quantity: 1 }
        pendingQty.value = 1
      }
    } catch (e) {
      // 迟到守卫：关窗 reset 已递增会话序号，迟到的失败（如"条码未匹配"）同样丢弃，
      // 避免弹窗关闭后才弹出过期错误提示（BUG-02：成功回写有守卫，失败提示此前漏了）
      if (seq !== sessionId) return
      // 条码未命中：提示并保留输入便于重扫（spec §7）
      opts.onError(e instanceof Error ? e.message : '条码未匹配到商品')
    }
  }

  function addItem(item: ScanItem) {
    // 上限由宿主页经 maxQuantity 传入并换算（订单场景为剩余量−表单已填量的本次可扫入量）；
    // 单次与合并累计校验收敛在纯函数内
    const max = opts.maxQuantity ? opts.maxQuantity(item) : Infinity
    const r = mergeScannedItem(rows.value, item, {
      excludedIds: opts.excludedIds(),
      autoAccumulate: autoAccumulate.value,
      maxQuantity: max,
    })
    if (r.error) opts.onError(r.error)
  }

  // 逐件关：确认待填数量后加入
  function submitPending() {
    if (!pending.value) return
    const item = { ...pending.value, quantity: Number(pendingQty.value) || 0 }
    if (item.quantity <= 0) {
      opts.onError('数量必须大于 0')
      return
    }
    addItem(item)
    pending.value = null
    // 处理后清空扫码框并保持聚焦，支持连续扫码
    barcode.value = ''
    inputRef.value?.focus()
  }

  function dismissPending() {
    pending.value = null
    barcode.value = ''
    inputRef.value?.focus()
  }

  function reset() {
    // 递增会话序号作废在途扫码的回写（BUG-02：仅清状态取消不了已发出的请求）
    sessionId++
    rows.value = []
    pending.value = null
    barcode.value = ''
    pendingQty.value = 1
  }

  return {
    rows,
    barcode,
    inputRef,
    perItem,
    autoAccumulate,
    pending,
    pendingQty,
    handleScan,
    addItem,
    submitPending,
    dismissPending,
    reset,
  }
}
