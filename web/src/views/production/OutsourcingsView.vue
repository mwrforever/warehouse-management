<!-- 委外加工页：筛选列表 + 新建/编辑弹窗（工单→委外工序节点预填：回收品只读/组件应发=数量×单位用量/数量≤节点剩余 1520）
     发出扣库存（1522 失败红色提示）+ 回收弹窗（剩余可回收/回收品只读/独立入库仓库库位）+
     余料退回弹窗（可退=已发−已退）+ 回收/退回记录弹窗 -->
<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  productionApi,
  type OutsourcingItem,
  type OutsourcingPrefill,
  type OutsourcingReceiptRecord,
  type OutsourcingReturnRecord,
  type ProductionOperation,
} from '../../api/production'
import { supplierApi, type SupplierItem } from '../../api/supplier'
import { warehouseApi, type LocationItem, type WarehouseItem } from '../../api/warehouse'
import ListFilterBar from '../../components/ListFilterBar.vue'
import { useListQuery } from '../../composables/useListQuery'
import { useRemoteOptions } from '../../composables/useRemoteOptions'
import { useAuthStore } from '../../stores/auth'
import { formatThousand } from '../../utils/format'

const auth = useAuthStore()
const route = useRoute()
const saving = ref(false)
const suppliers = ref<SupplierItem[]>([])
const warehouses = ref<WarehouseItem[]>([])
const locations = ref<LocationItem[]>([])

// 工单下拉选项（BF-3 remote）：label 单号+成品；仅 status=2 生产中工单可委外
interface OrderOption {
  id: number
  no: string
  product_name: string
}

// 工单下拉（BF-3）：生产中工单超 100 条后以单号关键字服务端搜索，初载保留前 100 条
const {
  options: orders,
  loading: ordersLoading,
  load: loadOrders,
  search: searchOrders,
  pin: pinOrder,
  reset: resetOrders,
} = useRemoteOptions<OrderOption>({
  fetch: (kw) =>
    productionApi.orders({ status: 2, per_page: 100, keyword: kw }).then((r) => r.items),
  keyOf: (o) => o.id,
  onError: (e) => ElMessage.error(e.message),
})
// 当前工单的委外工序（仅 is_outsourced=1 且未完成的节点可选，label 含节点号与产出）
const processOptions = ref<ProductionOperation[]>([])
// 选中工序的节点预填（组件清单/回收品/剩余可委外量）
const prefill = ref<OutsourcingPrefill | null>(null)
// 发料组件行视图模型（预填行 + 行内可调应发）
interface PrefillRow {
  material_id: number
  material_name: string
  material_code: string
  qty_per_unit: number
  required_qty: number
  unit_id: number
  unit_name: string
  stock: number
}
const itemRows = ref<PrefillRow[]>([])

// 数量精度工具：前端 Number 口径四舍五入 2 位（后端口径 bcmath 权威，超限 422 兜底）
function round2(n: number): number {
  return Math.round(n * 100) / 100
}

// 列表查询状态（统一组合式：防抖加载/查询/重置/刷新，请求序号守卫并发）
const { query, list, total, loading, load, search, reset, refresh } = useListQuery({
  defaultQuery: { keyword: '', status: undefined },
  fetch: (q) => productionApi.outsourcings(q),
  onError: (e) => ElMessage.error(e.message),
})

// 工序网络「打开委外页」跳转携带 ?keyword=单号：setup 期预填筛选实现按单号定位（BF-1）。
// 必须在挂载前赋值——ListFilterBar 以 props.keyword 为内部防抖源初始值，挂载后再赋值会触发
// 其 300ms 防抖链回发一次重复查询；onMounted 的 search() 即携带单号出参。重置仍回空串（defaultQuery）
const routeKeyword = route.query.keyword
if (routeKeyword) query.keyword = String(routeKeyword)

// 新建/编辑弹窗状态
const dialogVisible = ref(false)
const editingId = ref<number | null>(null) // 当前编辑草稿 id（null 表示新建）
const form = reactive({
  order_id: undefined as number | undefined,
  operation_id: undefined as number | undefined,
  supplier_id: undefined as number | undefined,
  warehouse_id: undefined as number | undefined,
  location_id: undefined as number | undefined,
  quantity: undefined as number | undefined,
  remark: '',
})

// 回收弹窗状态（剩余可回收 = 委外量 - 已回收累计；入库仓库/库位独立选择；回收品只读）
const receiptVisible = ref(false)
const receiptId = ref<number | null>(null)
const receiptRemaining = ref(0)
const receiptOutput = ref('')
const receiptLocations = ref<LocationItem[]>([])
const receiptForm = reactive({
  quantity: undefined as number | undefined,
  warehouse_id: undefined as number | undefined,
  location_id: undefined as number | undefined,
  remark: '',
})

