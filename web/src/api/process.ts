// 工序 API 封装：全量列表（sort 升序）+ CRUD
import { http } from './http'

export interface ProcessItem {
  id: number
  name: string
  code: string
  sort: number
  description: string | null
  status: number
}

export const processApi = {
  // 全量列表（sort 升序，供管理页与生产模块下拉）
  async list() {
    const { data } = await http.get('/processes')
    return data.data as { items: ProcessItem[] }
  },
  // 新建工序
  async create(payload: { name: string; code: string; sort?: number; description?: string; status?: number }) {
    await http.post('/processes', payload)
  },
  // 更新工序
  async update(id: number, payload: { name: string; code: string; sort?: number; description?: string; status?: number }) {
    await http.put(`/processes/${id}`, payload)
  },
  // 删除工序
  async remove(id: number) {
    await http.delete(`/processes/${id}`)
  },
}
