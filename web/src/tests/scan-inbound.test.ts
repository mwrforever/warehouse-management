// 扫码逻辑单测：四态行为、同条码报错、数量上限校验、合并相加（纯函数核心，不依赖 Vue 挂载）
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { mergeScannedItem, useScanInbound, calcMaxQuantity } from '../composables/useScanInbound'

const byBarcodeMock = vi.fn()
vi.mock('../api/product', () => ({
  productApi: { byBarcode: (...args: unknown[]) => byBarcodeMock(...args) },
}))

function item(product_id: number, quantity = 1) {
  return { product_id, quantity, name: `p${product_id}`, code: `C${product_id}`, type: 'finished' }
}

// byBarcode 返回商品形状（含 id，非 product_id；pre-flight Finding F 裁决：mock 对齐真实 API 契约
// web/src/api/product.ts byBarcode 返回 { id, name, code, type, spec, unit_name }）
function product(id: number) {
  return { id, name: `p${id}`, code: `C${id}`, type: 'finished', spec: null, unit_name: null }
}

describe('calcMaxQuantity（订单剩余量换算本次可扫入量，钳制非负）', () => {
  it('订单未映射商品（独立建单/订单外商品）返回 Infinity 维持无上限', () => {
    expect(calcMaxQuantity(undefined, 0)).toBe(Infinity)
  })

  it('正常场景：剩余量减表单已填数量即为本次可扫入量', () => {
    expect(calcMaxQuantity(10, 2)).toBe(8)
  })

  it('金额精度：结果保留 2 位小数（数量明文 decimal(12,2)）', () => {
    expect(calcMaxQuantity(10.123, 2.001)).toBe(8.12)
  })

  it('表单已填超过剩余量：钳制为 0 而非负值（本次不可再扫入）', () => {
    expect(calcMaxQuantity(10, 12)).toBe(0)
  })

  it('表单已填恰好等于剩余量：钳制为 0（边界，同超量不可再扫）', () => {
    expect(calcMaxQuantity(10, 10)).toBe(0)
  })
})

describe('mergeScannedItem（四态合并核心）', () => {
  it('累加开：同商品合并数量相加', () => {
    const rows = [item(1, 2)]
    const r = mergeScannedItem(rows, item(1, 3), { excludedIds: [], autoAccumulate: true })
    expect(r.error).toBeNull()
    expect(rows).toHaveLength(1)
    expect(rows[0]!.quantity).toBe(5)
  })

  it('累加关：同商品已在列表则报错且不加行', () => {
    const rows = [item(1)]
    const r = mergeScannedItem(rows, item(1), { excludedIds: [], autoAccumulate: false })
    expect(r.error).toBe('该商品已在列表中')
    expect(rows).toHaveLength(1)
  })

  it('累加关：excludedIds 中的商品（宿主已有行）同样报错', () => {
    const r = mergeScannedItem([], item(1), { excludedIds: [1], autoAccumulate: false })
    expect(r.error).toBe('该商品已在列表中')
  })

  it('新商品正常追加', () => {
    const rows = [item(1)]
    const r = mergeScannedItem(rows, item(2), { excludedIds: [], autoAccumulate: false })
    expect(r.error).toBeNull()
    expect(rows).toHaveLength(2)
  })

  it('累加开：剩余 10 已扫 8 再合并 5 超限拒绝，原行保持 8（BUG-03 合并后复核）', () => {
    const rows = [item(1, 8)]
    const r = mergeScannedItem(rows, item(1, 5), {
      excludedIds: [],
      autoAccumulate: true,
      maxQuantity: 10,
    })
    // 文案中性化：max 是宿主换算后的本次还可扫入量，不得标注为"订单剩余量"（评审 Important-1）
    expect(r.error).toContain('累计数量不能超过 10')
    expect(rows).toHaveLength(1)
    expect(rows[0]!.quantity).toBe(8)
  })

  it('累加开：合并后恰好等于剩余量放行', () => {
    const rows = [item(1, 8)]
    const r = mergeScannedItem(rows, item(1, 2), {
      excludedIds: [],
      autoAccumulate: true,
      maxQuantity: 10,
    })
    expect(r.error).toBeNull()
    expect(rows[0]!.quantity).toBe(10)
  })

  it('新行单次超限拒绝且不加行', () => {
    const r = mergeScannedItem([], item(1, 11), {
      excludedIds: [],
      autoAccumulate: true,
      maxQuantity: 10,
    })
    expect(r.error).toContain('数量不能超过 10')
    expect(r.rows).toHaveLength(0)
  })
})

