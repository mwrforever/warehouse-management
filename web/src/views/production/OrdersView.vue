<!-- 生产工单页：筛选列表 + 新建（成品+BOM 启用校验）+ BOM 展开确认 + 下达缺料警告 + 详情 tabs（物料需求/工序流转/报工记录） -->
<script setup lang="ts">
import { nextTick, onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  productionApi,
  type OperationGraphNode,
  type OperationReportRecord,
  type OutsourcingItem,
  type ProductionOrderDetail,
  type ProductionOrderItem,
  type ReleaseWarning,
} from '../../api/production'
import { bomApi } from '../../api/bom'
import { productApi, type ProductItem } from '../../api/product'
import ListFilterBar from '../../components/ListFilterBar.vue'
import OperationGraph from '../../components/OperationGraph.vue'
import { useListQuery } from '../../composables/useListQuery'
import { useAuthStore } from '../../stores/auth'
import { formatThousand, toLocalDateString } from '../../utils/format'

const auth = useAuthStore()
const router = useRouter()
const saving = ref(false)
// 成品下拉：仅成品（type=finished，生产工单只针对成品）
const products = ref<ProductItem[]>([])

// 列表查询状态（统一组合式：防抖加载/查询/重置/刷新，请求序号守卫并发）
const { query, list, total, loading, load, search, reset, refresh } = useListQuery({
  defaultQuery: { keyword: '', product_id: undefined, status: undefined },
  fetch: (q) => productionApi.orders(q),
  onError: (e) => ElMessage.error(e.message),
})

// 新建/编辑弹窗状态
const dialogVisible = ref(false)
const editing = ref(false)
const editingId = ref<number | null>(null)
const form = reactive({
  product_id: undefined as number | undefined,
  quantity: undefined as number | undefined,
  plan_date: toLocalDateString(new Date()),
  remark: '',
})
// 成品下拉实例引用（打开弹窗后聚焦）
const productSelect = ref<{ focus: () => void } | null>(null)

// BOM 展开确认弹窗（保存成功后只读展示展开结果）
const expandVisible = ref(false)
const expandData = ref<ProductionOrderDetail | null>(null)

// 下达缺料警告（琥珀警告条展示，不阻断下达）
const warningVisible = ref(false)
const releaseWarnings = ref<ReleaseWarning[]>([])

// 详情弹窗状态（报工记录 tab 按工序切换）
const detailVisible = ref(false)
const detail = ref<ProductionOrderDetail | null>(null)
const reportOpId = ref<number | null>(null)
const reportRecords = ref<OperationReportRecord[]>([])

// 工序网络「委外单」弹窗状态（委外节点按钮点击 → 按工单+工序过滤委外单列表）
const outsourceVisible = ref(false)
const outsourceOp = ref<OperationGraphNode | null>(null)
const outsourceList = ref<OutsourcingItem[]>([])
const outsourceLoading = ref(false)

// 工单五态筛选（value 与后端状态码一致）
const STATUS_OPTIONS: Record<number, string> = {
  0: '草稿',
  1: '已下达',
  2: '生产中',
  3: '已完成',
  4: '关闭',
}

// 状态标签语义色（production.md：草稿灰/已下达蓝/生产中琥珀/已完成深绿/关闭红）
function statusTagType(status: number) {
  if (status === 0) return 'info'
  if (status === 1) return 'primary'
  if (status === 2) return 'warning'
  if (status === 3) return 'success'
  return 'danger'
}

// 工序状态标签语义色（production.md：待开工灰/进行中蓝/已完成绿）
function opTagType(status: number) {
  if (status === 0) return 'info'
  if (status === 1) return 'primary'
  return 'success'
}

// 委外单状态标签语义色（与委外页一致：草稿灰/已发出蓝/已回收绿/已关闭橙）
function outsourceTagType(status: number) {
  if (status === 0) return 'info'
  if (status === 1) return 'primary'
  if (status === 3) return 'warning'
  return 'success'
}

// 会话序号守卫（BF-2，模式同 OutsourcingsView 评审 F5）：BOM 校验/编辑回填为异步落点，
// 快速切换成品/连点编辑时旧会话的慢响应必须丢弃——
// 防 A 的「无启用 BOM」迟到响应误清空 B 的选择并弹错、旧单详情覆盖新单回填
let sessionSeq = 0

