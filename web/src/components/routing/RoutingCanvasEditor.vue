<!-- 工艺路线画布编辑体（子页面承载，原弹窗演进）：表头（成品/版本/基准数量/启停/备注）+ Vue Flow 画布
     （工序节点 DAG）+ 右侧节点配置面板。新建/编辑共用（routingId 区分），readonly 只读查看。
     建节点三通道：工具栏「选择工序+添加节点」/ 节点卡片四边中点把手拖出 / 双击画布空白（后两者为无工序
     空节点，面板补配工序即可）；节点把手拖出会按「被拖节点 → 新节点」自动连线。
     拖拽与双击位置保留为节点自定义坐标，不被自动布局覆盖。
     保存前本地环预检（与后端 1701 同口径），完整结构/数量闭合校验由后端权威兜底 -->
<template>
  <div class="rc-editor">
    <div class="rc-topline">
      <h2 class="rc-title">{{ pageTitle }}</h2>
    </div>

    <div v-loading="loadingGraph" class="rc-body">
      <!-- 表头行：成品/版本/基准数量/启用/备注（readonly 全部禁用） -->
      <el-form
        ref="headerFormRef"
        :model="header"
        :rules="headerRules"
        label-width="76px"
        size="small"
        class="rc-header"
      >
        <el-form-item label="成品" prop="product_id" required>
          <div class="header-product">
            <el-select
              v-model="header.product_id"
              filterable
              remote
              :remote-method="searchFinished"
              :loading="finishedLoading"
              placeholder="搜索成品编码/名称"
              :disabled="readonly"
            >
              <el-option
                v-for="p in finishedOptions"
                :key="p.id"
                :label="productLabel(p)"
                :value="p.id"
              >
                <div class="opt-line">
                  <span>{{ productLabel(p) }}</span>
                  <el-tag
                    v-if="p.type_label"
                    size="small"
                    :type="productTypeTag(p.type)"
                    class="opt-tag"
                    >{{ p.type_label }}</el-tag
                  >
                </div>
              </el-option>
            </el-select>
          </div>
        </el-form-item>
        <el-form-item label="版本" prop="version" required>
          <el-input v-model="header.version" :disabled="readonly" style="width: 110px" />
        </el-form-item>
        <el-form-item label="基准数量" prop="quantity">
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
        <!-- Z-1 降级态：浏览器不满足画布基线（PointerEvent/ResizeObserver/DOMMatrixReadOnly）时，
             以提示条替代画布与配置面板——不渲染 Vue Flow 防白屏 -->
        <el-alert
          v-if="supportIssues.length > 0"
          class="rc-degrade"
          type="warning"
          :closable="false"
          show-icon
          title="当前浏览器不支持工艺画布编辑"
          :description="degradeDescription"
        />
        <template v-else>
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
              <span class="toolbar-tip">拖节点两侧圆点连线；拖节点四边的＋或双击空白新建节点</span>
            </div>
            <div ref="canvasRef" class="rc-canvas" @dblclick="onPaneDblClick">
              <VueFlow
                :nodes="flowNodes"
                :edges="flowEdges"
                :nodes-connectable="!readonly"
                :nodes-draggable="!readonly"
                :zoom-on-double-click="false"
                :fit-view-on-init="true"
                @connect="onConnect"
                @node-click="onNodeClick"
                @edge-click="onEdgeClick"
                @node-drag-stop="onNodeDragStop"
              >
                <!-- 自定义工序节点卡片：id=node_no，分层展示工序/材料/产出（说明文字小字，
                     工序内容与说明同行、材料与产出在说明下方竖排；内容只展示名称） -->
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
                    <!-- 工序行：说明「工序」小字，内容（节点号 · 工序名）紧随其后，与说明区分层级 -->
                    <div class="rn-title">
                      <span class="rn-label">工序</span>
                      <span class="rn-title-text"
                        ><span class="rn-no">{{ nodeById(id)?.node_no }}</span> ·
                        {{ nodeById(id)?.name || '未选工序' }}</span
                      >
                    </div>
                    <!-- 材料块：说明「材料」小字独占一行，材料名称在下方竖向罗列 -->
                    <div v-if="cardMaterials(id).length > 0" class="rn-section">
                      <span class="rn-label">材料</span>
                      <div class="rn-list">
                        <div
                          v-for="(m, mi) in cardMaterials(id)"
                          :key="m.material_id ?? mi"
                          class="rn-mat"
                        >
                          {{ productName(m.material_id) }} ×{{ m.qty_per_unit }}
                        </div>
                      </div>
                    </div>
                    <!-- 产出块：说明「产出」小字独占一行，产出名称在下方竖向罗列 -->
                    <div v-if="nodeById(id)?.output_product_id" class="rn-section">
                      <span class="rn-label">产出</span>
                      <div class="rn-list">
                        <div class="rn-out">
                          {{ productName(nodeById(id)?.output_product_id) }} ×{{
                            nodeById(id)?.output_qty
                          }}
                        </div>
                      </div>
                    </div>
                    <span v-if="nodeById(id)?.is_outsourced === 1" class="rn-badge">委外</span>
                    <Handle type="source" :position="Position.Right" />
                    <!-- 节点四边中点拖出建节点把手：按住拖入画布松手即创建空节点并自动连线
                         （被拖节点 → 新节点；readonly 隐藏；nodrag 类 + mousedown 拦截：
                         Vue Flow 节点拖动监听的是 mousedown 冒泡，仅拦 pointerdown 会把
                         d3-drag 漏给卡片本体，拖拽结束还会回选原节点） -->
                    <template v-if="!readonly">
                      <span
                        v-for="side in spawnSides"
                        :key="side"
                        class="ns-spawn nodrag"
                        :data-side="side"
                        :class="{ 'is-dragging': draggingHandle === `${id}:${side}` }"
                        title="拖出创建后续节点"
                        @pointerdown.stop.prevent="startSpawnFromNode(id, side)"
                        @mousedown.stop.prevent
                        >＋</span
                      >
                    </template>
                  </div>
                </template>
              </VueFlow>
              <div v-if="editorNodes.length === 0" class="canvas-empty">
                {{
                  readonly
                    ? '暂无工序节点'
                    : '从工具栏选择工序添加节点，或双击画布空白处、拖动节点四边的＋创建'
                }}
              </div>
            </div>
          </div>

          <!-- 右侧：节点配置面板（360px，分区排版） -->
          <aside class="rc-panel">
            <template v-if="selectedNode">
              <el-form label-width="72px" size="small" class="panel-form">
                <div class="panel-sep">节点</div>
                <el-form-item label="节点">
                  <div class="panel-node">
                    <el-select v-model="selectedNodeNo" placeholder="选择节点">
                      <el-option
                        v-for="n in editorNodes"
                        :key="n.node_no"
                        :label="nodeLabel(n)"
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
                <div class="panel-sep">工序与产出</div>
                <el-form-item label="工序">
                  <div class="panel-process">
                    <el-select
                      v-model="selectedNode.process_id"
                      :disabled="readonly"
                      placeholder="选择工序"
                      @change="(id: number) => applyNodeProcess(id)"
                    >
                      <el-option
                        v-for="p in processOptions"
                        :key="p.id"
                        :label="p.name"
                        :value="p.id"
                      />
                    </el-select>
                  </div>
                </el-form-item>
                <el-form-item label="输出产品">
                  <div class="panel-output">
                    <el-select
                      v-model="selectedNode.output_product_id"
                      filterable
                      clearable
                      remote
                      :remote-method="searchOutput"
                      :loading="outputLoading"
                      placeholder="搜索半成品/成品"
                      :disabled="readonly"
                      @change="(id: number | undefined) => applyNodeOutput(id)"
                    >
                      <el-option
                        v-for="p in outputOptions"
                        :key="p.id"
                        :label="productLabel(p)"
                        :value="p.id"
                      >
                        <div class="opt-line">
                          <span>{{ productLabel(p) }}</span>
                          <el-tag
                            v-if="p.type_label"
                            size="small"
                            :type="productTypeTag(p.type)"
                            class="opt-tag"
                            >{{ p.type_label }}</el-tag
                          >
                        </div>
                      </el-option>
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
                <div class="panel-sep">材料</div>
                <el-form-item label="输入材料">
                  <div class="mat-rows">
                    <div v-for="(row, idx) in selectedNode.materials" :key="idx" class="mat-row">
                      <el-select
                        v-model="row.material_id"
                        filterable
                        remote
                        :remote-method="searchMaterial"
                        :loading="materialLoading"
                        placeholder="搜索原料/半成品"
                        :disabled="readonly"
                        @change="(id: number) => applyMaterialUnit(row, id)"
                      >
                        <el-option
                          v-for="m in materialOptions"
                          :key="m.id"
                          :label="productLabel(m)"
                          :value="m.id"
                        >
                          <div class="opt-line">
                            <span>{{ productLabel(m) }}</span>
                            <el-tag
                              v-if="m.type_label"
                              size="small"
                              :type="productTypeTag(m.type)"
                              class="opt-tag"
                              >{{ m.type_label }}</el-tag
                            >
                          </div>
                        </el-option>
                      </el-select>
                      <el-input-number
                        v-model="row.qty_per_unit"
                        :min="0.01"
                        :precision="2"
                        :controls="false"
                        style="width: 96px"
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
                <div class="panel-sep">备注</div>
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
              <el-button
                v-if="!readonly"
                size="small"
                type="danger"
                plain
                class="panel-remove"
                @click="removeSelectedNode"
                >删除节点</el-button
              >
            </template>
            <div v-else class="panel-empty">点击画布节点或在「节点」下拉选择后配置</div>
          </aside>
        </template>
      </div>
    </div>

    <div class="rc-footer">
      <!-- 添加连线双通道之一：下拉兜底（拖拽 Handle 之外的主通道，供 E2E 与无鼠标场景）；降级态无画布不可连线 -->
      <div v-if="!readonly && supportIssues.length === 0" class="footer-links">
        <span class="link-label">连线</span>
        <div class="edge-from">
          <el-select v-model="linkFrom" placeholder="从节点" style="width: 150px">
            <el-option
              v-for="n in editorNodes"
              :key="n.node_no"
              :label="nodeLabel(n)"
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
              :label="nodeLabel(n)"
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
          <!-- Z-1 降级态：画布不可用时校验/保存无意义，仅保留取消退出 -->
          <el-button v-if="supportIssues.length === 0" size="small" @click="validateDag"
            >校验 DAG</el-button
          >
          <el-button size="small" @click="cancel">取 消</el-button>
          <el-button
            v-if="supportIssues.length === 0"
            size="small"
            type="primary"
            class="btn-primary"
            :loading="saving"
            @click="save"
            >保 存</el-button
          >
        </template>
        <el-button v-else size="small" @click="cancel">关 闭</el-button>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