describe('useScanInbound 组合式函数', () => {
  beforeEach(() => {
    vi.useFakeTimers()
    vi.clearAllMocks()
  })
  afterEach(() => vi.useRealTimers())

  it('逐件关：扫码后进入 pending 待填数量，确定后加入', async () => {
    byBarcodeMock.mockResolvedValue(product(1))
    const { barcode, handleScan, pending, submitPending, rows, perItem } = useScanInbound({
      excludedIds: () => [],
      onError: vi.fn(),
    })
    expect(perItem.value).toBe(false) // 默认关
    barcode.value = '888888'
    await handleScan()
    expect(pending.value).not.toBeNull()
    expect(rows.value).toHaveLength(0)
    submitPending()
    expect(rows.value).toHaveLength(1)
    expect(rows.value[0]!.product_id).toBe(1)
  })

  it('逐件开：扫码直接 +1，同款再扫继续 +1（累加开）', async () => {
    byBarcodeMock.mockResolvedValue(product(1))
    const { barcode, handleScan, rows, perItem } = useScanInbound({
      excludedIds: () => [],
      onError: vi.fn(),
    })
    perItem.value = true
    barcode.value = '888888'
    await handleScan()
    barcode.value = '888888'
    await handleScan()
    expect(rows.value).toHaveLength(1)
    expect(rows.value[0]!.quantity).toBe(2)
  })

  it('数量上限校验：超过 maxQuantity 拒绝', async () => {
    byBarcodeMock.mockResolvedValue(product(1))
    const onError = vi.fn()
    const { barcode, handleScan, pendingQty, submitPending, rows } = useScanInbound({
      excludedIds: () => [],
      maxQuantity: () => 5,
      onError,
    })
    barcode.value = '888888'
    await handleScan()
    pendingQty.value = 6
    submitPending()
    expect(onError).toHaveBeenCalledWith(expect.stringContaining('不能超过'))
    expect(rows.value).toHaveLength(0)
  })

  it('数量上限合并复核：剩余 10 已扫 8 再提交 5 被拒且原行不变（BUG-03）', async () => {
    byBarcodeMock.mockResolvedValue(product(1))
    const onError = vi.fn()
    const { barcode, handleScan, pendingQty, submitPending, rows } = useScanInbound({
      excludedIds: () => [],
      maxQuantity: () => 10, // 数量上限（宿主传入；订单场景为剩余量−表单已填量的还可扫入量）
      onError,
    })
    barcode.value = '888888'
    await handleScan()
    pendingQty.value = 8
    submitPending()
    expect(rows.value[0]!.quantity).toBe(8)
    // 同商品再次扫码提交 5：单次 5 ≤ 10，但累计 13 > 10，必须拦截
    barcode.value = '888888'
    await handleScan()
    pendingQty.value = 5
    submitPending()
    // 文案中性化：提示的是本次还可扫入量上限，不得标注为"订单剩余量"（评审 Important-1）
    expect(onError).toHaveBeenCalledWith(expect.stringContaining('累计数量不能超过 10'))
    expect(rows.value).toHaveLength(1)
    expect(rows.value[0]!.quantity).toBe(8)
  })

  it('累加关：同条码再次扫码报错「该商品已在列表中」', async () => {
    byBarcodeMock.mockResolvedValue(product(1))
    const onError = vi.fn()
    const { barcode, handleScan, submitPending, rows, autoAccumulate } = useScanInbound({
      excludedIds: () => [],
      onError,
    })
    autoAccumulate.value = false // 关闭累加
    barcode.value = '888888'
    await handleScan()
    submitPending()
    barcode.value = '888888'
    await handleScan()
    submitPending()
    expect(onError).toHaveBeenCalledWith('该商品已在列表中')
    expect(rows.value).toHaveLength(1)
  })

  it('条码未命中：错误提示且不加入（保留输入重扫）', async () => {
    byBarcodeMock.mockRejectedValue(new Error('条码未匹配到商品'))
    const onError = vi.fn()
    const { barcode, handleScan, rows } = useScanInbound({
      excludedIds: () => [],
      onError,
    })
    barcode.value = '000000'
    await handleScan()
    expect(onError).toHaveBeenCalledWith('条码未匹配到商品')
    expect(rows.value).toHaveLength(0)
  })

  it('blockedType 命中禁扫类型：报错不加入（销售原料禁售）', async () => {
    byBarcodeMock.mockResolvedValue({ ...product(1), type: 'raw_material' })
    const onError = vi.fn()
    const { barcode, handleScan, rows } = useScanInbound({
      excludedIds: () => [],
      blockedType: 'raw_material',
      onError,
    })
    barcode.value = '888888'
    await handleScan()
    expect(onError).toHaveBeenCalledWith('原料商品不可销售')
    expect(rows.value).toHaveLength(0)
  })

  it('关窗作废在途扫码：reset 后逐件开的迟到响应不写入 rows（BUG-02 幽灵行）', async () => {
    // 手动控制 byBarcode 的 resolve 时序：扫码发起 → 关窗 reset → 响应才迟到返回
    let resolveByBarcode!: (value: ReturnType<typeof product>) => void
    byBarcodeMock.mockReturnValue(
      new Promise<ReturnType<typeof product>>((resolve) => {
        resolveByBarcode = resolve
      }),
    )
    const onError = vi.fn()
    const { barcode, handleScan, rows, perItem, reset } = useScanInbound({
      excludedIds: () => [],
      onError,
    })
    perItem.value = true // 逐件开：迟到响应路径是 addItem 直接写 rows
    barcode.value = '888888'
    const scanning = handleScan()
    reset() // 关窗：作废会话，迟到响应必须被丢弃
    resolveByBarcode(product(1))
    await scanning
    expect(rows.value).toHaveLength(0)
    expect(onError).not.toHaveBeenCalled()
  })

  it('关窗作废在途扫码：reset 后逐件关的迟到响应不弹出 pending 待填行（BUG-02）', async () => {
    let resolveByBarcode!: (value: ReturnType<typeof product>) => void
    byBarcodeMock.mockReturnValue(
      new Promise<ReturnType<typeof product>>((resolve) => {
        resolveByBarcode = resolve
      }),
    )
    const { barcode, handleScan, pending, reset } = useScanInbound({
      excludedIds: () => [],
      onError: vi.fn(),
    })
    barcode.value = '888888'
    const scanning = handleScan()
    reset() // 关窗：作废会话，迟到响应必须被丢弃
    resolveByBarcode(product(1))
    await scanning
    expect(pending.value).toBeNull()
  })

  it('关窗作废在途扫码：reset 后迟到的条码未匹配不弹出错误提示（BUG-02 失败分支）', async () => {
    // 手动控制 byBarcode 的 reject 时序：扫码发起 → 关窗 reset → 失败才迟到返回
    let rejectByBarcode!: (reason: Error) => void
    byBarcodeMock.mockReturnValue(
      new Promise<ReturnType<typeof product>>((_resolve, reject) => {
        rejectByBarcode = reject
      }),
    )
    const onError = vi.fn()
    const { barcode, handleScan, reset } = useScanInbound({
      excludedIds: () => [],
      onError,
    })
    barcode.value = '000000'
    const scanning = handleScan()
    reset() // 关窗：作废会话，迟到的"条码未匹配"错误提示必须被丢弃（此前 catch 分支漏了会话守卫）
    rejectByBarcode(new Error('条码未匹配到商品'))
    await scanning
    expect(onError).not.toHaveBeenCalled()
  })

  it('reset 作废仅影响在途请求：作废后再扫码（新会话）正常回写', async () => {
    byBarcodeMock.mockResolvedValue(product(1))
    const { barcode, handleScan, rows, perItem, reset } = useScanInbound({
      excludedIds: () => [],
      onError: vi.fn(),
    })
    perItem.value = true
    barcode.value = '888888'
    await handleScan()
    reset()
    barcode.value = '888888'
    await handleScan() // reset 之后的扫码属于新会话，正常写入
    expect(rows.value).toHaveLength(1)
  })

  it('resolveProduct 自定义解析：返回 null 表示拒绝该商品（盘点账面校验）', async () => {
    byBarcodeMock.mockResolvedValue(product(1))
    const onError = vi.fn()
    const { barcode, handleScan, rows } = useScanInbound({
      excludedIds: () => [],
      // 自定义解析自身负责提示原因并返回 null 表示拒绝（pre-flight Finding G 裁决：
      // composable 对 null 静默 return，具体文案是盘点域特有，由宿主 resolveProduct 内调 onError）
      resolveProduct: () => {
        onError('商品在该仓库无库存，无需盘点')
        return null
      },
      onError,
    })
    barcode.value = '888888'
    await handleScan()
    expect(onError).toHaveBeenCalledWith('商品在该仓库无库存，无需盘点')
    expect(rows.value).toHaveLength(0)
  })
})
