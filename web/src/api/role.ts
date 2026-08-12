// 角色管理 API 封装：列表/权限清单/新建/更新/删除（删除被引用角色被后端拦截返回 1004）
import { http } from './http'

export interface RoleItem {
  id: number
  name: string
  code: string
  remark: string | null
  permissions: string[]
}

export interface PermissionItem {
  id: number
  name: string
  code: string
}

export const roleApi = {
  // 分页列表（每角色带已分配权限 code 集合，用于回填权限树）
  async list(params: { page?: number; per_page?: number; keyword?: string }) {
    const { data } = await http.get('/roles', { params: { per_page: 10, ...params } })
    return data.data as { items: RoleItem[]; total: number; page: number; per_page: number }
  },
  // 权限清单（按 group 分组）：角色编辑弹窗权限树数据源
  async permissions() {
    const { data } = await http.get('/permissions')
    return data.data as { groups: { group: string; permissions: PermissionItem[] }[] }
  },
  // 新建角色并分配权限（permission_ids 为权限 id 数组）
  async create(payload: { name: string; code: string; remark?: string; permission_ids: number[] }) {
    await http.post('/roles', payload)
  },
  // 更新角色并全量重挂权限
  async update(id: number, payload: { name: string; code: string; remark?: string; permission_ids: number[] }) {
    await http.put(`/roles/${id}`, payload)
  },
  // 删除角色（被用户引用时后端返回 1004 拦截）
  async remove(id: number) {
    await http.delete(`/roles/${id}`)
  },
}
