// 供应商页组件测试：四级地址级联选择 + 详细地址两段式表单
// TC-MST 回归保护：保存载荷必须携带 region 四字段（province/city/district/town），
// 且未选择地区时不带四字段（后端四列可空落库 null，而非空串）
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import ElementPlus from 'element-plus'
import SuppliersView from '../views/master/SuppliersView.vue'
import { useAuthStore } from '../stores/auth'

// mock 供应商 API：列表置空（页面挂载触发搜索），create/update 记录载荷
vi.mock('../api/supplier', () => ({
  supplierApi: {
    list: vi.fn().mockResolvedValue({ items: [], total: 0, page: 1, per_page: 10 }),
    create: vi.fn().mockResolvedValue({ data: { code: 0 } }),
    update: vi.fn().mockResolvedValue({ data: { code: 0 } }),
    remove: vi.fn(),
  },
}))
// mock china-division：仅用极小数据集驱动级联，避免加载完整区划数据并让用例确定性更强
vi.mock('china-division', () => ({
  pcas: {
    浙江省: { 杭州市: { 西湖区: ['三墩镇'] } },
  },
}))
import { supplierApi } from '../api/supplier'

describe('供应商页四级地址表单', () => {
  let pinia: ReturnType<typeof createPinia>

  beforeEach(() => {
    vi.clearAllMocks()
    pinia = createPinia()
    setActivePinia(pinia)
    const store = useAuthStore()
    store.permissions = ['supplier.create']
  })

  // 挂载组件（attachTo body：级联下拉 popper teleport 到 body）
  function mountView() {
    return mount(SuppliersView, {
      attachTo: document.body,
      global: { plugins: [ElementPlus, pinia] },
    })
  }

  // 点击指定文案按钮（新建/保存）
  async function clickButton(wrapper: VueWrapper, text: string) {
    const btn = wrapper.findAll('button').find((b) => b.text().trim() === text)
    expect(btn, `按钮「${text}」应存在`).toBeTruthy()
    await btn!.trigger('click')
    await flushPromises()
  }

  // 按表单项 label 填充 input
  async function fillByLabel(wrapper: VueWrapper, label: string, val: string) {
    const item = wrapper
      .findAll('.el-form-item')
      .find((fi) => fi.find('.el-form-item__label').text().trim() === label)
    expect(item, `表单项「${label}」应存在`).toBeTruthy()
    await item!.find('input').setValue(val)
  }

  // 在级联下拉 popper 中点击指定名称的节点（省→市→区县→乡镇逐级点选）
  async function pickNode(name: string) {
    const node = [...document.querySelectorAll('.el-cascader__dropdown .el-cascader-node')].find(
      (n) => (n as HTMLElement).textContent!.trim() === name,
    )
    expect(node, `级联节点「${name}」应存在`).toBeTruthy()
    ;(node as HTMLElement).dispatchEvent(new MouseEvent('click', { bubbles: true }))
    await flushPromises()
  }

  it('保存载荷携带 region 四字段（浙江省/杭州市/西湖区/三墩镇）与详细地址', async () => {
    // 正常路径：四级地址逐级选择后保存，载荷拆分四字段提交
    const wrapper = mountView()
    await flushPromises()
    await clickButton(wrapper, '新 建')

    await fillByLabel(wrapper, '名称', '测试供应商')
    await fillByLabel(wrapper, '编码', 'SUP-001')
    await fillByLabel(wrapper, '详细地址', '工业园1号')

    // 点击级联选择器展开，逐级点选省/市/区县/乡镇
    const cascader = wrapper.find('.el-dialog .el-cascader')
    expect(cascader, '四级地址级联选择器应存在').toBeTruthy()
    await cascader.trigger('click')
    await flushPromises()
    await pickNode('浙江省')
    await pickNode('杭州市')
    await pickNode('西湖区')
    await pickNode('三墩镇')

    await clickButton(wrapper, '保 存')
    expect(supplierApi.create).toHaveBeenCalledWith(
      expect.objectContaining({
        name: '测试供应商',
        code: 'SUP-001',
        province: '浙江省',
        city: '杭州市',
        district: '西湖区',
        town: '三墩镇',
        address: '工业园1号',
      }),
    )
    wrapper.unmount()
  })

  it('不选择地区时保存载荷不含 region 四字段（后端可空列落库 null）', async () => {
    // 边界条件：地址只填详细地址，四字段不进载荷（空串不提交）
    const wrapper = mountView()
    await flushPromises()
    await clickButton(wrapper, '新 建')

    await fillByLabel(wrapper, '名称', '无区域供应商')
    await fillByLabel(wrapper, '编码', 'SUP-002')
    await fillByLabel(wrapper, '详细地址', '某街巷12号')

    await clickButton(wrapper, '保 存')
    const payload = vi.mocked(supplierApi.create).mock.calls[0]![0]
    expect(payload).toEqual(
      expect.objectContaining({ name: '无区域供应商', code: 'SUP-002', address: '某街巷12号' }),
    )
    expect(payload).not.toHaveProperty('province')
    expect(payload).not.toHaveProperty('city')
    expect(payload).not.toHaveProperty('district')
    expect(payload).not.toHaveProperty('town')
    wrapper.unmount()
  })
})
