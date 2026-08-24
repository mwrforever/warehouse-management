<!-- 工艺路线画布编辑/查看弹窗：表头（成品/版本/基准数量/启停/备注）+ Vue Flow 画布（工序节点 DAG）
     + 右侧节点配置面板。新建/编辑共用（routingId 区分），readonly 只读查看。
     保存前本地环预检（与后端 1701 同口径），完整结构/数量闭合校验由后端权威兜底 -->
<template>
  <el-dialog
    :model-value="visible"
    :title="dialogTitle"
    width="min(1200px, 96vw)"
    top="4vh"
    :close-on-click-modal="false"
    @update:model-value="emit('update:visible', $event)"
  >
    <div v-loading="loadingGraph" class="rc-body">
      <!-- 表头行：成品/版本/基准数量/启用/备注（readonly 全部禁用） -->
      <el-form :model="header" label-width="76px" size="small" class="rc-header">
        <el-form-item label="成品" required>
          <div class="header-product">
            <el-select
              v-model="header.product_id"
              filterable
              placeholder="选择成品商品"
              :disabled="readonly"
            >
              <el-option
                v-for="p in finishedProducts"
                :key="p.id"
                :label="`${p.code} ${p.name}`"
                :value="p.id"
              />
            </el-select>
          </div>
        </el-form-item>
        <el-form-item label="版本" required>
          <el-input v-model="header.version" :disabled="readonly" style="width: 110px" />
        </el-form-item>
        <el-form-item label="基准数量">
          <el-input-number
            v-model="header.quantity"
            :min="0.01"
            :precision="2"
            :disabled="readonly"
          />
        </el-form-item>
        <el-form-item label="启用">
          <div class="header-status">
            <el-switch
              v-model="header.status"
              :active-value="1"
              :inactive-value="0"
              :disabled="readonly"
            />
          </div>
        </el-form-item>
        <el-form-item label="备注" class="header-remark">
          <el-input v-model="header.remark" placeholder="选填" :disabled="readonly" />
        </el-form-item>
      </el-form>

      <div class="rc-main">
        <!-- 左侧：工序库 + 画布 -->
        <div class="rc-left">
          <div v-if="!readonly" class="rc-toolbar">
            <div class="toolbar-process">
              <el-select
                v-model="toolbarProcessId"
                filterable
                placeholder="选择工序"
                style="width: 200px"
              >
                <el-option v-for="p in processes" :key="p.id" :label="p.name" :value="p.id" />
              </el-select>
            </div>
            <el-button size="small" class="btn-primary" @click="addNodeFromToolbar"
              >添加节点</el-button
            >
            <span class="toolbar-tip">拖拽节点两侧圆点连线，点选节点在右侧配置</span>
          </div>
          <div class="rc-canvas">
            <VueFlow
              :nodes="flowNodes"
              :edges="flowEdges"
              :nodes-connectable="!readonly"
              :nodes-draggable="!readonly"
              :fit-view-on-init="true"
              @connect="onConnect"
              @node-click="onNodeClick"
              @edge-click="onEdgeClick"
            >
              <!-- 自定义工序节点卡片：id=node_no，展示工序名/材料清单/产出/委外角标 -->
              <template #node-routing="{ id }">
                <div
                  class="rn-card"
                  :class="{
                    'is-outsourced': nodeById(id)?.is_outsourced === 1,
                    'is-selected': selectedNodeNo === id,
                  }"
                  @click="selectNode(id)"
                >
                  <Handle type="target" :position="Position.Left" />
                  <div class="rn-title">{{ nodeById(id)?.node_no }} · {{ nodeById(id)?.name }}</div>
                  <div
                    v-for="(m, mi) in cardMaterials(id)"
                    :key="m.material_id ?? mi"
                    class="rn-mat"
                  >
                    {{ productName(m.material_id) }} ×{{ m.qty_per_unit }}
                  </div>
                  <div v-if="nodeById(id)?.output_product_id" class="rn-out">
                    产出：{{ productName(nodeById(id)?.output_product_id) }} ×{{
                      nodeById(id)?.output_qty
                    }}
                  </div>
                  <span v-if="nodeById(id)?.is_outsourced === 1" class="rn-badge">委外</span>
                  <Handle type="source" :position="Position.Right" />
                </div>
              </template>
            </VueFlow>
            <div v-if="editorNodes.length === 0" class="canvas-empty">
              {{ readonly ? '暂无工序节点' : '从上方选择工序，添加第一个节点' }}
            </div>
          </div>
        </div>

        <!-- 右侧：节点配置面板（340px） -->
        <aside class="rc-panel">
          <template v-if="selectedNode">
            <el-form label-width="76px" size="small">
              <el-form-item label="节点">
                <div class="panel-node">
                  <el-select v-model="selectedNodeNo" placeholder="选择节点">
                    <el-option
                      v-for="n in editorNodes"
                      :key="n.node_no"
                      :label="`${n.node_no} · ${n.name}`"
                      :value="n.node_no"
                    />
                  </el-select>
                </div>
              </el-form-item>
              <el-form-item label="节点号">
                <div class="panel-node-no">
                  <el-input :model-value="selectedNode.node_no" disabled title="节点号自动生成" />
                </div>
              </el-form-item>
              <el-form-item label="工序">
                <div class="panel-process">
                  <el-select
                    v-model="selectedNode.process_id"
                    :disabled="readonly"
                    placeholder="选择工序"
                    @change="(id: number) => applyNodeProcess(id)"
                  >
                    <el-option v-for="p in processes" :key="p.id" :label="p.name" :value="p.id" />
                  </el-select>
                </div>
              </el-form-item>
              <el-form-item label="输出产品">
                <div class="panel-output">
                  <el-select
                    v-model="selectedNode.output_product_id"
                    filterable
                    clearable
                    placeholder="半成品或成品"
                    :disabled="readonly"
                  >
                    <el-option
                      v-for="p in outputOptions"
                      :key="p.id"
                      :label="`${p.code} ${p.name}`"
                      :value="p.id"
                    />
                  </el-select>
                </div>
              </el-form-item>
              <el-form-item label="产出数量">
                <el-input-number
                  v-model="selectedNode.output_qty"
                  :min="0.01"
                  :precision="2"
                  :disabled="readonly"
                />
              </el-form-item>
              <el-form-item label="委外工序">
                <div class="panel-outsourced">
                  <el-switch
                    v-model="selectedNode.is_outsourced"
                    :active-value="1"
                    :inactive-value="0"
                    :disabled="readonly"
                  />
                  <div
                    v-if="!readonly && selectedNode.is_outsourced === 1"
                    class="panel-outsourced-hint"
                  >
                    委外工序将在工单下达后生成委外需求
                  </div>
                </div>
              </el-form-item>
              <el-form-item label="输入材料">
                <div class="mat-rows">
                  <div v-for="(row, idx) in selectedNode.materials" :key="idx" class="mat-row">
                    <el-select
                      v-model="row.material_id"
                      filterable
                      placeholder="原料/半成品"
                      :disabled="readonly"
                      @change="(id: number) => applyMaterialUnit(row, id)"
                    >
                      <el-option
                        v-for="m in materialOptions"
                        :key="m.id"
                        :label="`${m.code} ${m.name}`"
                        :value="m.id"
                      />
                    </el-select>
                    <el-input-number
                      v-model="row.qty_per_unit"
                      :min="0.01"
                      :precision="2"
                      :controls="false"
                      style="width: 90px"
                      :disabled="readonly"
                    />
                    <span class="mat-unit">{{ matUnitName(row) }}</span>
                    <el-button v-if="!readonly" link type="danger" @click="removeMaterial(idx)"
                      >删 除</el-button
                    >
                  </div>
                  <el-button v-if="!readonly" size="small" class="add-mat" @click="addMaterial"
                    >添加材料</el-button
                  >
                </div>
              </el-form-item>
              <el-form-item label="备注">
                <el-input
                  v-model="selectedNode.remark"
                  type="textarea"
                  :rows="2"
                  placeholder="选填"
                  :disabled="readonly"
                />
              </el-form-item>
            </el-form>
            <el-button v-if="!readonly" size="small" type="danger" plain @click="removeSelectedNode"
              >删除节点</el-button
            >
          </template>
          <div v-else class="panel-empty">点击画布节点或在「节点」下拉选择后配置</div>
        </aside>
      </div>
    </div>

    <template #footer>
      <div class="rc-footer">
        <!-- 添加连线双通道之一：下拉兜底（拖拽 Handle 之外的主通道，供 E2E 与无鼠标场景） -->
        <div v-if="!readonly" class="footer-links">
          <span class="link-label">连线</span>
          <div class="edge-from">
            <el-select v-model="linkFrom" placeholder="从节点" style="width: 150px">
              <el-option
                v-for="n in editorNodes"
                :key="n.node_no"
                :label="`${n.node_no} · ${n.name}`"
                :value="n.node_no"
              />
            </el-select>
          </div>
          <span class="link-arrow">→</span>
          <div class="edge-to">
            <el-select v-model="linkTo" placeholder="到节点" style="width: 150px">
              <el-option
                v-for="n in editorNodes"
                :key="n.node_no"
                :label="`${n.node_no} · ${n.name}`"
                :value="n.node_no"
              />
            </el-select>
          </div>
          <el-button size="small" @click="addEdgeBySelect">添加连线</el-button>
          <el-button
            v-if="selectedEdgeKey"
            size="small"
            type="danger"
            plain
            @click="removeSelectedEdge"
            >删除连线 {{ selectedEdgeKey }}</el-button
          >
        </div>
        <div class="footer-actions">
          <template v-if="!readonly">
            <el-button size="small" @click="validateDag">校验 DAG</el-button>
            <el-button size="small" @click="closeDialog">取 消</el-button>
            <el-button
              size="small"
              type="primary"
              class="btn-primary"
              :loading="saving"
              @click="save"
              >保 存</el-button
            >
          </template>
          <el-button v-else size="small" @click="closeDialog">关 闭</el-button>
        </div>
      </div>
    </template>
  </el-dialog>
