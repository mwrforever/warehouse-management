// 库存报表页组件测试：维度切换重新请求/KPI 数值/空态（mock reportApi + inventoryApi；无图表组件）
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import ElementPlus from 'element-plus'
import InventoryReportView from '../views/reports/InventoryReportView.vue'

// mock 报表 API：按维度返回分组数据（断言 radio 切换触发对应参数请求）
const inventorySummary = vi.fn()
vi.mock('../api/report', () => ({
  reportApi: {
    inventorySummary: (...args: unknown[]) => inventorySummary(...args),
  },
}))
vi.mock('../api/inventory', () => ({
  inventoryApi: { alerts: vi.fn().mockResolvedValue({ items: [{ product_code: 'MAT-001' }] }) },
}))

describe('InventoryReportView', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
    inventorySummary.mockResolvedValue({
      items: [
        { group_name: '原材料', quantity_total: '15.50', product_count: 2, amount_total: null },
      ],
      total: { quantity_total: '15.50', product_count: 2, amount_total: null },
      truncated: false,
    })
  })

  it('渲染 KPI 与分组表格（千分位格式化）', async () => {
    // 正常路径：挂载后请求默认 category 维度，KPI 显示总量/种类
    const wrapper = mount(InventoryReportView, { global: { plugins: [ElementPlus] } })
    await flushPromises()
    expect(inventorySummary).toHaveBeenCalledWith({ group_by: 'category' })
    expect(wrapper.text()).toContain('原材料')
    expect(wrapper.text()).toContain('15.50')
  })

  it('维度切换触发重新请求', async () => {
    // 正常路径：切换「按仓库」radio → 以 warehouse 参数重新请求
    const wrapper = mount(InventoryReportView, { global: { plugins: [ElementPlus] } })
    await flushPromises()
    await wrapper.findAll('input[type="radio"]')[1]!.setValue()
    await flushPromises()
    expect(inventorySummary).toHaveBeenLastCalledWith({ group_by: 'warehouse' })
  })

  it('空数据渲染空态（KPI 0 + 表格空文案）', async () => {
    // 边界路径：items 空 → KPI 显示 0、表格空态文案存在、不报错
    inventorySummary.mockResolvedValue({
      items: [],
      total: { quantity_total: '0', product_count: 0, amount_total: null },
      truncated: false,
    })
    const wrapper = mount(InventoryReportView, { global: { plugins: [ElementPlus] } })
    await flushPromises()
    expect(wrapper.text()).toContain('暂无数据')
    expect(wrapper.text()).toContain('0.00')
  })
})
