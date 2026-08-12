// 库存 API 封装测试：查询参数/创建载荷/审核与导出路径
import { describe, it, expect, vi, beforeEach, type Mock } from 'vitest'

vi.mock('../api/http', () => ({
  http: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() },
}))
import { http } from '../api/http'
import { inventoryApi } from '../api/inventory'

// mock 句柄：运行时为 vi.fn()，静态类型用 vitest Mock（替代 any）
const mockGet = http.get as Mock
const mockPost = http.post as Mock

describe('inventory api', () => {
  beforeEach(() => vi.clearAllMocks())

  it('balances 携带分页/关键字/仓库/类型/预警参数', async () => {
    // 正常路径：余额查询参数完整传递
    mockGet.mockResolvedValue({
      data: { code: 0, data: { items: [], total: 0, page: 1, per_page: 10 } },
    })
    await inventoryApi.balances({
      page: 2,
      keyword: 'MAT',
      warehouse_id: 1,
      type: 'raw_material',
      alert: 1,
    })
    expect(http.get).toHaveBeenCalledWith('/inventory/balances', {
      params: {
        page: 2,
        per_page: 10,
        keyword: 'MAT',
        warehouse_id: 1,
        type: 'raw_material',
        alert: 1,
      },
    })
  })

  it('exportBalances 以 blob 形式请求导出', async () => {
    // 正常路径：导出走 blob 响应（前端触发下载）
    mockGet.mockResolvedValue({ data: new Blob() })
    await inventoryApi.exportBalances({ keyword: 'MAT' })
    expect(http.get).toHaveBeenCalledWith('/inventory/balances/export', {
      params: { keyword: 'MAT' },
      responseType: 'blob',
    })
  })

  it('movements 携带类型/方向/日期筛选', async () => {
    // 正常路径：流水筛选参数
    mockGet.mockResolvedValue({
      data: { code: 0, data: { items: [], total: 0, page: 1, per_page: 10 } },
    })
    await inventoryApi.movements({
      source_type: 'check_in',
      direction: 1,
      date_from: '2026-08-01',
      date_to: '2026-08-12',
    })
    expect(http.get).toHaveBeenCalledWith('/inventory/movements', {
      params: {
        source_type: 'check_in',
        direction: 1,
        date_from: '2026-08-01',
        date_to: '2026-08-12',
      },
    })
  })

  it('createCheck 提交盘点单载荷', async () => {
    // 正常路径：草稿创建请求体
    mockPost.mockResolvedValue({ data: { code: 0, data: { no: 'CK20260812-001' } } })
    const no = await inventoryApi.createCheck({
      warehouse_id: 1,
      remark: '盘点',
      items: [{ product_id: 5, location_id: 2, actual_qty: 105 }],
    })
    expect(no).toBe('CK20260812-001')
    expect(http.post).toHaveBeenCalledWith('/checks', {
      warehouse_id: 1,
      remark: '盘点',
      items: [{ product_id: 5, location_id: 2, actual_qty: 105 }],
    })
  })

  it('approveCheck 走审核路径并返回汇总', async () => {
    // 正常路径：审核响应汇总
    mockPost.mockResolvedValue({
      data: { code: 0, data: { changed_items: 2, increased: 1, decreased: 1 } },
    })
    const res = await inventoryApi.approveCheck(9)
    expect(res).toEqual({ changed_items: 2, increased: 1, decreased: 1 })
    expect(http.post).toHaveBeenCalledWith('/checks/9/approve')
  })

  it('autoBooks 按仓库查询账面', async () => {
    // 正常路径：账面预填路径
    mockGet.mockResolvedValue({ data: { code: 0, data: { items: [] } } })
    await inventoryApi.autoBooks(1)
    expect(http.get).toHaveBeenCalledWith('/checks/auto-books', { params: { warehouse_id: 1 } })
  })
})