</template>

<script setup lang="ts">
// 画布编辑器：编辑状态为纯前端结构（editorNodes/editorEdges），保存时 buildPayload 组装为后端契约；
// 编辑回显走 routingApi.graph + layoutPositions 自动布局（spec 无坐标列，位置不持久化）
import { computed, reactive, ref, watch } from 'vue'
import { ElMessage } from 'element-plus'
import { VueFlow, Handle, Position } from '@vue-flow/core'
import '@vue-flow/core/dist/style.css'
import '@vue-flow/core/dist/theme-default.css'
import { routingApi, type RoutingPayload } from '../../api/routing'
import { productApi, type ProductItem } from '../../api/product'
import { processApi, type ProcessItem } from '../../api/process'
import { hasCycle, layoutPositions, nextNodeNo } from '../../utils/dag'

// Vue Flow 样式在弹窗组件引入一次（style 基础样式 + theme-default 默认主题）

const props = defineProps<{
  /** 弹窗开关（v-model:visible） */
  visible: boolean
  /** 编辑/查看的路线 id；null 表示新建 */
  routingId: number | null
  /** 只读查看模式：隐藏全部编辑控件、画布禁拖禁连 */
  readonly: boolean
}>()
const emit = defineEmits<{
  'update:visible': [value: boolean]
  /** 保存成功后通知父级刷新列表 */
  saved: []
}>()

