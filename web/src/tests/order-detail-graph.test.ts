// 工单详情「工序网络」画布组件测试：节点状态着色（待开工灰/进行中蓝/已完成绿）、委外琥珀描边与角标、
// 点击节点联动下方累计明细与直接前驱。画布依赖 @vue-flow/core，单测将其 stub 为逐节点渲染插槽的
// 占位组件（真实画布交互由 Playwright E2E 覆盖），组件行为经节点卡片与明细描述区驱动
import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import ElementPlus from 'element-plus'
import OperationGraph from '../components/OperationGraph.vue'
import type { OperationGraphData } from '../api/production'

// Vue Flow 占位：受控 props + nodeClick 事件透传，按 nodes 逐个渲染 #node-op 插槽，
// 使节点卡片（数量/class/角标）可被断言、点击联动可经 emit 驱动
vi.mock('@vue-flow/core', () => ({
  VueFlow: {
    name: 'VueFlow',
    props: [
      'nodes',
      'edges',
      'nodesConnectable',
      'nodesDraggable',
      'edgesUpdatable',
      'fitViewOnInit',
    ],
    emits: ['nodeClick'],
    template: `
      <div class="vue-flow-stub">
        <div
          v-for="n in nodes"
          :key="n.id"
          class="vue-flow-stub__node"
          @click="$emit('nodeClick', { node: n })"
        >
          <slot name="node-op" :id="n.id" />
        </div>
      </div>
    `,
  },
  Handle: { name: 'Handle', props: ['type', 'position'], template: '<div class="handle-stub" />' },
  Position: { left: 'left', right: 'right', top: 'top', bottom: 'bottom' },
}))

// 3 节点线性 DAG：OP10 已完成 → OP20 进行中（委外）→ OP30 待开工
const graph: OperationGraphData = {
  nodes: [
    {
      id: 1,
      node_no: 'OP10',
      process_name: '下料',
      status: 2,
      status_label: '已完成',
      is_outsourced: 0,
      qualified_qty: '100',
      defective_qty: '2',
      hours: '8',
    },
    {
      id: 2,
      node_no: 'OP20',
      process_name: '电镀',
      status: 1,
      status_label: '进行中',
      is_outsourced: 1,
      qualified_qty: '60',
      defective_qty: '0',
      hours: '3',
    },
    {
      id: 3,
      node_no: 'OP30',
      process_name: '组装',
      status: 0,
      status_label: '待开工',
      is_outsourced: 0,
      qualified_qty: '0',
      defective_qty: '0',
      hours: '0',
    },
  ],
  edges: [
    { from_operation_id: 1, to_operation_id: 2, from_node_no: 'OP10', to_node_no: 'OP20' },
    { from_operation_id: 2, to_operation_id: 3, from_node_no: 'OP20', to_node_no: 'OP30' },
  ],
}

function mountGraph() {
  return mount(OperationGraph, {
    props: { graph },
    global: { plugins: [ElementPlus] },
  })
}

describe('工单详情工序网络画布', () => {
  it('渲染 3 个工序节点卡片，状态着色 done/running/pending 与标题格式正确', () => {
    // 正常路径：节点卡片 = graph.nodes 全量，状态 class 与后端 status 口径一致
    const wrapper = mountGraph()
    const cards = wrapper.findAll('.og-node')
    expect(cards).toHaveLength(3)
    expect(cards[0]!.classes()).toContain('og-node--done')
    expect(cards[1]!.classes()).toContain('og-node--running')
    expect(cards[2]!.classes()).toContain('og-node--pending')
    // 卡片标题：节点号 · 工序名
    expect(cards[0]!.text()).toContain('OP10 · 下料')
    // 累计行展示三项口径（合格/不良/工时）
    expect(cards[0]!.text()).toContain('合格 100')
    expect(cards[0]!.text()).toContain('不良 2')
    expect(cards[0]!.text()).toContain('工时 8')
    wrapper.unmount()
  })

  it('委外节点：琥珀描边类 og-node--outsourced + 右上「委外」角标，非委外节点无角标', () => {
    // 边界路径：委外标记优先于状态色（进行中 + 委外 → 琥珀描边叠加）
    const wrapper = mountGraph()
    const cards = wrapper.findAll('.og-node')
    expect(cards[1]!.classes()).toContain('og-node--outsourced')
    expect(cards[1]!.find('.og-badge').text()).toBe('委外')
    expect(cards[0]!.find('.og-badge').exists()).toBe(false)
    expect(cards[2]!.find('.og-badge').exists()).toBe(false)
    wrapper.unmount()
  })

  it('点击节点联动下方明细：工序/节点号/累计与直接前驱（从 edges 反推）', async () => {
    // 正常路径：画布点选 → el-descriptions 展示该节点累计明细与前驱工序
    const wrapper = mountGraph()
    // 初始未选中：仅提示文案，无明细描述区
    expect(wrapper.find('.og-hint').exists()).toBe(true)

    // 点击第二个节点（OP20 电镀，进行中委外）
    await wrapper.findAll('.vue-flow-stub__node')[1]!.trigger('click')
    const detail = wrapper.find('.og-detail')
    expect(detail.exists()).toBe(true)
    const text = detail.text()
    expect(text).toContain('电镀')
    expect(text).toContain('OP20')
    expect(text).toContain('进行中')
    // 直接前驱：仅 OP10 · 下料（edges 中 to_operation_id=2 的 from 端）
    expect(text).toContain('OP10 · 下料')
    expect(text).not.toContain('OP30')
    wrapper.unmount()
  })

  it('委外节点「委 外 单」按钮点击 emit outsourcing-click（携带完整节点，且不触发画布选中）', async () => {
    // 正常路径：仅委外节点渲染按钮；点击经 stop 只发联动事件——下方明细不被选中（提示文案保留）
    const wrapper = mountGraph()
    await wrapper.findAll('.og-outsource-btn')[0]!.trigger('click')
    expect(wrapper.emitted('outsourcing-click')).toHaveLength(1)
    expect(wrapper.emitted('outsourcing-click')![0]![0]).toEqual(graph.nodes[1])
    // 按钮点击不选中节点：明细描述区不出现，hint 文案保留
    expect(wrapper.find('.og-detail').exists()).toBe(false)
    expect(wrapper.find('.og-hint').exists()).toBe(true)
    wrapper.unmount()
  })

  it('非委外节点无「委 外 单」按钮（与委外角标同步渲染）', () => {
    // 边界路径：按钮仅挂在委外节点卡片上，非委外节点不得出现
    const wrapper = mountGraph()
    const cards = wrapper.findAll('.og-node')
    expect(cards[0]!.find('.og-outsource-btn').exists()).toBe(false)
    expect(cards[2]!.find('.og-outsource-btn').exists()).toBe(false)
    wrapper.unmount()
  })
})
