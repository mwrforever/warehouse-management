// 生产 API 封装测试：请求路径/参数/响应解包（镜像 sales.api.test.ts 模式）
import { describe, expect, it, vi, beforeEach } from 'vitest'
import { productionApi } from '../api/production'

// mock HTTP 模块：get/post/put/delete 各自返回 { data: 响应体外层 }，断言调用参数
vi.mock('../api/http', () => {
  const http = { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() }
  return { http }
})
import { http } from '../api/http'

describe('productionApi 工单', () => {
  beforeEach(() => vi.clearAllMocks())

  it('orders 分页列表：透传筛选参数并解包分页', async () => {
    vi.mocked(http.get).mockResolvedValue({
      data: { data: { items: [], total: 0, page: 1, per_page: 10 } },
    })
    await productionApi.orders({ keyword: 'MO', status: 1 })
    expect(http.get).toHaveBeenCalledWith('/production/orders', {
      params: { per_page: 10, keyword: 'MO', status: 1 },
    })
  })

  it('createOrder 返回单号', async () => {
    vi.mocked(http.post).mockResolvedValue({ data: { data: { no: 'MO20260812-001' } } })
    const no = await productionApi.createOrder({
      product_id: 1,
      quantity: 10,
      plan_date: '2026-08-12',
    })
    expect(http.post).toHaveBeenCalledWith('/production/orders', {
      product_id: 1,
      quantity: 10,
      plan_date: '2026-08-12',
    })
    expect(no).toBe('MO20260812-001')
  })

  it('releaseOrder 返回缺料警告列表', async () => {
    vi.mocked(http.post).mockResolvedValue({
      data: { data: { warnings: [{ material_name: '铝材', required: 20, stock: 0 }] } },
    })
    const res = await productionApi.releaseOrder(1)
    expect(http.post).toHaveBeenCalledWith('/production/orders/1/release')
    expect(res.warnings).toHaveLength(1)
  })

  it('orderMaterials 解包物料需求', async () => {
    vi.mocked(http.get).mockResolvedValue({
      data: { data: { items: [{ material_id: 1, required_qty: 20 }] } },
    })
    const res = await productionApi.orderMaterials(1)
    expect(http.get).toHaveBeenCalledWith('/production/orders/1/materials')
    expect(res.items[0].required_qty).toBe(20)
  })
})

describe('productionApi 单据', () => {
  beforeEach(() => vi.clearAllMocks())

  it('approvePick 审核领料单返回单号', async () => {
    vi.mocked(http.post).mockResolvedValue({ data: { data: { no: 'PL20260812-001' } } })
    const no = await productionApi.approvePick(1)
    expect(http.post).toHaveBeenCalledWith('/production/picks/1/approve')
    expect(no).toBe('PL20260812-001')
  })

  it('issuePick 返回发料状态文案', async () => {
    vi.mocked(http.post).mockResolvedValue({ data: { data: { issue_status: '全部发料' } } })
    const res = await productionApi.issuePick(1)
    expect(res.issue_status).toBe('全部发料')
  })

  it('fromOrderPicks 预填解包', async () => {
    vi.mocked(http.get).mockResolvedValue({
      data: { data: { order_id: 1, order_no: 'MO20260812-001', items: [] } },
    })
    const res = await productionApi.fromOrderPicks(1)
    expect(http.get).toHaveBeenCalledWith('/production/picks/from-order/1')
    expect(res.order_no).toBe('MO20260812-001')
  })

  it('receiptOutsourcing 回收返回回收单号', async () => {
    vi.mocked(http.post).mockResolvedValue({ data: { data: { no: 'OSR20260812-001' } } })
    const no = await productionApi.receiptOutsourcing(1, {
      quantity: 5,
      warehouse_id: 1,
      location_id: 2,
    })
    expect(http.post).toHaveBeenCalledWith('/production/outsourcings/1/receipts', {
      quantity: 5,
      warehouse_id: 1,
      location_id: 2,
    })
    expect(no).toBe('OSR20260812-001')
  })

  it('approveFinishedInbound 审核成品入库返回单号', async () => {
    vi.mocked(http.post).mockResolvedValue({ data: { data: { no: 'FI20260812-001' } } })
    const no = await productionApi.approveFinishedInbound(1)
    expect(http.post).toHaveBeenCalledWith('/production/finished-inbounds/1/approve')
    expect(no).toBe('FI20260812-001')
  })
})

describe('productionApi 报工与状态流转', () => {
  beforeEach(() => vi.clearAllMocks())

  it('report 提交工序报工', async () => {
    vi.mocked(http.post).mockResolvedValue({ data: { data: {} } })
    await productionApi.report(1, {
      qualified_qty: 5,
      defective_qty: 1,
      hours: 2,
      operator: '张三',
    })
    expect(http.post).toHaveBeenCalledWith('/production/operations/1/reports', {
      qualified_qty: 5,
      defective_qty: 1,
      hours: 2,
      operator: '张三',
    })
  })

  it('operationReports 解包报工记录分页', async () => {
    vi.mocked(http.get).mockResolvedValue({
      data: { data: { items: [{ id: 1, qualified_qty: 5 }], total: 1, page: 1, per_page: 10 } },
    })
    const res = await productionApi.operationReports(1)
    expect(http.get).toHaveBeenCalledWith('/production/operations/1/reports')
    expect(res.items[0].qualified_qty).toBe(5)
  })

  it('startOrder/completeOrder/closeOrder 走状态流转路径', async () => {
    vi.mocked(http.post).mockResolvedValue({ data: { data: {} } })
    await productionApi.startOrder(1)
    await productionApi.completeOrder(1)
    await productionApi.closeOrder(1)
    expect(http.post).toHaveBeenCalledWith('/production/orders/1/start')
    expect(http.post).toHaveBeenCalledWith('/production/orders/1/complete')
    expect(http.post).toHaveBeenCalledWith('/production/orders/1/close')
  })

  it('approveReturn 审核退料单返回单号', async () => {
    vi.mocked(http.post).mockResolvedValue({ data: { data: { no: 'RL20260812-001' } } })
    const no = await productionApi.approveReturn(1)
    expect(http.post).toHaveBeenCalledWith('/production/returns/1/approve')
    expect(no).toBe('RL20260812-001')
  })

  it('approveOutsourcing 发出委外返回单号', async () => {
    vi.mocked(http.post).mockResolvedValue({ data: { data: { no: 'OS20260812-001' } } })
    const no = await productionApi.approveOutsourcing(1)
    expect(http.post).toHaveBeenCalledWith('/production/outsourcings/1/approve')
    expect(no).toBe('OS20260812-001')
  })
})
