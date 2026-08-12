// HTTP 封装：统一 baseURL、Bearer token、响应解包与 401 处理
import axios from 'axios'

export const http = axios.create({ baseURL: '/api/v1', timeout: 15000 })

// 请求拦截：自动附加 localStorage 中的 token
http.interceptors.request.use((config) => {
  const token = localStorage.getItem('token')
  if (token) config.headers.Authorization = `Bearer ${token}`
  return config
})

// 响应拦截：解包 {code,message,data}；业务失败抛错供调用方展示 message
http.interceptors.response.use(
  (res) => {
    const body = res.data
    if (body && typeof body.code === 'number' && body.code !== 0) {
      // 401/403 统一跳转（由路由守卫处理登录态）
      if (body.code === 401) {
        localStorage.removeItem('token')
        window.location.href = '/login'
      }
      return Promise.reject(new Error(body.message || '请求失败'))
    }
    return res
  },
  (err) => {
    // HTTP 层错误：401 清除登录态并跳登录页
    if (err.response?.status === 401) {
      localStorage.removeItem('token')
      window.location.href = '/login'
    }
    return Promise.reject(err)
  }
)
