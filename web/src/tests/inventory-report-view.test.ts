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

  it('快速切换维度时旧响应不覆盖新结果（bug #4 回归）', async () => {
    // 竞态回归：第一次请求（category）挂起未返回，第二次（warehouse）先返回并渲染；
    // 随后旧响应才返回——序号守卫必须丢弃旧响应，最终展示 warehouse 结果
    let resolveFirst!: (v: unknown) => void
    inventorySummary
      .mockImplementationOnce(
        () =>
          new Promise((resolve) => {
            resolveFirst = resolve
          }),
      )
      .mockResolvedValueOnce({
        items: [
          { group_name: '仓组', quantity_total: '2.00', product_count: 1, amount_total: null },
        ],
        total: { quantity_total: '2.00', product_count: 1, amount_total: null },
        truncated: false,
      })
    const wrapper = mount(InventoryReportView, { global: { plugins: [ElementPlus] } })
    await flushPromises()
    // 切换「按仓库」维度：第二次请求立即返回
    await wrapper.findAll('input[type="radio"]')[1]!.setValue()
    await flushPromises()
    expect(wrapper.text()).toContain('仓组')
    // 旧响应（category 数据）此时才返回：必须被丢弃
    resolveFirst({
      items: [
        { group_name: '旧分类组', quantity_total: '1.00', product_count: 1, amount_total: null },
      ],
      total: { quantity_total: '1.00', product_count: 1, amount_total: null },
      truncated: false,
    })
    await flushPromises()
    expect(wrapper.text()).toContain('仓组')
    expect(wrapper.text()).not.toContain('旧分类组')
  })
})