// 余料退回弹窗状态（明细行可退 = 组件已发 − 已退；入库仓库/库位独立选择）
const returnVisible = ref(false)
const returnId = ref<number | null>(null)
// 退回明细行（return_qty 行内输入；可退为 0 的行不渲染）
interface ReturnRow {
  item_id: number
  material_name: string
  issued_qty: number
  returned_qty: number
  remaining: number
  return_qty: number
}
const returnRows = ref<ReturnRow[]>([])
const returnLocations = ref<LocationItem[]>([])
const returnForm = reactive({
  warehouse_id: undefined as number | undefined,
  location_id: undefined as number | undefined,
  remark: '',
})

// 回收/退回记录弹窗状态（共用容器，标题区分）
const recordsVisible = ref(false)
const recordsTitle = ref('回收记录')
const receiptRecords = ref<OutsourcingReceiptRecord[]>([])
const returnRecords = ref<OutsourcingReturnRecord[]>([])

// 委外状态标签语义色（草稿灰/已审核蓝/已回收绿/已关闭橙）
function statusTagType(status: number) {
  if (status === 0) return 'info'
  if (status === 1) return 'primary'
  if (status === 3) return 'warning'
  return 'success'
}

// 会话序号守卫（评审 F5，模式同 RoutingCanvasDialog）：工序预填/编辑回填为异步落点，
// 快速切换工序/关窗重开时旧会话的慢响应必须丢弃（防 A 的迟到响应覆盖 B 已回填的编辑态）
let sessionSeq = 0

// 关窗即作废在途：任一弹窗关闭后迟到的预填/详情/流水响应禁止回写与重开弹窗
//（弹窗已关，回写无意义且污染重开时的 reset）
// 新建/编辑弹窗开关同时是工单下拉的 remote 会话边界（BF-3）：打开初载前 100 条，关闭清空选项与 pin 集
watch(dialogVisible, (open) => {
  if (!open) sessionSeq++
  if (open) {
    loadOrders()
  } else {
    resetOrders()
  }
})
watch(returnVisible, (open) => {
  if (!open) sessionSeq++
})
watch(recordsVisible, (open) => {
  if (!open) sessionSeq++
})

// 选工单 → 加载该工单委外工序（下拉数据源）
async function onOrderChange(orderId: number | undefined, session: number = ++sessionSeq) {
  form.operation_id = undefined
  processOptions.value = []
  prefill.value = null
  itemRows.value = []
  if (!orderId) return
  try {
    const d = await productionApi.orderDetail(orderId)
    // 迟到守卫：会话已作废（工序切换/关窗重开）时丢弃过期下拉回写
    if (session !== sessionSeq) return
    // 工单回显 pin：详情携带单号/成品名，工单可能不在下拉前 100 条内，不 pin 则下拉只显示裸 id
    pinOrder({ id: d.id, no: d.no, product_name: d.product_name })
    // 委外对象=工艺路线节点：仅 is_outsourced=1 且未完成（status 2 已完成）的节点可委外（spec 5 §4 规则定义）
    processOptions.value = d.operations.filter((op) => op.is_outsourced === 1 && op.status !== 2)
  } catch (e) {
    if (session !== sessionSeq) return
    ElMessage.error((e as Error).message)
    form.order_id = undefined
  }
}

// 选中委外工序 → 拉节点预填：回收品只读 + 组件行（应发基数）+ 剩余可委外量
async function onOperationChange(opId: number | undefined, session: number = ++sessionSeq) {
  prefill.value = null
  itemRows.value = []
  if (!opId) return
  try {
    const p = await productionApi.fromOperation(opId)
    // 迟到守卫：会话已作废（已切到其它工序/关窗重开）时丢弃过期预填，防覆盖新工序回填
    if (session !== sessionSeq) return
    prefill.value = p
    itemRows.value = p.items.map((it) => ({
      material_id: it.material_id,
      material_name: it.material_name,
      material_code: it.material_code,
      // 单位用量/库存后端 decimal 字符串形态（bcmath 权威），前端 Number 归一参与折算
      qty_per_unit: Number(it.qty_per_unit),
      required_qty: 0,
      unit_id: it.unit_id,
      unit_name: it.unit_name,
      stock: Number(it.stock),
    }))
    // 数量已填（编辑回填场景）时直接按当前数量折算应发
    if (form.quantity != null) recomputeRows()
  } catch (e) {
    if (session !== sessionSeq) return
    ElMessage.error((e as Error).message)
    form.operation_id = undefined
  }
}

// 剩余可委外量（预填数据源；委外数量上限）
const remainingQty = computed(() => Number(prefill.value?.remaining_qty ?? 0))

// 应发重算：组件行 = 委外数量 × 单位用量（行内手工调整在数量变更时被折算值覆盖）
function recomputeRows() {
  if (form.quantity == null) return
  const qty = Number(form.quantity)
  for (const row of itemRows.value) {
    row.required_qty = round2(qty * row.qty_per_unit)
  }
}

// 委外数量 on-blur 校验：≤ 节点剩余计划量（1520 文案，超量回弹剩余值）+ 触发应发重算
function validateQuantity() {
  if (form.quantity == null) return
  if (Number(form.quantity) > remainingQty.value) {
    ElMessage.warning('委外数量超过节点剩余计划量')
    form.quantity = remainingQty.value
  }
  recomputeRows()
}

