// 用户管理 API 封装：列表/新建/更新/删除/重置密码，统一走 http 拦截器（业务失败抛后端 message）
import { http } from './http'

export interface UserItem {
  id: number
  name: string
  username: string
  email: string | null
  status: number
  last_login_at: string | null
  roles: { id: number; name: string }[]
}

export const userApi = {
  // 分页列表（keyword/status 筛选；per_page 缺省 10，与分页器页容量一致）
  async list(params: { page?: number; per_page?: number; keyword?: string; status?: number }) {
    const { data } = await http.get('/users', { params: { per_page: 10, ...params } })
    return data.data as { items: UserItem[]; total: number; page: number; per_page: number }
  },
  // 新建用户（新建必填密码；role_ids 为角色 id 数组）
  async create(payload: { name: string; username: string; password: string; email?: string; status: number; role_ids: number[] }) {
    await http.post('/users', payload)
  },
  // 更新用户（password 不带=不改密码）
  async update(id: number, payload: { name: string; username: string; email?: string; status: number; role_ids: number[] }) {
    await http.put(`/users/${id}`, payload)
  },
  // 删除用户（内置 admin 删除被后端拦截并返回 1003）
  async remove(id: number) {
    await http.delete(`/users/${id}`)
  },
  // 重置密码（后端校验强度：至少 8 位含字母和数字）
  async resetPassword(id: number, password: string) {
    await http.put(`/users/${id}/reset-password`, { password })
  },
}
