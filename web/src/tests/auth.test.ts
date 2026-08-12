// 认证 store 测试：登录/登出/权限状态流转（核心路径）
import { describe, it, expect, beforeEach, vi, type Mock } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { useAuthStore } from '../stores/auth'

// mock axios：避免真实网络
vi.mock('../api/http', () => ({
  http: {
    post: vi.fn(),
    get: vi.fn(),
  },
}))

import { http } from '../api/http'

// mock 句柄：运行时为 vi.fn()，静态类型用 vitest Mock（保留 mockResolvedValue 等链式 API，替代 any）
const mockPost = http.post as Mock
const mockGet = http.get as Mock

describe('auth store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    localStorage.clear()
    vi.clearAllMocks()
  })

  it('登录成功后保存 token 与用户权限', async () => {
    // 正常路径：login 保存 token、permissions
    const store = useAuthStore()
    mockPost.mockResolvedValue({
      data: {
        code: 0,
        data: {
          token: 'tk-1',
          user: { id: 1, name: 'a', username: 'admin', roles: [], permissions: ['user.list'] },
        },
      },
    })
    await store.login('admin', 'admin123')
    expect(localStorage.getItem('token')).toBe('tk-1')
    expect(store.permissions).toEqual(['user.list'])
    expect(store.user?.username).toBe('admin')
  })

  it('登录失败抛出后端 message', async () => {
    // 异常路径：1001 时抛错且不存 token
    const store = useAuthStore()
    mockPost.mockResolvedValue({ data: { code: 1001, message: '用户名或密码错误' } })
    await expect(store.login('admin', 'bad')).rejects.toThrow('用户名或密码错误')
    expect(localStorage.getItem('token')).toBeNull()
  })

  it('登出清除 token 与权限', async () => {
    // 正常路径：logout 清理全部状态
    const store = useAuthStore()
    localStorage.setItem('token', 'tk-1')
    store.permissions = ['user.list']
    mockPost.mockResolvedValue({ data: { code: 0 } })
    await store.logout()
    expect(localStorage.getItem('token')).toBeNull()
    expect(store.permissions).toEqual([])
  })

  it('fetchMe 失败(401)时标记未认证', async () => {
    // 异常路径：token 失效时清空状态
    const store = useAuthStore()
    localStorage.setItem('token', 'tk-old')
    mockGet.mockRejectedValue({ response: { status: 401 } })
    await store.fetchMe()
    expect(store.user).toBeNull()
    expect(localStorage.getItem('token')).toBeNull()
  })
})
