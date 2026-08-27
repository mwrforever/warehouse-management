// 工序 API 封装：全量列表（sort 升序，支持分类筛选）+ CRUD（编码由后端自动生成）
import { http } from './http'

export interface ProcessItem {
  id: number
  name: string
  code: string
  category_id: number | null
  category_label: string | null
  sort: number
  description: string | null
  status: number
}

export const processApi = {
  // 全量列表（sort 升序，供管理页与生产模块下拉）；category_id 筛选标签分类
  async list(params?: { category_id?: number }) {
    const { data } = await http.get('/processes', { params })
    return data.data as { items: ProcessItem[] }
  },
  // 新建工序（编码不传，后端按 proc 编号配置自动生成 PROC 前缀；响应回填 code 供展示）
  async create(payload: {
    name: string
    category_id?: number | null
    sort?: number
    description?: string
    status?: number
  }) {
    const { data } = await http.post('/processes', payload)
    return data.data as { id: number; code: string }
  },
  // 更新工序（编码保持不变，载荷不含 code）
  async update(
    id: number,
    payload: {
      name: string
      category_id?: number | null
      sort?: number
      description?: string
      status?: number
    },
  ) {
    await http.put(`/processes/${id}`, payload)
  },
  // 删除工序
  async remove(id: number) {
    await http.delete(`/processes/${id}`)
  },
}
