// 采购 API 封装测试：查询参数/创建载荷/审核与预填路径
import { describe, it, expect, vi, beforeEach, type Mock } from 'vitest'

vi.mock('../api/http', () => ({
  http: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() },
}))
import { http } from '../api/http'
import { purchaseApi } from '../api/purchase'

describe('purchase api', () => {
  beforeEach(() => vi.clearAllMocks())

  it('orders 携带分页/单号/供应商/状态/日期筛选参数', async () => {
    // 正常路径：订单查询参数完整传递
    ;(http.get as Mock).mockResolvedValue({
      data: { code: 0, data: { items: [], total: 0, page: 1, per_page: 10 } },
    })
    await purchaseApi.orders({
      page: 2,
      keyword: 'PO',
      supplier_id: 1,
      status: 1,
      date_from: '2026-08-01',
      date_to: '2026-08-12',
    })
    expect(http.get).toHaveBeenCalledWith('/purchase/orders', {
      params: {
        page: 2,
        per_page: 10,
        keyword: 'PO',
        supplier_id: 1,
        status: 1,
        date_from: '2026-08-01',
        date_to: '2026-08-12',
      },
    })
  })

  it('createOrder 提交草稿载荷（价格为分）', async () => {
    // 正常路径：订单创建请求体（price 分单位）
    ;(http.post as Mock).mockResolvedValue({ data: { code: 0, data: { no: 'PO20260812-001' } } })
    const no = await purchaseApi.createOrder({
      supplier_id: 1,
      order_date: '2026-08-12',
      items: [{ product_id: 5, quantity: 100, price: 500 }],
    })
    expect(no).toBe('PO20260812-001')
    expect(http.post).toHaveBeenCalledWith('/purchase/orders', {
      supplier_id: 1,
      order_date: '2026-08-12',
      items: [{ product_id: 5, quantity: 100, price: 500 }],
    })
  })

  it('approveOrder 走审核路径', async () => {
    // 正常路径：订单审核
    ;(http.post as Mock).mockResolvedValue({ data: { code: 0, data: { no: 'PO20260812-001' } } })
    await purchaseApi.approveOrder(9)
    expect(http.post).toHaveBeenCalledWith('/purchase/orders/9/approve')
  })

  it('availableOrders 查询可入库订单', async () => {
    // 正常路径：从订单生成下拉数据源
    ;(http.get as Mock).mockResolvedValue({ data: { code: 0, data: { items: [], total: 0 } } })
    await purchaseApi.availableOrders()
    expect(http.get).toHaveBeenCalledWith('/purchase/orders/available')
  })

  it('fromOrder 预填携带订单 ID', async () => {
    // 正常路径：从订单生成预填
    ;(http.get as Mock).mockResolvedValue({ data: { code: 0, data: { order_id: 3, items: [] } } })
    await purchaseApi.fromOrder(3)
    expect(http.get).toHaveBeenCalledWith('/purchase/inbounds/from-order/3')
  })

  it('createInbound 提交入库单载荷（含订单行引用）', async () => {
    // 正常路径：入库单创建（关联订单行）
    ;(http.post as Mock).mockResolvedValue({ data: { code: 0, data: { no: 'PI20260812-001' } } })
    const no = await purchaseApi.createInbound({
      supplier_id: 1,
      warehouse_id: 1,
      location_id: 1,
      order_id: 3,
      items: [{ product_id: 5, quantity: 60, price: 500, order_item_id: 7 }],
    })
    expect(no).toBe('PI20260812-001')
    expect(http.post).toHaveBeenCalledWith('/purchase/inbounds', {
      supplier_id: 1,
      warehouse_id: 1,
      location_id: 1,
      order_id: 3,
      items: [{ product_id: 5, quantity: 60, price: 500, order_item_id: 7 }],
    })
  })

  it('approveInbound 走审核路径并返回单号', async () => {
    // 正常路径：入库单审核
    ;(http.post as Mock).mockResolvedValue({ data: { code: 0, data: { no: 'PI20260812-001' } } })
    const no = await purchaseApi.approveInbound(9)
    expect(no).toBe('PI20260812-001')
    expect(http.post).toHaveBeenCalledWith('/purchase/inbounds/9/approve')
  })

  it('orderInbounds 查询订单入库记录', async () => {
    // 正常路径：订单详情页入库记录 tab
    ;(http.get as Mock).mockResolvedValue({ data: { code: 0, data: { items: [] } } })
    await purchaseApi.orderInbounds(3)
    expect(http.get).toHaveBeenCalledWith('/purchase/orders/3/inbounds')
  })
})