// 组件应发 on-blur 校验：≤ 单位用量×委外数量折算上限（后端 422 同文案，前端先回弹）
function validateItemQty(row: PrefillRow) {
  if (row.required_qty == null) return
  const cap = round2(Number(form.quantity ?? 0) * row.qty_per_unit)
  if (Number(row.required_qty) > cap) {
    ElMessage.warning('应发数量超过单位用量折算上限')
    row.required_qty = cap
  }
  if (Number(row.required_qty) < 0) row.required_qty = 0
}

// 仓库切换 → 联动库位下拉（新建/编辑弹窗）
async function onWarehouseChange(whId: number | undefined) {
  form.location_id = undefined
  locations.value = []
  if (!whId) return
  try {
    locations.value = (await warehouseApi.locations(whId)).items
  } catch (e) {
    ElMessage.error((e as Error).message)
  }
}

// 新建：清空表单（含节点预填与组件行）；路由直达时携带工单自动预填工序
function openCreate(orderId?: number) {
  editingId.value = null
  Object.assign(form, {
    order_id: undefined,
    operation_id: undefined,
    supplier_id: undefined,
    warehouse_id: undefined,
    location_id: undefined,
    quantity: undefined,
    remark: '',
  })
  prefill.value = null
  itemRows.value = []
  dialogVisible.value = true
  if (orderId) {
    form.order_id = orderId
    onOrderChange(orderId)
  }
}

// 编辑草稿：详情回填（含仓库/库位 id 与已保存组件应发）+ 节点预填同构；
// 编辑回填链（详情→工序下拉→预填→库位）共用同一会话号（子拉取不再自增），
// 快速连点编辑/关窗重开时慢响应不得覆盖新会话
async function openEdit(row: OutsourcingItem) {
  const session = ++sessionSeq
  try {
    const d = await productionApi.outsourcingDetail(row.id)
    if (session !== sessionSeq) return
    await onOrderChange(d.order_id, session)
    if (session !== sessionSeq) return
    editingId.value = row.id
    form.order_id = d.order_id
    form.operation_id = d.operation_id
    form.supplier_id = d.supplier_id
    form.warehouse_id = d.warehouse_id
    form.location_id = d.location_id
    form.quantity = Number(d.quantity)
    form.remark = d.remark ?? ''
    // 节点预填（组件行先按数量折算应发）
    await onOperationChange(d.operation_id, session)
    if (session !== sessionSeq) return
    // 草稿内手工调整过的组件应发优先（详情 items 为准，防编辑后折回默认折算值）
    const savedQty = new Map((d.items ?? []).map((i) => [i.material_id, Number(i.required_qty)]))
    for (const row of itemRows.value) {
      const saved = savedQty.get(row.material_id)
      if (saved != null) row.required_qty = saved
    }
    const locs = await warehouseApi.locations(d.warehouse_id)
    if (session !== sessionSeq) return
    locations.value = locs.items
    dialogVisible.value = true
  } catch (e) {
    if (session !== sessionSeq) return
    ElMessage.error((e as Error).message)
  }
}

// 保存：校验链（工单 → 工序 → 供应商 → 数量>0 且 ≤ 节点剩余 → 组件行 ≥1 → 仓库/库位）→ 新建/更新
async function save() {
  if (!form.order_id) {
    ElMessage.warning('请选择工单')
    return
  }
  if (!form.operation_id) {
    ElMessage.warning('请选择委外工序')
    return
  }
  if (!form.supplier_id) {
    ElMessage.warning('请选择供应商')
    return
  }
  if (form.quantity == null || Number(form.quantity) <= 0) {
    ElMessage.warning('委外数量必须大于 0')
    return
  }
  if (Number(form.quantity) > remainingQty.value) {
    ElMessage.warning('委外数量超过节点剩余计划量')
    return
  }
  // 未触发过数量 blur（如输入后直接点保存）时按当前数量兜底重算，防组件行残留折算旧值
  if (
    form.quantity != null &&
    itemRows.value.length > 0 &&
    itemRows.value.every((r) => Number(r.required_qty) <= 0)
  ) {
    recomputeRows()
  }
  // 过滤 0 行：空组件行不随单提交（后端 min:1 由非空行满足）
  const items = itemRows.value
    .filter((r) => Number(r.required_qty) > 0)
    .map((r) => ({
      material_id: r.material_id,
      required_qty: round2(Number(r.required_qty)),
      unit_id: r.unit_id,
    }))
  if (items.length === 0) {
    ElMessage.warning('至少需要一个发料组件')
    return
  }
  if (!form.warehouse_id || !form.location_id) {
    ElMessage.warning('仓库与库位不能为空')
    return
  }
  const payload = {
    order_id: form.order_id,
    operation_id: form.operation_id,
    supplier_id: form.supplier_id,
    warehouse_id: form.warehouse_id,
    location_id: form.location_id,
    quantity: Number(form.quantity),
    items,
    remark: form.remark,
  }
  saving.value = true
  try {
    if (editingId.value) {
      await productionApi.updateOutsourcing(editingId.value, payload)
    } else {
      await productionApi.createOutsourcing(payload)
    }
    ElMessage.success('保存成功')
    dialogVisible.value = false
    refresh()
  } catch (e) {
    ElMessage.error((e as Error).message)
  } finally {
    saving.value = false
  }
}

