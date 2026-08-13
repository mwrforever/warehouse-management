// 仪表盘 API 封装测试：4 接口路径与响应解包（mock http，与 report.api.test.ts 同构）
import { describe, it, expect, vi, beforeEach, type Mock } from 'vitest'

vi.mock('../api/http', () => ({
  http: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() },
}))
import { http } from '../api/http'
import { dashboardApi } from '../api/dashboard'

// mock 句柄：运行时为 vi.fn()，静态类型用 vitest Mock（替代 any）
const mockGet = http.get as Mock

describe('dashboard api', () => {
  beforeEach(() => vi.clearAllMocks())

  it('summary 请求 /dashboard/summary 并解包 data', async () => {
    // 正常路径：路径正确 + 解包统一响应
    const payload = {
      inventory_total_qty: '100.00',
      inventory_value: '150.00',
      today_inbound_qty: '5.00',
      today_outbound_qty: '3.00',
      pending_approvals: 2,
      work_order_running: 1,
      alert_count: 1,
    }
    mockGet.mockResolvedValue({ data: { code: 0, data: payload } })
    await expect(dashboardApi.summary()).resolves.toEqual(payload)
    expect(http.get).toHaveBeenCalledWith('/dashboard/summary')
  })

  it('pendingApprovals 请求 /dashboard/pending-approvals 并解包 items', async () => {
    // 正常路径
    mockGet.mockResolvedValue({ data: { code: 0, data: { items: [] } } })
    await expect(dashboardApi.pendingApprovals()).resolves.toEqual({ items: [] })
    expect(http.get).toHaveBeenCalledWith('/dashboard/pending-approvals')
  })

  it('workOrderProgress 请求 /dashboard/work-order-progress 并解包 items', async () => {
    // 正常路径
    mockGet.mockResolvedValue({ data: { code: 0, data: { items: [] } } })
    await expect(dashboardApi.workOrderProgress()).resolves.toEqual({ items: [] })
    expect(http.get).toHaveBeenCalledWith('/dashboard/work-order-progress')
  })

  it('alerts 请求 /dashboard/alerts 并解包 items', async () => {
    // 正常路径
    mockGet.mockResolvedValue({ data: { code: 0, data: { items: [] } } })
    await expect(dashboardApi.alerts()).resolves.toEqual({ items: [] })
    expect(http.get).toHaveBeenCalledWith('/dashboard/alerts')
  })
})