/** 材料行：材料未选择时为 null（保存时过滤空行），单位随材料自动带出 */
interface EditorMaterial {
  material_id: number | null
  qty_per_unit: number
  unit_id: number | null
}
/** 工序节点：name 为工序名快照（换工序时同步刷新） */
interface EditorNode {
  node_no: string
  process_id: number
  name: string
  output_product_id: number | null
  output_qty: number
  is_outsourced: 0 | 1
  remark: string | null
  materials: EditorMaterial[]
}
/** 边：以节点号引用两端（边 id = `${from}->${to}`，天然去重） */
interface EditorEdge {
  from: string
  to: string
}
/** 路线单头表单 */
interface EditorHeader {
  product_id: number | null
  version: string
  quantity: number
  status: 0 | 1
  remark: string
}

const header = reactive<EditorHeader>({
  product_id: null,
  version: 'v1',
  quantity: 1,
  status: 1,
  remark: '',
})
const editorNodes = ref<EditorNode[]>([])
const editorEdges = ref<EditorEdge[]>([])
const selectedNodeNo = ref<string | null>(null)
/** 画布点选的连线 id（`${from}->${to}`），供「删除连线」 */
const selectedEdgeKey = ref<string | null>(null)

// 下拉数据源：工序全量；输出产品=半成品+成品；材料=原料+半成品（spec §2.3 商品类型约束）
const processes = ref<ProcessItem[]>([])
const finishedProducts = ref<ProductItem[]>([])
const outputOptions = ref<ProductItem[]>([])
const materialOptions = ref<ProductItem[]>([])
// 商品名称/单位缓存：graph 回显的商品可能不在下拉前 100 条内，卡片展示优先查缓存
const productCache = ref(new Map<number, { name: string; unit_name?: string }>())

