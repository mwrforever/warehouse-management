<!-- 工单详情「工序网络」tab：只读 Vue Flow 工序 DAG 画布（状态着色/委外角标/累计口径），
     点击节点联动下方 el-descriptions 展示该工序累计明细与直接前驱。纯展示组件，数据全来自 graph prop -->
<template>
  <div class="og-wrap">
    <div class="og-canvas">
      <VueFlow
        :nodes="flowNodes"
        :edges="flowEdges"
        :nodes-connectable="false"
        :nodes-draggable="false"
        :edges-updatable="false"
        :fit-view-on-init="true"
        @node-click="onNodeClick"
      >
        <!-- 自定义工序节点卡片：id=工序（operation）id 字符串，经 id 反查渲染 -->
        <template #node-op="{ id }">
          <div class="og-node" :class="nodeClass(id)">
            <Handle type="target" :position="Position.Left" />
            <div class="og-title">
              {{ nodeById(id)?.node_no }} · {{ nodeById(id)?.process_name }}
            </div>
            <div class="og-status">{{ nodeById(id)?.status_label }}</div>
            <div class="og-qty">
              合格 {{ num(nodeById(id)?.qualified_qty) }} / 不良
              {{ num(nodeById(id)?.defective_qty) }} / 工时 {{ num(nodeById(id)?.hours) }}
            </div>
            <span v-if="nodeById(id)?.is_outsourced === 1" class="og-badge">委外</span>
            <button
              v-if="nodeById(id)?.is_outsourced === 1"
              type="button"
              class="og-outsource-btn"
              @click.stop="onOutsourceClick(id)"
            >
              委 外 单
            </button>
            <Handle type="source" :position="Position.Right" />
          </div>
        </template>
      </VueFlow>
    </div>
    <!-- 选中节点累计明细：工序/节点号/状态/三项累计/直接前驱（从 graph.edges 反推 to 端 = 选中 id） -->
    <el-descriptions v-if="selectedNode" :column="3" border size="small" class="og-detail">
      <el-descriptions-item label="工序">{{
        selectedNode.process_name ?? '—'
      }}</el-descriptions-item>
      <el-descriptions-item label="节点号">{{ selectedNode.node_no ?? '—' }}</el-descriptions-item>
      <el-descriptions-item label="状态">{{ selectedNode.status_label }}</el-descriptions-item>
      <el-descriptions-item label="累计合格">
        <span class="font-code">{{ num(selectedNode.qualified_qty) }}</span>
      </el-descriptions-item>
      <el-descriptions-item label="累计不良">
        <span class="font-code">{{ num(selectedNode.defective_qty) }}</span>
      </el-descriptions-item>
      <el-descriptions-item label="累计工时">
        <span class="font-code">{{ num(selectedNode.hours) }}</span>
      </el-descriptions-item>
      <el-descriptions-item label="直接前驱" :span="3">{{ predecessorText }}</el-descriptions-item>
    </el-descriptions>
    <div v-else class="og-hint">点击画布节点查看工序累计明细</div>
  </div>
</template>

<script setup lang="ts">
// 工序网络只读画布：节点 id 用工序（operation）id 字符串（天然唯一），
// 布局经 layoutPositions 拓扑分层自动排布（同工艺路线画布，位置不持久化）
import { computed, ref } from 'vue'
import { VueFlow, Handle, Position } from '@vue-flow/core'
import '@vue-flow/core/dist/style.css'
import '@vue-flow/core/dist/theme-default.css'
import { layoutPositions } from '../utils/dag'
import type { OperationGraphData, OperationGraphNode } from '../api/production'

// Vue Flow 样式在本组件引入（style 基础样式 + theme-default 默认主题；多组件重复引入会被打包去重）

const props = defineProps<{
  /** 工单工序图（详情接口 graph 字段；仅按路由下达的 DAG 工单非空） */
  graph: OperationGraphData
}>()

const emit = defineEmits<{
  /** 点击委外节点「委 外 单」按钮：携带完整工序节点通知父级打开委外单列表（仅委外节点可发） */
  'outsourcing-click': [operation: OperationGraphNode]
}>()

/** 选中节点的工序 id（字符串形式，与画布节点 id 同口径） */
const selectedId = ref<string | null>(null)

/** 按画布 id 反查节点（插槽 data 不带类型，经 id 反查保持类型安全） */
function nodeById(id: unknown): OperationGraphNode | undefined {
  return props.graph.nodes.find((n) => String(n.id) === id)
}

/** 数值口径：后端 decimal:2 字符串，统一 Number 后展示 */
function num(v: string | undefined): number {
  return Number(v ?? 0)
}

