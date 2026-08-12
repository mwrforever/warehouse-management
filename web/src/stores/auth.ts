// 认证状态：token 持久化、用户信息与权限（路由守卫/按钮级权限数据源）
import { defineStore } from 'pinia'
import { ref } from 'vue'
import { authApi, type AuthUser } from '../api/auth'

export const useAuthStore = defineStore('auth', () => {
  const token = ref<string | null>(localStorage.getItem('token'))
  const user = ref<AuthUser | null>(null)

  // 权限集合：登录/me 后填充，供守卫与按钮显隐判断
  const permissions = ref<string[]>([])

  /** 登录：调用后端，成功持久化 token 与用户信息 */
  async function login(username: string, password: string) {
    const res = await authApi.login(username, password)
    token.value = res.token
    user.value = res.user
    permissions.value = res.user.permissions
    localStorage.setItem('token', res.token)
  }

  /** 登出：撤销 token 并清空状态 */
  async function logout() {
    try {
      await authApi.logout()
    } finally {
      token.value = null
      user.value = null
      permissions.value = []
      localStorage.removeItem('token')
    }
  }

  /** 拉取当前用户（路由守卫首屏调用；失败视为未登录并清空登录态） */
  async function fetchMe() {
    try {
      user.value = await authApi.me()
      permissions.value = user.value.permissions
    } catch {
      // token 失效或网络异常：清空登录态，由路由守卫跳登录页
      token.value = null
      user.value = null
      permissions.value = []
      localStorage.removeItem('token')
    }
  }

  /** 按钮级权限判断 */
  function has(permission: string) {
    return permissions.value.includes(permission)
  }

  return { token, user, permissions, login, logout, fetchMe, has }
})
