// 用户 API 封装测试：分页参数与响应解包正确
import { describe, it, expect, vi, beforeEach } from 'vitest'

vi.mock('../api/http', () => ({ http: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() } }))
import { http } from '../api/http'
import { userApi } from '../api/user'

describe('user api', () => {
  beforeEach(() => vi.clearAllMocks())

  it('list 携带分页与关键字参数', async () => {
    // 正常路径：查询参数正确传递
    ;(http.get as any).mockResolvedValue({ data: { code: 0, data: { items: [], total: 0, page: 1, per_page: 10 } } })
    await userApi.list({ page: 2, keyword: 'tester' })
    expect(http.get).toHaveBeenCalledWith('/users', { params: { page: 2, per_page: 10, keyword: 'tester' } })
  })

  it('create 提交 role_ids 数组', async () => {
    // 正常路径：创建请求体结构
    ;(http.post as any).mockResolvedValue({ data: { code: 0 } })
    await userApi.create({ name: 'x', username: 'u', password: 'Test@12345', status: 1, role_ids: [1] })
    expect(http.post).toHaveBeenCalledWith('/users', expect.objectContaining({ role_ids: [1] }))
  })
})
