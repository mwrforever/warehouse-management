// 单位 API 封装：分页 CRUD（商品弹窗单位下拉复用 list）
import { http } from './http'

export interface UnitItem {
  id: number
  name: string
  code: string
  status: number
}

export const unitApi = {
  // 分页列表（下拉取全量时传 per_page=100）
  async list(params: { page?: number; per_page?: number; keyword?: string }) {
    const { data } = await http.get('/units', { params })
    return data.data as { items: UnitItem[]; total: number; page: number; per_page: number }
  },
  // 新建单位
  async create(payload: { name: string; code: string; status?: number }) {
    await http.post('/units', payload)
  },
  // 更新单位
  async update(id: number, payload: { name: string; code: string; status?: number }) {
    await http.put(`/units/${id}`, payload)
  },
  // 删除单位
  async remove(id: number) {
    await http.delete(`/units/${id}`)
  },
}
