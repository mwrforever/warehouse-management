// 销售 API 封装测试：查询参数/创建载荷/审核与预填路径/当日汇总
import { describe, it, expect, vi, beforeEach, type Mock } from 'vitest'

vi.mock('../api/http', () => ({
  http: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() },
}))
import { http } from '../api/http'
import { salesApi } from '../api/sales'

describe('sales api', () => {
  beforeEach(() => vi.clearAllMocks())

  it('orders 携带分页/单号/客户/状态/日期筛选参数', async () => {
    // 正常路径：订单查询参数完整传递
    ;(http.get as Mock).mockResolvedValue({
      data: { code: 0, data: { items: [], total: 0, page: 1, per_page: 10 } },
    })
    await salesApi.orders({
      page: 2,
      keyword: 'SO',
      customer_id: 1,
      status: 1,
      date_from: '2026-08-01',
      date_to: '2026-08-12',
    })
    expect(http.get).toHaveBeenCalledWith('/sales/orders', {
      params: {
        page: 2,
        per_page: 10,
        keyword: 'SO',
        customer_id: 1,
        status: 1,
        date_from: '2026-08-01',
        date_to: '2026-08-12',
      },
    })
  })

  it('createOrder 提交草稿载荷（价格为分）', async () => {
    // 正常路径：订单创建请求体（price 分单位）
    ;(http.post as Mock).mockResolvedValue({ data: { code: 0, data: { no: 'SO20260812-001' } } })
    const no = await salesApi.createOrder({
      customer_id: 1,
      order_date: '2026-08-12',
      items: [{ product_id: 5, quantity: 10, price: 10000 }],
    })
    expect(no).toBe('SO20260812-001')
    expect(http.post).toHaveBeenCalledWith('/sales/orders', {
      customer_id: 1,
      order_date: '2026-08-12',
      items: [{ product_id: 5, quantity: 10, price: 10000 }],
    })
  })

  it('approveOrder 走审核路径', async () => {
    // 正常路径：订单审核
    ;(http.post as Mock).mockResolvedValue({ data: { code: 0, data: { no: 'SO20260812-001' } } })
    await salesApi.approveOrder(9)
    expect(http.post).toHaveBeenCalledWith('/sales/orders/9/approve')
  })

  it('availableOrders 查询可出库订单（keyword/per_page 透传，BF-3 remote 下拉数据源）', async () => {
    // 正常路径：从订单生成下拉数据源——单号关键字与分页上限经 params 透传（后端分页钳制 100）
    ;(http.get as Mock).mockResolvedValue({ data: { code: 0, data: { items: [], total: 0 } } })
    await salesApi.availableOrders({ keyword: 'SO2026', per_page: 100 })
    expect(http.get).toHaveBeenCalledWith('/sales/orders/available', {
      params: { keyword: 'SO2026', per_page: 100 },
    })
  })

  it('fromOrder 预填携带订单 ID', async () => {
    // 正常路径：从订单生成预填
    ;(http.get as Mock).mockResolvedValue({ data: { code: 0, data: { order_id: 3, items: [] } } })
    await salesApi.fromOrder(3)
    expect(http.get).toHaveBeenCalledWith('/sales/outbounds/from-order/3')
  })

  it('createOutbound 提交出库单载荷（含订单行引用）', async () => {
    // 正常路径：出库单创建（关联订单行）
    ;(http.post as Mock).mockResolvedValue({ data: { code: 0, data: { no: 'SOUT20260812-001' } } })
    const no = await salesApi.createOutbound({
      customer_id: 1,
      warehouse_id: 1,
      location_id: 1,
      order_id: 3,
      items: [{ product_id: 5, quantity: 6, price: 10000, order_item_id: 7 }],
    })
    expect(no).toBe('SOUT20260812-001')
    expect(http.post).toHaveBeenCalledWith('/sales/outbounds', {
      customer_id: 1,
      warehouse_id: 1,
      location_id: 1,
      order_id: 3,
      items: [{ product_id: 5, quantity: 6, price: 10000, order_item_id: 7 }],
    })
  })

  it('approveOutbound 走审核路径并返回单号', async () => {
    // 正常路径：出库单审核
    ;(http.post as Mock).mockResolvedValue({ data: { code: 0, data: { no: 'SOUT20260812-001' } } })
    const no = await salesApi.approveOutbound(9)
    expect(no).toBe('SOUT20260812-001')
    expect(http.post).toHaveBeenCalledWith('/sales/outbounds/9/approve')
  })

  it('todaySummary 查询当日出库汇总', async () => {
    // 正常路径：列表页汇总行数据源
    ;(http.get as Mock).mockResolvedValue({ data: { code: 0, data: { items: [] } } })
    await salesApi.todaySummary()
    expect(http.get).toHaveBeenCalledWith('/sales/outbounds/today-summary')
  })

  it('orderOutbounds 查询订单出库记录', async () => {
    // 正常路径：订单详情页出库记录 tab
    ;(http.get as Mock).mockResolvedValue({ data: { code: 0, data: { items: [] } } })
    await salesApi.orderOutbounds(3)
    expect(http.get).toHaveBeenCalledWith('/sales/orders/3/outbounds')
  })
})