// 选成品后即时校验启用 BOM（无启用版本 → 1501 文案并清空选择）
async function onProductChange(pid: number | undefined) {
  if (!pid) return
  const session = ++sessionSeq
  try {
    const res = await bomApi.list({ product_id: pid })
    // 迟到守卫：旧成品的校验结果丢弃，防误清空新选择并弹错（快速切成品 A→B）
    if (session !== sessionSeq) return
    if (!res.items.some((b) => b.status === 1)) {
      ElMessage.error('该成品没有启用版本的 BOM')
      form.product_id = undefined
    }
  } catch (e) {
    // 过期会话的失败不提示：新会话已接管
    if (session !== sessionSeq) return
    ElMessage.error((e as Error).message)
  }
}

// 新建：清空表单并聚焦成品下拉
function openCreate() {
  editing.value = false
  editingId.value = null
  Object.assign(form, {
    product_id: undefined,
    quantity: undefined,
    plan_date: toLocalDateString(new Date()),
    remark: '',
  })
  dialogVisible.value = true
  // 弹窗挂载后聚焦成品下拉（nextTick 与既有页面扫码框聚焦一致）
  nextTick(() => productSelect.value?.focus())
}

// 编辑草稿（仅草稿可编辑，详情回填）；快速连点两行编辑时旧单慢详情丢弃（BF-2 会话守卫）
async function openEdit(row: ProductionOrderItem) {
  const session = ++sessionSeq
  try {
    const d = await productionApi.orderDetail(row.id)
    if (session !== sessionSeq) return
    editing.value = true
    editingId.value = row.id
    Object.assign(form, {
      product_id: d.product_id,
      quantity: Number(d.quantity),
      plan_date: d.plan_date,
      remark: d.remark ?? '',
    })
    dialogVisible.value = true
  } catch (e) {
    // 过期会话的失败不提示：新会话已接管，旧单报错会打扰当前编辑
    if (session !== sessionSeq) return
    ElMessage.error((e as Error).message)
  }
}

// 保存：校验链（成品 → 数量>0 → 计划日期）；新建成功 → 定位新单 → 展开确认弹窗
async function save() {
  if (!form.product_id) {
    ElMessage.warning('请选择成品')
    return
  }
  if (!form.quantity || Number(form.quantity) <= 0) {
    ElMessage.warning('数量必须大于 0')
    return
  }
  if (!form.plan_date) {
    ElMessage.warning('请选择计划日期')
    return
  }
  const payload = {
    product_id: form.product_id,
    quantity: form.quantity,
    plan_date: form.plan_date,
    remark: form.remark,
  }
  saving.value = true
  try {
    if (editing.value && editingId.value) {
      await productionApi.updateOrder(editingId.value, payload)
      ElMessage.success('保存成功')
      dialogVisible.value = false
      // 编辑保存按当前页重载（与删除/下达等操作一致）：多页列表下不得把用户踢回第 1 页
      refresh()
    } else {
      const res = await productionApi.createOrder(payload)
      ElMessage.success(`工单 ${res.no} 创建成功`)
      // 创建成功语义与后续展示解耦（BF-4）：先关窗清表单——若详情拉取失败落入外层 catch，
      // 弹窗滞留+通用错误会让用户误以为创建失败而重试，造成重复建单
      dialogVisible.value = false
      Object.assign(form, {
        product_id: undefined,
        quantity: undefined,
        plan_date: toLocalDateString(new Date()),
        remark: '',
      })
      // 以创建响应 id 拉详情打开 BOM 展开弹窗（不依赖列表回查，bug #11 回归）；
      // 失败仅提示「已创建」语义：新单可从列表定位，不得诱导重试
      try {
        expandData.value = await productionApi.orderDetail(res.id)
        expandVisible.value = true
      } catch {
        ElMessage.error(`工单 ${res.no} 已创建，展开确认加载失败，可从列表查看`)
      }
      // 列表后台补充刷新（重置筛选定位新单，新草稿必在 id 倒序首页）：失败仅警告，不影响创建成功语义
      reset()
    }
  } catch (e) {
    ElMessage.error((e as Error).message)
  } finally {
    saving.value = false
  }
}

