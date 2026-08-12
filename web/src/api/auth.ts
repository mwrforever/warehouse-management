// 认证相关 API 封装
import { http } from './http'

export interface AuthUser {
  id: number
  name: string
  username: string
  email: string | null
  status: number
  roles: { id: number; name: string }[]
  permissions: string[]
}

export const authApi = {
  // 登录：返回 token 与用户信息（业务失败抛后端 message，供登录页展示）
  async login(username: string, password: string) {
    const { data } = await http.post('/auth/login', { username, password })
    // 兜底业务校验：code !== 0 时抛出后端 message（拦截器已处理时此处不会触发）
    if (data && typeof data.code === 'number' && data.code !== 0) {
      throw new Error(data.message || '登录失败')
    }
    return data.data as { token: string; user: AuthUser }
  },
  // 登出
  async logout() {
    await http.post('/auth/logout')
  },
  // 当前用户信息（路由守卫使用）
  async me() {
    const { data } = await http.get('/auth/me')
    return data.data as AuthUser
  },
}