// 工具栏与连线表单
const toolbarProcessId = ref<number | null>(null)
const linkFrom = ref<string | null>(null)
const linkTo = ref<string | null>(null)
const loadingGraph = ref(false)
const saving = ref(false)
// 编辑/查看对象的编码（弹窗标题展示）
const routingCode = ref('')

const dialogTitle = computed(() => {
  if (props.readonly) return `工艺路线详情${routingCode.value ? ` - ${routingCode.value}` : ''}`
  return props.routingId
    ? `编辑工艺路线${routingCode.value ? ` - ${routingCode.value}` : ''}`
    : '新建工艺路线'
})

/** 当前配置面板绑定的节点 */
const selectedNode = computed(
  () => editorNodes.value.find((n) => n.node_no === selectedNodeNo.value) ?? null,
)

/** Vue Flow 受控节点：id=node_no，结构变化时按拓扑分层自动布局（位置不持久化，重开自动排布） */
const flowNodes = computed(() => {
  const ids = editorNodes.value.map((n) => n.node_no)
  const pos = layoutPositions(ids, editorEdges.value)
  return editorNodes.value.map((n) => ({
    id: n.node_no,
    type: 'routing',
    position: pos[n.node_no] ?? { x: 40, y: 40 },
    data: n,
  }))
})
const flowEdges = computed(() =>
  editorEdges.value.map((e) => ({ id: `${e.from}->${e.to}`, source: e.from, target: e.to })),
)

// 节点增删后清理悬空引用：连线选择与面板选中不得指向已删除节点（防止配出端点不存在的边）
watch(editorNodes, () => {
  const ids = new Set(editorNodes.value.map((n) => n.node_no))
  if (linkFrom.value && !ids.has(linkFrom.value)) linkFrom.value = null
  if (linkTo.value && !ids.has(linkTo.value)) linkTo.value = null
  if (selectedNodeNo.value && !ids.has(selectedNodeNo.value)) selectedNodeNo.value = null
})

// 会话序号（评审竞态修复）：开窗/关窗递增作废在途加载，下拉预拉与图回显的迟到响应据此丢弃，
// 防止"开 A→关→开 B"时 A 的慢响应覆盖 B 已 reset 的编辑态（与 useScanInbound BUG-02 同模式）
let sessionSeq = 0

