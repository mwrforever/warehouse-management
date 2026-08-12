// BOM 页组件测试：物料行选择物料后单位自动带出（spec §5.7「单位（自动带出）」）
// 背景：物料行单位原固定取第一个单位（units[0]），未随物料联动，
// 导致 MAT-001（单位个）行显示「千克」、保存载荷 unit_id 错误（E2E TC-MST-09 回归保护）
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import ElementPlus from 'element-plus'
import BomsView from '../views/master/BomsView.vue'
import { useAuthStore } from '../stores/auth'

// mock API：成品 FIN-002、物料 MAT-001（单位个 id=1）、单位列表（千克排第一，验证必须跟随物料而非默认第一个）
vi.mock('../api/bom', () => ({
  bomApi: {
    list: vi.fn().mockResolvedValue({ items: [], total: 0 }),
    create: vi.fn().mockResolvedValue({ data: { code: 0 } }),
    update: vi.fn(),
    remove: vi.fn(),
    toggle: vi.fn(),
    items: vi.fn().mockResolvedValue({ items: [] }),
  },
}))
vi.mock('../api/product', () => ({
  productApi: {
    list: vi.fn().mockImplementation((params: any) => {
      if (params.type === 'finished') return Promise.resolve({ items: [{ id: 2, name: '成品B', code: 'FIN-002', type: 'finished', type_label: '成品', category_id: 1, unit_id: 1, unit_name: '个', status: 1 }], total: 1 })
      return Promise.resolve({ items: [{ id: 1, name: '测试铝材', code: 'MAT-001', type: 'raw_material', type_label: '原料', category_id: 1, unit_id: 1, unit_name: '个', spec: '1mm', status: 1 }], total: 1 })
    }),
  },
}))
vi.mock('../api/unit', () => ({
  unitApi: {
    list: vi.fn().mockResolvedValue({ items: [{ id: 3, name: '千克', code: 'kg', status: 1 }, { id: 1, name: '个', code: 'pc', status: 1 }], total: 2 }),
  },
}))
import { bomApi } from '../api/bom'

describe('BOM 物料行单位自动带出', () => {
  let store: ReturnType<typeof useAuthStore>
  let pinia: ReturnType<typeof createPinia>

  beforeEach(() => {
    vi.clearAllMocks()
    pinia = createPinia()
    setActivePinia(pinia)
    store = useAuthStore()
    store.permissions = ['bom.create']
  })

  function mountView() {
    return mount(BomsView, { attachTo: document.body, global: { plugins: [ElementPlus, pinia] } })
  }

  it('新建弹窗选择物料 MAT-001 后，物料行单位自动带出为「个」且保存载荷 unit_id=1', async () => {
    // 正常路径：单位跟随物料（修复前固定取第一个单位「千克」）
    const wrapper = mountView()
    await flushPromises()
    const newBtn = wrapper.findAll('button').find((b: any) => b.text().trim() === '新 建')
    expect(newBtn).toBeTruthy()
    await newBtn!.trigger('click')
    await flushPromises()

    // 物料下拉选择 MAT-001（单位个 id=1）
    const materialSelect = wrapper.find('.item-row .el-select__wrapper')
    expect(materialSelect, '物料行下拉应存在').toBeTruthy()
    await materialSelect.trigger('click')
    await flushPromises()
    const opt = [...document.querySelectorAll('.el-select-dropdown:not(.el-tree-select__popper) .el-select-dropdown__item')]
      .find((o) => (o as HTMLElement).textContent!.trim() === 'MAT-001 测试铝材')
    expect(opt, '物料选项 MAT-001 应存在').toBeTruthy()
    ;(opt as HTMLElement).dispatchEvent(new MouseEvent('click', { bubbles: true }))
    await flushPromises()

    // 断言单位文本已带出「个」（修复前为「千克」）
    const unitText = wrapper.find('.item-row .unit-name').text().trim()
    expect(unitText, '物料行单位应自动带出物料单位').toBe('个')

    // 选择成品 FIN-002（保存前置必填），触发保存：断言 create 载荷 unit_id 为物料单位（个 id=1）
    const productSelect = wrapper.findAll('.el-dialog .el-select__wrapper')[0]
    await productSelect.trigger('click')
    await flushPromises()
    const finOpt = [...document.querySelectorAll('.el-select-dropdown:not(.el-tree-select__popper) .el-select-dropdown__item')]
      .find((o) => (o as HTMLElement).textContent!.trim() === 'FIN-002 成品B')
    expect(finOpt, '成品选项 FIN-002 应存在').toBeTruthy()
    ;(finOpt as HTMLElement).dispatchEvent(new MouseEvent('click', { bubbles: true }))
    await flushPromises()

    const saveBtn = wrapper.findAll('button').find((b: any) => b.text().trim() === '保 存')
    await saveBtn!.trigger('click')
    await flushPromises()
    expect(bomApi.create).toHaveBeenCalledWith(expect.objectContaining({
      items: expect.arrayContaining([expect.objectContaining({ material_id: 1, unit_id: 1 })]),
    }))
    wrapper.unmount()
  })
})
