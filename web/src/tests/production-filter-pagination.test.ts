// 生产模块筛选分页回归测试（bug #5）：翻页后切换筛选必须重置回第 1 页
// 覆盖 5 个页面：工单/领料/退料/委外/成品入库（mock 各 api + auth store + vue-router）
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import ElementPlus from 'element-plus'

const ordersMock = vi.fn()
const picksMock = vi.fn()
const returnsMock = vi.fn()
const outsourcingsMock = vi.fn()
const finishedInboundsMock = vi.fn()
vi.mock('../api/production', () => ({
  productionApi: {
    orders: (...args: unknown[]) => ordersMock(...args),
    picks: (...args: unknown[]) => picksMock(...args),
    returns: (...args: unknown[]) => returnsMock(...args),
    outsourcings: (...args: unknown[]) => outsourcingsMock(...args),
    finishedInbounds: (...args: unknown[]) => finishedInboundsMock(...args),
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
vi.mock('../api/bom', () => ({
  bomApi: { list: vi.fn().mockResolvedValue({ items: [] }) },
}))
// 权限开关：默认持有全部生产模块权限
vi.mock('../stores/auth', () => ({
  useAuthStore: () => ({ has: () => true }),
}))
vi.mock('vue-router', () => ({
  useRouter: () => ({ push: vi.fn() }),
  // 无路由直达参数：挂载后不自动打开弹窗
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

// 公共动作：翻页到第 3 页 → 切状态筛选 → 断言最后一次列表请求携带 page=1
async function assertFilterResetsPage(
  wrapper: ReturnType<typeof mount>,
  listMock: ReturnType<typeof vi.fn>,
  selectValue: number,
) {
  // 初始加载（挂载触发，page=1）
  await flushPromises()
  expect(listMock).toHaveBeenLastCalledWith(expect.objectContaining({ page: 1 }))
  // 翻页到第 3 页（el-pagination 双向绑定 + current-change）
  const pagination = wrapper.findComponent({ name: 'ElPagination' })
  await pagination.vm.$emit('update:current-page', 3)
  await pagination.vm.$emit('current-change', 3)
  await flushPromises()
  expect(listMock).toHaveBeenLastCalledWith(expect.objectContaining({ page: 3 }))
  // 切状态筛选：必须重置回第 1 页（bug #5 回归——旧实现从旧页码取值导致错页/空页）
  const selects = wrapper.findAllComponents({ name: 'ElSelect' })
  const statusSelect = selects[selects.length - 1]!
  await statusSelect.vm.$emit('update:modelValue', selectValue)
  await statusSelect.vm.$emit('change', selectValue)
  await flushPromises()
  expect(listMock).toHaveBeenLastCalledWith(
    expect.objectContaining({ page: 1, status: selectValue }),
  )
}

describe('生产模块筛选重置分页（bug #5 回归）', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
    ordersMock.mockResolvedValue(pageResult())
    picksMock.mockResolvedValue(pageResult())
    returnsMock.mockResolvedValue(pageResult())
    outsourcingsMock.mockResolvedValue(pageResult())
    finishedInboundsMock.mockResolvedValue(pageResult())
  })

  it('工单页：翻页后切状态筛选重置回第 1 页', async () => {
    const wrapper = mount(OrdersView, { global: { plugins: [ElementPlus] } })
    await assertFilterResetsPage(wrapper, ordersMock, 1)
  })

  it('领料单页：翻页后切状态筛选重置回第 1 页', async () => {
    const wrapper = mount(PicksView, { global: { plugins: [ElementPlus] } })
    await assertFilterResetsPage(wrapper, picksMock, 1)
  })

  it('退料单页：翻页后切状态筛选重置回第 1 页', async () => {
    const wrapper = mount(ReturnsView, { global: { plugins: [ElementPlus] } })
    await assertFilterResetsPage(wrapper, returnsMock, 1)
  })

  it('委外加工页：翻页后切状态筛选重置回第 1 页', async () => {
    const wrapper = mount(OutsourcingsView, { global: { plugins: [ElementPlus] } })
    await assertFilterResetsPage(wrapper, outsourcingsMock, 1)
  })

  it('成品入库页：翻页后切状态筛选重置回第 1 页', async () => {
    const wrapper = mount(FinishedInboundsView, { global: { plugins: [ElementPlus] } })
    await assertFilterResetsPage(wrapper, finishedInboundsMock, 1)
  })
})
