<!-- 成品入库页：筛选列表 + 从工单生成新建弹窗（自动带成品行，默认剩余产量 + on-blur 上限校验）+ 审核加库存联动工单进度 -->
<script setup lang="ts">
import { onMounted, reactive, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import {
  ElMessage,
  ElMessageBox,
  type FormInstance,
  type FormItemRule,
  type FormRules,
} from 'element-plus'
import { productionApi, type FinishedInboundItem } from '../../api/production'
import { warehouseApi, type LocationItem, type WarehouseItem } from '../../api/warehouse'
import ListFilterBar from '../../components/ListFilterBar.vue'
import { useListQuery } from '../../composables/useListQuery'
import { useRemoteOptions } from '../../composables/useRemoteOptions'
import { useAuthStore } from '../../stores/auth'
import { quantityRule } from '../../utils/formRules'

const auth = useAuthStore()
const route = useRoute()
const saving = ref(false)
const warehouses = ref<WarehouseItem[]>([])
const locations = ref<LocationItem[]>([])

// 工单下拉选项（BF-3 remote）：label 单号+成品；仅 status=2 生产中工单可成品入库
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

// 列表查询状态（统一组合式：防抖加载/查询/重置/刷新，请求序号守卫并发）
const { query, list, total, loading, load, search, reset, refresh } = useListQuery({
  defaultQuery: { keyword: '', status: undefined },
  fetch: (q) => productionApi.finishedInbounds(q),
  onError: (e) => ElMessage.error(e.message),
})

// 弹窗状态
const dialogVisible = ref(false)
const editingId = ref<number | null>(null) // 当前编辑草稿 id（null 表示新建）
// 当前表单会话的剩余产量（新建取工单详情；编辑取入库单详情）——数量上限校验数据源
const formRemaining = ref(0)
const form = reactive({
  order_id: undefined as number | undefined,
  warehouse_id: undefined as number | undefined,
  location_id: undefined as number | undefined,
  remark: '',
  items: [] as {
    product_id: number
    product_name: string
    quantity: number
  }[],
})
// 弹窗表单引用：保存前统一触发 el-form 校验（D-17）
const formRef = ref<FormInstance>()
// 表单校验规则（D-17）：工单/仓库/库位必填；明细行入库数量须 > 0 且最多 2 位小数。
// 「入库数量 ≤ 工单剩余产量」为业务上限校验，保持在行内 on-blur 与保存侧手工
const rules: FormRules = {
  order_id: [{ required: true, message: '请选择工单', trigger: 'change' }],
  warehouse_id: [{ required: true, message: '请选择仓库', trigger: 'change' }],
  location_id: [{ required: true, message: '请选择库位', trigger: 'change' }],
}
// 明细行入库数量规则：从工单生成预填剩余产量（>0），行内均须 > 0（防空数量入库行）
const quantityRules: FormItemRule[] = [quantityRule(false, '入库数量必须大于 0')]

// 会话序号守卫（BF-2，模式同 OutsourcingsView 评审 F5）：选单预填/编辑回填为异步落点，
// 快速切单/连点编辑/关窗重开时旧会话的慢响应必须丢弃——
// 防 A 的迟到成品行覆盖 B 的明细、form.order_id 被拉回旧单（所见非所选）
let sessionSeq = 0

// 关窗即作废在途：弹窗关闭后迟到的预填/详情响应禁止回写（弹窗已关，回写无意义且污染重开时的 reset）
// 弹窗开关同时是工单下拉的 remote 会话边界（BF-3）：打开初载前 100 条，关闭清空选项与 pin 集
watch(dialogVisible, (open) => {
  if (!open) sessionSeq++
  if (open) {
    loadOrders()
  } else {
    resetOrders()
  }
})

// 成品入库单状态标签语义色（production.md：草稿灰/已审核绿）
function statusTagType(status: number) {
  return status === 0 ? 'info' : 'success'
}

// 选工单 → 自动带出成品行（数量默认=剩余产量，可改；剩余=计划数-已完工）
async function onOrderChange(orderId: number | undefined, session: number = ++sessionSeq) {
  if (!orderId) return
  try {
    const d = await productionApi.orderDetail(orderId)
    // 迟到守卫：旧工单的慢详情丢弃，防覆盖新单成品行与 order_id（快速切单 A→B 所见非所选）
    if (session !== sessionSeq) return
    const remaining = Number(d.quantity) - Number(d.completed_qty)
    // 工单回显 pin：详情携带单号/成品名，工单可能不在下拉前 100 条内，不 pin 则下拉只显示裸 id
    pinOrder({ id: d.id, no: d.no, product_name: d.product_name })
    formRemaining.value = remaining
    form.order_id = d.id
    form.items = [
      {
        product_id: d.product_id,
        product_name: d.product_name,
        quantity: remaining,
      },
    ]
  } catch (e) {
    // 过期会话的失败不回写：旧工单报错/清空不得打扰新会话已回填的选择
    if (session !== sessionSeq) return
    // 预填失败：清空工单选择，避免带无效工单保存
    ElMessage.error((e as Error).message)
    form.order_id = undefined
    form.items = []
  }
}

// 仓库切换 → 联动库位下拉
async function onWarehouseChange(whId: number | undefined, session: number = ++sessionSeq) {
  form.location_id = undefined
  locations.value = []
  if (!whId) return
  try {
    const data = await warehouseApi.locations(whId)
    // 迟到守卫：旧仓库的慢响应丢弃，防快速切换 A→B 时 A 的库位覆盖 B 的选择
    if (session !== sessionSeq) return
    locations.value = data.items
  } catch (e) {
    if (session !== sessionSeq) return
    ElMessage.error((e as Error).message)
  }
}

// 行内入库量 on-blur 校验：≤ 剩余产量（1525 文案，超量回弹剩余值）
function validateQuantity(row: { quantity: number }) {
  if (row.quantity == null) return
  if (Number(row.quantity) > formRemaining.value) {
    ElMessage.warning('入库数量超过工单剩余产量')
    row.quantity = formRemaining.value
  }
}

// 新建（从工单生成）：清空表单；路由直达时携带工单自动预填
function openCreate(orderId?: number) {
  editingId.value = null
  formRemaining.value = 0
  Object.assign(form, {
    order_id: undefined,
    warehouse_id: undefined,
    location_id: undefined,
    remark: '',
    items: [],
  })
  dialogVisible.value = true
  if (orderId) {
    form.order_id = orderId
    onOrderChange(orderId)
  }
}

// 编辑草稿：详情回填（含剩余产量与仓库/库位 id）
// 回填链（详情→库位）共用同一会话号，快速连点两行编辑/关窗重开时慢响应不得覆盖新会话
async function openEdit(row: FinishedInboundItem) {
  const session = ++sessionSeq
  try {
    const d = await productionApi.finishedInboundDetail(row.id)
    if (session !== sessionSeq) return
    editingId.value = row.id
    // 工单回显 pin：详情携带单号与成品行（成品行商品即工单成品），工单可能不在下拉前 100 条内
    pinOrder({
      id: d.order_id,
      no: d.order_no,
      product_name: d.items[0]?.product_name ?? '',
    })
    formRemaining.value = Number(d.remaining_qty)
    form.order_id = d.order_id
    form.warehouse_id = d.warehouse_id
    form.location_id = d.location_id
    form.remark = d.remark ?? ''
    form.items = d.items.map((i) => ({
      product_id: i.product_id,
      product_name: i.product_name,
      quantity: Number(i.quantity),
    }))
    const locs = await warehouseApi.locations(d.warehouse_id)
    if (session !== sessionSeq) return
    locations.value = locs.items
    dialogVisible.value = true
  } catch (e) {
    // 过期会话的失败不提示：新会话已接管，旧单报错会打扰当前编辑
    if (session !== sessionSeq) return
    ElMessage.error((e as Error).message)
  }
}

// 保存：校验链（el-form rules 前置：工单/仓库库位必填 + 每行入库数量格式 →
// 明细非空 → 每行数量 ≤ 剩余产量，业务上限保持手工）→ 新建/更新
async function save() {
  // 提交前统一 el-form 校验（D-17）：表头必填 + 明细行数量必填/范围精度在前端拦截，避免发出可预期的 422 请求
  const valid = await formRef.value?.validate().catch(() => false)
  if (!valid) return
  if (!form.items.length) {
    ElMessage.warning('请至少添加一条明细')
    return
  }
  if (form.items.some((i) => Number(i.quantity) > formRemaining.value)) {
    ElMessage.warning('入库数量超过工单剩余产量')
    return
  }
  // 工单/仓库/库位经上方 rules 校验必填，此处 ! 收窄类型（纯类型层面，运行时值不变）
  const payload = {
    order_id: form.order_id!,
    warehouse_id: form.warehouse_id!,
    location_id: form.location_id!,
    remark: form.remark,
    items: form.items.map((i) => ({ product_id: i.product_id, quantity: i.quantity })),
  }
  saving.value = true
  try {
    if (editingId.value) {
      await productionApi.updateFinishedInbound(editingId.value, payload)
    } else {
      await productionApi.createFinishedInbound(payload)
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

async function removeRowAction(row: FinishedInboundItem) {
  try {
    await ElMessageBox.confirm(`确认删除成品入库单 ${row.no}？删除后不可恢复`, '提示', {
      type: 'warning',
      confirmButtonText: '确 定',
      cancelButtonText: '取 消',
    })
  } catch {
    return
  }
  try {
    await productionApi.deleteFinishedInbound(row.id)
    ElMessage.success('删除成功')
    refresh()
  } catch (e) {
    ElMessage.error((e as Error).message)
  }
}

// 审核：确认后成品库存将增加且工单进度更新（满产自动完成工单）
async function approveRow(row: FinishedInboundItem) {
  try {
    await ElMessageBox.confirm(
      `确认审核成品入库单 ${row.no}？审核后成品库存将增加且工单进度更新`,
      '提示',
      {
        type: 'warning',
        confirmButtonText: '确 定',
        cancelButtonText: '取 消',
      },
    )
  } catch {
    return
  }
  try {
    await productionApi.approveFinishedInbound(row.id)
    ElMessage.success('审核成功')
    refresh()
  } catch (e) {
    ElMessage.error((e as Error).message)
  }
}

onMounted(async () => {
  search()
  try {
    warehouses.value = (await warehouseApi.list({ per_page: 100, status: 1 })).items
  } catch {
    // 下拉加载失败不阻塞主流程
  }
  // 工单列表「成品入库」跳转直达：打开从工单生成弹窗并自动预填
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
      title="成品入库"
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
      </el-select>
      <template #actions>
        <el-button
          v-if="auth.has('production.finished.create')"
          class="btn-primary"
          @click="openCreate()"
          >从工单生成</el-button
        >
      </template>
    </ListFilterBar>

    <el-table v-loading="loading" :data="list" class="data-table">
      <el-table-column prop="no" label="单号" min-width="150" class-name="font-code" />
      <el-table-column prop="order_no" label="工单" min-width="150" class-name="font-code" />
      <el-table-column prop="product_name" label="成品" min-width="140" />
      <el-table-column
        prop="quantity"
        label="数量"
        align="right"
        width="100"
        class-name="font-code"
      />
      <el-table-column label="状态" width="90">
        <template #default="{ row }">
          <el-tag :type="statusTagType(row.status)">{{ row.status_label }}</el-tag>
        </template>
      </el-table-column>
      <el-table-column prop="operator" label="审核人" min-width="100">
        <template #default="{ row }">{{ row.operator ?? '—' }}</template>
      </el-table-column>
      <el-table-column label="操作" width="180" fixed="right">
        <template #default="{ row }">
          <!-- 草稿：编辑/删除/审核；已审核无操作 -->
          <el-button
            v-if="row.status === 0 && auth.has('production.finished.update')"
            link
            type="primary"
            @click="openEdit(row)"
            >编 辑</el-button
          >
          <el-button
            v-if="row.status === 0 && auth.has('production.finished.delete')"
            link
            type="danger"
            @click="removeRowAction(row)"
            >删 除</el-button
          >
          <el-button
            v-if="row.status === 0 && auth.has('production.finished.update')"
            link
            type="success"
            @click="approveRow(row)"
            >审 核</el-button
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

    <!-- 从工单生成弹窗：工单 → 成品行（数量默认=剩余产量）→ 仓库/库位 -->
    <el-dialog
      v-model="dialogVisible"
      :title="editingId ? '编辑成品入库单' : '从工单生成成品入库单'"
      width="900px"
      :close-on-click-modal="false"
    >
      <el-form ref="formRef" :model="form" :rules="rules" label-width="90px">
        <div class="form-grid">
          <el-form-item label="工单" prop="order_id" required>
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
          <el-form-item label="剩余产量">
            <span class="remain-cell">{{ formRemaining }}</span>
          </el-form-item>
          <el-form-item label="仓库" prop="warehouse_id" required>
            <el-select
              v-model="form.warehouse_id"
              placeholder="选择仓库"
              style="width: 100%"
              @change="onWarehouseChange"
            >
              <el-option v-for="w in warehouses" :key="w.id" :label="w.name" :value="w.id" />
            </el-select>
          </el-form-item>
          <el-form-item label="库位" prop="location_id" required>
            <el-select v-model="form.location_id" placeholder="选择库位" style="width: 100%">
              <el-option v-for="l in locations" :key="l.id" :label="l.name" :value="l.id" />
            </el-select>
          </el-form-item>
          <el-form-item label="备注">
            <el-input v-model="form.remark" maxlength="200" />
          </el-form-item>
        </div>
        <el-table :data="form.items" size="small" max-height="360" class="data-table">
          <el-table-column prop="product_name" label="成品" min-width="180" />
          <el-table-column label="入库数量" width="160">
            <template #default="{ row, $index }">
              <el-form-item
                :prop="`items.${$index}.quantity`"
                :rules="quantityRules"
                label-width="0"
              >
                <el-input-number
                  v-model="row.quantity"
                  :min="0"
                  :precision="2"
                  :controls="false"
                  :max="formRemaining"
                  style="width: 100%"
                  @blur="validateQuantity(row)"
                />
              </el-form-item>
            </template>
          </el-table-column>
        </el-table>
      </el-form>
      <template #footer>
        <el-button @click="dialogVisible = false">取 消</el-button>
        <el-button class="btn-primary" :loading="saving" @click="save">保 存</el-button>
      </template>
    </el-dialog>
  </div>
</template>
<style scoped>
/* 成品入库页样式（nexus-factory）：骨架与领料单页一致；生产特有样式见 pages/production.md §7 */
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
/* 剩余产量（Fira Code 加粗，production.md §2） */
.remain-cell {
  font-family: 'Fira Code', monospace;
  font-weight: 700;
}
</style>
