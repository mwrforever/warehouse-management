// 供应商 API 封装：分页 CRUD + 关键字搜索
import { http } from './http'

export interface SupplierItem {
  id: number
  name: string
  code: string
  contact: string | null
  phone: string | null
  address: string | null
  remark: string | null
  status: number
}

export const supplierApi = {
  // 分页列表（名称/编码/联系人模糊搜索）
  async list(params: { page?: number; per_page?: number; keyword?: string; status?: number }) {
    const { data } = await http.get('/suppliers', { params })
    return data.data as { items: SupplierItem[]; total: number; page: number; per_page: number }
  },
  // 新建供应商
  async create(payload: { name: string; code: string; contact?: string; phone?: string; address?: string; remark?: string; status?: number }) {
    await http.post('/suppliers', payload)
  },
  // 更新供应商
  async update(id: number, payload: { name: string; code: string; contact?: string; phone?: string; address?: string; remark?: string; status?: number }) {
    await http.put(`/suppliers/${id}`, payload)
  },
  // 删除供应商
  async remove(id: number) {
    await http.delete(`/suppliers/${id}`)
  },
}