// 弹窗打开：重置编辑态并拉取下拉数据；编辑/查看另拉完整图回显
watch(
  () => props.visible,
  async (open) => {
    if (!open) {
      // 关窗即作废在途：迟到的下拉/图响应禁止回写（弹窗已关，回写无意义且污染下次开窗的 reset）
      sessionSeq++
      return
    }
    // 记录本次会话序号：其后每个 await 落点写状态前校验，序号变了说明已关窗/重开，丢弃本次结果
    const session = ++sessionSeq
    resetEditor()
    loadingGraph.value = true
    try {
      const [procs, fin, semi, raw] = await Promise.all([
        processApi.list(),
        productApi.list({ page: 1, per_page: 100, type: 'finished' }),
        productApi.list({ page: 1, per_page: 100, type: 'semi_finished' }),
        productApi.list({ page: 1, per_page: 100, type: 'raw_material' }),
      ])
      if (session !== sessionSeq) return
      processes.value = procs.items
      finishedProducts.value = fin.items
      // 输出产品：半成品 +（终点工序的）成品；材料：原料 + 半成品
      outputOptions.value = [...semi.items, ...fin.items]
      materialOptions.value = [...raw.items, ...semi.items]
      if (props.routingId != null) await loadGraph(props.routingId, session)
    } catch (e) {
      // 迟到守卫：会话已作废（关窗/重开）时丢弃失败提示与关窗动作，避免过期报错打扰新会话
      if (session !== sessionSeq) return
      // 下拉或图数据加载失败：提示并关闭弹窗，避免半初始化编辑器
      ElMessage.error((e as Error).message)
      emit('update:visible', false)
    } finally {
      // 迟到守卫：旧会话不得清除新会话的 loading 态（只有当前会话才能复位）
      if (session === sessionSeq) loadingGraph.value = false
    }
  },
)

function resetEditor() {
  Object.assign(header, {
    product_id: null,
    version: 'v1',
    quantity: 1,
    status: 1,
    remark: '',
  })
  editorNodes.value = []
  editorEdges.value = []
  selectedNodeNo.value = null
  selectedEdgeKey.value = null
  toolbarProcessId.value = null
  linkFrom.value = null
  linkTo.value = null
  routingCode.value = ''
  productCache.value.clear()
}

/** 编辑回显：单头 + 节点/材料/边还原；历史商品名称入缓存供卡片展示 */
async function loadGraph(id: number, session: number) {
  const g = await routingApi.graph(id)
  // 迟到守卫：会话已作废（关窗/重开）时丢弃过期图数据回写，防止覆盖新会话的编辑态
  if (session !== sessionSeq) return
  routingCode.value = g.routing.code
  Object.assign(header, {
    product_id: g.routing.product_id,
    version: g.routing.version,
    quantity: g.routing.quantity,
    status: g.routing.status === 1 ? 1 : 0,
    remark: g.routing.remark ?? '',
  })
  editorNodes.value = g.nodes.map((n) => ({
    node_no: n.node_no,
    process_id: n.process_id,
    name: n.name,
    output_product_id: n.output_product_id || null,
    output_qty: n.output_qty,
    is_outsourced: n.is_outsourced === 1 ? 1 : 0,
    remark: n.remark,
    materials: n.materials.map((m) => ({
      material_id: m.material_id,
      qty_per_unit: m.qty_per_unit,
      unit_id: m.unit_id,
    })),
  }))
  editorEdges.value = g.edges.map((e) => ({ from: e.from_node_no, to: e.to_node_no }))
  // 回显商品（含不在下拉列表的历史商品）入名称缓存
  for (const n of g.nodes) {
    if (n.output_product_id)
      productCache.value.set(n.output_product_id, { name: n.output_product_name })
    for (const m of n.materials)
      productCache.value.set(m.material_id, { name: m.material_name, unit_name: m.unit_name })
  }
}

/** 商品名（卡片/产出展示）：缓存 → 下拉列表，未知返回空 */
function productName(id: number | null | undefined): string {
  if (!id) return ''
  return (
    productCache.value.get(id)?.name ??
    [...outputOptions.value, ...materialOptions.value].find((p) => p.id === id)?.name ??
    ''
  )
}

/** 材料行单位名：随所选材料自动带出（下拉商品带 unit_name；回显行兜底缓存） */
function matUnitName(row: EditorMaterial): string {
  if (row.material_id == null) return ''
  return (
    materialOptions.value.find((p) => p.id === row.material_id)?.unit_name ??
    productCache.value.get(row.material_id)?.unit_name ??
    ''
  )
}

// ===== 节点操作 =====