async function removeRowAction(row: OutsourcingItem) {
  try {
    await ElMessageBox.confirm(`确认删除委外单 ${row.no}？删除后不可恢复`, '提示', {
      type: 'warning',
      confirmButtonText: '确 定',
      cancelButtonText: '取 消',
    })
  } catch {
    return
  }
  try {
    await productionApi.deleteOutsourcing(row.id)
    ElMessage.success('删除成功')
    refresh()
  } catch (e) {
    ElMessage.error((e as Error).message)
  }
}

// 发出（审核）：确认后库存将减少（失败 1522 等：http 层红色提示后端精确消息含商品编码）
async function approveRow(row: OutsourcingItem) {
  try {
    await ElMessageBox.confirm('确认发出委外物料？库存将减少', '提示', {
      type: 'warning',
      confirmButtonText: '确 定',
      cancelButtonText: '取 消',
    })
  } catch {
    return
  }
  try {
    await productionApi.approveOutsourcing(row.id)
    ElMessage.success('发出成功')
    refresh()
  } catch (e) {
    ElMessage.error((e as Error).message)
  }
}

// 回收弹窗打开：直接消费列表行字段（数量/已回收/回收品三字段 index 均返回），省一次委外详情请求
//（详情是委外域最重读路径：4 组关系预载 + SUM）。列表行滞后仅致预填偏大，提交有后端 1524 超收校验兜底；
// 剩余可回收 = 委外量 - 已回收，默认全量回收
function openReceipt(row: OutsourcingItem) {
  receiptId.value = row.id
  // received_qty 类型可选（历史行兜底 0），output_product_name 无路线历史单为空显示 —
  receiptRemaining.value = round2(Number(row.quantity) - Number(row.received_qty ?? 0))
  receiptOutput.value = row.output_product_name ?? '—'
  Object.assign(receiptForm, {
    quantity: receiptRemaining.value,
    warehouse_id: undefined,
    location_id: undefined,
    remark: '',
  })
  receiptLocations.value = []
  receiptVisible.value = true
}

// 回收入库仓库切换 → 联动库位下拉（与发出仓库独立选择）
async function onReceiptWarehouseChange(whId: number | undefined) {
  receiptForm.location_id = undefined
  receiptLocations.value = []
  if (!whId) return
  try {
    receiptLocations.value = (await warehouseApi.locations(whId)).items
  } catch (e) {
    ElMessage.error((e as Error).message)
  }
}

// 回收量 on-blur 校验：≤ 剩余可回收（1524 文案，超量回弹剩余值）
function validateReceiptQty() {
  if (receiptForm.quantity == null) return
  if (Number(receiptForm.quantity) > receiptRemaining.value) {
    ElMessage.warning('回收数量超过委外数量')
    receiptForm.quantity = receiptRemaining.value
  }
}

// 提交回收：校验链（数量>0 且 ≤ 剩余 → 入库仓库/库位）→ 创建即审核回收单 → 状态转已回收
async function submitReceipt() {
  if (!receiptId.value) return
  if (receiptForm.quantity == null || Number(receiptForm.quantity) <= 0) {
    ElMessage.warning('回收数量必须大于 0')
    return
  }
  if (Number(receiptForm.quantity) > receiptRemaining.value) {
    ElMessage.warning('回收数量超过委外数量')
    return
  }
  if (!receiptForm.warehouse_id || !receiptForm.location_id) {
    ElMessage.warning('仓库与库位不能为空')
    return
  }
  saving.value = true
  try {
    await productionApi.receiptOutsourcing(receiptId.value, {
      quantity: receiptForm.quantity,
      warehouse_id: receiptForm.warehouse_id,
      location_id: receiptForm.location_id,
      remark: receiptForm.remark,
    })
    ElMessage.success('回收成功')
    receiptVisible.value = false
    refresh()
  } catch (e) {
    ElMessage.error((e as Error).message)
  } finally {
    saving.value = false
  }
}

