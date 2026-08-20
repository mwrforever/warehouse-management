// 生产模块筛选分页回归（bug #5）：翻页后切筛选必须重置回第 1 页
// 改造后筛选触发为 300ms 防抖 load()，需 vi.useFakeTimers + advanceTimersByTime
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import ElementPlus from 'element-plus'

const mocks = {
  orders: vi.fn(),
  picks: vi.fn(),
  returns: vi.fn(),
  outsourcings: vi.fn(),
  finishedInbounds: vi.fn(),
}
vi.mock('../api/production', () => ({
  productionApi: {
    orders: (...a: unknown[]) => mocks.orders(...a),
    picks: (...a: unknown[]) => mocks.picks(...a),
    returns: (...a: unknown[]) => mocks.returns(...a),
    outsourcings: (...a: unknown[]) => mocks.outsourcings(...a),
    finishedInbounds: (...a: unknown[]) => mocks.finishedInbounds(...a),
  },
}))
vi.mock('../api/warehouse', () => ({
  warehouseApi: { list: vi.fn().mockResolvedValue({ items: [] }) },
}))
vi.mock('../api/supplier', () => ({
  supplierApi: { list: vi.fn().mockResolvedValue({ items: [] }) },
}))
vi.mock('../api/product', () => ({
  productApi: { list: vi.fn().mockResolvedValue({ items: [] }) },
}))
vi.mock('../api/bom', () => ({ bomApi: { list: vi.fn().mockResolvedValue({ items: [] }) } }))
vi.mock('../stores/auth', () => ({ useAuthStore: () => ({ has: () => true }) }))
vi.mock('vue-router', () => ({
  useRouter: () => ({ push: vi.fn() }),
  useRoute: () => ({ query: {} }),
}))

import OrdersView from '../views/production/OrdersView.vue'
import PicksView from '../views/production/PicksView.vue'
import ReturnsView from '../views/production/ReturnsView.vue'
import OutsourcingsView from '../views/production/OutsourcingsView.vue'
import FinishedInboundsView from '../views/production/FinishedInboundsView.vue'

function pageResult() {
  return { items: [], total: 0, page: 1, per_page: 10 }
}

describe('生产模块筛选重置分页（bug #5 回归，防抖改造后）', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.useFakeTimers()
    vi.clearAllMocks()
    Object.values(mocks).forEach((m) => m.mockResolvedValue(pageResult()))
  })
  afterEach(() => vi.useRealTimers())

  async function assertFilterResetsPage(
    wrapper: ReturnType<typeof mount>,
    listMock: ReturnType<typeof vi.fn>,
    selectValue: number,
  ) {
    await flushPromises()
    expect(listMock).toHaveBeenLastCalledWith(expect.objectContaining({ page: 1 }))
    const pagination = wrapper.findComponent({ name: 'ElPagination' })
    await pagination.vm.$emit('update:current-page', 3)
    await pagination.vm.$emit('current-change', 3)
    await flushPromises()
    expect(listMock).toHaveBeenLastCalledWith(expect.objectContaining({ page: 3 }))
    // 切状态筛选 → 防抖 300ms 后触发 load → 重置回第 1 页
    const selects = wrapper.findAllComponents({ name: 'ElSelect' })
    const statusSelect = selects[selects.length - 1]!
    await statusSelect.vm.$emit('update:modelValue', selectValue)
    await statusSelect.vm.$emit('change', selectValue)
    vi.advanceTimersByTime(300)
    await flushPromises()
    expect(listMock).toHaveBeenLastCalledWith(
      expect.objectContaining({ page: 1, status: selectValue }),
    )
  }

  it('工单页：翻页后切状态筛选重置回第 1 页', async () => {
    const wrapper = mount(OrdersView, { global: { plugins: [ElementPlus] } })
    await assertFilterResetsPage(wrapper, mocks.orders, 1)
  })
  it('领料单页：翻页后切状态筛选重置回第 1 页', async () => {
    const wrapper = mount(PicksView, { global: { plugins: [ElementPlus] } })
    await assertFilterResetsPage(wrapper, mocks.picks, 1)
  })
  it('退料单页：翻页后切状态筛选重置回第 1 页', async () => {
    const wrapper = mount(ReturnsView, { global: { plugins: [ElementPlus] } })
    await assertFilterResetsPage(wrapper, mocks.returns, 1)
  })
  it('委外加工页：翻页后切状态筛选重置回第 1 页', async () => {
    const wrapper = mount(OutsourcingsView, { global: { plugins: [ElementPlus] } })
    await assertFilterResetsPage(wrapper, mocks.outsourcings, 1)
  })
  it('成品入库页：翻页后切状态筛选重置回第 1 页', async () => {
    const wrapper = mount(FinishedInboundsView, { global: { plugins: [ElementPlus] } })
    await assertFilterResetsPage(wrapper, mocks.finishedInbounds, 1)
  })
})
