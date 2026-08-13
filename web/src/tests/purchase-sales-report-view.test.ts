// 采购销售汇总页组件测试：KPI 数值/差额负数/空态（mock reportApi + ReportChart 隔离 echarts）
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import ElementPlus from 'element-plus'
import PurchaseSalesReportView from '../views/reports/PurchaseSalesReportView.vue'

const purchaseSales = vi.fn()
vi.mock('../api/report', () => ({
  reportApi: {
    purchaseSales: (...args: unknown[]) => purchaseSales(...args),
  },
}))
// mock ReportChart：隔离 echarts（jsdom 无 canvas，真实初始化会抛错）
vi.mock('../components/ReportChart.vue', () => ({
  default: { name: 'ReportChart', template: '<div class="mock-chart" />' },
}))

describe('PurchaseSalesReportView', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
    purchaseSales.mockResolvedValue({
      items: [
        {
          period: '2026-08-10',
          purchase_amount: '123.45',
          sales_amount: '50.00',
          purchase_qty: '10.00',
          sales_qty: '4.00',
        },
      ],
      totals: {
        purchase_amount: '123.45',
        sales_amount: '50.00',
        purchase_qty: '10.00',
        sales_qty: '4.00',
      },
      truncated: false,
    })
  })

  it('渲染 KPI 与差额（销售-采购为负时红色样式）', async () => {
    // 正常路径：差额 = 50.00 - 123.45 = -73.45（负数加 negative 类）
    const wrapper = mount(PurchaseSalesReportView, { global: { plugins: [ElementPlus] } })
    await flushPromises()
    // 收窄断言到 KPI 卡（避免与表格单元格数值混淆）
    const cards = wrapper.findAll('.kpi-card')
    expect(cards.at(0)!.text()).toContain('123.45')
    expect(cards.at(1)!.text()).toContain('50.00')
    const neg = wrapper.find('.kpi-value.negative')
    expect(neg.exists()).toBeTruthy()
    expect(neg.text()).toContain('-73.45')
  })

  it('空数据渲染空态（KPI 0 + el-empty 文案）', async () => {
    // 边界路径：items 空 → KPI 显示 0.00、el-empty「暂无数据」、不渲染图表
    purchaseSales.mockResolvedValue({
      items: [],
      totals: { purchase_amount: '0', sales_amount: '0', purchase_qty: '0', sales_qty: '0' },
      truncated: false,
    })
    const wrapper = mount(PurchaseSalesReportView, { global: { plugins: [ElementPlus] } })
    await flushPromises()
    expect(wrapper.text()).toContain('暂无数据')
    expect(wrapper.find('.mock-chart').exists()).toBeFalsy()
    // 空态三要素之「KPI 显示 0」：采购金额卡 formatThousand('0') 恒为 '0.00'
    expect(wrapper.findAll('.kpi-card').at(0)!.text()).toContain('0.00')
  })
})
