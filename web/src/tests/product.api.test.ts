// 商品 API 封装测试：查询参数/创建载荷/扫码查询路径
import { describe, it, expect, vi, beforeEach, type Mock } from 'vitest'

vi.mock('../api/http', () => ({
  http: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() },
}))
import { http } from '../api/http'
import { productApi } from '../api/product'

// mock 句柄：运行时为 vi.fn()，静态类型用 vitest Mock（替代 any）
const mockGet = http.get as Mock
const mockPost = http.post as Mock

describe('product api', () => {
  beforeEach(() => vi.clearAllMocks())

  it('list 携带分页/关键字/类型/分类参数', async () => {
    // 正常路径：查询参数正确传递
    mockGet.mockResolvedValue({
      data: { code: 0, data: { items: [], total: 0, page: 1, per_page: 10 } },
    })
    await productApi.list({ page: 2, keyword: 'MAT', type: 'raw_material', category_id: 3 })
    expect(http.get).toHaveBeenCalledWith('/products', {
      params: { page: 2, per_page: 10, keyword: 'MAT', type: 'raw_material', category_id: 3 },
    })
  })

  it('create 提交完整商品载荷', async () => {
    // 正常路径：创建请求体结构
    mockPost.mockResolvedValue({ data: { code: 0 } })
    await productApi.create({
      name: '铝材',
      code: 'MAT-001',
      type: 'raw_material',
      category_id: 1,
      unit_id: 1,
      spec: '1mm',
      barcode: '888',
      safety_min: 10,
      safety_max: 100,
      status: 1,
    })
    expect(http.post).toHaveBeenCalledWith(
      '/products',
      expect.objectContaining({ code: 'MAT-001', safety_max: 100 }),
    )
  })

  it('byBarcode 命中条码查询路由', async () => {
    // 正常路径：扫码查询路径正确
    mockGet.mockResolvedValue({ data: { code: 0, data: { name: '成品B' } } })
    await productApi.byBarcode('888888')
    expect(http.get).toHaveBeenCalledWith('/products/barcode/888888')
  })

  it('list 响应透传 remark 字段（编辑回填数据源）', async () => {
    // 正常路径：列表项携带 remark，编辑弹窗据此回填（备注静默丢失回归保护）
    mockGet.mockResolvedValue({
      data: {
        code: 0,
        data: {
          items: [{ id: 1, name: '铝材', code: 'MAT-001', remark: '备用料' }],
          total: 1,
          page: 1,
          per_page: 10,
        },
      },
    })
    const res = await productApi.list({ page: 1 })
    expect(res.items[0].remark).toBe('备用料')
  })
})
