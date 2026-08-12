// BOM API 封装测试：创建载荷（含明细数组）/明细查询/启用切换
import { describe, it, expect, vi, beforeEach } from 'vitest'

vi.mock('../api/http', () => ({ http: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() } }))
import { http } from '../api/http'
import { bomApi } from '../api/bom'

describe('bom api', () => {
  beforeEach(() => vi.clearAllMocks())

  it('create 提交单头+明细数组', async () => {
    // 正常路径：items 数组原样传递
    ;(http.post as any).mockResolvedValue({ data: { code: 0, data: { id: 1, code: 'BOM20260812-001' } } })
    await bomApi.create({ product_id: 9, version: 'v1', quantity: 1, items: [{ material_id: 5, quantity: 2, unit_id: 1 }] })
    expect(http.post).toHaveBeenCalledWith('/boms', expect.objectContaining({ items: [{ material_id: 5, quantity: 2, unit_id: 1 }] }))
  })

  it('toggle 提交启用状态', async () => {
    // 正常路径：启用切换请求体
    ;(http.put as any).mockResolvedValue({ data: { code: 0 } })
    await bomApi.toggle(3, 1)
    expect(http.put).toHaveBeenCalledWith('/boms/3/toggle', { status: 1 })
  })

  it('items 查询明细列表', async () => {
    // 正常路径：明细查询路径
    ;(http.get as any).mockResolvedValue({ data: { code: 0, data: { items: [] } } })
    await bomApi.items(3)
    expect(http.get).toHaveBeenCalledWith('/boms/3/items')
  })
})