async function removeRowAction(row: ProductionOrderItem) {
  try {
    await ElMessageBox.confirm(`确认删除工单 ${row.no}？删除后不可恢复`, '提示', {
      type: 'warning',
      confirmButtonText: '确 定',
      cancelButtonText: '取 消',
    })
  } catch {
    return
  }
  try {
    await productionApi.deleteOrder(row.id)
    ElMessage.success('删除成功')
    refresh()
  } catch (e) {
    ElMessage.error((e as Error).message)
  }
}

// 下达：确认 → release → warnings 非空则琥珀警告条展示（不阻断），否则成功提示
async function releaseRow(row: ProductionOrderItem) {
  try {
    await ElMessageBox.confirm(`确认下达工单 ${row.no}？`, '提示', {
      type: 'warning',
      confirmButtonText: '确 定',
      cancelButtonText: '取 消',
    })
  } catch {
    return
  }
  try {
    const res = await productionApi.releaseOrder(row.id)
    releaseWarnings.value = res.warnings
    if (res.warnings.length) {
      warningVisible.value = true
    } else {
      ElMessage.success('下达成功')
    }
    refresh()
  } catch (e) {
    ElMessage.error((e as Error).message)
  }
}

// 开工：已下达 → 生产中（首工序置进行中）
async function startRow(row: ProductionOrderItem) {
  try {
    await ElMessageBox.confirm(`确认开工工单 ${row.no}？`, '提示', {
      type: 'warning',
      confirmButtonText: '确 定',
      cancelButtonText: '取 消',
    })
  } catch {
    return
  }
  try {
    await productionApi.startOrder(row.id)
    ElMessage.success('开工成功')
    refresh()
  } catch (e) {
    ElMessage.error((e as Error).message)
  }
}

// 完工：生产中 → 已完成（前置：所有工序完成且已有成品入库）
async function completeRow(row: ProductionOrderItem) {
  try {
    await ElMessageBox.confirm(`确认完工工单 ${row.no}？完工后不可再报工`, '提示', {
      type: 'warning',
      confirmButtonText: '确 定',
      cancelButtonText: '取 消',
    })
  } catch {
    return
  }
  try {
    await productionApi.completeOrder(row.id)
    ElMessage.success('完工成功')
    refresh()
  } catch (e) {
    ElMessage.error((e as Error).message)
  }
}

// 关闭：已完成 → 关闭
async function closeRow(row: ProductionOrderItem) {
  try {
    await ElMessageBox.confirm(`确认关闭工单 ${row.no}？`, '提示', {
      type: 'warning',
      confirmButtonText: '确 定',
      cancelButtonText: '取 消',
    })
  } catch {
    return
  }
  try {
    await productionApi.closeOrder(row.id)
    ElMessage.success('关闭成功')
    refresh()
  } catch (e) {
    ElMessage.error((e as Error).message)
  }
}

// 详情：物料需求/工序流转/报工记录（报工记录默认选中首道工序并加载）
async function openDetail(row: ProductionOrderItem) {
  try {
    detail.value = await productionApi.orderDetail(row.id)
    const ops = detail.value.operations
    reportOpId.value = ops.length ? ops[0].id : null
    if (reportOpId.value) {
      reportRecords.value = (await productionApi.operationReports(reportOpId.value)).items
    } else {
      reportRecords.value = []
    }
    detailVisible.value = true
  } catch (e) {
    ElMessage.error((e as Error).message)
  }
}

// 报工记录 tab 按工序切换加载
async function onReportOpChange(opId: number | undefined) {
  if (!opId) {
    reportRecords.value = []
    return
  }
  try {
    reportRecords.value = (await productionApi.operationReports(opId)).items
  } catch (e) {
    ElMessage.error((e as Error).message)
  }
}

// 工序网络委外节点按钮点击：按当前工单 + 委外工序过滤委外单（per_page 100 覆盖该工序全量）
async function onOutsourceClick(op: OperationGraphNode) {
  if (!detail.value) return
  outsourceOp.value = op
  outsourceVisible.value = true
  outsourceLoading.value = true
  try {
    outsourceList.value = (
      await productionApi.outsourcings({
        order_id: detail.value.id,
        operation_id: op.id,
        per_page: 100,
      })
    ).items
  } catch (e) {
    // 列表加载失败：提示并关闭弹窗，避免空态误导（与详情弹窗加载失败同语义）
    ElMessage.error((e as Error).message)
    outsourceVisible.value = false
  } finally {
    outsourceLoading.value = false
  }
}

