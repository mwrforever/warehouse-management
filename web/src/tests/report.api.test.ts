// 报表 API 封装测试：查询参数完整传递（4 接口）
import { describe, it, expect, vi, beforeEach, type Mock } from 'vitest'

vi.mock('../api/http', () => ({
  http: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() },
}))
import { http } from '../api/http'
import { reportApi } from '../api/report'

// mock 句柄：运行时为 vi.fn()，静态类型用 vitest Mock（替代 any）
const mockGet = http.get as Mock

describe('report api', () => {
  beforeEach(() => vi.clearAllMocks())

  it('inventorySummary 携带分组维度参数', async () => {
    // 正常路径：维度参数完整传递
    mockGet.mockResolvedValue({
      data: { code: 0, data: { items: [], total: {}, truncated: false } },
    })
    await reportApi.inventorySummary({ group_by: 'warehouse' })
    expect(http.get).toHaveBeenCalledWith('/reports/inventory-summary', {
      params: { group_by: 'warehouse' },
    })
  })

  it('movementsSummary 携带日期区间/粒度/来源类型参数', async () => {
    // 正常路径：闭区间日期 + 粒度 + 可空来源类型
    mockGet.mockResolvedValue({
      data: { code: 0, data: { items: [], totals: {}, truncated: false } },
    })
    await reportApi.movementsSummary({
      date_from: '2026-08-01',
      date_to: '2026-08-31',
      granularity: 'day',
      source_type: 'pick',
    })
    expect(http.get).toHaveBeenCalledWith('/reports/movements-summary', {
      params: {
        date_from: '2026-08-01',
        date_to: '2026-08-31',
        granularity: 'day',
        source_type: 'pick',
      },
    })
  })

  it('production 携带日期区间与可空成品筛选', async () => {
    // 正常路径：成品筛选参数传递
    mockGet.mockResolvedValue({ data: { code: 0, data: { items: [], truncated: false } } })
    await reportApi.production({ date_from: '2026-08-01', date_to: '2026-08-31', product_id: 3 })
    expect(http.get).toHaveBeenCalledWith('/reports/production', {
      params: { date_from: '2026-08-01', date_to: '2026-08-31', product_id: 3 },
    })
  })

  it('purchaseSales 携带日期区间与粒度参数', async () => {
    // 正常路径：采购销售汇总参数传递
    mockGet.mockResolvedValue({
      data: { code: 0, data: { items: [], totals: {}, truncated: false } },
    })
    await reportApi.purchaseSales({
      date_from: '2026-08-01',
      date_to: '2026-08-31',
      granularity: 'month',
    })
    expect(http.get).toHaveBeenCalledWith('/reports/purchase-sales', {
      params: { date_from: '2026-08-01', date_to: '2026-08-31', granularity: 'month' },
    })
  })
})