/** 按节点号取节点（画布插槽 data 不带类型，经 id 反查保持类型安全） */
function nodeById(id: unknown): EditorNode | undefined {
  return editorNodes.value.find((n) => n.node_no === id)
}

/** 卡片材料清单：过滤未选择材料的空行 */
function cardMaterials(id: unknown): EditorMaterial[] {
  return (nodeById(id)?.materials ?? []).filter((m) => m.material_id !== null)
}

/** 工具栏添加节点：node_no 自动生成（OP10/OP20…），name=工序名快照，产出/材料待配 */
function addNodeFromToolbar() {
  if (toolbarProcessId.value == null) return ElMessage.warning('请先选择工序')
  const proc = processes.value.find((p) => p.id === toolbarProcessId.value)
  if (!proc) return ElMessage.warning('所选工序不存在')
  const node: EditorNode = {
    node_no: nextNodeNo(editorNodes.value.map((n) => n.node_no)),
    process_id: proc.id,
    name: proc.name,
    output_product_id: null,
    output_qty: 1,
    is_outsourced: 0,
    remark: null,
    materials: [],
  }
  editorNodes.value.push(node)
  // 新节点自动选中，直接进入右侧配置
  selectedNodeNo.value = node.node_no
}

/** 面板切换工序：工序名快照同步刷新（节点卡片标题跟随） */
function applyNodeProcess(id: number) {
  const proc = processes.value.find((p) => p.id === id)
  if (selectedNode.value && proc) {
    selectedNode.value.process_id = id
    selectedNode.value.name = proc.name
  }
}

/** 删除节点：级联删除引用它的连线（边端点必须存在，悬空边后端会拒） */
function removeSelectedNode() {
  if (!selectedNode.value) return
  const no = selectedNode.value.node_no
  editorEdges.value = editorEdges.value.filter((e) => e.from !== no && e.to !== no)
  editorNodes.value = editorNodes.value.filter((n) => n.node_no !== no)
  selectedNodeNo.value = null
}

/** 添加空材料行：默认用量 1，选材料后单位自动带出 */
function addMaterial() {
  selectedNode.value?.materials.push({ material_id: null, qty_per_unit: 1, unit_id: null })
}
function removeMaterial(idx: number) {
  selectedNode.value?.materials.splice(idx, 1)
}
/** 选材料后带出其计量单位（spec §5.7 单位自动带出口径，与 BOM 页一致） */
function applyMaterialUnit(row: EditorMaterial, materialId: number) {
  row.unit_id = materialOptions.value.find((p) => p.id === materialId)?.unit_id ?? null
}

// ===== 连线操作 =====

/** 画布点选节点 → 右侧面板配置 */
function onNodeClick({ node }: { node: { id: string } }) {
  selectedNodeNo.value = node.id
}
function selectNode(id: unknown) {
  if (typeof id === 'string') selectedNodeNo.value = id
}
/** 画布点选连线 → 标记待删除（底部出现「删除连线」按钮） */
function onEdgeClick({ edge }: { edge: { id: string } }) {
  selectedEdgeKey.value = edge.id
}
/** 拖拽 Handle 连线入口（@connect） */
function onConnect({ source, target }: { source?: string | null; target?: string | null }) {
  if (source && target) addEditorEdge(source, target)
}
/** 下拉添加连线入口 */
function addEdgeBySelect() {
  if (!linkFrom.value || !linkTo.value) return ElMessage.warning('请选择连线的起止节点')
  addEditorEdge(linkFrom.value, linkTo.value)
}
/** 统一加边入口：自环/重复边拦截；允许暂构成环路（由校验/保存拦截，便于先搭后查） */
function addEditorEdge(from: string, to: string) {
  if (from === to) return ElMessage.warning('连线起点与终点不能相同')
  if (editorEdges.value.some((e) => e.from === from && e.to === to))
    return ElMessage.warning('该连线已存在')
  editorEdges.value.push({ from, to })
}
/** 删除画布点选的连线 */
function removeSelectedEdge() {
  if (!selectedEdgeKey.value) return
  const [from, to] = selectedEdgeKey.value.split('->')
  editorEdges.value = editorEdges.value.filter((e) => !(e.from === from && e.to === to))
  selectedEdgeKey.value = null
}

