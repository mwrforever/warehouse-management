// UserSelect 单测：≤50 走下拉 / >50 走分页弹窗、选中回填、数据缓存，
// 弹窗防抖搜索（命中收缩不翻转形态、慢响应乱序丢弃守卫）
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import ElementPlus from 'element-plus'
import { nextTick } from 'vue'

const listMock = vi.fn()
vi.mock('../api/user', () => ({
  userApi: { list: (...args: unknown[]) => listMock(...args) },
}))

import UserSelect, { __resetUserSelectCache } from '../components/UserSelect.vue'

function users(n: number) {
  return Array.from({ length: n }, (_, i) => ({
    id: i + 1,
    name: `用户${i + 1}`,
    username: `user${i + 1}`,
    email: null,
    status: 1,
    last_login_at: null,
    roles: [],
  }))
}

describe('UserSelect', () => {
  beforeEach(() => {
    vi.useFakeTimers()
    vi.clearAllMocks()
    __resetUserSelectCache() // 清模块级缓存，避免用例间串扰（pre-flight Finding B 裁决）
  })
  afterEach(() => vi.useRealTimers())

  it('用户 ≤50 时渲染 el-select（下拉直接选择）', async () => {
    listMock.mockResolvedValue({ items: users(10), total: 10 })
    const wrapper = mount(UserSelect, {
      props: { modelValue: null },
      global: { plugins: [ElementPlus] },
    })
    await flushPromises()
    expect(wrapper.findComponent({ name: 'ElSelect' }).exists()).toBe(true)
    expect(wrapper.find('.user-dialog').exists()).toBe(false)
  })

  it('用户 >50 时点击输入框弹出分页搜索弹窗', async () => {
    listMock.mockResolvedValue({ items: users(60), total: 60 })
    const wrapper = mount(UserSelect, {
      props: { modelValue: null },
      global: { plugins: [ElementPlus] },
    })
    await flushPromises()
    expect(wrapper.findComponent({ name: 'ElSelect' }).exists()).toBe(false)
    await wrapper.find('input').trigger('click')
    expect(wrapper.find('.user-dialog').exists()).toBe(true)
  })

  it('分页弹窗选择后回填 modelValue（姓名）', async () => {
    listMock.mockResolvedValue({ items: users(60), total: 60 })
    const wrapper = mount(UserSelect, {
      props: { modelValue: null },
      global: { plugins: [ElementPlus] },
    })
    await flushPromises()
    await wrapper.find('input').trigger('click')
    await nextTick()
    const rows = wrapper.findAll('.user-dialog .el-table__row')
    await rows[0]!.trigger('click')
    expect(wrapper.emitted('update:modelValue')!.at(-1)).toEqual(['用户1'])
  })

  it('下拉模式选择后回填 modelValue', async () => {
    listMock.mockResolvedValue({ items: users(10), total: 10 })
    const wrapper = mount(UserSelect, {
      props: { modelValue: null },
      global: { plugins: [ElementPlus] },
    })
    await flushPromises()
    // 直接对 ElSelect 触发 update:modelValue（对 ElOption 手工 $emit 不驱动 el-select 内部 model，
    // pre-flight Finding H 裁决：改走 ElSelect 组件事件）
    const select = wrapper.findComponent({ name: 'ElSelect' })
    select.vm.$emit('update:modelValue', '用户1')
    await nextTick()
    expect(wrapper.emitted('update:modelValue')!.at(-1)).toEqual(['用户1'])
  })

  it('弹窗内防抖搜索命中 ≤50 时形态不翻转（BUG-01/15）', async () => {
    // 初始 60 用户走弹窗模式；输入关键字后命中仅 3 条
    listMock.mockResolvedValueOnce({ items: users(60), total: 60 })
    listMock.mockResolvedValue({ items: users(3), total: 3 })
    const wrapper = mount(UserSelect, {
      props: { modelValue: null },
      global: { plugins: [ElementPlus] },
    })
    await flushPromises()
    await wrapper.find('input').trigger('click') // 点击输入框弹出搜索弹窗
    // 300ms 防抖到期自动搜索（BUG-15：补齐弹窗防抖搜索路径的用例覆盖）
    await wrapper.find('.search-row input').setValue('用户1')
    vi.advanceTimersByTime(300)
    await flushPromises()
    // 命中数 3 ≤50，但形态由用户总数决定：弹窗不被卸载成 el-select
    expect(wrapper.find('.user-dialog').exists()).toBe(true)
    expect(wrapper.findComponent({ name: 'ElSelect' }).exists()).toBe(false)
    // 防抖搜索已带关键字发出请求
    expect(listMock).toHaveBeenLastCalledWith({ per_page: 10, keyword: '用户1' })
  })

  it('慢响应乱序回写被丢弃：后发搜索结果不被先发慢请求覆盖（BUG-05）', async () => {
    // 首笔搜索（关键字"旧"）响应悬挂，第二笔（关键字"新"）立即返回，随后旧响应才到
    let resolveStale!: (v: { items: ReturnType<typeof users>; total: number }) => void
    listMock.mockImplementation((params: { keyword?: string }) => {
      if (params.keyword === undefined) return Promise.resolve({ items: users(60), total: 60 })
      if (params.keyword === '旧') return new Promise((r) => (resolveStale = r))
      return Promise.resolve({ items: users(3), total: 3 })
    })
    const wrapper = mount(UserSelect, {
      props: { modelValue: null },
      global: { plugins: [ElementPlus] },
    })
    await flushPromises()
    await wrapper.find('input').trigger('click') // 打开弹窗
    const search = async (kw: string) => {
      await wrapper.find('.search-row input').setValue(kw)
      vi.advanceTimersByTime(300) // 防抖到期自动搜索
      await flushPromises()
    }
    await search('旧') // 先发：悬挂未回
    await search('新') // 后发：立即回，3 条命中
    resolveStale({ items: users(80), total: 80 }) // 先发的旧响应迟到
    await flushPromises()
    // 乱序守卫丢弃过期响应：表格与分页器保持后发搜索的结果
    expect(wrapper.findAll('.user-dialog .el-table__row')).toHaveLength(3)
    expect(wrapper.findComponent({ name: 'ElPagination' }).props('total')).toBe(3)
  })

  it('缓存已拉取选项：二次挂载不再请求（组件卸载前缓存复用）', async () => {
    listMock.mockResolvedValue({ items: users(5), total: 5 })
    const mountOne = async () =>
      mount(UserSelect, { props: { modelValue: null }, global: { plugins: [ElementPlus] } })
    const w1 = await mountOne()
    await flushPromises()
    w1.unmount()
    expect(listMock).toHaveBeenCalledTimes(1)
    await mountOne()
    await flushPromises()
    expect(listMock).toHaveBeenCalledTimes(1) // 命中缓存，不再请求
  })

  it('缓存命中保留真实总数：用户 >100 时二次挂载分页 total 不漂移（BUG-11）', async () => {
    // 后端 per_page 钳制 100（UserController min(100)）：items 至多 100 条而 total=120
    listMock.mockResolvedValue({ items: users(100), total: 120 })
    const mountOne = async () =>
      mount(UserSelect, { props: { modelValue: null }, global: { plugins: [ElementPlus] } })
    const w1 = await mountOne()
    await flushPromises()
    w1.unmount()
    const w2 = await mountOne()
    await flushPromises()
    await w2.find('input').trigger('click') // 打开弹窗
    // 缓存命中不重发请求，且分页器总数为真实 120 而非 items.length=100（第 11 页起可达）
    expect(listMock).toHaveBeenCalledTimes(1)
    expect(w2.findComponent({ name: 'ElPagination' }).props('total')).toBe(120)
  })
})
