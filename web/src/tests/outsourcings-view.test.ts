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
// 路由查询可变载体：vi.hoisted 提升到 mock 工厂可用（keyword 跳转预填用例注入 ?keyword=单号，其余用例空查询）
const routeQuery = vi.hoisted(() => ({ value: {} as Record<string, unknown> }))
vi.mock('vue-router', () => ({ useRoute: () => ({ query: routeQuery.value }) }))

// DAG 基线：OP10 下料（委外+待开工，可选）/ OP20 组装（非委外，不可选）/ OP30 质检（委外但已完成，不可选）
// / OP40 冲压（委外+待开工，可选——竞态用例的「工序 B」）
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
    id: 14,
    seq: 4,
    node_no: 'OP40',
    process_name: '冲压',
    process_id: 4,
    process_code: 'STP',
    status: 0,
    status_label: '待开工',
    qualified_qty: 0,
    defective_qty: 0,
    hours: 0,
    is_outsourced: 1,
    output_product_id: 3,
    output_product_name: '半成品D',
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

// 竞态用例「工序 B」的节点预填：OP40 产出半成品D，输入 原料C×1
const prefillB: typeof prefill = {
  operation_id: 14,
  node_no: 'OP40',
  process_name: '冲压',
  order_id: 1,
  order_no: 'MO-001',
  plan_qty: '10.00',
  outsourced_qty: '0.00',
  remaining_qty: '8.00',
  output_product_id: 3,
  output_product_name: '半成品D',
  items: [
    {
      material_id: 103,
      material_name: '原料C',
      material_code: 'MAT-003',
      qty_per_unit: '1.00',
      unit_id: 1,
      unit_name: '个',
      stock: '20.00',
    },
  ],
}

function page(items: unknown[], total = items.length) {
  return { items, total, page: 1, per_page: 10 }
}

