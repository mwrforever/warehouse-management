// 认证相关 API 封装（会话模式 R4-3：登录态由后端会话 cookie 决定，前端不持久化 token）
import { http, fetchCsrfCookie } from './http'

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
  // 会话握手：登录/登出等写请求前先 GET /sanctum/csrf-cookie 换取 XSRF-TOKEN cookie（浏览器自动携带）
  async csrf() {
    await fetchCsrfCookie()
  },
  // 登录：提交凭证建立会话（浏览器自动携带 cookie 与 XSRF 头；响应 token 为后端兼容返回，前端不再落盘）
  // 业务失败抛后端 message，供登录页展示
  async login(username: string, password: string) {
    const { data } = await http.post('/auth/login', { username, password })
    // 兜底业务校验：code !== 0 时抛出后端 message（拦截器已处理时此处不会触发）
    if (data && typeof data.code === 'number' && data.code !== 0) {
      throw new Error(data.message || '登录失败')
    }
    return data.data as { token: string; user: AuthUser }
  },
  // 登出：撤销服务端会话（cookie 会话失效后，随后的 /me 探测即 401）
  async logout() {
    await http.post('/auth/logout')
  },
  // 当前用户信息（路由守卫首屏探测会话使用）
  async me() {
    const { data } = await http.get('/auth/me')
    return data.data as AuthUser
  },
}
