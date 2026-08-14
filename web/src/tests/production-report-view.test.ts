// 生产统计页组件测试：KPI 渲染 + 快速切换成品筛选时旧响应不覆盖新结果（bug #4 回归）
// （mock reportApi + productApi；无图表组件）
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import ElementPlus from 'element-plus'
import ProductionReportView from '../views/reports/ProductionReportView.vue'

const production = vi.fn()
vi.mock('../api/report', () => ({
  reportApi: {
    production: (...args: unknown[]) => production(...args),
  },
}))
vi.mock('../api/product', () => ({
  productApi: { list: vi.fn().mockResolvedValue({ items: [] }) },
}))

describe('ProductionReportView', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
    production.mockResolvedValue({
      items: [],
      totals: {
        order_count: 3,
        total_plan: '30',
        total_completed: '15',
        total_qualified: '15',
        total_defective: '0',
      },
      truncated: false,
    })
  })

  it('渲染 KPI（工单数/总计划/平均达成率/平均良率）', async () => {
    // 正常路径：挂载后请求默认参数，KPI 卡渲染 totals
    const wrapper = mount(ProductionReportView, { global: { plugins: [ElementPlus] } })
    await flushPromises()
    expect(production).toHaveBeenCalledTimes(1)
    const cards = wrapper.findAll('.kpi-card')
    expect(cards.at(0)!.text()).toContain('3')
    expect(cards.at(1)!.text()).toContain('30')
  })

  it('快速切换成品筛选时旧响应不覆盖新结果（bug #4 回归）', async () => {
    // 竞态回归：第一次请求（挂载）挂起未返回，第二次（切成品筛选）先返回并渲染；
    // 随后旧响应才返回——序号守卫必须丢弃旧响应，KPI 保持新值
    let resolveFirst!: (v: unknown) => void
    production
      .mockImplementationOnce(
        () =>
          new Promise((resolve) => {
            resolveFirst = resolve
          }),
      )
      .mockResolvedValueOnce({
        items: [],
        totals: {
          order_count: 7,
          total_plan: '70',
          total_completed: '70',
          total_qualified: '70',
          total_defective: '0',
        },
        truncated: false,
      })
    const wrapper = mount(ProductionReportView, { global: { plugins: [ElementPlus] } })
    await flushPromises()
    // 切换成品筛选：第二次请求立即返回
    const select = wrapper.findComponent({ name: 'ElSelect' })
    await select.vm.$emit('update:modelValue', 9)
    await select.vm.$emit('change', 9)
    await flushPromises()
    expect(wrapper.findAll('.kpi-card').at(0)!.text()).toContain('7')
    // 旧响应（工单数 3）此时才返回：必须被丢弃
    resolveFirst({
      items: [],
      totals: {
        order_count: 3,
        total_plan: '30',
        total_completed: '15',
        total_qualified: '15',
        total_defective: '0',
      },
      truncated: false,
    })
    await flushPromises()
    expect(wrapper.findAll('.kpi-card').at(0)!.text()).toContain('7')
    expect(wrapper.findAll('.kpi-card').at(0)!.text()).not.toContain('3')
  })
})
