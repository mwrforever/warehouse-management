// 工艺路线画布编辑器组件测试：环路本地预检拦截（1701 同口径）、保存载荷结构、删节点连带删边、
// 自动编号、画布双击创建空节点（未配工序保存拦截）、节点四边把手拖出自动连线 + 重开会话迟到响应守卫
// 画布依赖 @vue-flow/core，单测将其 stub 为占位组件并渲染 node-routing 插槽（节点卡片/四边把手可 DOM 断言，
// 真实画布拖拽/连线交互由 Playwright E2E TC-RTG-* 覆盖），组件行为经可见控件驱动：工序下拉+添加节点 /
// 节点面板配置 / 添加连线双下拉 / 校验 DAG / 保 存
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { mount, flushPromises, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createRouter, createMemoryHistory, type Router } from 'vue-router'
import ElementPlus, { ElMessage } from 'element-plus'
import RoutingCanvasView from '../views/master/RoutingCanvasView.vue'
import { useAuthStore } from '../stores/auth'

// Vue Flow 占位：保留受控 props 与事件名，并按 nodes 渲染 node-routing 插槽（节点卡片含四边把手，
// 供拖出自动连线用例 DOM 驱动）；useVueFlow 返回恒等坐标换算（与真实 store 挂载前的默认行为一致，
// 拖拽/双击落点即流坐标）
vi.mock('@vue-flow/core', () => ({
  VueFlow: {
    name: 'VueFlow',
    props: ['nodes', 'edges', 'nodesConnectable', 'nodesDraggable'],
    emits: ['connect', 'nodeClick', 'edgeClick', 'nodeDragStop'],
    template:
      '<div class="vue-flow-stub"><template v-for="n in nodes || []" :key="n.id">' +
      '<slot name="node-routing" :id="n.id" /></template></div>',
  },
  Handle: { name: 'Handle', props: ['type', 'position'], template: '<div class="handle-stub" />' },
  Position: { left: 'left', right: 'right', top: 'top', bottom: 'bottom' },
  useVueFlow: vi.fn(() => ({
    screenToFlowCoordinate: (p: { x: number; y: number }) => p,
  })),
}))

// 工艺路线 API mock：列表空、graph 返回空图（默认用例仅覆盖新建路径）
vi.mock('../api/routing', () => ({
  routingApi: {
    list: vi.fn().mockResolvedValue({ items: [], total: 0 }),
    create: vi.fn().mockResolvedValue({ id: 1, code: 'RTG-001' }),
    update: vi.fn().mockResolvedValue({}),
    remove: vi.fn().mockResolvedValue({}),
    toggle: vi.fn().mockResolvedValue({}),
    graph: vi.fn().mockResolvedValue({ routing: null, nodes: [], edges: [] }),
  },
}))

// 商品 mock：按 type 区分成品（表头）/原料（材料行）/半成品（输出产品下拉）
vi.mock('../api/product', () => ({
  productApi: {
    list: vi.fn().mockImplementation((params: { type?: string }) => {
      const base = { category_id: 1, status: 1 }
      if (params.type === 'finished')
        return Promise.resolve({
          items: [
            {
              ...base,
              id: 9,
              name: '成品桌',
              code: 'FIN-002',
              type: 'finished',
              type_label: '成品',
              unit_id: 1,
              unit_name: '个',
            },
          ],
          total: 1,
        })
      if (params.type === 'semi_finished')
        return Promise.resolve({
          items: [
            {
              ...base,
              id: 5,
              name: '桌面板',
              code: 'SEM-001',
              type: 'semi_finished',
              type_label: '半成品',
              unit_id: 2,
              unit_name: '块',
            },
          ],
          total: 1,
        })
      return Promise.resolve({
        items: [
          {
            ...base,
            id: 1,
            name: '铝材',
            code: 'MAT-001',
            type: 'raw_material',
            type_label: '原料',
            unit_id: 1,
            unit_name: '个',
          },
        ],
        total: 1,
      })
    }),
  },
}))