/* global HTMLElement, MouseEvent, PointerEvent, window */
// 画布编辑器：编辑状态为纯前端结构（editorNodes/editorEdges），保存时 buildPayload 组装为后端契约；
// 编辑回显走 routingApi.graph + layoutPositions 自动布局；拖拽/双击创建的节点位置存 customPositions
import { computed, onMounted, onUnmounted, reactive, ref, watch } from 'vue'
import { ElMessage, type FormInstance, type FormRules } from 'element-plus'
import { VueFlow, Handle, Position, useVueFlow } from '@vue-flow/core'
import '@vue-flow/core/dist/style.css'
import '@vue-flow/core/dist/theme-default.css'
import { routingApi, type RoutingPayload } from '../../api/routing'
import { bomApi } from '../../api/bom'
import { productApi, type ProductType } from '../../api/product'
import { processApi, type ProcessItem } from '../../api/process'
import { getCanvasSupportIssues } from '../../utils/browserSupport'
import { hasCycle, layoutPositions, nextNodeNo } from '../../utils/dag'
import { useRemoteOptions } from '../../composables/useRemoteOptions'
import { quantityRule } from '../../utils/formRules'

const props = defineProps<{
  /** 编辑/查看的路线 id；null 表示新建 */
  routingId: number | null
  /** 只读查看模式：隐藏全部编辑控件、画布禁拖禁连 */
  readonly: boolean
}>()
const emit = defineEmits<{
  /** 保存成功后通知父级返回列表并刷新 */
  saved: []
  /** 取消/关闭：不保存返回列表 */
  cancel: []
}>()

