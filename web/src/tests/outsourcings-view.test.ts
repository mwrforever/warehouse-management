// 委外页重构组件测试：工序节点预填（仅委外且未完成节点可选）、回收品只读、组件行应发=数量×单位用量、
// 数量变化应发重算 + 超剩余回弹、保存载荷 items 结构、余料退回弹窗（可退=已发−已退/超退回弹/提交载荷）
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import ElementPlus from 'element-plus'
import OutsourcingsView from '../views/production/OutsourcingsView.vue'

// mock productionApi 全表面：委外列表/工单下拉/工单详情/节点预填/委外详情/新建更新/退回记录与提交
const mocks = {
  outsourcings: vi.fn(),
  orders: vi.fn(),
  orderDetail: vi.fn(),
  fromOperation: vi.fn(),
  outsourcingDetail: vi.fn(),
  createOutsourcing: vi.fn(),
  updateOutsourcing: vi.fn(),
  outsourcingReturns: vi.fn(),
  createOutsourcingReturn: vi.fn(),
}
vi.mock('../api/production', () => ({
  productionApi: {
    outsourcings: (...a: unknown[]) => mocks.outsourcings(...a),
    orders: (...a: unknown[]) => mocks.orders(...a),
    orderDetail: (...a: unknown[]) => mocks.orderDetail(...a),
    fromOperation: (...a: unknown[]) => mocks.fromOperation(...a),
    outsourcingDetail: (...a: unknown[]) => mocks.outsourcingDetail(...a),
    createOutsourcing: (...a: unknown[]) => mocks.createOutsourcing(...a),
    updateOutsourcing: (...a: unknown[]) => mocks.updateOutsourcing(...a),
    outsourcingReturns: (...a: unknown[]) => mocks.outsourcingReturns(...a),
    createOutsourcingReturn: (...a: unknown[]) => mocks.createOutsourcingReturn(...a),
  },
}))
vi.mock('../api/warehouse', () => ({
  warehouseApi: {
    list: vi
      .fn()
      .mockResolvedValue({ items: [{ id: 1, name: '主仓', code: 'WH-01', status: 1 }], total: 1 }),
    locations: vi.fn().mockResolvedValue({
      items: [{ id: 2, name: 'B-01', warehouse_id: 1, status: 1 }],
      total: 1,
    }),
  },
}))
vi.mock('../api/supplier', () => ({
  supplierApi: {
    list: vi.fn().mockResolvedValue({
      items: [{ id: 1, name: '测试供应商', code: 'SUP-001', status: 1 }],
      total: 1,
    }),
  },
}))
vi.mock('../stores/auth', () => ({ useAuthStore: () => ({ has: () => true }) }))
vi.mock('vue-router', () => ({ useRoute: () => ({ query: {} }) }))

// DAG 基线：OP10 下料（委外+待开工，可选）/ OP20 组装（非委外，不可选）/ OP30 质检（委外但已完成，不可选）
const ops = [
  {
    id: 11,
    seq: 1,
    node_no: 'OP10',
    process_name: '下料',
    process_id: 1,
    process_code: 'CUT',
    status: 0,
    status_label: '待开工',
    qualified_qty: 0,
    defective_qty: 0,
    hours: 0,
    is_outsourced: 1,
    output_product_id: 2,
    output_product_name: '半成品B',
  },
  {
    id: 12,
    seq: 2,
    node_no: 'OP20',
    process_name: '组装',
    process_id: 2,
    process_code: 'ASM',
    status: 0,
    status_label: '待开工',
    qualified_qty: 0,
    defective_qty: 0,
    hours: 0,
    is_outsourced: 0,
  },
  {
    id: 13,
    seq: 3,
    node_no: 'OP30',
    process_name: '质检',
    process_id: 3,
    process_code: 'QC',
    status: 2,
    status_label: '已完成',
    qualified_qty: 0,
    defective_qty: 0,
    hours: 0,
    is_outsourced: 1,
  },
]
// 节点预填：OP10 产出半成品B，输入 原料A×2/半成品B×1，计划 10 已委外 0 剩余 5
const prefill = {
  operation_id: 11,
  node_no: 'OP10',
  process_name: '下料',
  order_id: 1,
  order_no: 'MO-001',
  plan_qty: '10.00',
  outsourced_qty: '0.00',
  remaining_qty: '5.00',
  output_product_id: 2,
  output_product_name: '半成品B',
  items: [
    {
      material_id: 101,
      material_name: '原料A',
      material_code: 'MAT-001',
      qty_per_unit: '2.00',
      unit_id: 1,
      unit_name: '个',
      stock: '100.00',
    },
    {
      material_id: 102,
      material_name: '半成品B',
      material_code: 'SEM-002',
      qty_per_unit: '1.00',
      unit_id: 1,
      unit_name: '个',
      stock: '50.00',
    },
  ],
}