// 可控 deferred：竞态用例手动控制慢响应 resolve 时机（无需真实定时器）
function deferred<T>() {
  let resolve!: (v: T) => void
  let reject!: (e: unknown) => void
  const promise = new Promise<T>((res, rej) => {
    resolve = res
    reject = rej
  })
  return { promise, resolve, reject }
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
    routeQuery.value = {}
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

  // 评审 F5：快速切换工序 A（慢）→ B（快）时，迟到的 A 预填响应不得覆盖 B 已回填的编辑态
  it('竞态：快速切换工序时慢预填响应不得覆盖新工序回填', async () => {
    const dA = deferred<typeof prefill>()
    // 工序 A（OP10）响应受控慢；工序 B（OP40）立即返回
    mocks.fromOperation.mockImplementationOnce(() => dA.promise).mockResolvedValueOnce(prefillB)
    const wrapper = await mountView()
    await openCreateWithOrder(wrapper)
    // 先选 OP10（在途）：预填区未出现（旧会话残留不展示）
    await pickOption(wrapper, 1, 'OP10. 下料（产出：半成品B）')
    expect(mocks.fromOperation).toHaveBeenCalledTimes(1)
    expect(wrapper.find('.el-dialog .prefill-block').exists()).toBe(false)
    // 切工序 B（快）→ 回填为 B 的预填（回收品=半成品D）
    await pickOption(wrapper, 1, 'OP40. 冲压（产出：半成品D）')
    await flushPromises()
    expect(wrapper.find('.el-dialog .prefill-product').text().trim()).toBe('半成品D')
    // 迟到响应 A 落点：会话守卫丢弃，不覆盖 B（回收品仍是半成品D、组件行仍是 B 的原料C）
    dA.resolve(prefill)
    await flushPromises()
    expect(wrapper.find('.el-dialog .prefill-product').text().trim()).toBe('半成品D')
    const rows = wrapper.findAll('.item-table .el-table__row')
    expect(rows).toHaveLength(1)
    expect(rows[0]!.text()).toContain('原料C')
  })

  // 评审 F5：编辑弹窗快速连点重开（A 慢 B 快）时，慢详情响应不得覆盖新会话回填
  it('竞态：编辑弹窗快速重开时慢详情响应不得覆盖新会话', async () => {
    // 两个草稿行：row1 慢、row2 快
    const row1 = {
      id: 4,
      no: 'OS20260824-001',
      order_id: 1,
      order_no: 'MO-001',
      operation_id: 11,
      node_no: 'OP10',
      process_name: '下料',
      supplier_id: 1,
      supplier_name: '测试供应商',
      quantity: '3.00',
      status: 0,
      status_label: '草稿',
      approved_at: null,
      operator: null,
      created_at: '2026-08-24 10:00:00',
    }
    const row2 = { ...row1, id: 5, no: 'OS20260824-002' }
    mocks.outsourcings.mockResolvedValue(page([row1, row2]))
    const detailShape = {
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
      quantity: '',
      received_qty: '0.00',
      approved_at: null,
      operator: null,
      remark: null,
      items: [] as {
        id: number
        material_id: number
        material_name: string
        required_qty: string
        issued_qty: string
        returned_qty: string
        unit_name: string
      }[],
    }
    // row1 详情受控慢（数量 3、应发 5.00/2.50）；row2 详情立即返回（数量 2、应发 4.00/2.00）
    const detail1 = {
      ...detailShape,
      id: 4,
      no: 'OS20260824-001',
      quantity: '3.00',
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
    }
    const detail2 = {
      ...detailShape,
      id: 5,
      no: 'OS20260824-002',
      quantity: '2.00',
      items: [
        {
          id: 41,
          material_id: 101,
          material_name: '原料A',
          required_qty: '4.00',
          issued_qty: '0.00',
          returned_qty: '0.00',
          unit_name: '个',
        },
        {
          id: 42,
          material_id: 102,
          material_name: '半成品B',
          required_qty: '2.00',
          issued_qty: '0.00',
          returned_qty: '0.00',
          unit_name: '个',
        },
      ],
    }
    const d1 = deferred<typeof detail2>()
    mocks.outsourcingDetail.mockImplementationOnce(() => d1.promise).mockResolvedValueOnce(detail2)
    mocks.fromOperation.mockResolvedValue(prefill)
    const wrapper = await mountView()

    // 连点「编 辑」row1（慢，在途）→ row2（快）→ 弹窗回填为 row2（数量 2、应发 4.00/2.00）
    const editBtns = () => wrapper.findAll('button').filter((b) => b.text().trim() === '编 辑')
    await editBtns()[0]!.trigger('click')
    await flushPromises()
    expect(mocks.outsourcingDetail).toHaveBeenCalledTimes(1)
    await editBtns()[1]!.trigger('click')
    await flushPromises()
    expect(mocks.outsourcingDetail).toHaveBeenCalledTimes(2)
    const qtyInput = wrapper.find('.el-dialog .el-input-number input')
    expect((qtyInput.element as HTMLInputElement).value).toBe('2.00')
    let rowInputs = wrapper.findAll('.item-table .el-input-number input')
    expect((rowInputs[0]!.element as HTMLInputElement).value).toBe('4.00')
    expect((rowInputs[1]!.element as HTMLInputElement).value).toBe('2.00')
    // 迟到响应 row1 落点：会话守卫丢弃——弹窗仍为 row2 回填，无过期错误提示
    d1.resolve(detail1)
    await flushPromises()
    expect((qtyInput.element as HTMLInputElement).value).toBe('2.00')
    rowInputs = wrapper.findAll('.item-table .el-input-number input')
    expect((rowInputs[0]!.element as HTMLInputElement).value).toBe('4.00')
    expect((rowInputs[1]!.element as HTMLInputElement).value).toBe('2.00')
    expect([...document.querySelectorAll('.el-message--error')]).toHaveLength(0)
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

  // BF-2：快速连点两行「退回余料」时（A 慢 B 快），A 的迟到详情不得覆盖 B 单的退回明细——
  // 否则用户在「B 单语境」的弹窗里提交，实际回收/退回的是 A 单（所见非所选）
  it('竞态：快速连点退回余料时慢详情不得覆盖新单退回明细（BF-2）', async () => {
    // 两行已审核委外单均可退回余料
    const rowShape = {
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
    }
    mocks.outsourcings.mockResolvedValue(
      page([
        { ...rowShape, id: 4, no: 'OS20260824-001' },
        { ...rowShape, id: 5, no: 'OS20260824-002' },
      ]),
    )
    // row1（单 4）详情受控慢（原料A 已发10已退4）；row2（单 5）立即返回（原料C 已发8已退2）
    const detail1 = {
      ...rowShape,
      id: 4,
      no: 'OS20260824-001',
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
    }
    const detail2 = {
      ...rowShape,
      id: 5,
      no: 'OS20260824-002',
      items: [
        {
          id: 41,
          material_id: 103,
          material_name: '原料C',
          required_qty: '8.00',
          issued_qty: '8.00',
          returned_qty: '2.00',
          unit_name: '个',
        },
      ],
    }
    const d1 = deferred<typeof detail2>()
    mocks.outsourcingDetail.mockImplementationOnce(() => d1.promise).mockResolvedValueOnce(detail2)
    const wrapper = await mountView()

    // 连点两行「退回余料」：row1（慢，在途）→ row2（快）→ 弹窗以 row2 明细打开（原料C 可退 6）
    const returnBtns = () => wrapper.findAll('button').filter((b) => b.text().trim() === '退回余料')
    await returnBtns()[0]!.trigger('click')
    await flushPromises()
    expect(mocks.outsourcingDetail).toHaveBeenCalledTimes(1)
    await returnBtns()[1]!.trigger('click')
    await flushPromises()
    expect(mocks.outsourcingDetail).toHaveBeenCalledTimes(2)
    const dialog = wrapper.find('.el-dialog')
    expect(dialog.text()).toContain('余料退回')
    expect(dialog.text()).toContain('原料C')
    expect(dialog.text()).not.toContain('原料A')
    // 迟到响应 row1 落点：会话守卫丢弃——明细仍是 row2 的原料C，且无过期错误提示
    d1.resolve(detail1)
    await flushPromises()
    expect(dialog.text()).toContain('原料C')
    expect(dialog.text()).not.toContain('原料A')
    expect([...document.querySelectorAll('.el-message--error')]).toHaveLength(0)
  })

  // BF-1 回归：工序网络「打开委外页」跳转携带 ?keyword=单号，委外页须消费该参数实现按单号定位
  it('keyword 跳转预填：携带单号进入时首查即带 keyword 出参且筛选框回显单号', async () => {
    routeQuery.value = { keyword: 'OS20260824-009' }
    const wrapper = await mountView()
    // 首查（onMounted search）即携带单号出参，且全程仅此一次查询（setup 期预填不触发防抖链重复请求）
    expect(mocks.outsourcings).toHaveBeenCalledTimes(1)
    expect(mocks.outsourcings).toHaveBeenCalledWith(
      expect.objectContaining({ keyword: 'OS20260824-009' }),
    )
    // 筛选框回显单号（ListFilterBar 以 props.keyword 为内部防抖源初始值）
    expect((wrapper.find('.kw-input input').element as HTMLInputElement).value).toBe(
      'OS20260824-009',
    )
  })

  // PF-3 回归：回收弹窗所需三字段（数量/已回收/回收品）列表行全有，打开弹窗不得再拉委外详情
  //（详情是委外域最重读路径：4 组关系预载 + SUM；列表行滞后仅致预填偏大，提交有后端 1524 超收校验兜底）
  it('回收弹窗：直接消费列表行字段打开，不调详情接口且剩余=委外量−已回收（PF-3）', async () => {
    mocks.outsourcings.mockResolvedValue(
      page([
        {
          id: 7,
          no: 'OS20260824-007',
          order_id: 1,
          order_no: 'MO-001',
          operation_id: 11,
          node_no: 'OP10',
          process_name: '下料',
          output_product_name: '半成品B',
          supplier_id: 1,
          supplier_name: '测试供应商',
          quantity: '10.00',
          received_qty: '4.00',
          status: 1,
          status_label: '已审核',
          approved_at: '2026-08-24 10:00:00',
          operator: null,
          created_at: '2026-08-24 10:00:00',
        },
      ]),
    )
    const wrapper = await mountView()

    // 已审核行「回 收」→ 弹窗立即以列表行字段打开（无详情请求）
    const receiptBtn = wrapper.findAll('button').find((b) => b.text().trim() === '回 收')
    expect(receiptBtn).toBeTruthy()
    await receiptBtn!.trigger('click')
    await flushPromises()
    expect(mocks.outsourcingDetail).not.toHaveBeenCalled()
    // 回收品与剩余可回收来自列表行：半成品B / 10−4=6，默认回收量 6
    const dialog = wrapper.findAll('.el-dialog').find((d) => d.text().includes('委外回收'))
    expect(dialog, '回收弹窗应打开').toBeTruthy()
    expect(dialog!.find('.receipt-product').text().trim()).toBe('半成品B')
    expect(dialog!.find('.remain-cell').text().trim()).toBe('6.00')
    const qtyInput = dialog!.find('.el-input-number input')
    expect((qtyInput.element as HTMLInputElement).value).toBe('6.00')
  })
})
