// 仪表盘页组件测试：KPI 渲染/并行请求/空态/单区失败重试/权限隐藏/白名单跳转
// （mock dashboardApi + auth store + vue-router）
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import ElementPlus from 'element-plus'

const summaryMock = vi.fn()
const pendingMock = vi.fn()
const progressMock = vi.fn()
const alertsMock = vi.fn()
vi.mock('../api/dashboard', () => ({
  dashboardApi: {
    summary: (...args: unknown[]) => summaryMock(...args),
    pendingApprovals: (...args: unknown[]) => pendingMock(...args),
    workOrderProgress: (...args: unknown[]) => progressMock(...args),
    alerts: (...args: unknown[]) => alertsMock(...args),
  },
}))

// 权限开关：控制 auth.has 返回值（默认持有全部审核权限）
let hasAll = true
vi.mock('../stores/auth', () => ({
  useAuthStore: () => ({
    has: (p: string) =>
      hasAll &&
      [
        'purchase.order.update',
        'purchase.inbound.update',
        'sales.order.update',
        'sales.outbound.update',
        'check.update',
        'production.pick.update',
        'production.return.update',
        'production.outsource.update',
        'production.finished.update',
      ].includes(p),
  }),
}))

// 路由 mock：捕获白名单跳转
const push = vi.fn()
vi.mock('vue-router', () => ({ useRouter: () => ({ push }) }))

import DashboardView from '../views/DashboardView.vue'

// 默认 KPI 响应（字段形状与后端契约一致）
function okSummary() {
  return {
    inventory_total_qty: '1234.50',
    inventory_value: '567.80',
    today_inbound_qty: '10.00',
    today_outbound_qty: '3.00',
    pending_approvals: 2,
    work_order_running: 1,
    alert_count: 1,
  }
}

describe('DashboardView', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
    hasAll = true
    summaryMock.mockResolvedValue(okSummary())
    pendingMock.mockResolvedValue({ items: [] })
    progressMock.mockResolvedValue({ items: [] })
    alertsMock.mockResolvedValue({ items: [] })
  })

  it('挂载即并行请求 4 接口', async () => {
    // 正常路径：4 区接口各一次
    mount(DashboardView, { global: { plugins: [ElementPlus] } })
    await flushPromises()
    expect(summaryMock).toHaveBeenCalledTimes(1)
    expect(pendingMock).toHaveBeenCalledTimes(1)
    expect(progressMock).toHaveBeenCalledTimes(1)
    expect(alertsMock).toHaveBeenCalledTimes(1)
  })

  it('渲染 4 KPI 卡（千分位/方向色前缀/次级文案）', async () => {
    // 正常路径：数值格式化与语义文案
    const wrapper = mount(DashboardView, { global: { plugins: [ElementPlus] } })
    await flushPromises()
    expect(wrapper.text()).toContain('1,234.50')
    expect(wrapper.text()).toContain('库存总值 ¥567.80')
    expect(wrapper.find('.kpi-in').text()).toBe('+10.00')
    expect(wrapper.find('.kpi-out').text()).toBe('-3.00')
    expect(wrapper.find('.kpi-warn').text()).toBe('2')
    expect(wrapper.text()).toContain('生产中 1')
  })

  it('库存总值 null 显示未启用成本核算', async () => {
    // 边界路径：无成本价 → 不显示 ¥0
    summaryMock.mockResolvedValue({ ...okSummary(), inventory_value: null })
    const wrapper = mount(DashboardView, { global: { plugins: [ElementPlus] } })
    await flushPromises()
    expect(wrapper.text()).toContain('未启用成本核算')
    expect(wrapper.text()).not.toContain('库存总值 ¥')
  })

  it('空态：待审核全部已审核/预警库存正常/工单暂无', async () => {
    // 边界路径：三类空态文案
    const wrapper = mount(DashboardView, { global: { plugins: [ElementPlus] } })
    await flushPromises()
    expect(wrapper.text()).toContain('全部单据已审核 ✓')
    expect(wrapper.text()).toContain('库存状态正常')
    expect(wrapper.text()).toContain('暂无进行中工单')
  })

  it('工单进度单区失败：显示重试按钮且其余区正常渲染', async () => {
    // 边界路径（TC-DSH-08 并行容错）：单区失败不影响其他区
    progressMock.mockRejectedValue(new Error('网络错误'))
    const wrapper = mount(DashboardView, { global: { plugins: [ElementPlus] } })
    await flushPromises()
    expect(wrapper.text()).toContain('工单进度加载失败')
    expect(wrapper.text()).toContain('重 试')
    expect(wrapper.text()).toContain('库存总量') // KPI 区正常
    expect(wrapper.text()).toContain('库存状态正常') // 预警区不受影响
  })

  it('无审核权限：待审核 KPI 卡与区块隐藏（TC-DSH-07）', async () => {
    // 边界路径（权限过滤展示）：接口仍请求（后端过滤），仅前端隐藏
    hasAll = false
    const wrapper = mount(DashboardView, { global: { plugins: [ElementPlus] } })
    await flushPromises()
    expect(pendingMock).toHaveBeenCalledTimes(1)
    expect(wrapper.text()).not.toContain('待审核单据')
    expect(wrapper.text()).toContain('库存总量')
    expect(wrapper.text()).toContain('今日入库')
  })

  it('点击待审核行：白名单内路径跳转', async () => {
    // 正常路径：后端下发 url 在白名单内 → 跳转
    pendingMock.mockResolvedValue({
      items: [
        {
          module: '采购',
          type: '订单',
          no: 'PO20260813-001',
          created_at: '2026-08-13 10:00:00',
          url: '/purchase/orders',
        },
      ],
    })
    const wrapper = mount(DashboardView, { global: { plugins: [ElementPlus] } })
    await flushPromises()
    await wrapper.find('.pending-row').trigger('click')
    expect(push).toHaveBeenCalledWith('/purchase/orders')
  })

  it('点击预警卡：跳转库存预警页', async () => {
    // 正常路径：预警卡固定跳 /inventory/alerts
    alertsMock.mockResolvedValue({
      items: [
        {
          product_name: '低库存原料',
          product_code: 'MAT-001',
          warehouse_name: '主仓',
          quantity: '3.00',
          safety_min: '10.00',
        },
      ],
    })
    const wrapper = mount(DashboardView, { global: { plugins: [ElementPlus] } })
    await flushPromises()
    await wrapper.find('.alert-card').trigger('click')
    expect(push).toHaveBeenCalledWith('/inventory/alerts')
  })

  it('点击工单行：跳转生产工单页（V1 详情由列表页承载）', async () => {
    // 正常路径：工单行固定跳 /production/orders
    progressMock.mockResolvedValue({
      items: [
        {
          no: 'MO20260813-001',
          product_name: '测试成品',
          quantity: '10',
          completed_qty: '2.5',
          progress: '25.00',
          status: 2,
          status_label: '生产中',
        },
      ],
    })
    const wrapper = mount(DashboardView, { global: { plugins: [ElementPlus] } })
    await flushPromises()
    expect(wrapper.text()).toContain('MO20260813-001')
    expect(wrapper.text()).toContain('25.00%')
    expect(wrapper.text()).toContain('生产中')
    await wrapper.find('.order-row').trigger('click')
    expect(push).toHaveBeenCalledWith('/production/orders')
  })
})
