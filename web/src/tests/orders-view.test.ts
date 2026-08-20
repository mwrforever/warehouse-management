// 生产工单页组件测试：新建成功但列表刷新失败时不得误报创建失败（bug #11 回归）
// 旧实现：create 成功 → 列表回查 find 单号 → 找不到 throw → 外层 catch 弹错误，诱导用户重复提交
// 新实现：create 响应直接带 id → 以 id 拉详情打开 BOM 展开弹窗；列表刷新失败经 useListQuery.onError 错误提示
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import ElementPlus from 'element-plus'
import OrdersView from '../views/production/OrdersView.vue'
import { useAuthStore } from '../stores/auth'

const ordersMock = vi.fn()
const orderDetailMock = vi.fn()
const createOrderMock = vi.fn()
vi.mock('../api/production', () => ({
  productionApi: {
    orders: (...args: unknown[]) => ordersMock(...args),
    orderDetail: (...args: unknown[]) => orderDetailMock(...args),
    createOrder: (...args: unknown[]) => createOrderMock(...args),
    updateOrder: vi.fn(),
    deleteOrder: vi.fn(),
    releaseOrder: vi.fn(),
    startOrder: vi.fn(),
    completeOrder: vi.fn(),
    closeOrder: vi.fn(),
    orderMaterials: vi.fn(),
    operationReports: vi.fn(),
  },
}))
vi.mock('../api/product', () => ({
  productApi: {
    list: vi.fn().mockResolvedValue({
      items: [
        {
          id: 2,
          name: '成品B',
          code: 'FIN-002',
          type: 'finished',
          type_label: '成品',
          category_id: 1,
          unit_id: 1,
          unit_name: '个',
          status: 1,
        },
      ],
      total: 1,
    }),
  },
}))
// BOM 校验接口：返回启用版本（onProductChange 通过校验不拦截）
vi.mock('../api/bom', () => ({
  bomApi: { list: vi.fn().mockResolvedValue({ items: [{ id: 1, status: 1 }] }) },
}))
vi.mock('vue-router', () => ({ useRouter: () => ({ push: vi.fn() }) }))

describe('OrdersView 新建成功但列表刷新失败', () => {
  let pinia: ReturnType<typeof createPinia>

  beforeEach(() => {
    vi.clearAllMocks()
    pinia = createPinia()
    setActivePinia(pinia)
    const store = useAuthStore()
    store.permissions = ['production.order.create']
    // 首次加载（挂载）返回空列表；保存后的后台刷新抛错（模拟列表刷新失败）
    ordersMock.mockResolvedValueOnce({ items: [], total: 0, page: 1, per_page: 10 })
    ordersMock.mockRejectedValueOnce(new Error('网络异常'))
    createOrderMock.mockResolvedValue({ no: 'MO20260814-001', id: 42 })
    orderDetailMock.mockResolvedValue({
      id: 42,
      no: 'MO20260814-001',
      product_id: 2,
      product_name: '成品B',
      product_code: 'FIN-002',
      quantity: 10,
      plan_date: '2026-08-14',
      bom_id: 1,
      bom_code: 'BOM-001',
      status: 0,
      status_label: '草稿',
      completed_qty: 0,
      progress: 0,
      released_at: null,
      completed_at: null,
      closed_at: null,
      remark: null,
      materials: [],
      operations: [],
    })
  })

  it('创建成功：以响应 id 直开 BOM 展开弹窗，列表刷新失败仅提示不误报（bug #11 回归）', async () => {
    const wrapper = mount(OrdersView, {
      attachTo: document.body,
      global: { plugins: [ElementPlus, pinia] },
    })
    await flushPromises()
    // 打开新建弹窗
    const newBtn = wrapper.findAll('button').find((b) => b.text().trim() === '新 建')
    expect(newBtn).toBeTruthy()
    await newBtn!.trigger('click')
    await flushPromises()
    // 成品下拉选择 FIN-002（触发 BOM 启用校验）。
    // 注意：工具栏筛选下拉与弹窗下拉同文案，Element Plus 会把隐藏 popper 也渲染进 body——
    // 取最后一个匹配项（最后打开/最近挂载的 popper 即弹窗内下拉）
    const productSelect = wrapper.findAll('.el-dialog .el-select__wrapper')[0]
    await productSelect.trigger('click')
    await flushPromises()
    const candidates = [
      ...document.querySelectorAll('.el-select-dropdown .el-select-dropdown__item'),
    ].filter((o) => (o as HTMLElement).textContent!.trim() === '成品B（FIN-002）')
    const opt = candidates[candidates.length - 1]
    expect(opt, '成品选项 FIN-002 应存在').toBeTruthy()
    ;(opt as HTMLElement).dispatchEvent(new MouseEvent('click', { bubbles: true }))
    await flushPromises()
    // 数量 10（计划日期 openCreate 已默认今天）
    const qtyInput = wrapper.findComponent({ name: 'ElInputNumber' })
    await qtyInput.vm.$emit('update:modelValue', 10)
    await flushPromises()
    // 保存：创建成功 → 详情直开
    const saveBtn = wrapper.findAll('button').find((b) => b.text().trim() === '保 存')
    await saveBtn!.trigger('click')
    await flushPromises()
    // 创建载荷正确
    expect(createOrderMock).toHaveBeenCalledWith(
      expect.objectContaining({ product_id: 2, quantity: 10 }),
    )
    // 以创建响应 id 直拉详情（不依赖列表回查）
    expect(orderDetailMock).toHaveBeenCalledWith(42)
    // BOM 展开弹窗已打开
    expect(wrapper.text()).toContain('BOM 展开确认')
    expect(wrapper.text()).toContain('工单已创建（草稿）')
    // 提示语义：成功提示 + 列表刷新失败错误提示（后台刷新经 useListQuery.onError 展示）；不得出现「创建失败」类误导文案
    expect(document.body.textContent).toContain('创建成功')
    expect(document.body.textContent).toContain('网络异常')
    expect(document.body.textContent).not.toContain('工单已创建，请刷新列表查看')
    wrapper.unmount()
  })
})