function page(items: unknown[], total = items.length) {
  return { items, total, page: 1, per_page: 10 }
}

// 弹窗内下拉选项点击（真实交互：校验选项过滤与回填）
async function pickOption(wrapper: ReturnType<typeof mount>, selectIndex: number, text: string) {
  const selectWrapper = wrapper.findAll('.el-dialog .el-select__wrapper')[selectIndex]
  expect(selectWrapper, `第 ${selectIndex} 个下拉应存在`).toBeTruthy()
  await selectWrapper.trigger('click')
  await flushPromises()
  const opt = [
    ...document.querySelectorAll(
      '.el-select-dropdown:not(.el-tree-select__popper) .el-select-dropdown__item',
    ),
  ].find((o) => (o as HTMLElement).textContent!.trim() === text)
  expect(opt, `选项「${text}」应存在`).toBeTruthy()
  ;(opt as HTMLElement).dispatchEvent(new MouseEvent('click', { bubbles: true }))
  await flushPromises()
}

// 弹窗内下拉选项文本集合（断言工序过滤结果）
function dropdownOptionTexts(): string[] {
  return [...document.querySelectorAll('.el-select-dropdown__item')].map((o) =>
    (o as HTMLElement).textContent!.trim(),
  )
}

describe('委外页：工序节点预填 + 组件应发折算 + 余料退回', () => {
  // 挂载实例跟踪：用例结束后卸载并清空 body（el-select popper/ElMessage 均渲染在 body，
  // 残留会导致后续用例的选项查找命中过期 DOM）
  let wrapper: ReturnType<typeof mount> | undefined

  beforeEach(() => {
    vi.clearAllMocks()
    setActivePinia(createPinia())
    wrapper = undefined
    mocks.outsourcings.mockResolvedValue(page([]))
    mocks.orders.mockResolvedValue(
      page([
        {
          id: 1,
          no: 'MO-001',
          product_id: 2,
          product_name: '成品B',
          product_code: 'FIN-002',
          quantity: 10,
          completed_qty: 0,
          progress: 0,
          plan_date: '2026-08-01',
          status: 2,
          status_label: '生产中',
          released_at: null,
          completed_at: null,
        },
      ]),
    )
    mocks.orderDetail.mockResolvedValue({
      id: 1,
      no: 'MO-001',
      quantity: '10.00',
      materials: [],
      operations: ops,
      status: 2,
    })
  })

  async function mountView() {
    wrapper = mount(OutsourcingsView, {
      attachTo: document.body,
      global: { plugins: [ElementPlus] },
    })
    await flushPromises()
    return wrapper
  }

  afterEach(() => {
    wrapper?.unmount()
    document.body.innerHTML = ''
  })

  // 新建弹窗：点「新 建」并把 MO-001 填入工单下拉
  async function openCreateWithOrder(wrapper: ReturnType<typeof mount>) {
    const newBtn = wrapper.findAll('button').find((b) => b.text().trim() === '新 建')
    expect(newBtn).toBeTruthy()
    await newBtn!.trigger('click')
    await flushPromises()
    await pickOption(wrapper, 0, 'MO-001（成品B）')
    expect(mocks.orderDetail).toHaveBeenCalledWith(1)
  }

  it('新建流程：仅委外且未完成工序可选，选中后预填回收品只读与组件应发=数量×单位用量', async () => {
    mocks.fromOperation.mockResolvedValue(prefill)
    const wrapper = await mountView()
    await openCreateWithOrder(wrapper)

    // 工序下拉只含委外且未完成节点（OP10）；非委外 OP20 与已完成 OP30 不出现
    const opSelect = wrapper.findAll('.el-dialog .el-select__wrapper')[1]
    await opSelect.trigger('click')
    await flushPromises()
    const texts = dropdownOptionTexts()
    expect(texts).toContain('OP10. 下料（产出：半成品B）')
    expect(texts.some((t) => t.includes('组装'))).toBe(false)
    expect(texts.some((t) => t.includes('质检'))).toBe(false)

    // 选中 OP10 → 拉节点预填 → 回收品只读 + 可用量展示
    await pickOption(wrapper, 1, 'OP10. 下料（产出：半成品B）')
    expect(mocks.fromOperation).toHaveBeenCalledWith(11)
    expect(wrapper.find('.el-dialog .prefill-product').text().trim()).toBe('半成品B')
    expect(wrapper.find('.el-dialog .remain-cell').text().trim()).toBe('5.00')

    // 填数量 4（≤剩余 5）→ 组件行应发 = 4×2=8 / 4×1=4（行内可改、单位与库存带出）
    const qtyInput = wrapper.find('.el-dialog .el-input-number input')
    await qtyInput.setValue('4')
    await qtyInput.trigger('blur')
    await flushPromises()
    const rows = wrapper.findAll('.item-table .el-table__row')
    expect(rows).toHaveLength(2)
    expect(rows[0]!.text()).toContain('原料A MAT-001')
    expect(rows[0]!.text()).toContain('100.00')
    const rowInputs = wrapper.findAll('.item-table .el-input-number input')
    expect((rowInputs[0]!.element as HTMLInputElement).value).toBe('8.00')
    expect((rowInputs[1]!.element as HTMLInputElement).value).toBe('4.00')
  })

  it('数量变化应发重算；超剩余回弹 warning 并以剩余为上限重算', async () => {
    mocks.fromOperation.mockResolvedValue(prefill)
    const wrapper = await mountView()
    await openCreateWithOrder(wrapper)
    await pickOption(wrapper, 1, 'OP10. 下料（产出：半成品B）')

    // 先填 4 → 行应发 8/4；再改 6（>剩余 5）→ 回弹 5，行应发 10/5
    const qtyInput = wrapper.find('.el-dialog .el-input-number input')
    await qtyInput.setValue('4')
    await qtyInput.trigger('blur')
    await flushPromises()
    await qtyInput.setValue('6')
    await qtyInput.trigger('blur')
    await flushPromises()
    const warning = [...document.querySelectorAll('.el-message--warning')].some((m) =>
      m.textContent?.includes('委外数量超过节点剩余计划量'),
    )
    expect(warning).toBe(true)
    const rowInputs = wrapper.findAll('.item-table .el-input-number input')
    expect((rowInputs[0]!.element as HTMLInputElement).value).toBe('10.00')
    expect((rowInputs[1]!.element as HTMLInputElement).value).toBe('5.00')
  })

  it('保存载荷：items 结构正确且行内可改（超折算上限回弹）', async () => {
    mocks.fromOperation.mockResolvedValue(prefill)
    mocks.createOutsourcing.mockResolvedValue('OS20260824-001')
    const wrapper = await mountView()
    await openCreateWithOrder(wrapper)
    await pickOption(wrapper, 1, 'OP10. 下料（产出：半成品B）')
    const qtyInput = wrapper.find('.el-dialog .el-input-number input')
    await qtyInput.setValue('4')
    await qtyInput.trigger('blur')
    await flushPromises()

    // 行内可改：原料A 应发 8 → 7（≤ 折算上限 8）；半成品B 应发 4 → 9 超上限（4）回弹 4
    const rowInputs = wrapper.findAll('.item-table .el-input-number input')
    await rowInputs[0]!.setValue('7')
    await rowInputs[0]!.trigger('blur')
    await rowInputs[1]!.setValue('9')
    await rowInputs[1]!.trigger('blur')
    await flushPromises()
    const capWarning = [...document.querySelectorAll('.el-message--warning')].some((m) =>
      m.textContent?.includes('应发数量超过单位用量折算上限'),
    )
    expect(capWarning).toBe(true)

    // 供应商/仓库/库位 → 保存：载荷含订单头与 items（过滤 0 行）
    await pickOption(wrapper, 2, '测试供应商（SUP-001）')
    await pickOption(wrapper, 3, '主仓')
    await pickOption(wrapper, 4, 'B-01')
    const saveBtn = wrapper.findAll('button').find((b) => b.text().trim() === '保 存')
    await saveBtn!.trigger('click')
    await flushPromises()
    expect(mocks.createOutsourcing).toHaveBeenCalledWith(
      expect.objectContaining({
        order_id: 1,
        operation_id: 11,
        supplier_id: 1,
        warehouse_id: 1,
        location_id: 2,
        quantity: 4,
        items: [
          { material_id: 101, required_qty: 7, unit_id: 1 },
          { material_id: 102, required_qty: 4, unit_id: 1 },
        ],
      }),
    )
  })

  it('编辑弹窗：同构回填（detail 组件数量优先于折算值）', async () => {
    mocks.fromOperation.mockResolvedValue(prefill)
    mocks.outsourcings.mockResolvedValue(
      page([
        {
          id: 4,
          no: 'OS20260824-001',
          order_id: 1,
          order_no: 'MO-001',
          operation_id: 11,
          process_name: '下料',
          supplier_id: 1,
          supplier_name: '测试供应商',
          quantity: '3.00',
          status: 0,
          status_label: '草稿',
          approved_at: null,
          operator: null,
          created_at: '2026-08-24 10:00:00',
        },
      ]),
    )
    mocks.outsourcingDetail.mockResolvedValue({
      id: 4,
      no: 'OS20260824-001',
      order_id: 1,
      order_no: 'MO-001',
      operation_id: 11,
      node_no: 'OP10',
      process_name: '下料',
      output_product_name: '半成品B',
      supplier_id: 1,
      supplier_name: '测试供应商',
      status: 0,
      status_label: '草稿',
      warehouse_id: 1,
      warehouse_name: '主仓',
      location_id: 2,
      location_name: 'B-01',
      quantity: '3.00',
      received_qty: '0.00',
      approved_at: null,
      operator: null,
      remark: null,
      items: [
        {
          id: 31,
          material_id: 101,
          material_name: '原料A',
          required_qty: '5.00',
          issued_qty: '0.00',
          returned_qty: '0.00',
          unit_name: '个',
        },
        {
          id: 32,
          material_id: 102,
          material_name: '半成品B',
          required_qty: '2.50',
          issued_qty: '0.00',
          returned_qty: '0.00',
          unit_name: '个',
        },
      ],
    })
    mocks.updateOutsourcing.mockResolvedValue(undefined)
    const wrapper = await mountView()

    // 行内「编 辑」打开编辑弹窗：数量 3、组件行保留草稿内已保存的应发（5.00/2.50，非折算 6/3）
    const editBtn = wrapper.findAll('button').find((b) => b.text().trim() === '编 辑')
    await editBtn!.trigger('click')
    await flushPromises()
    expect(mocks.outsourcingDetail).toHaveBeenCalledWith(4)
    expect(mocks.fromOperation).toHaveBeenCalledWith(11)
    const rowInputs = wrapper.findAll('.item-table .el-input-number input')
    expect((rowInputs[0]!.element as HTMLInputElement).value).toBe('5.00')
    expect((rowInputs[1]!.element as HTMLInputElement).value).toBe('2.50')

    const saveBtn = wrapper.findAll('button').find((b) => b.text().trim() === '保 存')
    await saveBtn!.trigger('click')
    await flushPromises()
    expect(mocks.updateOutsourcing).toHaveBeenCalledWith(
      4,
      expect.objectContaining({
        order_id: 1,
        operation_id: 11,
        quantity: 3,
        items: [
          { material_id: 101, required_qty: 5, unit_id: 1 },
          { material_id: 102, required_qty: 2.5, unit_id: 1 },
        ],
      }),
    )
  })

  it('余料退回：可退=已发−已退列表、超退回弹 warning、提交载荷', async () => {
    mocks.outsourcings.mockResolvedValue(
      page([
        {
          id: 3,
          no: 'OS20260824-003',
          order_id: 1,
          order_no: 'MO-001',
          operation_id: 11,
          node_no: 'OP10',
          process_name: '下料',
          supplier_id: 1,
          supplier_name: '测试供应商',
          quantity: '10.00',
          status: 1,
          status_label: '已审核',
          received_qty: 0,
          approved_at: null,
          operator: null,
          created_at: '2026-08-24 10:00:00',
        },
      ]),
    )
    mocks.outsourcingDetail.mockResolvedValue({
      id: 3,
      no: 'OS20260824-003',
      order_id: 1,
      order_no: 'MO-001',
      operation_id: 11,
      node_no: 'OP10',
      process_name: '下料',
      output_product_name: '半成品B',
      supplier_id: 1,
      supplier_name: '测试供应商',
      status: 1,
      status_label: '已审核',
      warehouse_id: 1,
      warehouse_name: '主仓',
      location_id: 2,
      location_name: 'B-01',
      quantity: '10.00',
      received_qty: '0.00',
      approved_at: null,
      operator: null,
      remark: null,
      items: [
        {
          id: 21,
          material_id: 101,
          material_name: '原料A',
          required_qty: '10.00',
          issued_qty: '10.00',
          returned_qty: '4.00',
          unit_name: '个',
        },
      ],
    })
    mocks.createOutsourcingReturn.mockResolvedValue('ORT20260824-001')
    const wrapper = await mountView()

    // 已审核行 → 「退回余料」→ 明细可退 = 已发 10 − 已退 4 = 6
    const returnBtn = wrapper.findAll('button').find((b) => b.text().trim() === '退回余料')
    expect(returnBtn).toBeTruthy()
    await returnBtn!.trigger('click')
    await flushPromises()
    expect(mocks.outsourcingDetail).toHaveBeenCalledWith(3)
    const returnDialog = wrapper.find('.el-dialog')
    expect(returnDialog.text()).toContain('余料退回')
    expect(returnDialog.text()).toContain('10.00')
    expect(returnDialog.text()).toContain('4.00')
    expect(returnDialog.text()).toContain('6.00')

    // 填 7 > 可退 6 → 回弹 warning；再填 6 → 选中入库仓库/库位 → 提交载荷
    const returnInput = returnDialog.find('.el-input-number input')
    await returnInput.setValue('7')
    await returnInput.trigger('blur')
    await flushPromises()
    const overWarning = [...document.querySelectorAll('.el-message--warning')].some((m) =>
      m.textContent?.includes('退回数量超过已发未退数量'),
    )
    expect(overWarning).toBe(true)
    await returnInput.setValue('6')
    await returnInput.trigger('blur')
    await flushPromises()
    const returnSelects = returnDialog.findAll('.el-select__wrapper')
    await returnSelects[0]!.trigger('click')
    await flushPromises()
    const whOpt = [...document.querySelectorAll('.el-select-dropdown__item')].find(
      (o) => (o as HTMLElement).textContent!.trim() === '主仓',
    )
    ;(whOpt as HTMLElement).dispatchEvent(new MouseEvent('click', { bubbles: true }))
    await flushPromises()
    const loc = returnDialog.findAll('.el-select__wrapper')[1]
    await loc.trigger('click')
    await flushPromises()
    const b01Opt = [...document.querySelectorAll('.el-select-dropdown__item')].find(
      (o) => (o as HTMLElement).textContent!.trim() === 'B-01',
    )
    ;(b01Opt as HTMLElement).dispatchEvent(new MouseEvent('click', { bubbles: true }))
    await flushPromises()
    const submitBtn = returnDialog.findAll('button').find((b) => b.text().trim() === '提交退回')
    await submitBtn!.trigger('click')
    await flushPromises()
    expect(mocks.createOutsourcingReturn).toHaveBeenCalledWith(3, {
      items: [{ item_id: 21, quantity: 6 }],
      warehouse_id: 1,
      location_id: 2,
      remark: '',
    })
  })
})
