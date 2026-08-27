// 认证 store 测试：登录/登出/权限状态流转（核心路径，会话模式 R4-3：登录态由后端会话 cookie 决定，前端不落盘 token）
import { describe, it, expect, beforeEach, vi, type Mock } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { useAuthStore } from '../stores/auth'

// mock axios：避免真实网络
vi.mock('../api/http', () => ({
  http: {
    post: vi.fn(),
    get: vi.fn(),
  },
  fetchCsrfCookie: vi.fn(),
}))

import { http, fetchCsrfCookie } from '../api/http'

// mock 句柄：运行时为 vi.fn()，静态类型用 vitest Mock（保留 mockResolvedValue 等链式 API，替代 any）
const mockPost = http.post as Mock
const mockGet = http.get as Mock
const mockCsrf = fetchCsrfCookie as Mock

describe('auth store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    localStorage.clear()
    vi.clearAllMocks()
  })

  it('登录成功先握手再提交凭证，随后拉取用户权限（不落盘 token）', async () => {
    // 正常路径：会话模式——握手 → 登录 → /me 填充用户与权限；token 不再写入 localStorage（D-19）
    const store = useAuthStore()
    mockCsrf.mockResolvedValue({})
    mockPost.mockResolvedValue({
      data: { code: 0, data: { token: 'tk-1', user: { id: 1 } } },
    })
    mockGet.mockResolvedValue({
      data: {
        code: 0,
        data: { id: 1, name: 'a', username: 'admin', roles: [], permissions: ['user.list'] },
      },
    })
    await store.login('admin', 'admin123')
    // 持握手先于凭证提交的时序（Sanctum SPA 约定），且 token 不落盘
    expect(mockCsrf).toHaveBeenCalledOnce()
    expect(mockPost).toHaveBeenCalledWith('/auth/login', {
      username: 'admin',
      password: 'admin123',
    })
    expect(localStorage.getItem('token')).toBeNull()
    expect(store.permissions).toEqual(['user.list'])
    expect(store.user?.username).toBe('admin')
  })

  it('登录失败抛出后端 message 且不建立本地登录态', async () => {
    // 异常路径：1001 时抛错，用户/权限保持空
    const store = useAuthStore()
    mockCsrf.mockResolvedValue({})
    mockPost.mockResolvedValue({ data: { code: 1001, message: '用户名或密码错误' } })
    await expect(store.login('admin', 'bad')).rejects.toThrow('用户名或密码错误')
    expect(store.user).toBeNull()
    expect(store.permissions).toEqual([])
    expect(localStorage.getItem('token')).toBeNull()
  })

  it('登出撤销会话并清空用户与权限', async () => {
    // 正常路径：logout 清理全部本地状态（会话由服务端作废）
    const store = useAuthStore()
    store.user = {
      id: 1,
      name: 'a',
      username: 'admin',
      email: null,
      status: 1,
      roles: [],
      permissions: [],
    }
    store.permissions = ['user.list']
    mockPost.mockResolvedValue({ data: { code: 0 } })
    await store.logout()
    expect(mockPost).toHaveBeenCalledWith('/auth/logout')
    expect(store.user).toBeNull()
    expect(store.permissions).toEqual([])
  })

  it('fetchMe 成功时填充用户权限并返回已认证', async () => {
    // 正常路径：路由守卫首屏以 /me 探测会话
    const store = useAuthStore()
    mockGet.mockResolvedValue({
      data: {
        code: 0,
        data: { id: 1, name: 'a', username: 'admin', roles: [], permissions: ['user.list'] },
      },
    })
    await expect(store.fetchMe()).resolves.toBe(true)
    expect(store.user?.username).toBe('admin')
    expect(store.permissions).toEqual(['user.list'])
  })

  it('fetchMe 失败(401)时标记未认证并清空状态', async () => {
    // 异常路径：会话失效时清空状态，返回 false 供守卫跳登录页
    const store = useAuthStore()
    mockGet.mockRejectedValue({ response: { status: 401 } })
    await expect(store.fetchMe()).resolves.toBe(false)
    expect(store.user).toBeNull()
    expect(store.permissions).toEqual([])
    expect(localStorage.getItem('token')).toBeNull()
  })
})