// 工序 mock：sort 升序两道工序，供「添加节点」下拉
vi.mock('../api/process', () => ({
  processApi: {
    list: vi.fn().mockResolvedValue({
      items: [
        { id: 1, name: '下料', code: 'CUT', sort: 1, description: null, status: 1 },
        { id: 2, name: '焊接', code: 'WELD', sort: 2, description: null, status: 1 },
      ],
    }),
  },
}))

// BOM mock：成品桌（id 9）有启用 BOM（基准 1，明细 铝材×3）；其余产品无 BOM
vi.mock('../api/bom', () => ({
  bomApi: {
    list: vi.fn().mockImplementation((params: { product_id?: number }) =>
      Promise.resolve({
        items:
          params.product_id === 9
            ? [
                {
                  id: 7,
                  code: 'BOM001',
                  product_id: 9,
                  product_name: '成品桌',
                  type_label: '成品',
                  version: 'V1',
                  quantity: 1,
                  status: 1,
                  remark: null,
                },
              ]
            : [],
        total: 0,
        page: 1,
        per_page: 100,
      }),
    ),
    items: vi.fn().mockResolvedValue({
      items: [
        { id: 11, material_id: 1, material_name: '铝材', quantity: 3, unit_id: 1, unit_name: '个' },
      ],
    }),
  },
}))
import { routingApi, type RoutingListItem } from '../api/routing'
import { productApi } from '../api/product'
import { bomApi } from '../api/bom'