// 退回余料弹窗打开：详情组件行 → 可退 = 已发 − 已退（已退满的行不展示）
// 会话序号守卫（BF-2）：快速连点两行退回/关窗重开时旧单慢详情必须丢弃——
// 防 A 的迟到明细覆盖 B 单语境，用户在「B 单语境」弹窗里提交实际退回 A 单
async function openReturn(row: OutsourcingItem) {
  const session = ++sessionSeq
  try {
    const d = await productionApi.outsourcingDetail(row.id)
    if (session !== sessionSeq) return
    if (!d.items?.length) {
      ElMessage.warning('该委外单无发料组件，不可退回')
      return
    }
    returnId.value = row.id
    returnRows.value = d.items
      .map((i) => ({
        item_id: i.id,
        material_name: i.material_name,
        issued_qty: Number(i.issued_qty),
        returned_qty: Number(i.returned_qty),
        // 可退 = 已发 − 已退（后端 bcmath 归一，前端 Number 口径）
        remaining: round2(Number(i.issued_qty) - Number(i.returned_qty)),
        return_qty: 0,
      }))
      .filter((r) => r.remaining > 0)
    Object.assign(returnForm, {
      warehouse_id: undefined,
      location_id: undefined,
      remark: '',
    })
    returnLocations.value = []
    returnVisible.value = true
  } catch (e) {
    // 过期会话的失败不提示：新会话已接管，旧单报错会打扰当前操作
    if (session !== sessionSeq) return
    ElMessage.error((e as Error).message)
  }
}

// 退回量 on-blur 校验：≤ 可退（超退 422 文案，超量回弹可退值）
function validateReturnQty(row: ReturnRow) {
  if (row.return_qty == null) return
  if (Number(row.return_qty) > row.remaining) {
    ElMessage.warning('退回数量超过已发未退数量')
    row.return_qty = row.remaining
  }
  if (Number(row.return_qty) < 0) row.return_qty = 0
}

// 退回入库仓库切换 → 联动库位下拉（独立选择）
async function onReturnWarehouseChange(whId: number | undefined) {
  returnForm.location_id = undefined
  returnLocations.value = []
  if (!whId) return
  try {
    returnLocations.value = (await warehouseApi.locations(whId)).items
  } catch (e) {
    ElMessage.error((e as Error).message)
  }
}

// 提交退回：校验链（至少一行退回量>0 → 逐行 ≤ 可退 → 入库仓库/库位）→ 创建即审核退回单 → 刷新
async function submitReturn() {
  if (!returnId.value) return
  const lines = returnRows.value
    .filter((r) => Number(r.return_qty) > 0)
    .map((r) => ({ item_id: r.item_id, quantity: round2(Number(r.return_qty)) }))
  if (lines.length === 0) {
    ElMessage.warning('请填写退回数量')
    return
  }
  if (
    lines.some((line) => {
      const row = returnRows.value.find((r) => r.item_id === line.item_id)
      return row ? line.quantity > row.remaining : true
    })
  ) {
    ElMessage.warning('退回数量超过已发未退数量')
    return
  }
  if (!returnForm.warehouse_id || !returnForm.location_id) {
    ElMessage.warning('仓库与库位不能为空')
    return
  }
  saving.value = true
  try {
    await productionApi.createOutsourcingReturn(returnId.value, {
      items: lines,
      warehouse_id: returnForm.warehouse_id,
      location_id: returnForm.location_id,
      remark: returnForm.remark,
    })
    ElMessage.success('退回成功')
    returnVisible.value = false
    // 全部组件退回后委外单自动关闭（status 3），刷新列表同步
    refresh()
  } catch (e) {
    ElMessage.error((e as Error).message)
  } finally {
    saving.value = false
  }
}

// 回收记录弹窗：按委外单加载回收流水（会话序号守卫 BF-2：快速连点两行时旧单慢流水丢弃）
async function openReceipts(row: OutsourcingItem) {
  const session = ++sessionSeq
  try {
    const d = await productionApi.outsourcingReceipts(row.id)
    if (session !== sessionSeq) return
    recordsTitle.value = '回收记录'
    receiptRecords.value = d.items
    recordsVisible.value = true
  } catch (e) {
    if (session !== sessionSeq) return
    ElMessage.error((e as Error).message)
  }
}

// 退回记录弹窗：按委外单加载退回流水（会话序号守卫同上）
async function openReturns(row: OutsourcingItem) {
  const session = ++sessionSeq
  try {
    const d = await productionApi.outsourcingReturns(row.id)
    if (session !== sessionSeq) return
    recordsTitle.value = '退回记录'
    returnRecords.value = d.items
    recordsVisible.value = true
  } catch (e) {
    if (session !== sessionSeq) return
    ElMessage.error((e as Error).message)
  }
}

