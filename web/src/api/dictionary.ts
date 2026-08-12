// 数据字典 API 封装：字典/字典项 CRUD（字典项列表仅返回启用项 status=1）
import { http } from './http'

export interface DictionaryItem {
  id: number
  name: string
  code: string
  remark: string | null
}

export interface DictItem {
  id: number
  label: string
  value: string
  sort: number
  status: number
}

export const dictionaryApi = {
  // 分页字典列表
  async list(params: { page?: number; per_page?: number; keyword?: string }) {
    const { data } = await http.get('/dictionaries', { params: { per_page: 10, ...params } })
    return data.data as { items: DictionaryItem[]; total: number; page: number; per_page: number }
  },
  // 新建字典（编码重复被后端拦截返回 1005）
  async create(payload: { name: string; code: string; remark?: string }) {
    await http.post('/dictionaries', payload)
  },
  // 更新字典
  async update(id: number, payload: { name: string; code: string; remark?: string }) {
    await http.put(`/dictionaries/${id}`, payload)
  },
  // 删除字典（级联删除字典项；删除前前端提示引用此字典的下拉将失效）
  async remove(id: number) {
    await http.delete(`/dictionaries/${id}`)
  },
  // 字典项列表（仅启用项 status=1，按 sort 排序）
  async items(dictId: number) {
    const { data } = await http.get(`/dictionaries/${dictId}/items`)
    return data.data as { items: DictItem[] }
  },
  // 新增字典项
  async createItem(
    dictId: number,
    payload: { label: string; value: string; sort?: number; status?: number },
  ) {
    await http.post(`/dictionaries/${dictId}/items`, payload)
  },
  // 更新字典项
  async updateItem(
    itemId: number,
    payload: { label: string; value: string; sort?: number; status?: number },
  ) {
    await http.put(`/dictionaries/items/${itemId}`, payload)
  },
  // 删除字典项
  async removeItem(itemId: number) {
    await http.delete(`/dictionaries/items/${itemId}`)
  },
}
