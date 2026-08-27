// ProductPicker 商品选择器测试：选项少（≤50）走下拉直选、选项多（>50）切换分页弹窗（搜索防抖 +
// 查询按钮节流 + 分页）；弹窗行展示名称正文+编号标签；pin 回显并入/移除；change 携带选中行（unit_id 带出）
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { mount, flushPromises, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import ElementPlus from 'element-plus'
import ProductPicker, { __resetProductPickerCache } from '../components/ProductPicker.vue'
import { productApi } from '../api/product'

// mock 商品 API：finished 档 60 条（分页生成，per_page 感知），semi_finished 档 1 条 → 合计 >50 弹窗模式；
// 下拉模式用例（合计 ≤50）用 selectImpl 覆盖并在 afterEach 恢复。
// vi.hoisted：vi.mock 工厂提升执行于模块顶部，实现须经 hoisted 提前定义避免 TDZ
const { dialogImpl, selectImpl } = vi.hoisted(() => {
  type ListParams = { type?: string; page?: number; per_page?: number }
  // 与 ProductItem 结构对齐（type 为字面量联合，可赋值给 ProductType）
  type MockItem = {
    id: number
    name: string
    code: string
    type: 'raw_material' | 'semi_finished' | 'finished'
    type_label: string
    unit_id: number
    unit_name: string
    category_id: number
    category_name: string | null
    spec: string | null
    barcode: string | null
    safety_min: number
    safety_max: number
    status: number
    remark: string | null
  }
  const semi: MockItem[] = [
    {
      id: 5,
      name: '半成品A',
      code: 'SEMI-001',
      type: 'semi_finished',
      type_label: '半成品',
      unit_id: 2,
      unit_name: '块',
      category_id: 1,
      category_name: null,
      spec: null,
      barcode: null,
      safety_min: 0,
      safety_max: 0,
      status: 1,
      remark: null,
    },
  ]
  const dialogImpl = (params: ListParams) => {
    if (params.type === 'semi_finished')
      return Promise.resolve({ items: semi, total: 1, page: 1, per_page: params.per_page ?? 10 })
    const per = params.per_page ?? 10
    const page = params.page ?? 1
    const total = 60
    const count = Math.min(per, Math.max(0, total - (page - 1) * per))
    const items: MockItem[] = Array.from({ length: count }, (_, i) => {
      const n = (page - 1) * per + i + 1
      return {
        id: 100 + n,
        name: `成品${n}`,
        code: `FIN-${String(n).padStart(3, '0')}`,
        type: 'finished',
        type_label: '成品',
        unit_id: 1,
        unit_name: '个',
        category_id: 1,
        category_name: null,
        spec: null,
        barcode: null,
        safety_min: 0,
        safety_max: 0,
        status: 1,
        remark: null,
      }
    })
    return Promise.resolve({ items, total, page, per_page: per })
  }
  const selectImpl = (params: ListParams) => {
    if (params.type === 'semi_finished')
      return Promise.resolve({ items: semi, total: 1, page: 1, per_page: params.per_page ?? 10 })
    const per = params.per_page ?? 10
    const items: MockItem[] =
      per >= 100
        ? [
            {
              id: 2,
              name: '成品B',
              code: 'FIN-002',
              type: 'finished',
              type_label: '成品',
              unit_id: 1,
              unit_name: '个',
              category_id: 1,
              category_name: null,
              spec: null,
              barcode: null,
              safety_min: 0,
              safety_max: 0,
              status: 1,
              remark: null,
            },
          ]
        : []
    return Promise.resolve({ items, total: 1, page: 1, per_page: per })
  }
  return { dialogImpl, selectImpl }
})
vi.mock('../api/product', () => ({
  productApi: { list: vi.fn().mockImplementation(dialogImpl) },
}))

// 选择器弹窗内的表格行文本（名称正文 + 编号标签紧跟）
function rowTexts(wrapper: VueWrapper) {
  return wrapper
    .findAll('.el-table__row')
    .map((r) => r.text())
    .join('|')
}

describe('ProductPicker 下拉模式（选项合计 ≤50）', () => {
  let pinia: ReturnType<typeof createPinia>

  beforeEach(() => {
    vi.clearAllMocks()
    __resetProductPickerCache()
    // 覆盖为下拉模式：finished 档合计 1 条（两档合计 ≤50）
    vi.mocked(productApi.list).mockImplementation(selectImpl)
    pinia = createPinia()
    setActivePinia(pinia)
  })
  afterEach(() => {
    // 恢复弹窗模式实现，防止跨 describe 串扰（vi.clearAllMocks 不清 mockImplementation）
    vi.mocked(productApi.list).mockImplementation(dialogImpl)
  })

  it('选项少时渲染 el-select，选择后 emit 值与选中行（含 unit_id 供单位带出）', async () => {
    // 正常路径：合计 2 条 ≤50 → 下拉直选；change 携带完整行信息
    const wrapper = mount(ProductPicker, {
      attachTo: document.body,
      props: { modelValue: null, types: ['semi_finished', 'finished'] },
      global: { plugins: [ElementPlus, pinia] },
    })
    await flushPromises()
    expect(wrapper.find('.el-select').exists(), '选项少应渲染下拉').toBe(true)

    await wrapper.find('.el-select__wrapper').trigger('click')
    await flushPromises()
    const opt = [...document.querySelectorAll('.el-select-dropdown__item')].find((o) =>
      (o as HTMLElement).textContent!.includes('半成品A'),
    )
    expect(opt, '半成品选项应存在').toBeTruthy()
    ;(opt as HTMLElement).dispatchEvent(new MouseEvent('click', { bubbles: true }))
    await flushPromises()

    expect(wrapper.emitted('update:modelValue')).toEqual([[5]])
    expect(wrapper.emitted('change')).toEqual([
      [{ id: 5, name: '半成品A', code: 'SEMI-001', unit_id: 2 }],
    ])
    wrapper.unmount()
  })

  it('清除时 emit null（值与行一并置空）', async () => {
    // 边界条件：clearable 清除后父级值复位为空
    const wrapper = mount(ProductPicker, {
      attachTo: document.body,
      props: { modelValue: 5, types: ['semi_finished', 'finished'] },
      global: { plugins: [ElementPlus, pinia] },
    })
    await flushPromises()
    wrapper.findComponent({ name: 'ElSelect' }).vm.$emit('clear')
    await flushPromises()
    expect(wrapper.emitted('update:modelValue')).toEqual([[null]])
    expect(wrapper.emitted('change')).toEqual([[null]])
    wrapper.unmount()
  })
})

describe('ProductPicker 分页弹窗模式（选项合计 >50）', () => {
  let pinia: ReturnType<typeof createPinia>

  beforeEach(() => {
    vi.clearAllMocks()
    __resetProductPickerCache()
    pinia = createPinia()
    setActivePinia(pinia)
  })
  afterEach(() => vi.useRealTimers())

  it('选项多时渲染点击弹窗：表格行展示名称正文+编号标签，点击行选中', async () => {
    // 正常路径：合计 61 >50 → 弹窗模式；行内名称与编码均可见（名字正文+编号标签）
    const wrapper = mount(ProductPicker, {
      attachTo: document.body,
      props: { modelValue: null, types: ['semi_finished', 'finished'] },
      global: { plugins: [ElementPlus, pinia] },
    })
    await flushPromises()
    expect(wrapper.find('.el-select').exists(), '选项多应渲染弹窗选择器').toBe(false)

    const text = rowTexts(wrapper)
    expect(text).toContain('成品1')
    expect(text).toContain('FIN-001')
    expect(text).toContain('成品')

    const row = wrapper.findAll('.el-table__row').find((r) => r.text().includes('成品1'))
    await row!.trigger('click')
    await flushPromises()
    expect(wrapper.emitted('update:modelValue')).toEqual([[101]])
    expect(wrapper.emitted('change')).toEqual([
      [{ id: 101, name: '成品1', code: 'FIN-001', unit_id: 1 }],
    ])
    wrapper.unmount()
  })

  it('搜索输入 300ms 防抖后携带 keyword 查询并回首页', async () => {
    // 正常路径 + 防抖：弹窗内逐字符输入只发最后一次请求
    vi.useFakeTimers()
    const wrapper = mount(ProductPicker, {
      attachTo: document.body,
      props: { modelValue: null, types: ['finished'] },
      global: { plugins: [ElementPlus, pinia] },
    })
    await flushPromises()
    const kwInput = wrapper.find('.search-row input')
    await kwInput.setValue('成')
    await kwInput.setValue('成品1')
    expect(productApi.list).not.toHaveBeenCalledWith(expect.objectContaining({ keyword: '成' }))
    await vi.advanceTimersByTimeAsync(300)
    expect(productApi.list).toHaveBeenCalledWith(
      expect.objectContaining({ keyword: '成品1', page: 1, per_page: 10 }),
    )
    wrapper.unmount()
  })

  it('翻页触发下一页查询（各档独立分页合并展示）', async () => {
    // 正常路径：分页器翻页后按新页码请求
    const wrapper = mount(ProductPicker, {
      attachTo: document.body,
      props: { modelValue: null, types: ['finished'] },
      global: { plugins: [ElementPlus, pinia] },
    })
    await flushPromises()
    const nextBtn = wrapper.find('.el-pagination .btn-next')
    expect(nextBtn.exists(), '分页器下一页应存在').toBe(true)
    await nextBtn.trigger('click')
    await flushPromises()
    expect(productApi.list).toHaveBeenCalledWith(
      expect.objectContaining({ page: 2, per_page: 10, type: 'finished' }),
    )
    wrapper.unmount()
  })

  it('pin 回显并入表格尾部，pin 清空时移除并入项（防串单）', async () => {
    // 边界条件：编辑回显的历史商品不在搜索结果内 → pin 保证展示；清空后并入项移除
    const wrapper = mount(ProductPicker, {
      attachTo: document.body,
      props: {
        modelValue: 999,
        types: ['finished'],
        pin: { id: 999, name: '历史成品', code: 'OLD-001', unit_id: 3 },
      },
      global: { plugins: [ElementPlus, pinia] },
    })
    await flushPromises()
    expect(rowTexts(wrapper)).toContain('历史成品')
    expect(rowTexts(wrapper)).toContain('OLD-001')

    // pin 清空（弹窗关闭语义）：并入项移除，搜索结果保留
    await wrapper.setProps({ pin: null })
    await flushPromises()
    expect(rowTexts(wrapper)).not.toContain('历史成品')
    expect(rowTexts(wrapper)).toContain('成品1')
    wrapper.unmount()
  })
})