onMounted(async () => {
  search()
  try {
    warehouses.value = (await warehouseApi.list({ per_page: 100, status: 1 })).items
    suppliers.value = (await supplierApi.list({ per_page: 100, status: 1 })).items
  } catch {
    // 下拉加载失败不阻塞主流程
  }
  // 工单列表「委 外」跳转直达：打开新建弹窗并自动预填工序
  const orderId = Number(route.query.order_id)
  if (orderId) {
    openCreate(orderId)
  }
})
</script>
<template>
  <div class="page-card">
    <ListFilterBar
      v-model:keyword="query.keyword"
      title="委外加工"
      keyword-placeholder="单号"
      @keyword-change="() => load()"
      @search="search"
      @reset="reset"
      @refresh="refresh"
    >
      <el-select
        v-model="query.status"
        placeholder="状态"
        clearable
        style="width: 120px"
        @change="() => load()"
      >
        <el-option label="草稿" :value="0" />
        <el-option label="已审核" :value="1" />
        <el-option label="已回收" :value="2" />
        <el-option label="已关闭" :value="3" />
      </el-select>
      <template #actions>
        <el-button
          v-if="auth.has('production.outsource.create')"
          class="btn-primary"
          @click="openCreate()"
          >新 建</el-button
        >
      </template>
    </ListFilterBar>

    <el-table v-loading="loading" :data="list" class="data-table">
      <el-table-column prop="no" label="单号" min-width="150" class-name="font-code" />
      <el-table-column prop="order_no" label="工单" min-width="150" class-name="font-code" />
      <el-table-column label="委外工序" min-width="160">
        <template #default="{ row }">{{ row.node_no ?? '' }}{{ row.process_name }}</template>
      </el-table-column>
      <el-table-column label="回收品" min-width="110">
        <template #default="{ row }">{{ row.output_product_name ?? '—' }}</template>
      </el-table-column>
      <el-table-column prop="supplier_name" label="供应商" min-width="140" />
      <el-table-column label="数量" align="right" width="100" class-name="font-code">
        <template #default="{ row }">{{ formatThousand(row.quantity) }}</template>
      </el-table-column>
      <el-table-column label="已回收" align="right" width="100" class-name="font-code">
        <template #default="{ row }">{{ formatThousand(row.received_qty ?? 0) }}</template>
      </el-table-column>
      <el-table-column label="状态" width="90">
        <template #default="{ row }">
          <el-tag :type="statusTagType(row.status)">{{ row.status_label }}</el-tag>
        </template>
      </el-table-column>
      <el-table-column label="操作" width="260" fixed="right">
        <template #default="{ row }">
          <!-- 草稿：编辑/删除/审核（发出）；已审核：回收/退回余料；已回收：退回余料；均可看回收/退回记录 -->
          <el-button
            v-if="row.status === 0 && auth.has('production.outsource.update')"
            link
            type="primary"
            @click="openEdit(row)"
            >编 辑</el-button
          >
          <el-button
            v-if="row.status === 0 && auth.has('production.outsource.delete')"
            link
            type="danger"
            @click="removeRowAction(row)"
            >删 除</el-button
          >
          <el-button
            v-if="row.status === 0 && auth.has('production.outsource.update')"
            link
            type="success"
            @click="approveRow(row)"
            >审 核</el-button
          >
          <el-button
            v-if="row.status === 1 && auth.has('production.outsource.update')"
            link
            type="primary"
            @click="openReceipt(row)"
            >回 收</el-button
          >
          <el-button
            v-if="(row.status === 1 || row.status === 2) && auth.has('production.outsource.update')"
            link
            type="warning"
            @click="openReturn(row)"
            >退回余料</el-button
          >
          <el-button link type="primary" @click="openReceipts(row)">回收记录</el-button>
          <el-button link type="primary" @click="openReturns(row)">退回记录</el-button>
        </template>
      </el-table-column>
    </el-table>
    <div class="pager">
      <el-pagination
        v-model:current-page="query.page"
        :page-size="query.per_page"
        :total="total"
        layout="total, prev, pager, next"
        @current-change="refresh"
      />
    </div>

    <!-- 新建/编辑弹窗：工单 → 委外工序（节点预填：回收品只读 + 组件表格）→ 供应商/数量 → 仓库/库位 -->
    <el-dialog
      v-model="dialogVisible"
      :title="editingId ? '编辑委外单' : '新 建委外单'"
      width="900px"
      :close-on-click-modal="false"
    >
      <el-form label-width="90px">
        <div class="form-grid">
          <el-form-item label="工单" required>
            <el-select
              v-model="form.order_id"
              placeholder="输入单号搜索生产中工单"
              filterable
              remote
              :remote-method="searchOrders"
              :loading="ordersLoading"
              style="width: 100%"
              @change="onOrderChange"
            >
              <el-option
                v-for="o in orders"
                :key="o.id"
                :label="`${o.no}（${o.product_name}）`"
                :value="o.id"
              />
            </el-select>
          </el-form-item>
          <el-form-item label="委外工序" required>
            <el-select
              v-model="form.operation_id"
              placeholder="选择工序"
              style="width: 100%"
              :disabled="!form.order_id"
              @change="onOperationChange"
            >
              <el-option
                v-for="op in processOptions"
                :key="op.id"
                :label="`${op.node_no ?? op.seq}. ${op.process_name}（产出：${op.output_product_name ?? '—'}）`"
                :value="op.id"
              />
            </el-select>
          </el-form-item>
          <el-form-item label="供应商" required>
            <el-select
              v-model="form.supplier_id"
              placeholder="选择供应商"
              filterable
              style="width: 100%"
            >
              <el-option
                v-for="s in suppliers"
                :key="s.id"
                :label="`${s.name}（${s.code}）`"
                :value="s.id"
              />
            </el-select>
          </el-form-item>
          <el-form-item label="数量" required>
            <el-input-number
              v-model="form.quantity"
              :min="0"
              :precision="2"
              :controls="false"
              placeholder="委外数量"
              style="width: 100%"
              @blur="validateQuantity"
              @change="validateQuantity"
            />
          </el-form-item>
          <el-form-item label="仓库" required>
            <el-select
              v-model="form.warehouse_id"
              placeholder="选择仓库"
              style="width: 100%"
              @change="onWarehouseChange"
            >
              <el-option v-for="w in warehouses" :key="w.id" :label="w.name" :value="w.id" />
            </el-select>
          </el-form-item>
          <el-form-item label="库位" required>
            <el-select
              v-model="form.location_id"
              placeholder="选择库位"
              style="width: 100%"
              :disabled="!form.warehouse_id"
            >
              <el-option v-for="l in locations" :key="l.id" :label="l.name" :value="l.id" />
            </el-select>
          </el-form-item>
          <el-form-item label="备注">
            <el-input v-model="form.remark" maxlength="200" />
          </el-form-item>
        </div>
        <!-- 工序选中后的节点预填区：回收品只读 + 剩余可委外量 + 组件行（应发=数量×单位用量，行内可调） -->
        <div v-if="prefill" class="prefill-block">
          <div class="prefill-meta">
            <span class="prefill-label"
              >回收品：<span class="prefill-product">{{ prefill.output_product_name }}</span></span
            >
            <span class="prefill-label"
              >可用量：<span class="remain-cell">{{ formatThousand(remainingQty) }}</span></span
            >
          </div>
          <el-table :data="itemRows" size="small" class="data-table item-table">
            <el-table-column label="物料" min-width="180">
              <template #default="{ row }"
                >{{ row.material_name }} {{ row.material_code }}</template
              >
            </el-table-column>
            <el-table-column label="应发数量" width="160">
              <template #default="{ row }">
                <el-input-number
                  v-model="row.required_qty"
                  :min="0"
                  :precision="2"
                  :controls="false"
                  placeholder="应发数量"
                  size="small"
                  @blur="validateItemQty(row)"
                  @change="validateItemQty(row)"
                />
              </template>
            </el-table-column>
            <el-table-column prop="unit_name" label="单位" width="80" />
            <el-table-column label="可用库存" align="right" width="110" class-name="font-code">
              <template #default="{ row }">{{ formatThousand(row.stock) }}</template>
            </el-table-column>
          </el-table>
        </div>
      </el-form>
      <template #footer>
        <el-button @click="dialogVisible = false">取 消</el-button>
        <el-button class="btn-primary" :loading="saving" @click="save">保 存</el-button>
      </template>
    </el-dialog>

    <!-- 回收弹窗：回收品只读 + 剩余可回收展示 + 回收量（≤ 剩余）+ 入库仓库/库位（独立选择） -->
    <el-dialog
      v-model="receiptVisible"
      title="委外回收"
      width="560px"
      :close-on-click-modal="false"
    >
      <el-form label-width="90px">
        <el-form-item label="回收品">
          <span class="receipt-product">{{ receiptOutput }}</span>
        </el-form-item>
        <el-form-item label="剩余可回收">
          <span class="remain-cell">{{ formatThousand(receiptRemaining) }}</span>
        </el-form-item>
        <el-form-item label="回收数量" required>
          <el-input-number
            v-model="receiptForm.quantity"
            :min="0"
            :precision="2"
            :controls="false"
            :max="receiptRemaining"
            style="width: 100%"
            @blur="validateReceiptQty"
          />
        </el-form-item>
        <el-form-item label="入库仓库" required>
          <el-select
            v-model="receiptForm.warehouse_id"
            placeholder="选择仓库"
            style="width: 100%"
            @change="onReceiptWarehouseChange"
          >
            <el-option v-for="w in warehouses" :key="w.id" :label="w.name" :value="w.id" />
          </el-select>
        </el-form-item>
        <el-form-item label="入库库位" required>
          <el-select
            v-model="receiptForm.location_id"
            placeholder="选择库位"
            style="width: 100%"
            :disabled="!receiptForm.warehouse_id"
          >
            <el-option v-for="l in receiptLocations" :key="l.id" :label="l.name" :value="l.id" />
          </el-select>
        </el-form-item>
        <el-form-item label="备注">
          <el-input v-model="receiptForm.remark" maxlength="200" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="receiptVisible = false">取 消</el-button>
        <el-button class="btn-primary" :loading="saving" @click="submitReceipt"
          >提 交回收</el-button
        >
      </template>
    </el-dialog>

    <!-- 余料退回弹窗：组件明细（已发/已退/可退）+ 退回量（≤ 可退）+ 入库仓库/库位（独立选择） -->
    <el-dialog v-model="returnVisible" title="余料退回" width="720px" :close-on-click-modal="false">
      <el-form label-width="90px">
        <el-table :data="returnRows" size="small" class="data-table">
          <el-table-column prop="material_name" label="物料" min-width="140" />
          <el-table-column label="已发" align="right" width="90" class-name="font-code">
            <template #default="{ row }">{{ formatThousand(row.issued_qty) }}</template>
          </el-table-column>
          <el-table-column label="已退" align="right" width="90" class-name="font-code">
            <template #default="{ row }">{{ formatThousand(row.returned_qty) }}</template>
          </el-table-column>
          <el-table-column label="可退" align="right" width="90" class-name="font-code">
            <template #default="{ row }">{{ formatThousand(row.remaining) }}</template>
          </el-table-column>
          <el-table-column label="退回量" width="150">
            <template #default="{ row }">
              <el-input-number
                v-model="row.return_qty"
                :min="0"
                :precision="2"
                :controls="false"
                size="small"
                @blur="validateReturnQty(row)"
                @change="validateReturnQty(row)"
              />
            </template>
          </el-table-column>
        </el-table>
        <el-form-item label="入库仓库" required>
          <el-select
            v-model="returnForm.warehouse_id"
            placeholder="选择仓库"
            style="width: 100%"
            @change="onReturnWarehouseChange"
          >
            <el-option v-for="w in warehouses" :key="w.id" :label="w.name" :value="w.id" />
          </el-select>
        </el-form-item>
        <el-form-item label="入库库位" required>
          <el-select
            v-model="returnForm.location_id"
            placeholder="选择库位"
            style="width: 100%"
            :disabled="!returnForm.warehouse_id"
          >
            <el-option v-for="l in returnLocations" :key="l.id" :label="l.name" :value="l.id" />
          </el-select>
        </el-form-item>
        <el-form-item label="备注">
          <el-input v-model="returnForm.remark" maxlength="200" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="returnVisible = false">取 消</el-button>
        <el-button class="btn-primary" :loading="saving" @click="submitReturn">提交退回</el-button>
      </template>
    </el-dialog>

    <!-- 回收/退回记录弹窗：按委外单加载对应流水 -->
    <el-dialog v-model="recordsVisible" :title="recordsTitle" width="700px">
      <el-table
        v-if="recordsTitle === '回收记录'"
        :data="receiptRecords"
        size="small"
        class="data-table"
      >
        <el-table-column prop="no" label="回收单号" min-width="150" class-name="font-code" />
        <el-table-column
          prop="quantity"
          label="数量"
          align="right"
          width="100"
          class-name="font-code"
        />
        <el-table-column prop="warehouse_name" label="仓库" min-width="100" />
        <el-table-column prop="location_name" label="库位" min-width="100" />
        <el-table-column prop="received_at" label="回收时间" width="160" />
        <el-table-column prop="operator" label="操作人" min-width="90">
          <template #default="{ row }">{{ row.operator ?? '—' }}</template>
        </el-table-column>
      </el-table>
      <el-table v-else :data="returnRecords" size="small" class="data-table">
        <el-table-column prop="no" label="退回单号" min-width="150" class-name="font-code" />
        <el-table-column prop="material_name" label="物料" min-width="140" />
        <el-table-column
          prop="quantity"
          label="数量"
          align="right"
          width="100"
          class-name="font-code"
        />
        <el-table-column prop="warehouse_name" label="仓库" min-width="100" />
        <el-table-column prop="location_name" label="库位" min-width="100" />
        <el-table-column prop="returned_at" label="退回时间" width="160" />
        <el-table-column prop="operator" label="操作人" min-width="90">
          <template #default="{ row }">{{ row.operator ?? '—' }}</template>
        </el-table-column>
      </el-table>
      <template #footer>
        <el-button @click="recordsVisible = false">关 闭</el-button>
      </template>
    </el-dialog>
  </div>