// ===== 校验与保存 =====

/** 节点全部传递前驱（反向 BFS）：供材料闭环提示判断半成品是否有产出来源 */
function ancestorsOf(nodeNo: string): Set<string> {
  const preds = new Map<string, string[]>()
  for (const e of editorEdges.value) preds.set(e.to, [...(preds.get(e.to) ?? []), e.from])
  const seen = new Set<string>()
  const queue = [...(preds.get(nodeNo) ?? [])]
  while (queue.length > 0) {
    const cur = queue.shift()!
    if (seen.has(cur)) continue
    seen.add(cur)
    queue.push(...(preds.get(cur) ?? []))
  }
  return seen
}

/** 材料闭环前端提示（后端 1702 权威）：输入半成品无前驱产出时逐条列出，不阻断 */
function materialClosureWarnings(): string[] {
  const problems: string[] = []
  for (const n of editorNodes.value) {
    const anc = ancestorsOf(n.node_no)
    for (const m of n.materials) {
      if (m.material_id == null) continue
      const mat = materialOptions.value.find((p) => p.id === m.material_id)
      // 仅半成品需要前驱产出；原料直接采购；不在下拉列表的历史材料类型未知，交后端校验
      if (!mat || mat.type !== 'semi_finished') continue
      const hasSource = [...anc].some(
        (a) => editorNodes.value.find((x) => x.node_no === a)?.output_product_id === m.material_id,
      )
      if (!hasSource) problems.push(`工序[${n.name}]的材料[${mat.name}]无前驱产出来源`)
    }
  }
  return problems
}

/** 「校验 DAG」：空画布守卫（hasCycle 空集短路后此处兜底提示）+ 本地环预检（1701 同口径文案）+ 材料闭环提示（不阻断） */
function validateDag() {
  if (editorNodes.value.length === 0) return ElMessage.warning('请先添加工序节点')
  const ids = editorNodes.value.map((n) => n.node_no)
  if (hasCycle(ids, editorEdges.value)) {
    ElMessage.error('工艺路线存在工序环路')
    return
  }
  const warnings = materialClosureWarnings()
  if (warnings.length > 0) ElMessage.warning(`材料闭环提示：${warnings.join('；')}`)
  else ElMessage.success('DAG 校验通过')
}

/** 保存载荷组装：空材料行过滤；未选产品/单位以 0 占位（后端校验权威） */
function buildPayload(h: EditorHeader, nodes: EditorNode[], edges: EditorEdge[]): RoutingPayload {
  return {
    product_id: h.product_id ?? 0,
    version: h.version,
    quantity: h.quantity,
    status: h.status,
    remark: h.remark || null,
    nodes: nodes.map((n) => ({
      node_no: n.node_no,
      process_id: n.process_id,
      name: n.name,
      output_product_id: n.output_product_id ?? 0,
      output_qty: n.output_qty,
      is_outsourced: n.is_outsourced,
      remark: n.remark,
      materials: n.materials
        .filter((m) => m.material_id !== null)
        .map((m) => ({
          material_id: m.material_id as number,
          qty_per_unit: m.qty_per_unit,
          unit_id: m.unit_id ?? 0,
        })),
    })),
    edges: edges.map((e) => ({ from_node_no: e.from, to_node_no: e.to })),
  }
}

/** 保 存：本地环预检强制通过才调接口；新建/编辑按 routingId 分流 */
async function save() {
  // 保存前强制本地环预检：与后端 1701 同口径，未通过不调接口
  if (
    hasCycle(
      editorNodes.value.map((n) => n.node_no),
      editorEdges.value,
    )
  ) {
    ElMessage.error('工艺路线存在工序环路')
    return
  }
  if (!header.product_id) return ElMessage.warning('请选择成品')
  if (!header.version.trim()) return ElMessage.warning('请填写版本')
  if (editorNodes.value.length === 0) return ElMessage.warning('请先添加工序节点')
  saving.value = true
  try {
    const payload = buildPayload(header, editorNodes.value, editorEdges.value)
    if (props.routingId) await routingApi.update(props.routingId, payload)
    else await routingApi.create(payload)
    ElMessage.success('保存成功')
    emit('saved')
    emit('update:visible', false)
  } catch (e) {
    ElMessage.error((e as Error).message)
  } finally {
    saving.value = false
  }
}

