// 认证状态：登录态由后端会话 cookie 决定（Sanctum SPA 会话模式 R4-3 / D-19），
// 用户信息与权限存 store（路由守卫/按钮级权限数据源）；前端不再持久化 token
import { defineStore } from 'pinia'
import { ref } from 'vue'
import { authApi, type AuthUser } from '../api/auth'

export const useAuthStore = defineStore('auth', () => {
  const user = ref<AuthUser | null>(null)

  // 权限集合：登录/me 后填充，供守卫与按钮显隐判断
  const permissions = ref<string[]>([])

  /** 登录：握手取 CSRF cookie → 提交凭证建立会话 → 拉 /me 填充用户权限（token 由后端会话管理，前端不落盘） */
  async function login(username: string, password: string) {
    await authApi.csrf()
    await authApi.login(username, password)
    // 会话已建立：拉取当前会话用户（拉取失败抛后端 message 由登录页展示，凭证已生效不重复提交）
    user.value = await authApi.me()
    permissions.value = user.value.permissions
  }

  /** 登出：撤销服务端会话并清空本地状态（后端失败也需清理本地，避免登出卡死页面） */
  async function logout() {
    try {
      await authApi.logout()
    } finally {
      user.value = null
      permissions.value = []
    }
  }

  /** 拉取当前用户：返回是否已认证（路由守卫首屏以 /me 探测会话；401/网络异常视为未登录并清空登录态） */
  async function fetchMe(): Promise<boolean> {
    try {
      user.value = await authApi.me()
      permissions.value = user.value.permissions
      return true
    } catch {
      // 会话失效或网络异常：清空登录态，由路由守卫跳登录页
      user.value = null
      permissions.value = []
      return false
    }
  }

  /** 按钮级权限判断 */
  function has(permission: string) {
    return permissions.value.includes(permission)
  }

  return { user, permissions, login, logout, fetchMe, has }
})
