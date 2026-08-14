// 出入库汇总页组件测试：KPI 渲染 + 快速切换粒度时旧响应不覆盖新结果（bug #4 回归）
// （mock reportApi + ReportChart 隔离 echarts）
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import ElementPlus from 'element-plus'
import MovementsReportView from '../views/reports/MovementsReportView.vue'

const movementsSummary = vi.fn()
vi.mock('../api/report', () => ({
  reportApi: {
    movementsSummary: (...args: unknown[]) => movementsSummary(...args),
  },
}))
// 路由 mock：消除 useRouter injection 告警（下钻跳转不在本测试范围）
vi.mock('vue-router', () => ({ useRouter: () => ({ push: vi.fn() }) }))
// mock ReportChart：隔离 echarts（jsdom 无 canvas，真实初始化会抛错）
vi.mock('../components/ReportChart.vue', () => ({
  default: { name: 'ReportChart', template: '<div class="mock-chart" />' },
}))

describe('MovementsReportView', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
    movementsSummary.mockResolvedValue({
      items: [],
      totals: { inbound_qty: '10.00', outbound_qty: '3.00', inbound_count: 1, outbound_count: 1 },
      truncated: false,
    })
  })

  it('渲染 KPI（总入库/总出库/净变动）', async () => {
    // 正常路径：挂载后请求默认近 30 天按日，KPI 卡渲染 totals
    const wrapper = mount(MovementsReportView, { global: { plugins: [ElementPlus] } })
    await flushPromises()
    expect(movementsSummary).toHaveBeenCalledTimes(1)
    const cards = wrapper.findAll('.kpi-card')
    expect(cards.at(0)!.text()).toContain('10.00')
    expect(cards.at(1)!.text()).toContain('3.00')
  })

  it('快速切换粒度时旧响应不覆盖新结果（bug #4 回归）', async () => {
    // 竞态回归：第一次请求（day）挂起未返回，第二次（month）先返回并渲染；
    // 随后旧响应才返回——序号守卫必须丢弃旧响应，KPI 保持新值
    let resolveFirst!: (v: unknown) => void
    movementsSummary
      .mockImplementationOnce(
        () =>
          new Promise((resolve) => {
            resolveFirst = resolve
          }),
      )
      .mockResolvedValueOnce({
        items: [],
        totals: { inbound_qty: '20.00', outbound_qty: '0', inbound_count: 2, outbound_count: 0 },
        truncated: false,
      })
    const wrapper = mount(MovementsReportView, { global: { plugins: [ElementPlus] } })
    await flushPromises()
    // 切换「按月」粒度：第二次请求立即返回
    await wrapper.findAll('input[type="radio"]')[1]!.setValue()
    await flushPromises()
    expect(wrapper.findAll('.kpi-card').at(0)!.text()).toContain('20.00')
    // 旧响应（入库 999.00）此时才返回：必须被丢弃
    resolveFirst({
      items: [],
      totals: { inbound_qty: '999.00', outbound_qty: '0', inbound_count: 9, outbound_count: 0 },
      truncated: false,
    })
    await flushPromises()
    expect(wrapper.findAll('.kpi-card').at(0)!.text()).toContain('20.00')
    expect(wrapper.findAll('.kpi-card').at(0)!.text()).not.toContain('999.00')
  })
})