/** 材料行：材料未选择时为 null（保存时过滤空行），单位随材料自动带出 */
interface EditorMaterial {
  material_id: number | null
  qty_per_unit: number
  unit_id: number | null
}
/** 工序节点：process_id 空表示画布拖拽/双击创建的未配工序节点（保存前拦截）；name 为工序名快照 */
interface EditorNode {
  node_no: string
  process_id: number | null
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
// 表头表单引用：保存前统一触发 el-form 校验（D-17）；节点配置面板/材料行为画布语义
//（节点由 DAG 环预检 + 后端权威校验），不进 el-form rules
const headerFormRef = ref<FormInstance>()
// 表头校验规则（D-17）：成品/版本必填；基准数量须 > 0 且最多 2 位小数
const headerRules: FormRules = {
  product_id: [{ required: true, message: '请选择成品', trigger: 'change' }],
  version: [{ required: true, message: '请填写版本', trigger: 'blur' }],
  quantity: [quantityRule(false, '基准数量必须大于 0')],
}
const editorNodes = ref<EditorNode[]>([])
const editorEdges = ref<EditorEdge[]>([])
const selectedNodeNo = ref<string | null>(null)
/** 画布点选的连线 id（`${from}->${to}`），供「删除连线」 */
const selectedEdgeKey = ref<string | null>(null)
/** 节点自定义坐标：拖拽/双击创建与画布拖动后的位置，优先于自动布局（布局仅供初始排布） */
const customPositions = reactive<Record<string, { x: number; y: number }>>({})

// 下拉数据源：工序全量（小主数据，维持本地过滤不动）；商品三路走远程搜索（BF-3）
// 商品名称/单位缓存：graph 回显的商品可能不在下拉前 100 条内，卡片展示优先查缓存
const processes = ref<ProcessItem[]>([])

/** 画布商品下拉选项：搜索结果为完整商品档案；回显 pin 项仅有 id/名称/单位（graph 历史数据，code 可缺） */
interface CanvasProduct {
  id: number
  code: string
  name: string
  unit_id: number | null
  unit_name: string | null
  type?: ProductType
  type_label?: string
}

// 下拉选项类型标签语义色（与商品管理页类型列一致：原料 info/半成品 warning/成品 success）；
// pin 回显项无 type 时不渲染标签（默认 raw_material 色仅作类型兜底，不影响已选值展示）
const PRODUCT_TYPE_TAGS = {
  raw_material: 'info',
  semi_finished: 'warning',
  finished: 'success',
} as const

function productTypeTag(type: ProductType | undefined) {
  return PRODUCT_TYPE_TAGS[type ?? 'raw_material']
}

/** 商品下拉单页容量：初载与关键字搜索共用（后端 per_page 硬钳制上限 100） */
const PRODUCT_PAGE_SIZE = 100

// 会话内商品请求合并（PJ-1）：同一 (type, keyword) 的并发请求共享同一 Promise——
// 成品/半成品分别被两个下拉引用（finished 在成品+输出下拉、semi_finished 在输出+材料下拉），
// 合并后每次进入页面商品请求从 5 个降为 3 个（fin/semi/raw 各 1）；reset 时清空合并表，
// 重新进入仍重新拉取，保持「主数据取新鲜值」语义，未引入跨页缓存
const pendingProduct = new Map<string, Promise<CanvasProduct[]>>()

function fetchProductOnce(type: ProductType, kw = ''): Promise<CanvasProduct[]> {
  const key = `${type}:${kw}`
  let p = pendingProduct.get(key)
  if (!p) {
    // 同参请求只发一次，其余调用方复用同一在途 Promise
    p = productApi
      .list({ page: 1, per_page: PRODUCT_PAGE_SIZE, type, keyword: kw })
      .then((r) => r.items)
    pendingProduct.set(key, p)
  }
  return p
}

// 成品下拉（单路 finished）：remote 服务端搜索，初载取前 100 条
const {
  options: finishedOptions,
  loading: finishedLoading,
  load: loadFinished,
  search: searchFinished,
  pin: pinFinished,
  reset: resetFinished,
} = useRemoteOptions<CanvasProduct>({
  fetch: (kw) => fetchProductOnce('finished', kw),
  keyOf: (p) => p.id,
  onError: (e) => ElMessage.error(e.message),
})

// 输出产品下拉（双路合并：半成品 + 成品，spec §2.3 输出类型约束）
const {
  options: outputOptions,
  loading: outputLoading,
  load: loadOutput,
  search: searchOutput,
  pin: pinOutput,
  reset: resetOutput,
} = useRemoteOptions<CanvasProduct>({
  // 双路经会话内请求合并：半成品与成品下拉共享的 finished 请求不再重复发出
  fetch: (kw) =>
    Promise.all([fetchProductOnce('semi_finished', kw), fetchProductOnce('finished', kw)]).then(
      ([semi, fin]) => [...semi, ...fin],
    ),
  keyOf: (p) => p.id,
  onError: (e) => ElMessage.error(e.message),
})

// 材料下拉（双路合并：原料 + 半成品，spec §2.3 材料类型约束）
const {
  options: materialOptions,
  loading: materialLoading,
  load: loadMaterial,
  search: searchMaterial,
  pin: pinMaterial,
  reset: resetMaterial,
} = useRemoteOptions<CanvasProduct>({
  // 双路经会话内请求合并：半成品与输出下拉共享的 semi_finished 请求不再重复发出
  fetch: (kw) =>
    Promise.all([fetchProductOnce('raw_material', kw), fetchProductOnce('semi_finished', kw)]).then(
      ([raw, semi]) => [...raw, ...semi],
    ),
  keyOf: (p) => p.id,
  onError: (e) => ElMessage.error(e.message),
})

// 画布 store：屏幕坐标 → 流坐标换算（拖拽/双击落点对位；挂载前为恒等映射，渲染后随视口校准）
const { screenToFlowCoordinate } = useVueFlow()

const productCache = ref(new Map<number, { name: string; unit_name?: string }>())

/** 下拉选项文案：编码+名称；回显 pin 项无编码时仅展示名称（避免前导空格） */
function productLabel(p: CanvasProduct): string {
  return p.code ? `${p.code} ${p.name}` : p.name
}

/** 节点下拉/卡片文案：未配工序的拖拽/双击空节点显示「未选工序」占位 */
function nodeLabel(n: EditorNode): string {
  return `${n.node_no} · ${n.name || '未选工序'}`
}

/** 工序下拉选项：正常工序列表 + 选中值不在列表时的动态兜底项。
    兜底项取节点工序名快照（回显后工序被删/列表异常时，避免下拉只显示裸 id） */
const processOptions = computed(() => {
  const cur = selectedNode.value?.process_id
  if (cur == null || processes.value.some((p) => p.id === cur)) return processes.value
  return [
    {
      id: cur,
      name: selectedNode.value?.name || `工序#${cur}`,
      code: '',
      sort: 0,
      description: null,
      status: 1,
    },
    ...processes.value,
  ]
})

// 工具栏与连线表单
const toolbarProcessId = ref<number | null>(null)
const linkFrom = ref<string | null>(null)
const linkTo = ref<string | null>(null)
const loadingGraph = ref(false)
const saving = ref(false)
// 编辑/查看对象的编码（页面标题展示）
const routingCode = ref('')

// Z-1 浏览器能力检测：画布可用性前置条件（PointerEvent/ResizeObserver/DOMMatrixReadOnly，基线 Chrome/Edge ≥ 100）。
// 能力在会话内不变，组件装载时检测一次；不满足时进入降级态——不渲染 Vue Flow 防白屏，仅提示并允许返回
const supportIssues = getCanvasSupportIssues()

/** 降级提示文案：逐项缺失原因 + 升级指引（列表/筛选不受影响，可返回列表） */
const degradeDescription = computed(() => {
  const reasons = supportIssues.map((i) => i.message).join('；')
  return `${reasons}。请升级浏览器至 Chrome/Edge 100 及以上版本（2022 年起主流版本）后再使用画布编辑；列表查询、筛选与详情查看不受影响，可返回列表。`
})

const pageTitle = computed(() => {
  if (props.readonly) return `工艺路线详情${routingCode.value ? ` - ${routingCode.value}` : ''}`
  return props.routingId
    ? `编辑工艺路线${routingCode.value ? ` - ${routingCode.value}` : ''}`
    : '新建工艺路线'
})

/** 当前配置面板绑定的节点 */
const selectedNode = computed(
  () => editorNodes.value.find((n) => n.node_no === selectedNodeNo.value) ?? null,
)

/** Vue Flow 受控节点：id=node_no；自定义坐标优先，否则按拓扑分层自动布局（位置不持久化，重开自动排布） */
const flowNodes = computed(() => {
  const ids = editorNodes.value.map((n) => n.node_no)
  const pos = layoutPositions(ids, editorEdges.value)
  return editorNodes.value.map((n) => ({
    id: n.node_no,
    type: 'routing',
    position: customPositions[n.node_no] ?? pos[n.node_no] ?? { x: 40, y: 40 },
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

// 会话序号（评审竞态修复）：进入/离开页面时递增作废在途加载，下拉预拉与图回显的迟到响应据此丢弃，
// 防止"A 页未加载完→返回→再编辑 B"时 A 的慢响应覆盖 B 已 reset 的编辑态（与 useScanInbound BUG-02 同模式）
let sessionSeq = 0

// 页面装载：重置编辑态并拉取下拉数据；编辑/查看另拉完整图回显（组件随路由挂载，无弹窗开关 watcher）
onMounted(async () => {
  // 记录本次会话序号：其后每个 await 落点写状态前校验，序号变了说明已卸载/重开，丢弃本次结果
  const session = ++sessionSeq
  resetEditor()
  loadingGraph.value = true
  try {
    const procs = await processApi.list()
    if (session !== sessionSeq) return
    processes.value = procs.items
    // 商品三路下拉初载（BF-3 remote 模式保留前 100 条初始选项）：不阻塞页面打开，
    // 失败由各自 onError 提示且保持空列表可继续搜索，不再整体中断编辑
    void loadFinished()
    void loadOutput()
    void loadMaterial()
    if (props.routingId != null) await loadGraph(props.routingId, session)
  } catch (e) {
    // 迟到守卫：会话已作废（已返回列表）时丢弃失败提示，避免过期报错打扰
    if (session !== sessionSeq) return
    // 工序或图数据加载失败：提示并返回列表，避免半初始化编辑器
    ElMessage.error((e as Error).message)
    emit('cancel')
  } finally {
    // 迟到守卫：旧会话不得清除新会话的 loading 态（只有当前会话才能复位）
    if (session === sessionSeq) loadingGraph.value = false
  }
})

onUnmounted(() => {
  // 页面卸载即作废在途：迟到的下拉/图响应禁止回写（组件已销毁，回写无意义）
  sessionSeq++
})

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
  for (const key of Object.keys(customPositions)) delete customPositions[key]
  // 商品下拉选项集与 pin 集一并清空并作废在途，防止上一会话（编辑 A）的回显项串入下一会话
  resetFinished()
  resetOutput()
  resetMaterial()
  // 会话内请求合并表一并作废：下一会话重新拉取，避免跨进入命中旧会话的在途/已决 Promise（新鲜语义保持）
  pendingProduct.clear()
}

/** 编辑回显：单头 + 节点/材料/边还原；历史商品名称入缓存供卡片展示 */
async function loadGraph(id: number, session: number) {
  const g = await routingApi.graph(id)
  // 迟到守卫：会话已作废（已返回列表）时丢弃过期图数据回写，防止覆盖新会话的编辑态
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
  // 回显商品并入下拉选项集（pin）：历史商品可能不在初载前 100 条内，不 pin 则下拉只能显示裸 id；
  // 名称/单位缓存仍供节点卡片展示
  if (g.routing.product_id) {
    pinFinished({
      id: g.routing.product_id,
      code: '',
      name: g.routing.product_name,
      unit_id: null,
      unit_name: null,
    })
  }
  for (const n of g.nodes) {
    if (n.output_product_id) {
      pinOutput({
        id: n.output_product_id,
        code: '',
        name: n.output_product_name,
        unit_id: null,
        unit_name: null,
      })
    }
    for (const m of n.materials) {
      pinMaterial({
        id: m.material_id,
        code: '',
        name: m.material_name,
        unit_id: m.unit_id,
        unit_name: m.unit_name,
      })
    }
  }
  // 回显商品（含不在下拉列表的历史商品）入名称缓存
  for (const n of g.nodes) {
    if (n.output_product_id)
      productCache.value.set(n.output_product_id, { name: n.output_product_name })
    for (const m of n.materials)
      productCache.value.set(m.material_id, { name: m.material_name, unit_name: m.unit_name })
  }
}

/** 商品名（卡片材料/产出行展示）：下拉选项 → 回显缓存，只展示名称（编码不进卡片）；未知返回空 */
function productName(id: number | null | undefined): string {
  if (!id) return ''
  return (
    [...outputOptions.value, ...materialOptions.value, ...finishedOptions.value].find(
      (p) => p.id === id,
    )?.name ??
    productCache.value.get(id)?.name ??
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

// ===== 画布建节点（节点四边中点拖出 / 双击空白）与节点位置 =====

/** 节点四边中点把手方位（模板 v-for 复用） */
const spawnSides = ['top', 'right', 'bottom', 'left'] as const
/** 正在拖拽的把手标识（`节点号:方位`，模板高亮当前把手） */
const draggingHandle = ref<string | null>(null)
const canvasRef = ref<HTMLElement | null>(null)

/** 画布空白双击：创建一个无工序空节点（无连线），面板补配工序后即可保存 */
function onPaneDblClick(e: MouseEvent) {
  if (props.readonly || supportIssues.length > 0) return
  // 双击落在节点卡片/连线/拖拽把手上不触发（空白区双击才建节点）
  const target = e.target as HTMLElement | null
  if (target?.closest('.vue-flow__node, .vue-flow__edge, .ns-spawn')) return
  const point = screenToFlowCoordinate({ x: e.clientX, y: e.clientY })
  spawnNodeAt(point)
}

/** 节点四边中点把手按下：window 级跟踪到松手，落点在画布内则建新节点并自动连线（被拖节点 → 新节点） */
function startSpawnFromNode(nodeNo: string, side: string) {
  if (props.readonly || supportIssues.length > 0) return
  draggingHandle.value = `${nodeNo}:${side}`
  const onUp = (ev: PointerEvent) => {
    draggingHandle.value = null
    window.removeEventListener('pointermove', onMove)
    window.removeEventListener('pointerup', onUp)
    const canvasEl = canvasRef.value
    if (!canvasEl) return
    const rect = canvasEl.getBoundingClientRect()
    // jsdom 无真实布局时 rect 为全零矩形，命中判定短路放行（拖拽真实落地由 E2E 覆盖）
    const inside =
      rect.width === 0 ||
      (ev.clientX >= rect.left &&
        ev.clientX <= rect.right &&
        ev.clientY >= rect.top &&
        ev.clientY <= rect.bottom)
    if (!inside) return
    spawnFromNode(nodeNo, screenToFlowCoordinate({ x: ev.clientX, y: ev.clientY }))
  }
  const onMove = () => {
    // 仅维持当前把手反色高亮，位置取释放点（无实时幽灵预览）
  }
  window.addEventListener('pointermove', onMove)
  window.addEventListener('pointerup', onUp)
}

/** 节点把手拖出建节点：spawnNodeAt + 自动追加「被拖节点 → 新节点」连线。
    连线方向与把手方位无关（从左侧把手拖出同样为被拖节点 → 新节点），保持语义简单统一 */
function spawnFromNode(nodeNo: string, position: { x: number; y: number }) {
  const created = spawnNodeAt(position)
  addEditorEdge(nodeNo, created.node_no)
}

/** 创建无工序空节点并写入自定义坐标（拖拽/双击落点对位，不被自动布局覆盖），返回新建节点 */
function spawnNodeAt(position: { x: number; y: number }): EditorNode {
  const node: EditorNode = {
    node_no: nextNodeNo(editorNodes.value.map((n) => n.node_no)),
    process_id: null,
    name: '',
    output_product_id: null,
    output_qty: 1,
    is_outsourced: 0,
    remark: null,
    materials: [],
  }
  customPositions[node.node_no] = { x: position.x, y: position.y }
  editorNodes.value.push(node)
  // 新节点自动选中，直接进入右侧配置
  selectedNodeNo.value = node.node_no
  return node
}

/** 画布拖动节点结束：记录自定义坐标，保证拖动后不被自动布局拉回 */
function onNodeDragStop({
  node,
}: {
  node: {
    id: string
    computedPosition?: { x: number; y: number }
    position?: { x: number; y: number }
  }
}) {
  const p = node.computedPosition ?? node.position
  if (p) customPositions[node.id] = { x: p.x, y: p.y }
}

/** 面板切换工序：工序名快照同步刷新（节点卡片标题跟随） */
function applyNodeProcess(id: number) {
  const proc = processes.value.find((p) => p.id === id)
  if (selectedNode.value && proc) {
    selectedNode.value.process_id = id
    selectedNode.value.name = proc.name
  }
}

/** 面板切换输出产品：按该产品的启用 BOM 自动填充输入材料清单（BOM 明细 ÷ 基准数量 = 每单位用量），
    替代逐行手工维护；无 BOM 或拉取失败时保留现有材料行，用户仍可手工增删调整 */
async function applyNodeOutput(id: number | undefined) {
  const node = selectedNode.value
  if (!id || !node) return
  // 记录目标节点号：await 期间用户可能切换选中/再次切换输出产品，落点前校验防旧响应覆盖新选择
  const targetNo = node.node_no
  // 先落选值：节点卡片产出行即时刷新（BOM 拉取非阻塞）
  node.output_product_id = id
  try {
    const list = await bomApi.list({ product_id: id, per_page: 100 })
    // 竞态守卫：选中节点已切换或输出产品已再次变更时，丢弃本次迟到的 BOM 材料回填
    if (selectedNodeNo.value !== targetNo || selectedNode.value?.output_product_id !== id) return
    const bom = list.items.find((b) => b.status === 1) ?? list.items[0]
    if (!bom) {
      ElMessage.info('该产品暂无 BOM，请手动添加材料')
      return
    }
    const { items } = await bomApi.items(bom.id)
    // 第二个 await 落点同样需守卫（items 拉取期间仍可能切换）
    if (selectedNodeNo.value !== targetNo || selectedNode.value?.output_product_id !== id) return
    const basis = Number(bom.quantity) || 1
    // 材料行整体替换为 BOM 明细（再次切换输出产品可重新拉取）
    node.materials = items.map((m) => ({
      material_id: m.material_id,
      qty_per_unit: Number((m.quantity / basis).toFixed(2)),
      unit_id: m.unit_id,
    }))
    // BOM 明细商品并入材料下拉选项与名称缓存（历史材料可能不在初载前 100 条内）
    for (const m of items) {
      pinMaterial({
        id: m.material_id,
        code: '',
        name: m.material_name,
        unit_id: m.unit_id,
        unit_name: m.unit_name,
      })
      productCache.value.set(m.material_id, { name: m.material_name, unit_name: m.unit_name })
    }
    ElMessage.success(`已按产品 BOM（${bom.version}）填充材料清单`)
  } catch (e) {
    ElMessage.error((e as Error).message)
  }
}

/** 删除节点：级联删除引用它的连线（边端点必须存在，悬空边后端会拒） */
function removeSelectedNode() {
  if (!selectedNode.value) return
  const no = selectedNode.value.node_no
  editorEdges.value = editorEdges.value.filter((e) => e.from !== no && e.to !== no)
  editorNodes.value = editorNodes.value.filter((n) => n.node_no !== no)
  delete customPositions[no]
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

/** 保存载荷组装：空材料行过滤；未选产品/工序以 0 占位（工序空已被保存前拦截，0 仅为类型兜底） */
function buildPayload(h: EditorHeader, nodes: EditorNode[], edges: EditorEdge[]): RoutingPayload {
  return {
    product_id: h.product_id ?? 0,
    version: h.version,
    quantity: h.quantity,
    status: h.status,
    remark: h.remark || null,
    nodes: nodes.map((n) => ({
      node_no: n.node_no,
      process_id: n.process_id ?? 0,
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

/** 保 存：本地环预检 + 未配工序拦截 + 表头 el-form 校验通过才调接口；新建/编辑按 routingId 分流 */
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
  // 提交前统一 el-form 校验（D-17）：成品/版本/基准数量在前端拦截，避免发出可预期的 422 请求
  const valid = await headerFormRef.value?.validate().catch(() => false)
  if (!valid) return
  if (editorNodes.value.length === 0) return ElMessage.warning('请先添加工序节点')
  // 拖拽/双击创建的空节点拦截：后端 nodes.*.process_id 必填（exists 校验），前端先行提示
  const unassigned = editorNodes.value.filter((n) => n.process_id == null)
  if (unassigned.length > 0)
    return ElMessage.warning(`节点「${unassigned.map((n) => n.node_no).join('、')}」未选择工序`)
  saving.value = true
  try {
    const payload = buildPayload(header, editorNodes.value, editorEdges.value)
    if (props.routingId) await routingApi.update(props.routingId, payload)
    else await routingApi.create(payload)
    ElMessage.success('保存成功')
    emit('saved')
  } catch (e) {
    ElMessage.error((e as Error).message)
  } finally {
    saving.value = false
  }
}

function cancel() {
  emit('cancel')
}
</script>

<style scoped>
/* 编辑体：标题 + 表头行 + 左画布右面板两栏 + 底部工具条（页面卡片内纵向排布） */
.rc-editor {
  display: flex;
  flex-direction: column;
  gap: var(--sp-3);
}
.rc-topline {
  display: flex;
  align-items: center;
}
.rc-title {
  font-size: 16px;
  font-weight: 600;
  color: var(--t1);
}
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
/* Z-1 降级提示条：替代画布两栏区域展示，占满宽度 */
.rc-degrade {
  flex: 1;
}
/* 画布容器：固定高度 + 相对定位承载边缘把手与空态提示 */
.rc-canvas {
  position: relative;
  height: 520px;
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
/* 节点四边中点拖出建节点把手：绝对定位于卡片边线中点、微凸出体外（rn-card 为定位基准），
   16px 紧凑尺寸对齐连线 Handle 热区；hover/拖拽反色，样式与连线 Handle 圆点（左右中点）
   重叠时以连线圆点优先（见下方 z-index 修正） */
.ns-spawn {
  position: absolute;
  width: 16px;
  height: 16px;
  display: flex;
  align-items: center;
  justify-content: center;
  border-radius: var(--r-full);
  background: var(--surface);
  border: 1px solid var(--a-600);
  color: var(--a-600);
  font-size: 11px;
  line-height: 1;
  cursor: grab;
  user-select: none;
  touch-action: none;
  z-index: 5;
  pointer-events: auto;
  transition:
    background-color 0.15s ease,
    color 0.15s ease,
    transform 0.15s ease,
    box-shadow 0.15s ease;
}
.ns-spawn:hover {
  transform: scale(1.18);
  background: var(--a-600);
  color: var(--surface);
  box-shadow: 0 0 0 3px var(--a-600-12);
}
.ns-spawn.is-dragging {
  cursor: grabbing;
  background: var(--a-600);
  color: var(--surface);
  transform: scale(1.18);
}
.ns-spawn[data-side='top'] {
  top: -6px;
  left: calc(50% - 8px);
}
.ns-spawn[data-side='bottom'] {
  bottom: -6px;
  left: calc(50% - 8px);
}
.ns-spawn[data-side='left'] {
  left: -6px;
  top: calc(50% - 8px);
}
.ns-spawn[data-side='right'] {
  right: -6px;
  top: calc(50% - 8px);
}
/* 左/右中点把手与连线 Handle 圆点同位重叠：圆点 z-index 置高，保证拖拽连线仍以圆点为热区
   （把手热区为圆点外圈，视觉上有叠加，属已知取舍） */
.rn-card :deep(.vue-flow__handle) {
  z-index: 6;
}
/* 右侧配置面板：分区标题 + 行间距放宽，缓解拥挤 */
.rc-panel {
  width: 360px;
  flex: none;
  border: 1px solid var(--border);
  border-radius: var(--r-md);
  padding: var(--sp-4);
  overflow-y: auto;
  max-height: 560px;
}
.panel-empty {
  color: var(--t3);
  padding: var(--sp-5) 0;
  text-align: center;
}
.panel-form :deep(.el-form-item) {
  margin-bottom: var(--sp-4);
}
.panel-sep {
  margin: var(--sp-4) 0 var(--sp-3);
  padding-top: var(--sp-3);
  border-top: 1px solid var(--p-100);
  color: var(--t3);
  font-size: 12px;
  font-weight: 600;
}
.panel-sep:first-child {
  margin-top: 0;
  border-top: none;
  padding-top: 0;
}
.panel-remove {
  width: 100%;
  margin-top: var(--sp-1);
}
.mat-rows {
  width: 100%;
  display: flex;
  flex-direction: column;
  gap: var(--sp-3);
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
/* 画布节点卡片：分层展示（工序/材料/产出），说明文字 10px 弱色小字、内容 10~11px 比说明大一号，
   整体字号在上一版基础上再减一档；委外琥珀描边 + 角标；选中态强调色描边；
   入场淡入缩放动效（新节点 mount 触发一次，编辑回显全部节点同时播放）；
   拖拽中（Vue Flow 在节点 wrapper 加 .dragging）卡片微放大 + 阴影，与选中态共用过渡 */
.rn-card {
  position: relative;
  min-width: 132px;
  max-width: 190px;
  padding: 4px 8px;
  background: var(--surface);
  border: 1.5px solid var(--border-strong);
  border-radius: var(--r-md);
  box-shadow: var(--sh-sm);
  font-size: 11px;
  cursor: pointer;
  animation: rn-in 0.22s ease-out;
  transition:
    border-color 0.18s ease,
    box-shadow 0.18s ease,
    transform 0.18s ease;
}
@keyframes rn-in {
  from {
    opacity: 0;
    transform: translateY(3px) scale(0.94);
  }
  to {
    opacity: 1;
    transform: none;
  }
}
.rn-card.is-selected {
  border-color: var(--a-600);
  box-shadow: 0 0 0 3px var(--a-600-12);
}
.rn-card.is-outsourced {
  border-color: var(--warn);
}
.vue-flow__node.dragging .rn-card {
  border-color: var(--a-600);
  box-shadow: var(--sh-md);
  transform: scale(1.03);
}
/* 分区说明文字：10px 弱色小字，与内容（11px）区分层级 */
.rn-label {
  font-size: 10px;
  color: var(--t3);
  line-height: 1.4;
  flex: none;
}
/* 工序行：说明与内容同行基线对齐，内容为主视觉 */
.rn-title {
  display: flex;
  align-items: baseline;
  gap: 4px;
  font-weight: 600;
  color: var(--t1);
  line-height: 1.5;
}
.rn-title-text {
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
/* 节点号次要化：弱色小字，突出工序名主体 */
.rn-no {
  color: var(--t3);
  font-weight: 400;
  font-size: 10px;
}
/* 材料/产出块：说明独占一行，内容竖排罗列 */
.rn-section {
  margin-top: 2px;
}
.rn-list {
  margin-top: 1px;
}
.rn-mat {
  color: var(--t2);
  font-size: 10px;
  line-height: 1.5;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.rn-out {
  color: var(--ok);
  font-size: 10px;
  line-height: 1.5;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.rn-badge {
  position: absolute;
  top: -8px;
  right: 6px;
  padding: 0 5px;
  border-radius: var(--r-full);
  background: var(--warn);
  color: var(--surface);
  font-size: 10px;
  line-height: 16px;
}
/* 下拉选项行：编码名称左对齐、类型标签右对齐（标签不参与选中值文本） */
.opt-line {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--sp-2);
}
.opt-tag {
  flex: none;
}
/* 新连线淡入：边元素 mount 时播放一次，不干扰连线选中高亮 */
.vue-flow__edge .vue-flow__edge-path {
  animation: edge-in 0.25s ease-out;
}
@keyframes edge-in {
  from {
    opacity: 0;
  }
  to {
    opacity: 1;
  }
}
/* 动效可访问性：系统偏好减少动态时关闭全部画布动效 */
@media (prefers-reduced-motion: reduce) {
  .rn-card,
  .ns-spawn,
  .vue-flow__edge .vue-flow__edge-path {
    animation: none;
    transition: none;
  }
}
</style>
