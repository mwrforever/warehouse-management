// 按钮级权限测试：只读账号（仅 *.list 权限）不得渲染 新建/编辑/删除/重置密码 等写操作按钮
// 覆盖安全路径：前端按钮显隐与后端权限拦截双重防线中的前端一侧（核心功能，E2E TC-SYS-05 回归保护）
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import ElementPlus from 'element-plus'
import UsersView from '../views/system/UsersView.vue'
import RolesView from '../views/system/RolesView.vue'
import DictionariesView from '../views/system/DictionariesView.vue'
import { useAuthStore } from '../stores/auth'

// mock 三个 API 模块：列表返回单行数据用于断言行内操作按钮
vi.mock('../api/user', () => ({
  userApi: {
    list: vi.fn().mockResolvedValue({ items: [{ id: 1, username: 'u1', name: '用户1', email: 'u1@test.com', status: 1, roles: [] }], total: 1 }),
    create: vi.fn(),
    update: vi.fn(),
    remove: vi.fn(),
    resetPassword: vi.fn(),
  },
}))
vi.mock('../api/role', () => ({
  roleApi: {
    list: vi.fn().mockResolvedValue({ items: [{ id: 1, name: '管理员', code: 'admin', permissions: [] }], total: 1 }),
    permissions: vi.fn().mockResolvedValue({ groups: [] }),
    create: vi.fn(),
    update: vi.fn(),
    remove: vi.fn(),
  },
}))
vi.mock('../api/dictionary', () => ({
  dictionaryApi: {
    list: vi.fn().mockResolvedValue({ items: [{ id: 1, name: '计量单位', code: 'unit', remark: '' }], total: 1 }),
    create: vi.fn(),
    update: vi.fn(),
    remove: vi.fn(),
    items: vi.fn(),
    createItem: vi.fn(),
    updateItem: vi.fn(),
    removeItem: vi.fn(),
  },
}))

// 工具：过滤页面渲染出的全部按钮文本
function buttonTexts(wrapper: any): string[] {
  return wrapper.findAll('button').map((b: any) => b.text().trim()).filter(Boolean)
}

describe('按钮级权限显隐', () => {
  let store: ReturnType<typeof useAuthStore>
  let pinia: ReturnType<typeof createPinia>

  beforeEach(() => {
    pinia = createPinia()
    setActivePinia(pinia)
    store = useAuthStore()
  })

  // 挂载组件：复用与测试同一 pinia 实例，保证 store 权限与组件内 useAuthStore() 一致
  function mountView(view: any) {
    return mount(view, { global: { plugins: [ElementPlus, pinia] } })
  }

  it('用户管理：仅 user.list 权限时不渲染 新建/编辑/重置密码/删除 按钮', async () => {
    // 异常路径（越权防护）：只读账号看不到任何写操作入口
    store.permissions = ['user.list']
    const wrapper = mountView(UsersView)
    await flushPromises()
    const texts = buttonTexts(wrapper)
    expect(texts).not.toContain('新 建')
    expect(texts).not.toContain('编 辑')
    expect(texts).not.toContain('重置密码')
    expect(texts).not.toContain('删 除')
  })

  it('用户管理：具备全部写权限时渲染 新建/编辑/重置密码/删除 按钮', async () => {
    // 正常路径：管理员可见全部操作
    store.permissions = ['user.list', 'user.create', 'user.update', 'user.delete']
    const wrapper = mountView(UsersView)
    await flushPromises()
    const texts = buttonTexts(wrapper)
    expect(texts).toContain('新 建')
    expect(texts).toContain('编 辑')
    expect(texts).toContain('重置密码')
    expect(texts).toContain('删 除')
  })

  it('角色管理：仅 role.list 权限时不渲染 新建/编辑/删除 按钮', async () => {
    // 异常路径（越权防护）：只读账号看不到角色写操作
    store.permissions = ['role.list']
    const wrapper = mountView(RolesView)
    await flushPromises()
    const texts = buttonTexts(wrapper)
    expect(texts).not.toContain('新 建')
    expect(texts).not.toContain('编 辑')
    expect(texts).not.toContain('删 除')
  })

  it('角色管理：具备全部写权限时渲染 新建/编辑/删除 按钮', async () => {
    // 正常路径：管理员可见角色全部操作
    store.permissions = ['role.list', 'role.create', 'role.update', 'role.delete']
    const wrapper = mountView(RolesView)
    await flushPromises()
    const texts = buttonTexts(wrapper)
    expect(texts).toContain('新 建')
    expect(texts).toContain('编 辑')
    expect(texts).toContain('删 除')
  })

  it('字典管理：仅 dictionary.list 权限时不渲染 新建/编辑/删除 按钮', async () => {
    // 异常路径（越权防护）：只读账号看不到字典写操作
    store.permissions = ['dictionary.list']
    const wrapper = mountView(DictionariesView)
    await flushPromises()
    const texts = buttonTexts(wrapper)
    expect(texts).not.toContain('新 建')
    expect(texts).not.toContain('编 辑')
    expect(texts).not.toContain('删 除')
  })

  it('字典管理：具备全部写权限时渲染 新建/编辑/删除 按钮', async () => {
    // 正常路径：管理员可见字典全部操作
    store.permissions = ['dictionary.list', 'dictionary.create', 'dictionary.update', 'dictionary.delete']
    const wrapper = mountView(DictionariesView)
    await flushPromises()
    const texts = buttonTexts(wrapper)
    expect(texts).toContain('新 建')
    expect(texts).toContain('编 辑')
    expect(texts).toContain('删 除')
  })
})
