// BOM API 封装：单头+明细 CRUD + 启用切换 + 明细查询
import { http } from './http'

export interface BomItem {
  id: number
  material_id: number
  material_name: string
  quantity: number
  unit_id: number
  unit_name: string
}

export interface BomRow {
  id: number
  code: string
  product_id: number
  product_name: string | null
  version: string
  quantity: number
  status: number
  remark: string | null
}

export const bomApi = {
  // 分页列表（成品/单号过滤）
  async list(params: { page?: number; per_page?: number; product_id?: number; keyword?: string }) {
    const { data } = await http.get('/boms', { params })
    return data.data as { items: BomRow[]; total: number; page: number; per_page: number }
  },
  // 新建（单头+明细一次提交；status 缺省=启用）
  async create(payload: {
    product_id: number
    version: string
    quantity?: number
    remark?: string
    status?: number
    items: { material_id: number; quantity: number; unit_id: number }[]
  }) {
    const { data } = await http.post('/boms', payload)
    return data.data as { id: number; code: string }
  },
  // 更新（明细全量替换）
  async update(
    id: number,
    payload: {
      product_id: number
      version: string
      quantity?: number
      remark?: string
      status?: number
      items: { material_id: number; quantity: number; unit_id: number }[]
    },
  ) {
    await http.put(`/boms/${id}`, payload)
  },
  // 删除 BOM
  async remove(id: number) {
    await http.delete(`/boms/${id}`)
  },
  // 明细列表（物料名/单位名联查）
  async items(id: number) {
    const { data } = await http.get(`/boms/${id}/items`)
    return data.data as { items: BomItem[] }
  },
  // 启用/停用切换
  async toggle(id: number, status: number) {
    await http.put(`/boms/${id}/toggle`, { status })
  },
}