</template>
<style scoped>
/* 委外加工页样式（nexus-factory）：骨架与其他生产页面一致；生产特有样式见 pages/production.md §6 */
.page-card {
  background: var(--surface);
  border-radius: 8px;
  box-shadow: var(--shadow-sm);
  padding: var(--space-2xl);
}
.btn-primary {
  background: var(--color-accent);
  border-color: var(--color-accent);
  color: var(--surface);
}
.btn-primary:hover {
  opacity: 0.9;
}
.pager {
  display: flex;
  justify-content: flex-end;
  margin-top: var(--space-lg);
}
.form-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 0 var(--space-lg);
}
/* 节点预填区（回收品/可用量/组件表格） */
.prefill-block {
  margin-top: var(--space-md);
}
.prefill-meta {
  display: flex;
  gap: var(--space-xl);
  margin-bottom: var(--space-sm);
}
.prefill-label {
  color: var(--color-text-secondary);
}
.prefill-product {
  color: var(--color-accent);
  font-weight: 600;
}
/* 剩余可回收/可用量（Fira Code 加粗，production.md §2） */
.remain-cell {
  font-family: 'Fira Code', monospace;
  font-weight: 700;
}
/* 回收品只读展示（回收弹窗） */
.receipt-product {
  color: var(--color-accent);
  font-weight: 600;
}
</style>