describe('工艺路线画布编辑器', () => {
  let pinia: ReturnType<typeof createPinia>
  let router: Router

  beforeEach(() => {
    vi.clearAllMocks()
    // 清理前一用例残留的 ElMessage（jsdom 3s 自动关闭定时器不在用例间隙触发）
    document.querySelectorAll('.el-message').forEach((m) => m.remove())
    pinia = createPinia()
    setActivePinia(pinia)
    useAuthStore().permissions = [
      'routing.list',
      'routing.create',
      'routing.update',
      'routing.delete',
    ]
  })

  // 挂载画布子页面：以内存路由进入 canvas 地址（query 携带 routing_id/mode，与列表页跳转一致）
  async function mountCanvas(query: Record<string, string> = {}) {
    router = createRouter({
      history: createMemoryHistory(),
      routes: [
        { path: '/master/routings/canvas', name: 'canvas', component: RoutingCanvasView },
        { path: '/master/routings', name: 'list', component: { template: '<div />' } },
      ],
    })
    await router.push({ path: '/master/routings/canvas', query })
    await router.isReady()
    const wrapper = mount(RoutingCanvasView, {
      attachTo: document.body,
      global: { plugins: [ElementPlus, pinia, router] },
    })
    await flushPromises()
    return wrapper
  }

  // 在指定容器内的 el-select 选择选项。Element Plus 会把已关闭 popper（v-show display:none）留在 DOM，
  // 且本组用例会反复开关同一批下拉（如「添加节点」「添加连线」），DOM 顺序不可靠——
  // 仅在当前可见的下拉（.el-select__popper 未隐藏）中匹配文本，规避残留弹层串扰。
  // 商品选项文本含类型标签（如「FIN-002 成品桌 成品」），先精确匹配、失败回退包含匹配
  async function pickOption(wrapper: VueWrapper, selector: string, label: string) {
    const select = wrapper.find(`${selector} .el-select__wrapper`)
    expect(select.exists(), `下拉应存在：${selector}`).toBe(true)
    await select.trigger('click')
    await flushPromises()
    const items = [
      ...document.querySelectorAll('.el-select-dropdown .el-select-dropdown__item'),
    ] as HTMLElement[]
    const match = (o: HTMLElement) => {
      const t = o.textContent!.trim()
      return t === label || t.includes(label)
    }
    // 可见 popper 判定：最近挂载的 .el-select__popper 祖先未携带 display:none
    const visible = items.filter((o) => {
      const popper = o.closest('.el-select__popper') as HTMLElement | null
      return !popper || popper.style.display !== 'none'
    })
    const pool = visible.some(match) ? visible : items
    const opt = pool.filter(match).pop()
    expect(opt, `选项「${label}」应存在于 ${selector}`).toBeTruthy()
    ;(opt as HTMLElement).dispatchEvent(new MouseEvent('click', { bubbles: true }))
    // 等一个宏任务：关闭 popper 的离开过渡在 jsdom 中经 0ms 超时回调置 display:none，
    // 保证下一次 pickOption 的可见性过滤生效
    await new Promise((r) => setTimeout(r, 0))
    await flushPromises()
  }

  // 选工序并点「添加节点」：同一工序可重复添加（重选工序仅换下拉值，节点号自动递增）
  async function addNode(wrapper: VueWrapper, processName: string) {
    await pickOption(wrapper, '.toolbar-process', processName)
    const addBtn = wrapper.findAll('button').find((b) => b.text().trim() === '添加节点')
    expect(addBtn, '添加节点按钮应存在').toBeTruthy()
    await addBtn!.trigger('click')
    await flushPromises()
  }

  // 底部「添加连线」：从 from 到 to（节点选项文案为「OP10 · 下料」格式）
  async function addEdge(wrapper: VueWrapper, from: string, to: string) {
    await pickOption(wrapper, '.edge-from', from)
    await pickOption(wrapper, '.edge-to', to)
    const linkBtn = wrapper.findAll('button').find((b) => b.text().trim() === '添加连线')
    expect(linkBtn, '添加连线按钮应存在').toBeTruthy()
    await linkBtn!.trigger('click')
    await flushPromises()
  }

  // 点击底部动作按钮（按文案定位：校验 DAG / 保 存 / 取 消）
  async function clickFooterBtn(wrapper: VueWrapper, text: string) {
    const btn = wrapper.findAll('button').find((b) => b.text().trim() === text)
    expect(btn, `按钮「${text}」应存在`).toBeTruthy()
    await btn!.trigger('click')
    await flushPromises()
  }

  // 可控 Promise：手动 resolve 模拟迟到的接口响应（竞态用例专用）
  type GraphResp = Awaited<ReturnType<typeof routingApi.graph>>
  function deferredGraph() {
    let resolve!: (value: GraphResp) => void
    const promise = new Promise<GraphResp>((res) => {
      resolve = res
    })
    return { promise, resolve }
  }

  // 表头「版本」输入框当前值（按 label 定位，不受表头字段顺序调整影响）
  function headerVersion(wrapper: VueWrapper): string {
    const item = wrapper
      .findAll('.rc-header .el-form-item')
      .find((f) => f.find('.el-form-item__label').text() === '版本')
    expect(item, '版本表单项应存在').toBeTruthy()
    return (item!.find('input').element as HTMLInputElement).value
  }

  it('校验 DAG 拦截环路：A→B→A 提示 1701 同口径文案且不调保存接口', async () => {
    // 异常路径：本地环路预检必须在调 API 前拦截（后端 1701 的前端防线）
    const errorSpy = vi.spyOn(ElMessage, 'error')
    const wrapper = await mountCanvas()

    await addNode(wrapper, '下料') // OP10
    await addNode(wrapper, '焊接') // OP20
    await addEdge(wrapper, 'OP10 · 下料', 'OP20 · 焊接')
    await addEdge(wrapper, 'OP20 · 焊接', 'OP10 · 下料') // 成环

    await clickFooterBtn(wrapper, '校验 DAG')
    expect(errorSpy).toHaveBeenCalledWith('工艺路线存在工序环路')

    // 保存前强制同口径预检：环路同样被拦，create 始终未被调用
    await clickFooterBtn(wrapper, '保 存')
    expect(routingApi.create).not.toHaveBeenCalled()
    wrapper.unmount()
  })

  it('保存载荷结构：2 节点 1 边带材料，材料行单位自动带出且 is_outsourced 为 0/1', async () => {
    // 正常路径：载荷组装契约（nodes/edges/materials 字段名与后端一致）
    const wrapper = await mountCanvas()

    // 表头成品 FIN-002（保存必填）
    await pickOption(wrapper, '.header-product', 'FIN-002 成品桌')
    await addNode(wrapper, '下料') // OP10
    await addNode(wrapper, '焊接') // OP20
    await addEdge(wrapper, 'OP10 · 下料', 'OP20 · 焊接')

    // 选中 OP10（面板节点下拉），配置输出产品与委外标记
    await pickOption(wrapper, '.panel-node', 'OP10 · 下料')
    await pickOption(wrapper, '.panel-output', 'SEM-001 桌面板')
    await wrapper.find('.panel-outsourced .el-switch').trigger('click')
    await flushPromises()

    // OP10 加材料行 MAT-001（单位 个 id=1 自动带出），用量保持默认 1
    const addMatBtn = wrapper.findAll('button').find((b) => b.text().trim() === '添加材料')
    expect(addMatBtn, '添加材料按钮应存在').toBeTruthy()
    await addMatBtn!.trigger('click')
    await flushPromises()
    await pickOption(wrapper, '.mat-row', 'MAT-001 铝材')

    await clickFooterBtn(wrapper, '保 存')
    expect(routingApi.create).toHaveBeenCalledTimes(1)
    const payload = vi.mocked(routingApi.create).mock.calls[0]![0]
    // 单头：成品 + 版本默认 v1
    expect(payload.product_id).toBe(9)
    expect(payload.version).toBe('v1')
    // 节点：OP10 委外=1、材料 MAT-001 单位自动带出且用量为数值；OP20 委外=0
    expect(payload.nodes).toHaveLength(2)
    expect(payload.nodes[0]).toMatchObject({
      node_no: 'OP10',
      process_id: 1,
      name: '下料',
      output_product_id: 5,
      is_outsourced: 1,
    })
    expect(payload.nodes[0]!.materials).toEqual([{ material_id: 1, qty_per_unit: 1, unit_id: 1 }])
    expect(payload.nodes[1]).toMatchObject({ node_no: 'OP20', is_outsourced: 0 })
    // 边：from/to 节点号契约
    expect(payload.edges).toEqual([{ from_node_no: 'OP10', to_node_no: 'OP20' }])
    wrapper.unmount()
  })

  it('委外开关打开显示提示文案：工单下达后生成委外需求（spec 6.3）', async () => {
    // 正常路径：面板「委外工序」switch 打开 → 提示行出现，关闭后消失
    const wrapper = await mountCanvas()

    await addNode(wrapper, '下料') // OP10 自动选中进入面板
    expect(wrapper.find('.panel-outsourced-hint').exists()).toBe(false)

    await wrapper.find('.panel-outsourced .el-switch').trigger('click')
    await flushPromises()
    const hint = wrapper.find('.panel-outsourced-hint')
    expect(hint.exists()).toBe(true)
    expect(hint.text()).toBe('委外工序将在工单下达后生成委外需求')

    // 开关关闭后提示消失
    await wrapper.find('.panel-outsourced .el-switch').trigger('click')
    await flushPromises()
    expect(wrapper.find('.panel-outsourced-hint').exists()).toBe(false)
    wrapper.unmount()
  })

  it('删除节点连带删边：删 OP10 后保存载荷仅剩 OP20 且边为空', async () => {
    // 边界路径：节点删除必须级联清理引用它的连线（否则保存载荷边端点悬空被后端拒）
    const wrapper = await mountCanvas()

    await pickOption(wrapper, '.header-product', 'FIN-002 成品桌')
    await addNode(wrapper, '下料') // OP10
    await addNode(wrapper, '焊接') // OP20
    await addEdge(wrapper, 'OP10 · 下料', 'OP20 · 焊接')

    // 面板选中 OP10 并删除
    await pickOption(wrapper, '.panel-node', 'OP10 · 下料')
    const delBtn = wrapper.findAll('button').find((b) => b.text().trim() === '删除节点')
    expect(delBtn, '删除节点按钮应存在').toBeTruthy()
    await delBtn!.trigger('click')
    await flushPromises()

    await clickFooterBtn(wrapper, '保 存')
    expect(routingApi.create).toHaveBeenCalledTimes(1)
    const payload = vi.mocked(routingApi.create).mock.calls[0]![0]
    expect(payload.nodes.map((n) => n.node_no)).toEqual(['OP20'])
    expect(payload.edges).toEqual([])
    wrapper.unmount()
  })

  it('添加节点自动编号：连续两次添加得到 OP10、OP20', async () => {
    // 正常路径：node_no 由 nextNodeNo 自动生成（大于最大号的最小十位倍数），面板只读展示
    const wrapper = await mountCanvas()

    await addNode(wrapper, '下料')
    expect((wrapper.find('.panel-node-no input').element as HTMLInputElement).value).toBe('OP10')

    await addNode(wrapper, '焊接')
    expect((wrapper.find('.panel-node-no input').element as HTMLInputElement).value).toBe('OP20')
    wrapper.unmount()
  })

  it('双击画布空白创建空节点：显示未选工序且未配工序保存被前端拦截', async () => {
    // 正常路径：双击空白处创建无工序空节点（无连线，面板自动选中补配）；
    // 异常路径：未选工序时保存不得调接口（后端 nodes.*.process_id 必填的前端防线）
    const wrapper = await mountCanvas()

    await wrapper.find('.rc-canvas').trigger('dblclick', { clientX: 220, clientY: 160 })
    await flushPromises()
    // 空节点自动选中：节点号 OP10，工序下拉保持空（placeholder 状态下无选中文本）
    expect((wrapper.find('.panel-node-no input').element as HTMLInputElement).value).toBe('OP10')
    // 未配工序保存拦截（先选成品排除表头校验干扰，命中「未选工序」提示）
    const warnSpy = vi.spyOn(ElMessage, 'warning')
    await pickOption(wrapper, '.header-product', 'FIN-002 成品桌')
    await clickFooterBtn(wrapper, '保 存')
    expect(warnSpy).toHaveBeenCalledWith(expect.stringContaining('未选择工序'))
    expect(routingApi.create).not.toHaveBeenCalled()

    // 面板补选工序后保存可发请求（载荷 process_id 与工序名快照正确）
    await pickOption(wrapper, '.panel-process', '下料')
    await clickFooterBtn(wrapper, '保 存')
    expect(routingApi.create).toHaveBeenCalledTimes(1)
    const payload = vi.mocked(routingApi.create).mock.calls[0]![0]
    expect(payload.nodes).toHaveLength(1)
    expect(payload.nodes[0]).toMatchObject({ node_no: 'OP10', process_id: 1, name: '下料' })
    wrapper.unmount()
  })

  it('切换输出产品自动按 BOM 填充材料清单：明细按基准换算单位用量并带出单位', async () => {
    // 正常路径：输出产品有启用 BOM → 材料行自动替换为 BOM 明细（基准 1 时明细数量即单位用量）；
    // 例外路径：无 BOM 的产品保持现有行并提示手动添加（不破坏用户已配内容）
    const wrapper = await mountCanvas()
    await addNode(wrapper, '下料') // OP10 自动选中进入面板
    expect(wrapper.find('.mat-row').exists()).toBe(false)

    // 选成品桌（id 9，BOM V1 基准 1 / 明细 铝材×3）
    await pickOption(wrapper, '.panel-output', 'FIN-002 成品桌')
    await flushPromises()
    expect(bomApi.list).toHaveBeenCalledWith({ product_id: 9, per_page: 100 })
    expect(bomApi.items).toHaveBeenCalledWith(7)
    const matRow = wrapper.find('.mat-row')
    expect(matRow.exists(), 'BOM 明细应自动生成材料行').toBe(true)
    expect(Number((matRow.find('.el-input-number input').element as HTMLInputElement).value)).toBe(
      3,
    )
    expect(matRow.find('.mat-unit').text()).toBe('个')

    // 无 BOM 产品：提示手动添加且不覆盖已有行
    const infoSpy = vi.spyOn(ElMessage, 'info')
    await pickOption(wrapper, '.panel-output', 'SEM-001 桌面板')
    await flushPromises()
    expect(infoSpy).toHaveBeenCalledWith('该产品暂无 BOM，请手动添加材料')
    expect(wrapper.findAll('.mat-row')).toHaveLength(1)
    wrapper.unmount()
  })

  it('节点四边把手拖出自动连线：上边中点拖出创建 OP20 并追加 OP10→OP20 边', async () => {
    // 正常路径：节点把手拖出 = spawnNodeAt（松手点建空节点）+ addEditorEdge（自动连线），
    // jsdom 无真实指针拖拽，此处从把手派发 pointerdown 后在 window 上派发 pointerup 模拟松手，
    // 边的存在经保存载荷断言（E2E TC-RTG-06 覆盖真实拖拽）
    const wrapper = await mountCanvas()
    await pickOption(wrapper, '.header-product', 'FIN-002 成品桌')

    // 双击画布空白创建 OP10（无连线）
    await wrapper.find('.rc-canvas').trigger('dblclick', { clientX: 220, clientY: 160 })
    await flushPromises()
    expect((wrapper.find('.panel-node-no input').element as HTMLInputElement).value).toBe('OP10')

    // 节点卡片经 VueFlow stub 渲染：取 OP10 上边中点把手模拟指针拖拽（松手点落在画布内）
    const handle = wrapper.find('.rn-card .ns-spawn[data-side="top"]')
    expect(handle.exists(), 'OP10 卡片上边把手应存在').toBe(true)
    await handle.trigger('pointerdown')
    await flushPromises()
    window.dispatchEvent(new MouseEvent('pointerup', { clientX: 60, clientY: 60 }))
    await flushPromises()
    // 松手按落点创建 OP20 并自动选中进入配置面板
    expect((wrapper.find('.panel-node-no input').element as HTMLInputElement).value).toBe('OP20')

    // 两节点补配工序后保存：载荷边必须是自动连线的 OP10→OP20（拖出即连线的业务结果）
    await pickOption(wrapper, '.panel-node', 'OP10 · 未选工序')
    await pickOption(wrapper, '.panel-process', '下料')
    await pickOption(wrapper, '.panel-node', 'OP20 · 未选工序')
    await pickOption(wrapper, '.panel-process', '焊接')
    await clickFooterBtn(wrapper, '保 存')
    expect(routingApi.create).toHaveBeenCalledTimes(1)
    const payload = vi.mocked(routingApi.create).mock.calls[0]![0]
    expect(payload.edges).toEqual([{ from_node_no: 'OP10', to_node_no: 'OP20' }])
    wrapper.unmount()
  })

  it('重开会话迟到响应守卫：开 A 返回再开 B，A 的图数据后到被丢弃、编辑态为 B', async () => {
    // 竞态路径：编辑 A 的图加载挂起期间返回列表重开 B，A 的图数据迟到返回时必须被会话序号守卫丢弃，
    // 否则 A 的单头/节点会覆盖 B 已 reset 的编辑态（评审发现的时序竞态）
    const rowA: RoutingListItem = {
      id: 1,
      code: 'RTG-001',
      product_id: 9,
      product_name: '成品桌',
      version: 'v1',
      quantity: 1,
      status: 1,
      status_label: '启用',
      remark: null,
    }
    const rowB: RoutingListItem = {
      id: 2,
      code: 'RTG-002',
      product_id: 9,
      product_name: '成品桌',
      version: 'v2',
      quantity: 2,
      status: 1,
      status_label: '启用',
      remark: null,
    }
    // A 的图：版本 v3（与 reset 默认 v1 区分，若被误写一眼可辨）+ 独有节点名
    const graphA: GraphResp = {
      routing: {
        id: 1,
        code: 'RTG-001',
        product_id: 9,
        product_name: '成品桌',
        version: 'v3',
        quantity: 3,
        status: 1,
        remark: null,
      },
      nodes: [
        {
          id: 11,
          node_no: 'OP10',
          process_id: 1,
          process_name: '下料',
          name: 'A工序',
          output_product_id: 9,
          output_product_name: '成品桌',
          output_qty: 1,
          is_outsourced: 0,
          remark: null,
          materials: [],
        },
      ],
      edges: [],
    }
    const graphB: GraphResp = {
      routing: {
        id: 2,
        code: 'RTG-002',
        product_id: 9,
        product_name: '成品桌',
        version: 'v2',
        quantity: 2,
        status: 1,
        remark: null,
      },
      nodes: [
        {
          id: 22,
          node_no: 'OP10',
          process_id: 2,
          process_name: '焊接',
          name: 'B工序',
          output_product_id: 9,
          output_product_name: '成品桌',
          output_qty: 1,
          is_outsourced: 0,
          remark: null,
          materials: [],
        },
      ],
      edges: [],
    }
    vi.mocked(routingApi.list).mockResolvedValue({
      items: [rowA, rowB],
      total: 2,
      page: 1,
      per_page: 10,
    })
    const dfdA = deferredGraph()
    const dfdB = deferredGraph()

    // 开 A（编辑 id=1）：图加载挂起（dfdA 未 resolve），此时返回列表（真实应用销毁视图）
    vi.mocked(routingApi.graph).mockImplementation(() => dfdA.promise)
    const wrapperA = await mountCanvas({ routing_id: '1' })
    await clickFooterBtn(wrapperA, '取 消')
    wrapperA.unmount()

    // 重开 B（编辑 id=2）：图加载同样挂起（dfdB 未 resolve）
    vi.mocked(routingApi.graph).mockImplementation(() => dfdB.promise)
    const wrapperB = await mountCanvas({ routing_id: '2' })

    // A 的图数据此刻才到：会话已作废，回写被丢弃——画布仍空、标题与版本均非 A 的（未守卫则此处必失败）
    dfdA.resolve(graphA)
    await flushPromises()
    expect(document.querySelector('.canvas-empty')?.textContent).toContain('双击画布空白处')
    expect(wrapperB.find('.rc-title').text()).toBe('编辑工艺路线')
    expect(headerVersion(wrapperB)).toBe('v1')

    // B 的图数据到达：编辑态还原为 B 的版本 v2 与标题编码 RTG-002，空态消失
    dfdB.resolve(graphB)
    await flushPromises()
    expect(wrapperB.find('.rc-title').text()).toBe('编辑工艺路线 - RTG-002')
    expect(headerVersion(wrapperB)).toBe('v2')
    expect(document.querySelector('.canvas-empty')).toBeNull()
    wrapperB.unmount()
  })
})

