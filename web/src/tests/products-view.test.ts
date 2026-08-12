// 商品页组件测试：分类树选择绑定 category_id（E2E TC-MST-07 回归保护）+ 安全库存 min>max 前端拦截
// 背景：el-tree-select 未配置 node-key 时取值默认为数据项的 value 字段，
// 而分类数据项为 {id, name, ...}，导致选择分类后 category_id 绑定失效（真实缺陷回归用例）
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import ElementPlus from 'element-plus'
import ProductsView from '../views/master/ProductsView.vue'
import { useAuthStore } from '../stores/auth'

// mock 商品/分类/单位 API：提供树形分类（原材料 id=1、成品 id=2）与单位列表
vi.mock('../api/product', () => ({
  productApi: {
    list: vi.fn().mockResolvedValue({ items: [], total: 0 }),
    create: vi.fn().mockResolvedValue({ data: { code: 0 } }),
    update: vi.fn(),
    remove: vi.fn(),
    byBarcode: vi.fn(),
  },
}))
vi.mock('../api/category', () => ({
  categoryApi: {
    tree: vi.fn().mockResolvedValue([
      { id: 1, name: '原材料', parent_id: 0, sort: 1, status: 1, children: [] },
      { id: 2, name: '成品', parent_id: 0, sort: 2, status: 1, children: [] },
    ]),
  },
}))
vi.mock('../api/unit', () => ({
  unitApi: {
    list: vi.fn().mockResolvedValue({ items: [{ id: 1, name: '个', code: 'pc', status: 1 }], total: 1 }),
  },
}))
import { productApi } from '../api/product'

describe('商品页分类树选择与安全库存校验', () => {
  let store: ReturnType<typeof useAuthStore>
  let pinia: ReturnType<typeof createPinia>

  beforeEach(() => {
    vi.clearAllMocks()
    pinia = createPinia()
    setActivePinia(pinia)
    store = useAuthStore()
    store.permissions = ['product.create']
  })

  // 挂载组件（attachTo body：下拉默认 teleport 到 body）
  function mountView() {
    return mount(ProductsView, { attachTo: document.body, global: { plugins: [ElementPlus, pinia] } })
  }

  // 点击指定文案按钮（新建/保存）
  async function clickButton(wrapper: any, text: string) {
    const btn = wrapper.findAll('button').find((b: any) => b.text().trim() === text)
    expect(btn, `按钮「${text}」应存在`).toBeTruthy()
    await btn!.trigger('click')
    await flushPromises()
  }

  // 按表单项 label 填充 input
  async function fillByLabel(wrapper: any, label: string, val: string) {
    const item = wrapper.findAll('.el-form-item').find((fi: any) => fi.find('.el-form-item__label').text().trim() === label)
    expect(item, `表单项「${label}」应存在`).toBeTruthy()
    await item!.find('input').setValue(val)
  }

  it('打开新建弹窗后选择分类树节点「原材料」，分类选择器显示选中值且保存载荷携带 category_id=1', async () => {
    // 正常路径：分类选择必须真实绑定到表单（回归：node-key 缺失导致选择失效、category_id 无法保存）
    const wrapper = mountView()
    await flushPromises()
    await clickButton(wrapper, '新 建')

    // 触发弹窗内第一个下拉（el-tree-select 分类选择器）展开
    const treeWrapper = wrapper.findAll('.el-dialog .el-select__wrapper')[0]
    expect(treeWrapper, '分类树选择器应存在').toBeTruthy()
    await treeWrapper!.trigger('click')
    await flushPromises()

    // 点击下拉树节点「原材料」（种子 id=1）：该点击必须将 category_id 更新为 1
    const node = document.querySelector('.el-tree-select__popper .el-tree-node__content')
    expect(node, '下拉应渲染分类树节点').toBeTruthy()
    ;(node as HTMLElement).dispatchEvent(new MouseEvent('click', { bubbles: true }))
    await flushPromises()

    // 断言选择生效：选择器显示「原材料」而非占位「选择分类」（修复前点击无效仍显示占位）
    const selectedText = wrapper.findAll('.el-dialog .el-select__wrapper')[0]?.text().trim()
    expect(selectedText, '分类选择器应显示已选分类').toBe('原材料')

    // 填写必填字段并触发保存：断言创建载荷携带 category_id=1
    await fillByLabel(wrapper, '名称', '测试铝材')
    await fillByLabel(wrapper, '编码', 'MAT-001')
    // 单位下拉选择「个」
    await wrapper.findAll('.el-dialog .el-select__wrapper')[1].trigger('click')
    await flushPromises()
    const unitOption = [...document.querySelectorAll('.el-select-dropdown:not(.el-tree-select__popper) .el-select-dropdown__item')]
      .find((o) => (o as HTMLElement).textContent!.trim() === '个')
    expect(unitOption, '单位选项「个」应存在').toBeTruthy()
    ;(unitOption as HTMLElement).dispatchEvent(new MouseEvent('click', { bubbles: true }))
    await flushPromises()

    await clickButton(wrapper, '保 存')
    expect(productApi.create).toHaveBeenCalledWith(expect.objectContaining({ category_id: 1, code: 'MAT-001', unit_id: 1 }))
    wrapper.unmount()
  })

  it('安全库存下限大于上限时前端拦截，不发创建请求并提示错误', async () => {
    // 边界条件：min>max 直接拦截（后端 1122 双保险的前端一侧）
    const wrapper = mountView()
    await flushPromises()
    await clickButton(wrapper, '新 建')

    // 填写必填字段（名称/编码/单位/分类），避免被「请填写必填项」拦截
    await fillByLabel(wrapper, '名称', '测试铝材')
    await fillByLabel(wrapper, '编码', 'MAT-001')
    await wrapper.findAll('.el-dialog .el-select__wrapper')[1].trigger('click')
    await flushPromises()
    const unitOption = [...document.querySelectorAll('.el-select-dropdown:not(.el-tree-select__popper) .el-select-dropdown__item')]
      .find((o) => (o as HTMLElement).textContent!.trim() === '个')
    expect(unitOption, '单位选项「个」应存在').toBeTruthy()
    ;(unitOption as HTMLElement).dispatchEvent(new MouseEvent('click', { bubbles: true }))
    await flushPromises()
    // 分类树选择「原材料」
    await wrapper.findAll('.el-dialog .el-select__wrapper')[0].trigger('click')
    await flushPromises()
    const catNode = document.querySelector('.el-tree-select__popper .el-tree-node__content')
    expect(catNode, '分类树节点应存在').toBeTruthy()
    ;(catNode as HTMLElement).dispatchEvent(new MouseEvent('click', { bubbles: true }))
    await flushPromises()

    // 设置安全库存下限 200 / 上限 100（min>max 边界）
    const numInputs = wrapper.findAll('.el-input-number input')
    expect(numInputs.length).toBe(2)
    await numInputs[0].setValue('200')
    await numInputs[0].trigger('change')
    await numInputs[1].setValue('100')
    await numInputs[1].trigger('change')
    await flushPromises()

    await clickButton(wrapper, '保 存')

    expect(productApi.create).not.toHaveBeenCalled()
    const msg = document.querySelector('.el-message--error')
    expect(msg, '应弹出错误提示').toBeTruthy()
    expect((msg as HTMLElement).textContent).toContain('安全库存下限不能大于上限')
    wrapper.unmount()
  })
})
