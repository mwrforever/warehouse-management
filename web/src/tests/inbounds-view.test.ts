// 采购入库单页组件测试（BF-3/B-106）：来源订单下拉远程搜索——
// 可入库订单超 100 条后须输入单号关键字搜索（原一次性全量装载 available 无分页，超量不可选）
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import ElementPlus from 'element-plus'
import InboundsView from '../views/purchase/InboundsView.vue'

// mock purchaseApi 全表面：列表/可入库订单/订单预填/详情/审核/删除
// 说明：vi.fn 包装转发（而非裸箭头）——断言直接针对 purchaseApi.availableOrders（spy 形态），
// vi.fn 实现体内延迟引用外部 mocks（工厂执行早于模块体，直接引用 mocks 会 TDZ），mocks.* 仍为用例可控出口
const mocks = {
  inbounds: vi.fn(),
  availableOrders: vi.fn(),
  fromOrder: vi.fn(),
  inboundDetail: vi.fn(),
  approveInbound: vi.fn(),
  deleteInbound: vi.fn(),
}
vi.mock('../api/purchase', () => ({
  purchaseApi: {
    inbounds: vi.fn((...a: unknown[]) => mocks.inbounds(...a)),
    availableOrders: vi.fn((...a: unknown[]) => mocks.availableOrders(...a)),
    fromOrder: vi.fn((...a: unknown[]) => mocks.fromOrder(...a)),
    inboundDetail: vi.fn((...a: unknown[]) => mocks.inboundDetail(...a)),
    approveInbound: vi.fn((...a: unknown[]) => mocks.approveInbound(...a)),
    deleteInbound: vi.fn((...a: unknown[]) => mocks.deleteInbound(...a)),
  },
}))
vi.mock('../api/supplier', () => ({
  supplierApi: { list: vi.fn().mockResolvedValue({ items: [], total: 0 }) },
}))
vi.mock('../api/warehouse', () => ({
  warehouseApi: {
    list: vi.fn().mockResolvedValue({ items: [], total: 0 }),
    locations: vi.fn().mockResolvedValue({ items: [], total: 0 }),
  },
}))
vi.mock('../api/product', () => ({
  productApi: { list: vi.fn().mockResolvedValue({ items: [], total: 0 }) },
}))
vi.mock('../stores/auth', () => ({ useAuthStore: () => ({ has: () => true }) }))
// 空路由：本组用例不走单号跳转直达
vi.mock('vue-router', () => ({
  useRoute: () => ({ query: {}, params: {} }),
  useRouter: () => ({ replace: vi.fn() }),
}))

import { purchaseApi } from '../api/purchase'

describe('采购入库「从订单生成」订单下拉远程搜索（BF-3/B-106）', () => {
  let pinia: ReturnType<typeof createPinia>

  beforeEach(() => {
    vi.clearAllMocks()
    vi.useFakeTimers()
    mocks.inbounds.mockResolvedValue({ items: [], total: 0 })
    mocks.availableOrders.mockResolvedValue({ items: [], total: 0 })
    pinia = createPinia()
    setActivePinia(pinia)
  })
  afterEach(() => vi.useRealTimers())

  function mountView() {
    return mount(InboundsView, {
      attachTo: document.body,
      global: { plugins: [ElementPlus, pinia] },
    })
  }

  it('初载与搜索均携带 per_page 上限；输入单号关键字后以 keyword 调 available 接口', async () => {
    // 正常路径：可入库订单持续增长必然超 100（后端 per_page 硬钳制 100），改 remote 单号搜索
    const wrapper = mountView()
    await flushPromises()
    // 初载：available 携带 per_page（不再全量装载订单头+全部明细行）
    expect(purchaseApi.availableOrders).toHaveBeenCalledWith(
      expect.objectContaining({ per_page: expect.any(Number) }),
    )

    // 打开「从订单生成」弹窗
    const fromOrderBtn = wrapper.findAll('button').find((b) => b.text().trim() === '从订单生成')
    expect(fromOrderBtn, '从订单生成入口应存在').toBeTruthy()
    await fromOrderBtn!.trigger('click')
    await flushPromises()

    const select = wrapper.find('.el-dialog').findComponent({ name: 'ElSelect' })
    expect(select.exists(), '来源订单下拉应存在').toBe(true)
    expect(select.props('remote'), '来源订单下拉应为 remote 服务端搜索模式').toBe(true)
    vi.clearAllMocks()
    // 输入单号关键字：300ms 防抖后携带 keyword 请求
    ;(select.props('remoteMethod') as (q: string) => void)('PO2026')
    expect(purchaseApi.availableOrders, '防抖窗口内不得发请求').not.toHaveBeenCalled()
    await vi.advanceTimersByTimeAsync(300)
    expect(purchaseApi.availableOrders).toHaveBeenCalledWith(
      expect.objectContaining({ keyword: 'PO2026' }),
    )
    wrapper.unmount()
  })
})
