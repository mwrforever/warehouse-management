// HTTP 封装：统一 baseURL、会话 cookie 携带、XSRF 校验头、响应解包与 401 处理
import axios from 'axios'

// 会话模式（R4-3 / D-19）：登录态由后端会话 cookie 决定，前端不再持有 token。
// withCredentials 保证跨域部署时随请求携带同源会话 cookie（开发面经 vite 代理同源转发，天然携带）；
// axios 自动从 XSRF-TOKEN cookie 取值放入 X-XSRF-TOKEN 请求头（Laravel 会话 CSRF 校验要求，写请求必须）
export const http = axios.create({
  baseURL: '/api/v1',
  timeout: 15000,
  withCredentials: true,
  xsrfCookieName: 'XSRF-TOKEN',
  xsrfHeaderName: 'X-XSRF-TOKEN',
})

/** 会话握手：先取 XSRF-TOKEN 与 laravel_session cookie（Sanctum SPA 约定，登录等写请求前必须执行）。
 * 端点挂在站根 /sanctum/csrf-cookie（web 中间件组），位于 /api/v1 前缀之外，故覆盖 baseURL 以同源站根请求 */
export function fetchCsrfCookie() {
  return http.get('/sanctum/csrf-cookie', { baseURL: '' })
}

// 响应拦截：解包 {code,message,data}；业务失败抛错供调用方展示 message
http.interceptors.response.use(
  (res) => {
    const body = res.data
    if (body && typeof body.code === 'number' && body.code !== 0) {
      // 401/403 统一跳转（由路由守卫处理登录态）；登录页自身探测会话产生的 401 不跳转，避免重载循环
      if (body.code === 401 && window.location.pathname !== '/login') {
        window.location.href = '/login'
      }
      return Promise.reject(new Error(body.message || '请求失败'))
    }
    return res
  },
  (err) => {
    // HTTP 层错误：401 未认证与 419 CSRF mismatch 均跳登录页（419=会话已失效但 CSRF 校验先行失败，
    // 继续停留只会反复失败；登录页自身探测会话的 401/419 不跳转，防重载循环）
    if (
      (err.response?.status === 401 || err.response?.status === 419) &&
      window.location.pathname !== '/login'
    ) {
      window.location.href = '/login'
    }
    // 其余错误：解出统一响应体中的后端 message 抛出（如 422 重复用户名 1002、403 无权限操作），
    // 供页面 ElMessage.error 展示，避免露出原始 axios 错误文案
    const body = err.response?.data
    if (body && typeof body.message === 'string' && body.message) {
      return Promise.reject(new Error(body.message))
    }
    return Promise.reject(err)
  },
)