function closeDialog() {
  emit('update:visible', false)
}
</script>

<style scoped>
/* 弹窗主体：表头行 + 左画布右面板两栏 */
.rc-body {
  display: flex;
  flex-direction: column;
  gap: var(--sp-3);
}
.rc-header {
  display: flex;
  flex-wrap: wrap;
  gap: var(--sp-2);
}
.header-remark {
  flex: 1;
  min-width: 220px;
}
.header-product :deep(.el-select),
.panel-node :deep(.el-select),
.panel-process :deep(.el-select),
.panel-output :deep(.el-select) {
  width: 100%;
}
.rc-main {
  display: flex;
  gap: var(--sp-3);
  align-items: stretch;
}
.rc-left {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: var(--sp-2);
}
.rc-toolbar {
  display: flex;
  align-items: center;
  gap: var(--sp-2);
}
.toolbar-tip {
  color: var(--t3);
  font-size: 12px;
}
.btn-primary {
  background: var(--a-600);
  border-color: var(--a-600);
  cursor: pointer;
}
/* 画布容器：固定高度 + 相对定位承载空态提示 */
.rc-canvas {
  position: relative;
  height: 440px;
  border: 1px solid var(--border);
  border-radius: var(--r-md);
  overflow: hidden;
}
.canvas-empty {
  position: absolute;
  inset: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  color: var(--t3);
  pointer-events: none;
}
/* 右侧配置面板 */
.rc-panel {
  width: 340px;
  flex: none;
  border: 1px solid var(--border);
  border-radius: var(--r-md);
  padding: var(--sp-3);
  overflow-y: auto;
  max-height: 520px;
}
.panel-empty {
  color: var(--t3);
  padding: var(--sp-5) 0;
  text-align: center;
}
.mat-rows {
  width: 100%;
  display: flex;
  flex-direction: column;
  gap: var(--sp-2);
}
/* 委外开关提示（spec 6.3）：开启后提示下达生成委外需求，readonly 不展示 */
.panel-outsourced-hint {
  margin-top: var(--sp-1);
  color: var(--t3);
  font-size: 12px;
  line-height: 1.5;
}
.mat-row {
  display: flex;
  align-items: center;
  gap: var(--sp-2);
}
.mat-row .el-select {
  flex: 1;
  min-width: 0;
}
.mat-unit {
  width: 34px;
  flex: none;
  color: var(--t3);
  font-size: 12px;
}
.add-mat {
  width: 100%;
  border-style: dashed;
  cursor: pointer;
}
/* 底部：左连线工具 + 右动作按钮 */
.rc-footer {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: var(--sp-3);
  flex-wrap: wrap;
}
.footer-links {
  display: flex;
  align-items: center;
  gap: var(--sp-2);
}
.link-label {
  color: var(--t2);
  font-size: 13px;
}
.link-arrow {
  color: var(--t3);
}
.footer-actions {
  display: flex;
  gap: var(--sp-2);
}
/* 画布节点卡片：委外琥珀描边 + 角标；选中态强调色描边 */
.rn-card {
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
.rn-card.is-selected {
  border-color: var(--a-600);
  box-shadow: var(--sh-md);
}
.rn-card.is-outsourced {
  border-color: var(--warn);
}
.rn-title {
  font-weight: 600;
  color: var(--t1);
  margin-bottom: var(--sp-1);
}
.rn-mat {
  color: var(--t2);
  line-height: 1.6;
}
.rn-out {
  color: var(--ok);
  margin-top: var(--sp-1);
}
.rn-badge {
  position: absolute;
  top: -9px;
  right: 8px;
  padding: 0 6px;
  border-radius: var(--r-full);
  background: var(--warn);
  color: #fff;
  font-size: 11px;
  line-height: 18px;
}
</style>
