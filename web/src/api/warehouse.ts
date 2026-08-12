// 仓库/库位 API 封装：仓库 CRUD + 库位子资源
import { http } from './http'

export interface WarehouseItem {
  id: number
  name: string
  code: string
  address: string | null
  manager: string | null
  status: number
}

export interface LocationItem {
  id: number
  name: string
  code: string
  status: number
}

export const warehouseApi = {
  // 仓库分页列表
  async list(params: { page?: number; per_page?: number; keyword?: string; status?: number }) {
    const { data } = await http.get('/warehouses', { params })
    return data.data as { items: WarehouseItem[]; total: number; page: number; per_page: number }
  },
  // 新建仓库
  async create(payload: {
    name: string
    code: string
    address?: string
    manager?: string
    status?: number
  }) {
    await http.post('/warehouses', payload)
  },
  // 更新仓库
  async update(
    id: number,
    payload: { name: string; code: string; address?: string; manager?: string; status?: number },
  ) {
    await http.put(`/warehouses/${id}`, payload)
  },
  // 删除仓库
  async remove(id: number) {
    await http.delete(`/warehouses/${id}`)
  },
  // 仓库下库位列表（全量）
  async locations(warehouseId: number) {
    const { data } = await http.get(`/warehouses/${warehouseId}/locations`)
    return data.data as { items: LocationItem[] }
  },
  // 新建库位
  async createLocation(
    warehouseId: number,
    payload: { name: string; code: string; status?: number },
  ) {
    await http.post(`/warehouses/${warehouseId}/locations`, payload)
  },
  // 更新库位
  async updateLocation(id: number, payload: { name: string; code: string; status?: number }) {
    await http.put(`/locations/${id}`, payload)
  },
  // 删除库位
  async removeLocation(id: number) {
    await http.delete(`/locations/${id}`)
  },
}