onMounted(async () => {
  search()
  try {
    // 成品下拉：仅成品（生产工单只针对成品，per_page 100 覆盖全量）
    products.value = (await productApi.list({ per_page: 100, type: 'finished' })).items
  } catch {
    // 下拉加载失败不阻塞主流程
  }
})
</script>
<template>
  <div class="page-card">
    <ListFilterBar
      v-model:keyword="query.keyword"
      title="生产工单"
      keyword-placeholder="单号"
      @keyword-change="() => load()"
      @search="search"
      @reset="reset"
      @refresh="refresh"
    >
      <el-select
        v-model="query.product_id"
        placeholder="成品"
        clearable
        filterable
        style="width: 180px"
        @change="() => load()"
      >
        <el-option
          v-for="p in products"
          :key="p.id"
          :label="`${p.name}（${p.code}）`"
          :value="p.id"
        />
      </el-select>
      <el-select
        v-model="query.status"
        placeholder="状态"
        clearable
        style="width: 130px"
        @change="() => load()"
      >
        <el-option
          v-for="(label, key) in STATUS_OPTIONS"
          :key="key"
          :label="label"
          :value="Number(key)"
        />
      </el-select>
      <template #actions>
        <el-button
          v-if="auth.has('production.order.create')"
          class="btn-primary"
          @click="openCreate"
          >新 建</el-button
        >
      </template>
    </ListFilterBar>

    <el-table v-loading="loading" :data="list" class="data-table">
      <el-table-column prop="no" label="单号" width="150" class-name="font-code" />
      <el-table-column prop="product_name" label="成品" min-width="120" />
      <el-table-column
        prop="quantity"
        label="计划数"
        width="100"
        align="right"
        class-name="font-code"
      />
      <el-table-column
        prop="completed_qty"
        label="完工数"
        width="100"
        align="right"
        class-name="font-code"
      />
      <el-table-column label="进度" width="160">
        <template #default="{ row }">
          <el-progress
            :percentage="Number(row.progress)"
            :stroke-width="8"
            :status="row.progress >= 100 ? 'success' : undefined"
            class="progress-bar"
          />
        </template>
      </el-table-column>
      <el-table-column prop="plan_date" label="计划日期" width="110" />
      <el-table-column label="状态" width="100">
        <template #default="{ row }">
          <el-tag :type="statusTagType(row.status)" :class="{ 'tag-done': row.status === 3 }">{{
            row.status_label
          }}</el-tag>
        </template>
      </el-table-column>
      <el-table-column label="操作" width="320" fixed="right">
        <template #default="{ row }">
          <!-- 草稿：编辑/删除/下达；已下达：开工/详情；生产中：领料/退料/报工/委外/成品入库/完工/详情；已完成：关闭/详情 -->
          <el-button
            v-if="row.status === 0 && auth.has('production.order.update')"
            link
            type="primary"
            @click="openEdit(row)"
            >编 辑</el-button
          >
          <el-button
            v-if="row.status === 0 && auth.has('production.order.delete')"
            link
            type="danger"
            @click="removeRowAction(row)"
            >删 除</el-button
          >
          <el-button
            v-if="row.status === 0 && auth.has('production.order.update')"
            link
            type="success"
            @click="releaseRow(row)"
            >下 达</el-button
          >
          <el-button
            v-if="row.status === 1 && auth.has('production.order.update')"
            link
            type="primary"
            @click="startRow(row)"
            >开 工</el-button
          >
          <template v-if="row.status === 2">
            <!-- 生产中五个业务跳转按各自列表权限门控（无权限不展示入口） -->
            <el-button
              v-if="auth.has('production.pick.list')"
              link
              type="primary"
              @click="router.push(`/production/picks?order_id=${row.id}`)"
              >领 料</el-button
            >
            <el-button
              v-if="auth.has('production.return.list')"
              link
              type="primary"
              @click="router.push(`/production/returns?order_id=${row.id}`)"
              >退 料</el-button
            >
            <el-button
              v-if="auth.has('production.report.list')"
              link
              type="primary"
              @click="router.push(`/production/reports?order_id=${row.id}`)"
              >报 工</el-button
            >
            <el-button
              v-if="auth.has('production.outsource.list')"
              link
              type="primary"
              @click="router.push(`/production/outsourcings?order_id=${row.id}`)"
              >委 外</el-button
            >
            <el-button
              v-if="auth.has('production.finished.list')"
              link
              type="primary"
              @click="router.push(`/production/finished-inbounds?order_id=${row.id}`)"
              >成品入库</el-button
            >
            <el-button
              v-if="auth.has('production.order.update')"
              link
              type="warning"
              @click="completeRow(row)"
              >完 工</el-button
            >
          </template>
          <el-button
            v-if="row.status === 3 && auth.has('production.order.update')"
            link
            type="warning"
            @click="closeRow(row)"
            >关 闭</el-button
          >
          <el-button v-if="row.status !== 0" link type="primary" @click="openDetail(row)"
            >详 情</el-button
          >
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

    <!-- 新建/编辑弹窗 -->
    <el-dialog
      v-model="dialogVisible"
      :title="editing ? '编辑工单' : '新 建工单'"
      width="900px"
      :close-on-click-modal="false"
    >
      <el-form :model="form" label-width="90px">
        <el-form-item label="成品" required>
          <el-select
            ref="productSelect"
            v-model="form.product_id"
            placeholder="选择成品"
            filterable
            style="width: 100%"
            @change="onProductChange"
          >
            <el-option
              v-for="p in products"
              :key="p.id"
              :label="`${p.name}（${p.code}）`"
              :value="p.id"
            />
          </el-select>
        </el-form-item>
        <el-form-item label="数量" required>
          <el-input-number
            v-model="form.quantity"
            :min="0"
            :precision="2"
            :controls="false"
            placeholder="计划生产数量"
            style="width: 100%"
          />
        </el-form-item>
        <el-form-item label="计划日期" required>
          <el-date-picker
            v-model="form.plan_date"
            type="date"
            value-format="YYYY-MM-DD"
            style="width: 100%"
          />
        </el-form-item>
        <el-form-item label="备注">
          <el-input v-model="form.remark" maxlength="200" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="dialogVisible = false">取 消</el-button>
        <el-button class="btn-primary" :loading="saving" @click="save">保 存</el-button>
      </template>
    </el-dialog>

    <!-- 展开确认弹窗：只读展示 BOM 展开物料与工序 -->
    <el-dialog
      v-model="expandVisible"
      title="BOM 展开确认"
      width="900px"
      :close-on-click-modal="false"
    >
      <template v-if="expandData">
        <el-alert
          type="info"
          :closable="false"
          title="工单已创建（草稿），以下为 BOM 展开结果，确认后进入列表操作"
        />
        <div class="section-title">物料需求</div>
        <el-table :data="expandData.materials" size="small" class="data-table">
          <el-table-column prop="material_name" label="物料" min-width="140" />
          <el-table-column
            prop="material_code"
            label="编码"
            class-name="font-code"
            min-width="120"
          />
          <el-table-column
            prop="required_qty"
            label="需求数量"
            align="right"
            width="100"
            class-name="font-code"
          />
        </el-table>
        <div class="section-title">工序序列</div>
        <el-table :data="expandData.operations" size="small" class="data-table">
          <el-table-column prop="seq" label="序号" width="70" class-name="font-code" />
          <el-table-column prop="process_name" label="工序" min-width="140" />
        </el-table>
      </template>
      <template #footer>
        <el-button @click="expandVisible = false">确 定</el-button>
      </template>
    </el-dialog>

    <!-- 下达缺料警告弹窗：琥珀警告条逐行展示（不阻断下达） -->
    <el-dialog
      v-model="warningVisible"
      title="缺料警告"
      width="560px"
      :close-on-click-modal="false"
    >
      <el-alert
        type="warning"
        :closable="false"
        show-icon
        title="工单已下达，以下材料库存不足，请及时安排领料或采购"
        class="warning-alert"
      />
      <div v-for="(w, i) in releaseWarnings" :key="i" class="warning-line">
        <span>{{ w.material_name }}（{{ w.material_code }}）</span>
        <span class="font-code">需求 {{ Number(w.required) }} / 库存 {{ Number(w.stock) }}</span>
      </div>
      <template #footer>
        <el-button @click="warningVisible = false">确 定</el-button>
      </template>
    </el-dialog>

    <!-- 详情弹窗：物料需求/工序流转/报工记录 tabs -->
    <el-dialog v-model="detailVisible" title="工单详情" width="900px">
      <template v-if="detail">
        <el-descriptions :column="3" border size="small">
          <el-descriptions-item label="单号">{{ detail.no }}</el-descriptions-item>
          <el-descriptions-item label="成品">{{ detail.product_name }}</el-descriptions-item>
          <el-descriptions-item label="状态">
            <el-tag
              :type="statusTagType(detail.status)"
              :class="{ 'tag-done': detail.status === 3 }"
              >{{ detail.status_label }}</el-tag
            >
          </el-descriptions-item>
          <el-descriptions-item label="计划数">
            <span class="font-code">{{ Number(detail.quantity) }}</span>
          </el-descriptions-item>
          <el-descriptions-item label="完工数">
            <span class="font-code">{{ Number(detail.completed_qty) }}</span>
          </el-descriptions-item>
          <el-descriptions-item label="计划日期">{{ detail.plan_date }}</el-descriptions-item>
          <el-descriptions-item label="完成率">
            <span class="font-code">{{ Number(detail.progress) }}%</span>
          </el-descriptions-item>
          <el-descriptions-item label="BOM">{{ detail.bom_code }}</el-descriptions-item>
          <el-descriptions-item label="备注">{{ detail.remark ?? '—' }}</el-descriptions-item>
        </el-descriptions>
        <el-tabs style="margin-top: 16px">
          <el-tab-pane label="物料需求">
            <el-table :data="detail.materials" size="small" class="data-table">
              <el-table-column prop="material_name" label="物料" min-width="140" />
              <el-table-column
                prop="material_code"
                label="编码"
                class-name="font-code"
                min-width="120"
              />
              <el-table-column
                prop="required_qty"
                label="需求"
                align="right"
                width="90"
                class-name="font-code"
              />
              <el-table-column
                prop="issued_qty"
                label="已领"
                align="right"
                width="90"
                class-name="font-code"
              />
              <el-table-column label="剩余" align="right" width="90">
                <template #default="{ row }"
                  ><span class="remain-cell">{{ Number(row.remaining_qty) }}</span></template
                >
              </el-table-column>
            </el-table>
          </el-tab-pane>
          <el-tab-pane label="工序流转">
            <el-table :data="detail.operations" size="small" class="data-table">
              <el-table-column prop="seq" label="序号" width="70" class-name="font-code" />
              <el-table-column prop="process_name" label="工序" min-width="140" />
              <el-table-column label="状态" width="100">
                <template #default="{ row }">
                  <el-tag :type="opTagType(row.status)">{{ row.status_label }}</el-tag>
                </template>
              </el-table-column>
              <el-table-column
                prop="qualified_qty"
                label="合格"
                align="right"
                width="90"
                class-name="font-code"
              />
              <el-table-column
                prop="defective_qty"
                label="不良"
                align="right"
                width="90"
                class-name="font-code"
              />
              <el-table-column
                prop="hours"
                label="工时"
                align="right"
                width="90"
                class-name="font-code"
              />
            </el-table>
          </el-tab-pane>
          <!-- 工序网络：仅按路由下达的 DAG 工单展示（routing_id 为空 = 旧逻辑展开的工单，隐藏） -->
          <el-tab-pane v-if="detail?.routing_id" label="工序网络">
            <OperationGraph
              v-if="detail?.graph"
              :graph="detail.graph"
              @outsourcing-click="onOutsourceClick"
            />
            <el-empty v-else description="暂无工序网络数据" />
          </el-tab-pane>
          <el-tab-pane label="报工记录">
            <el-select
              v-model="reportOpId"
              placeholder="选择工序"
              style="width: 240px"
              @change="onReportOpChange"
            >
              <el-option
                v-for="op in detail.operations"
                :key="op.id"
                :label="`${op.seq}. ${op.process_name}`"
                :value="op.id"
              />
            </el-select>
            <el-table
              v-if="reportRecords.length"
              :data="reportRecords"
              size="small"
              class="data-table"
              style="margin-top: 12px"
            >
              <el-table-column prop="report_time" label="报工时间" width="160" />
              <el-table-column prop="operator" label="操作人" width="100">
                <template #default="{ row }">{{ row.operator ?? '—' }}</template>
              </el-table-column>
              <el-table-column
                prop="qualified_qty"
                label="合格"
                align="right"
                width="90"
                class-name="font-code"
              />
              <el-table-column
                prop="defective_qty"
                label="不良"
                align="right"
                width="90"
                class-name="font-code"
              />
              <el-table-column
                prop="hours"
                label="工时"
                align="right"
                width="90"
                class-name="font-code"
              />
              <el-table-column prop="remark" label="备注" min-width="140">
                <template #default="{ row }">{{ row.remark ?? '—' }}</template>
              </el-table-column>
            </el-table>
            <el-empty v-else description="暂无报工记录" />
          </el-tab-pane>
        </el-tabs>
      </template>
      <template #footer>
        <el-button @click="detailVisible = false">关 闭</el-button>
      </template>
    </el-dialog>

    <!-- 工序网络委外单弹窗：展示该委外工序的委外单（单号/供应商/数量/状态），可跳转委外页按单号定位 -->
    <el-dialog
      v-model="outsourceVisible"
      :title="`委外单${outsourceOp ? ` - ${outsourceOp.node_no ?? ''} ${outsourceOp.process_name ?? ''}` : ''}`"
      width="560px"
      :close-on-click-modal="false"
    >
      <el-table v-loading="outsourceLoading" :data="outsourceList" size="small" class="data-table">
        <el-table-column prop="no" label="单号" min-width="140" class-name="font-code" />
        <el-table-column prop="supplier_name" label="供应商" min-width="120" />
        <el-table-column label="数量" align="right" width="90" class-name="font-code">
          <template #default="{ row }">{{ formatThousand(Number(row.quantity)) }}</template>
        </el-table-column>
        <el-table-column label="状态" width="90">
          <template #default="{ row }">
            <el-tag :type="outsourceTagType(row.status)">{{ row.status_label }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="120" fixed="right">
          <template #default="{ row }">
            <el-button
              link
              type="primary"
              @click="router.push(`/production/outsourcings?keyword=${row.no}`)"
              >打开委外页</el-button
            >
          </template>
        </el-table-column>
      </el-table>
      <el-empty v-if="!outsourceLoading && outsourceList.length === 0" description="暂无委外单" />
    </el-dialog>
  </div>
</template>
<style scoped>
/* 生产工单页样式（nexus-factory）：骨架与销售订单页一致，生产特有样式见 pages/production.md */
.page-card {
  background: #fff;
  border-radius: 8px;
  box-shadow: var(--shadow-sm);
  padding: var(--space-2xl);
}
.btn-primary {
  background: var(--color-accent);
  border-color: var(--color-accent);
  color: #fff;
}
.btn-primary:hover {
  opacity: 0.9;
}
.pager {
  display: flex;
  justify-content: flex-end;
  margin-top: var(--space-lg);
}
/* 进度条（列表列内固定宽，避免随列宽拉伸） */
.progress-bar {
  width: 140px;
}
/* 展开确认弹窗分区标题（Fira Code，production.md §2） */
.section-title {
  font-family: 'Fira Code', monospace;
  font-size: 13px;
  color: #334155;
  margin: 16px 0 8px;
}
/* 缺料警告条（琥珀色，不阻断下达；下方逐行明细） */
.warning-alert {
  margin-bottom: 12px;
}
/* 已完成深绿（与已审核绿同族但明度更低，防同态混淆——销售模块同款） */
.tag-done {
  background: #ecfdf5 !important;
  color: #047857 !important;
  border-color: #047857 !important;
}
.warning-line {
  display: flex;
  justify-content: space-between;
  padding: 6px 2px;
  font-size: 13px;
  color: var(--color-foreground);
}
/* 物料需求「剩余」列加粗（production.md §2） */
.remain-cell {
  font-family: 'Fira Code', monospace;
  font-weight: 700;
}
</style>