describe('画布商品下拉远程搜索（BF-3：超 100 商品可选）', () => {
  let pinia: ReturnType<typeof createPinia>
  let router: Router

  beforeEach(() => {
    vi.clearAllMocks()
    document.querySelectorAll('.el-message').forEach((m) => m.remove())
    pinia = createPinia()
    setActivePinia(pinia)
    useAuthStore().permissions = ['routing.list', 'routing.create', 'routing.update']
    vi.useFakeTimers()
  })
  afterEach(() => vi.useRealTimers())

  async function mountCanvas() {
    router = createRouter({
      history: createMemoryHistory(),
      routes: [
        { path: '/master/routings/canvas', name: 'canvas', component: RoutingCanvasView },
        { path: '/master/routings', name: 'list', component: { template: '<div />' } },
      ],
    })
    await router.push('/master/routings/canvas')
    await router.isReady()
    return mount(RoutingCanvasView, {
      attachTo: document.body,
      global: { plugins: [ElementPlus, pinia, router] },
    })
  }

  it('成品下拉输入关键字后以 keyword 调商品接口并替换选项（远程搜索替代本地前 100 条过滤）', async () => {
    // 正常路径：商品档案超 100 条时第 101 个成品必须可搜可选——remote 模式下调 productApi.list({ keyword })
    const wrapper = await mountCanvas()
    await flushPromises()

    // 表头成品下拉：el-select remote 模式（remote prop 为 true，remote-method 即 EP 内部输入回调）
    const select = wrapper.find('.header-product').findComponent({ name: 'ElSelect' })
    expect(select.exists(), '成品下拉应存在').toBe(true)
    expect(select.props('remote'), '成品下拉应为 remote 服务端搜索模式').toBe(true)
    const remoteMethod = select.props('remoteMethod') as (q: string) => void
    expect(typeof remoteMethod).toBe('function')

    // 输入关键字：300ms 防抖后以 keyword 请求商品接口
    vi.clearAllMocks() // 清掉初载调用，聚焦搜索调用参数
    remoteMethod('桌')
    expect(vi.mocked(productApi.list), '防抖窗口内不得发请求').not.toHaveBeenCalled()
    await vi.advanceTimersByTimeAsync(300)
    expect(productApi.list).toHaveBeenCalledWith(expect.objectContaining({ keyword: '桌' }))
    wrapper.unmount()
  })

  it('材料下拉输入关键字后同时搜原料与半成品（type 双路合并同现一个下拉）', async () => {
    // 正常路径：材料 = 原料 + 半成品两类合并；搜索须两路都带 keyword（后端 type 单值过滤）
    const wrapper = await mountCanvas()
    await flushPromises()

    // 先加一个节点展开材料配置区（材料行在节点面板内）
    const toolbarSelect = wrapper.find('.toolbar-process')
    await toolbarSelect.find('.el-select__wrapper').trigger('click')
    await flushPromises()
    const opt = [...document.querySelectorAll('.el-select-dropdown__item')].find(
      (o) => (o as HTMLElement).textContent!.trim() === '下料',
    )
    ;(opt as HTMLElement).dispatchEvent(new MouseEvent('click', { bubbles: true }))
    // 等一个宏任务（本 describe 启用假定时器，setTimeout 不会自触发，改由推进假时钟达成同语义）
    await vi.advanceTimersByTimeAsync(0)
    await flushPromises()
    const addBtn = wrapper.findAll('button').find((b) => b.text().trim() === '添加节点')
    await addBtn!.trigger('click')
    await flushPromises()

    // 添加一行材料，取材料行 el-select 断言 remote 搜索双路 keyword
    const addMat = wrapper.findAll('button').find((b) => b.text().trim() === '添加材料')
    await addMat!.trigger('click')
    await flushPromises()
    const select = wrapper.find('.mat-row').findComponent({ name: 'ElSelect' })
    expect(select.exists(), '材料行下拉应存在').toBe(true)
    expect(select.props('remote'), '材料下拉应为 remote 服务端搜索模式').toBe(true)
    vi.clearAllMocks()
    ;(select.props('remoteMethod') as (q: string) => void)('铝')
    await vi.advanceTimersByTimeAsync(300)
    const calls = vi.mocked(productApi.list).mock.calls.map((c) => c[0])
    expect(
      calls.filter((p) => p?.keyword === '铝'),
      '原料与半成品两路都应携带 keyword',
    ).toHaveLength(2)
    expect(calls.some((p) => p?.type === 'raw_material')).toBe(true)
    expect(calls.some((p) => p?.type === 'semi_finished')).toBe(true)
    wrapper.unmount()
  })
})
