// 客户 API 封装：分页 CRUD + 关键字搜索
import { http } from './http'

export interface CustomerItem {
  id: number
  name: string
  code: string
  contact: string | null
  phone: string | null
  address: string | null
  remark: string | null
  status: number
}

export const customerApi = {
  // 分页列表（名称/编码/联系人模糊搜索）
  async list(params: { page?: number; per_page?: number; keyword?: string; status?: number }) {
    const { data } = await http.get('/customers', { params })
    return data.data as { items: CustomerItem[]; total: number; page: number; per_page: number }
  },
  // 新建客户
  async create(payload: {
    name: string
    code: string
    contact?: string
    phone?: string
    address?: string
    remark?: string
    status?: number
  }) {
    await http.post('/customers', payload)
  },
  // 更新客户
  async update(
    id: number,
    payload: {
      name: string
      code: string
      contact?: string
      phone?: string
      address?: string
      remark?: string
      status?: number
    },
  ) {
    await http.put(`/customers/${id}`, payload)
  },
  // 删除客户
  async remove(id: number) {
    await http.delete(`/customers/${id}`)
  },
}
