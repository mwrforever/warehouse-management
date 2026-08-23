// 工序报工页组件测试：清除工单选择后重置工序/详情/表单（bug #3 回归：防向旧工单误提交报工）
// （mock productionApi + auth store + vue-router）
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import ElementPlus from 'element-plus'

const ordersMock = vi.fn()
const orderDetailMock = vi.fn()
const reportMock = vi.fn()
vi.mock('../api/production', () => ({
  productionApi: {
    orders: (...args: unknown[]) => ordersMock(...args),
    orderDetail: (...args: unknown[]) => orderDetailMock(...args),
    report: (...args: unknown[]) => reportMock(...args),
  },
}))

// 报工提交权限：默认持有（提交按钮可见）
// 共享可变 store 对象：预填用例需给 authStore.user 赋值后组件仍能读到（每次调用返回新对象会丢赋值）
const authStore = {
  user: null as { name: string } | null,
  has: (p: string) => p === 'production.report.create',
}
vi.mock('../stores/auth', () => ({
  useAuthStore: () => authStore,
}))

// 用户选择器挂载即请求用户列表：mock 返回空列表走下拉模式（total=0 ≤ 50），避免真实 http 请求
vi.mock('../api/user', () => ({
  userApi: {
    list: vi.fn().mockResolvedValue({ items: [], total: 0, page: 1, per_page: 10 }),
  },
}))

vi.mock('vue-router', () => ({
  useRouter: () => ({ push: vi.fn() }),
  // 无路由直达参数：挂载后不自动选中工单
  useRoute: () => ({ query: {} }),
}))

import ReportsView from '../views/production/ReportsView.vue'
import { useAuthStore } from '../stores/auth'

// 工单详情（含一道进行中工序）
function okDetail() {
  return {
    id: 1,
    no: 'MO20260814-001',
    product_id: 9,
    product_name: '成品B',
    product_code: 'FIN-002',
    quantity: 10,
    plan_date: '2026-08-14',
    bom_id: 1,
    bom_code: 'BOM-001',
    status: 2,
    status_label: '生产中',
    completed_qty: 0,
    progress: 0,
    released_at: null,
    completed_at: null,
    closed_at: null,
    remark: null,
    materials: [],
    operations: [
      {
        id: 11,
        seq: 1,
        process_id: 1,
        process_name: '下料',
        process_code: 'CUT',
        status: 1,
        status_label: '进行中',
        qualified_qty: 0,
        defective_qty: 0,
        hours: 0,
      },
    ],
  }
}

describe('ReportsView', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
    ordersMock.mockResolvedValue({
      items: [
        {
          id: 1,
          no: 'MO20260814-001',
          product_id: 9,
          product_name: '成品B',
          product_code: 'FIN-002',
          quantity: 10,
          completed_qty: 0,
          progress: 0,
          plan_date: '2026-08-14',
          status: 2,
          status_label: '生产中',
          released_at: null,
          completed_at: null,
        },
      ],
      total: 1,
      page: 1,
      per_page: 100,
    })
    orderDetailMock.mockResolvedValue(okDetail())
    reportMock.mockResolvedValue(undefined)
  })

  it('选中工单加载工序并渲染报工卡片', async () => {
    // 正常路径：下拉选中工单 → 按 id 拉详情 → 进行中工序卡片出现
    const wrapper = mount(ReportsView, { global: { plugins: [ElementPlus] } })
    await flushPromises()
    const select = wrapper.findComponent({ name: 'ElSelect' })
    await select.vm.$emit('update:modelValue', 1)
    await select.vm.$emit('change', 1)
    await flushPromises()
    expect(orderDetailMock).toHaveBeenCalledWith(1)
    expect(wrapper.text()).toContain('当前工序')
  })

  it('清除工单选择后重置工序与报工卡片，不残留旧工单（bug #3 回归）', async () => {
    // 核心回归：清除选择前卡片已渲染；清除后 detail/operations/reportForm 必须全部重置——
    // 旧实现仅 return 不重置，卡片残留旧工单工序，填数提交即向旧工单写报工
    const wrapper = mount(ReportsView, { global: { plugins: [ElementPlus] } })
    await flushPromises()
    const select = wrapper.findComponent({ name: 'ElSelect' })
    await select.vm.$emit('update:modelValue', 1)
    await select.vm.$emit('change', 1)
    await flushPromises()
    expect(wrapper.text()).toContain('当前工序')
    // 清除选择（el-select clearable 清空形态：空字符串）
    await select.vm.$emit('update:modelValue', '')
    await select.vm.$emit('change', '')
    await flushPromises()
    // 工序步骤条与报工卡片全部消失，空态提示回归
    expect(wrapper.text()).toContain('请选择生产中工单')
    expect(wrapper.text()).not.toContain('当前工序')
    // 无任何报工请求发出
    expect(reportMock).not.toHaveBeenCalled()
  })

  it('操作人预填当前登录用户姓名', async () => {
    // 共享 auth mock：给 user 赋值后组件 onMounted 预填操作人；切换工单/提交报工后均保留预填（不清空操作人）
    setActivePinia(createPinia())
    const auth = useAuthStore()
    // 预填用户按真实 AuthUser 结构补全必填字段（组件仅消费 name），替代原 as never 类型逃逸（BUG-09）
    auth.user = {
      id: 1,
      name: '测试管理员',
      username: 'admin',
      email: null,
      status: 1,
      roles: [],
      permissions: [],
    }
    const wrapper = mount(ReportsView, { global: { plugins: [ElementPlus] } })
    await flushPromises()
    // 操作人 UserSelect 仅在进行中工序卡片内渲染：先选中工单再断言
    const select = wrapper.findComponent({ name: 'ElSelect' })
    await select.vm.$emit('update:modelValue', 1)
    await select.vm.$emit('change', 1)
    await flushPromises()
    // 操作人表单项展示预填的当前登录用户姓名（el-select 选中值以 span 渲染，不落入 input value）
    const opItem = wrapper
      .findAll('.el-form-item')
      .find((f) => f.find('.el-form-item__label').text() === '操作人')
    expect(opItem?.text()).toContain('测试管理员')
    // 提交报工成功后：操作人仍保留预填（后续工序报工默认当前登录用户，spec §4.3）
    const qtyItem = wrapper
      .findAll('.el-form-item')
      .find((f) => f.find('.el-form-item__label').text() === '合格数')
    await qtyItem!.find('input').setValue('5')
    await wrapper
      .findAll('button')
      .find((b) => b.text().includes('报工'))!
      .trigger('click')
    await flushPromises()
    expect(reportMock).toHaveBeenCalledWith(11, expect.objectContaining({ operator: '测试管理员' }))
    expect(opItem?.text()).toContain('测试管理员')
  })
})
