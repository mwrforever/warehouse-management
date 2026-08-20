// 扫码逻辑单测：四态行为、同条码报错、数量上限校验、合并相加（纯函数核心，不依赖 Vue 挂载）
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { mergeScannedItem, useScanInbound } from '../composables/useScanInbound'

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
