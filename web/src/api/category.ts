// 分类 API 封装：树形列表 + CRUD
import { http } from './http'

export interface CategoryItem {
  id: number
  name: string
  parent_id: number
  sort: number
  status: number
  children?: CategoryItem[]
}

export const categoryApi = {
  // 树形列表（含全部层级，供管理页 el-tree 与商品页 el-tree-select）
  async tree() {
    const { data } = await http.get('/categories')
    return data.data as CategoryItem[]
  },
  // 新建分类
  async create(payload: { name: string; parent_id: number; sort?: number; status?: number }) {
    const { data } = await http.post('/categories', payload)
    return data.data as { id: number }
  },
  // 更新分类
  async update(
    id: number,
    payload: { name: string; parent_id: number; sort?: number; status?: number },
  ) {
    await http.put(`/categories/${id}`, payload)
  },
  // 删除分类
  async remove(id: number) {
    await http.delete(`/categories/${id}`)
  },
}
