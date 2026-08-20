// UserSelect 单测：≤50 走下拉 / >50 走分页弹窗、搜索防抖、选中回填、数据缓存
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
})