/** 节点状态修饰类：0 待开工灰 / 1 进行中蓝 / 2 已完成绿；委外（任意状态）追加琥珀描边类 */
function nodeClass(id: unknown): Record<string, boolean> {
  const n = nodeById(id)
  return {
    'og-node--pending': n?.status === 0,
    'og-node--running': n?.status === 1,
    'og-node--done': n?.status === 2,
    'og-node--outsourced': n?.is_outsourced === 1,
  }
}

/** 受控节点：位置按拓扑分层布局计算；布局边引用工序 id（node_no 仅展示用，不作连线锚点） */
const flowNodes = computed(() => {
  const ids = props.graph.nodes.map((n) => String(n.id))
  const layoutEdges = props.graph.edges.map((e) => ({
    from: String(e.from_operation_id),
    to: String(e.to_operation_id),
  }))
  const pos = layoutPositions(ids, layoutEdges)
  return props.graph.nodes.map((n) => ({
    id: String(n.id),
    type: 'op',
    position: pos[String(n.id)] ?? { x: 40, y: 40 },
  }))
})

/** 受控边：id = `${from}->${to}`（工序 id 拼接，天然去重） */
const flowEdges = computed(() =>
  props.graph.edges.map((e) => ({
    id: `${e.from_operation_id}->${e.to_operation_id}`,
    source: String(e.from_operation_id),
    target: String(e.to_operation_id),
  })),
)

/** 选中节点数据 */
const selectedNode = computed(
  () => props.graph.nodes.find((n) => String(n.id) === selectedId.value) ?? null,
)

/** 选中节点直接前驱文案：`节点号 · 工序名` 顿号分隔；无边（首工序）显示「无」 */
const predecessorText = computed(() => {
  const node = selectedNode.value
  if (!node) return ''
  const names = props.graph.edges
    .filter((e) => e.to_operation_id === node.id)
    .map((e) => {
      const from = props.graph.nodes.find((n) => n.id === e.from_operation_id)
      return `${from?.node_no ?? '—'} · ${from?.process_name ?? '—'}`
    })
  return names.length > 0 ? names.join('、') : '无'
})

/** 画布点选节点 → 下方明细联动 */
function onNodeClick({ node }: { node: { id: string } }) {
  selectedId.value = node.id
}

/** 「委 外 单」按钮：仅委外节点渲染；点击通知父级打开该工序委外单列表。
    按钮用 .stop 阻断冒泡——不触发画布 node-click，避免与节点选中联动打架 */
function onOutsourceClick(id: unknown) {
  const node = nodeById(id)
  if (node?.is_outsourced === 1) emit('outsourcing-click', node)
}
</script>

<style scoped>
.og-wrap {
  display: flex;
  flex-direction: column;
  gap: var(--sp-2);
}
/* 画布容器：固定高度边框盒（只读查看，画布内禁拖禁连） */
.og-canvas {
  height: 360px;
  border: 1px solid var(--border);
  border-radius: var(--r-md);
  overflow: hidden;
}
/* 节点卡片：状态描边色（待开工灰/进行中蓝/已完成绿）；委外琥珀类置于最后，覆盖任意状态色 */
.og-node {
  position: relative;
  min-width: 150px;
  max-width: 220px;
  padding: var(--sp-2) var(--sp-3);
  background: var(--surface);
  border: 1.5px solid var(--border-strong);
  border-radius: var(--r-md);
  box-shadow: var(--sh-sm);
  font-size: 12px;
  cursor: pointer;
}
.og-node--pending {
  border-color: var(--t3);
}
.og-node--running {
  border-color: var(--info);
}
.og-node--done {
  border-color: var(--ok);
}
.og-node--outsourced {
  border-color: var(--warn);
}
.og-title {
  font-weight: 600;
  color: var(--t1);
  margin-bottom: var(--sp-1);
}
.og-status {
  color: var(--t2);
  margin-bottom: var(--sp-1);
}
.og-qty {
  color: var(--t3);
}
/* 委外角标（右上琥珀圆角，与工艺路线画布同款） */
.og-badge {
  position: absolute;
  top: -9px;
  right: 8px;
  padding: 0 6px;
  border-radius: var(--r-full);
  background: var(--warn);
  color: var(--surface);
  font-size: 11px;
  line-height: 18px;
}
/* 「委 外 单」按钮（委外节点卡片内小号琥珀按钮，点击查看委外单列表） */
.og-outsource-btn {
  margin-top: var(--sp-1);
  padding: 0 8px;
  border: none;
  border-radius: var(--r-sm);
  background: var(--warn);
  color: var(--surface);
  font-size: 11px;
  line-height: 18px;
  cursor: pointer;
}
.og-outsource-btn:hover {
  opacity: 0.9;
}
/* 未选中时的提示文案 */
.og-hint {
  color: var(--t3);
  font-size: 12px;
  text-align: center;
}
.og-detail {
  margin-top: var(--sp-1);
}
</style>
