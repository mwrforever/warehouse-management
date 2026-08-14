<!-- 领料单页：筛选列表 + 从工单生成新建弹窗（预填物料剩余 + on-blur 上限校验）+ 审核扣库存（1515 失败红色提示）+ 发料状态流转 + 详情弹窗 -->
<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { useRoute } from 'vue-router'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  productionApi,
  type PickDetail,
  type PickItem,
  type ProductionOrderItem,
} from '../../api/production'
import { warehouseApi, type LocationItem, type WarehouseItem } from '../../api/warehouse'
import { useAuthStore } from '../../stores/auth'

const auth = useAuthStore()
const route = useRoute()
const loading = ref(false)
const saving = ref(false)
const list = ref<PickItem[]>([])
const total = ref(0)
const warehouses = ref<WarehouseItem[]>([])
const locations = ref<LocationItem[]>([])
// 生产中工单下拉（label 单号+成品，仅 status=2 可领料）
const orders = ref<ProductionOrderItem[]>([])

// 列表筛选（keyword 单号 / status 草稿/已审核两态）
const query = reactive({
  keyword: '',
  status: undefined as number | undefined,
  page: 1,
  per_page: 10,
})

// 弹窗状态（V1 仅「从工单生成」入口，独立新建不开放——复用销售 1406 教训）
const dialogVisible = ref(false)
const editingId = ref<number | null>(null) // 当前编辑草稿 id（null 表示新建）
// 弹窗标题中的预填工单单号（选中工单后展示）
const fromOrderNo = ref('')
const form = reactive({
  order_id: undefined as number | undefined,
  warehouse_id: undefined as number | undefined,
  location_id: undefined as number | undefined,
  remark: '',
  items: [] as {
    product_id: number
    product_name: string
    product_code: string
    required_qty: number
    remaining_qty: number
    pick_qty: number
  }[],
})

// 详情弹窗（pickDetail 含仓库/库位 id、审核人与时间，直接供详情展示）
const detailVisible = ref(false)
const detail = ref<PickDetail | null>(null)

// 领料单状态标签语义色（production.md：草稿灰/已审核绿）
function statusTagType(status: number) {
  return status === 0 ? 'info' : 'success'
}

// 发料状态标签语义色（production.md：未发料灰/部分发料琥珀/全部发料绿）
function issueTagType(issueStatus: number) {
  if (issueStatus === 0) return 'info'
  if (issueStatus === 1) return 'warning'
  return 'success'
}

async function loadList() {
  loading.value = true
  try {
    const res = await productionApi.picks(query)
    list.value = res.items
    total.value = res.total
  } catch (e) {
    ElMessage.error((e as Error).message)
  } finally {
    loading.value = false
  }
}

function search() {
  query.page = 1
  loadList()
}

// 选工单 → 从工单生成预填物料行（剩余量默认全量领用，可改）
async function onOrderChange(orderId: number | undefined) {
  if (!orderId) return
  try {
    const data = await productionApi.fromOrderPicks(orderId)
    form.order_id = data.order_id
    fromOrderNo.value = data.order_no
    form.items = data.items.map((i) => ({
      product_id: i.product_id,
      product_name: i.product_name,
      product_code: i.product_code,
      required_qty: Number(i.required_qty),
      remaining_qty: Number(i.remaining_qty),
      pick_qty: Number(i.remaining_qty),
    }))
  } catch (e) {
    // 预填失败：清空工单选择，避免带无效工单保存
    ElMessage.error((e as Error).message)
    form.order_id = undefined
    form.items = []
  }
}

// 仓库切换 → 联动库位下拉
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

// 行内领用量 on-blur 校验：≤ 剩余需求（1513 文案，超量回弹剩余值）
function validatePickQty(row: { pick_qty: number; remaining_qty: number }) {
  if (row.pick_qty == null) return
  if (Number(row.pick_qty) > Number(row.remaining_qty)) {
    ElMessage.warning('领料数量超过需求数量')
    row.pick_qty = Number(row.remaining_qty)
  }
}

// 新建（从工单生成）：清空表单；路由直达时携带工单自动预填
function openCreate(orderId?: number) {
  editingId.value = null
  fromOrderNo.value = ''
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

// 编辑草稿：详情回填 + fromOrderPicks 取剩余量（pickDetail 不含剩余字段，按 product_id 合并）
async function openEdit(row: PickItem) {
  try {
    const d = await productionApi.pickDetail(row.id)
    const pre = await productionApi.fromOrderPicks(d.order_id)
    editingId.value = row.id
    fromOrderNo.value = d.order_no
    form.order_id = d.order_id
    form.warehouse_id = d.warehouse_id
    form.location_id = d.location_id
    form.remark = d.remark ?? ''
    form.items = pre.items.map((i) => {
      const cur = d.items.find((it) => it.product_id === i.product_id)
      return {
        product_id: i.product_id,
        product_name: i.product_name,
        product_code: i.product_code,
        required_qty: Number(i.required_qty),
        remaining_qty: Number(i.remaining_qty),
        pick_qty: Number(cur?.pick_qty ?? i.remaining_qty),
      }
    })
    locations.value = (await warehouseApi.locations(d.warehouse_id)).items
    dialogVisible.value = true
  } catch (e) {
    ElMessage.error((e as Error).message)
  }
}

// 保存：校验链（工单 → 仓库/库位 → 明细非空 → 每行数量>0 且 ≤ 剩余）→ 新建/更新
async function save() {
  if (!form.order_id) {
    ElMessage.warning('请选择工单')
    return
  }
  if (!form.warehouse_id || !form.location_id) {
    ElMessage.warning('仓库与库位不能为空')
    return
  }
  if (!form.items.length) {
    ElMessage.warning('请至少添加一条明细')
    return
  }
  if (form.items.some((i) => Number(i.pick_qty) <= 0)) {
    ElMessage.warning('领用数量必须大于 0')
    return
  }
  if (form.items.some((i) => Number(i.pick_qty) > Number(i.remaining_qty))) {
    ElMessage.warning('领料数量超过需求数量')
    return
  }
  const payload = {
    order_id: form.order_id,
    warehouse_id: form.warehouse_id,
    location_id: form.location_id,
    remark: form.remark,
    items: form.items.map((i) => ({ product_id: i.product_id, pick_qty: i.pick_qty })),
  }
  saving.value = true
  try {
    if (editingId.value) {
      await productionApi.updatePick(editingId.value, payload)
    } else {
      await productionApi.createPick(payload)
    }
    ElMessage.success('保存成功')
    dialogVisible.value = false
    loadList()
  } catch (e) {
    ElMessage.error((e as Error).message)
  } finally {
    saving.value = false
  }
}

async function removeRowAction(row: PickItem) {
  try {
    await ElMessageBox.confirm(`确认删除领料单 ${row.no}？删除后不可恢复`, '提示', {
      type: 'warning',
      confirmButtonText: '确 定',
      cancelButtonText: '取 消',
    })
  } catch {
    return
  }
  try {
    await productionApi.deletePick(row.id)
    ElMessage.success('删除成功')
    loadList()
  } catch (e) {
    ElMessage.error((e as Error).message)
  }
}

// 审核：确认后库存将减少（失败 1515 等：http 层红色提示后端精确消息含商品编码，单据保持草稿）
async function approveRow(row: PickItem) {
  try {
    await ElMessageBox.confirm(`确认审核领料单 ${row.no}？审核后库存将减少`, '提示', {
      type: 'warning',
      confirmButtonText: '确 定',
      cancelButtonText: '取 消',
    })
  } catch {
    return
  }
  try {
    await productionApi.approvePick(row.id)
    ElMessage.success('审核成功')
    loadList()
  } catch (e) {
    ElMessage.error((e as Error).message)
  }
}

// 发料：仅已审核且未全部发料（发料状态标签随之更新）
async function issueRow(row: PickItem) {
  try {
    await ElMessageBox.confirm(`确认发料 ${row.no}？发料后不可修改`, '提示', {
      type: 'warning',
      confirmButtonText: '确 定',
      cancelButtonText: '取 消',
    })
  } catch {
    return
  }
  try {
    await productionApi.issuePick(row.id)
    ElMessage.success('发料成功')
    loadList()
  } catch (e) {
    ElMessage.error((e as Error).message)
  }
}

async function openDetail(row: PickItem) {
  try {
    detail.value = await productionApi.pickDetail(row.id)
    detailVisible.value = true
  } catch (e) {
    ElMessage.error((e as Error).message)
  }
}

onMounted(async () => {
  loadList()
  try {
    warehouses.value = (await warehouseApi.list({ per_page: 100, status: 1 })).items
    // 仅生产中工单可领料（status=2，per_page 100 覆盖全量）
    orders.value = (await productionApi.orders({ status: 2, per_page: 100 })).items
  } catch {
    // 下拉加载失败不阻塞主流程
  }
  // 工单列表「领 料」跳转直达：打开从工单生成弹窗并自动预填
  const orderId = Number(route.query.order_id)
  if (orderId) {
    openCreate(orderId)
  }
})
</script>
<template>
  <div class="page-card">
    <div class="toolbar">
      <span class="page-title">领料单</span>
      <el-input
        v-model="query.keyword"
        placeholder="单号"
        clearable
        style="width: 200px"
        @keyup.enter="search"
      />
      <el-select
        v-model="query.status"
        placeholder="状态"
        clearable
        style="width: 120px"
        @change="search"
      >
        <el-option label="草稿" :value="0" />
        <el-option label="已审核" :value="1" />
      </el-select>
      <el-button class="btn-primary" @click="search">查 询</el-button>
      <div class="spacer" />
      <el-button v-if="auth.has('production.pick.create')" class="btn-primary" @click="openCreate()"
        >从工单生成</el-button
      >
    </div>

    <el-table v-loading="loading" :data="list" class="data-table">
      <el-table-column prop="no" label="单号" min-width="150" class-name="font-code" />
      <el-table-column prop="order_no" label="工单" min-width="150" class-name="font-code" />
      <el-table-column prop="warehouse_name" label="仓库" width="100" />
      <el-table-column label="状态" width="90">
        <template #default="{ row }">
          <el-tag :type="statusTagType(row.status)">{{ row.status_label }}</el-tag>
        </template>
      </el-table-column>
      <el-table-column label="发料状态" width="100">
        <template #default="{ row }">
          <el-tag :type="issueTagType(row.issue_status)">{{ row.issue_status_label }}</el-tag>
        </template>
      </el-table-column>
      <el-table-column label="操作" width="200" fixed="right">
        <template #default="{ row }">
          <!-- 草稿：编辑/删除/审核；已审核：发料（未发料/部分发料时）/查看 -->
          <el-button
            v-if="row.status === 0 && auth.has('production.pick.update')"
            link
            type="primary"
            @click="openEdit(row)"
            >编 辑</el-button
          >
          <el-button
            v-if="row.status === 0 && auth.has('production.pick.delete')"
            link
            type="danger"
            @click="removeRowAction(row)"
            >删 除</el-button
          >
          <el-button
            v-if="row.status === 0 && auth.has('production.pick.update')"
            link
            type="success"
            @click="approveRow(row)"
            >审 核</el-button
          >
          <el-button
            v-if="row.status === 1 && row.issue_status !== 2 && auth.has('production.pick.update')"
            link
            type="primary"
            @click="issueRow(row)"
            >发 料</el-button
          >
          <el-button v-if="row.status !== 0" link type="primary" @click="openDetail(row)"
            >查 看</el-button
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
        @current-change="loadList"
      />
    </div>

    <!-- 从工单生成弹窗：工单 → 明细行内领用量（≤ 剩余）→ 仓库/库位 -->
    <el-dialog
      v-model="dialogVisible"
      :title="
        editingId ? '编辑领料单' : `从工单生成领料单${fromOrderNo ? `（${fromOrderNo}）` : ''}`
      "
      width="900px"
      :close-on-click-modal="false"
    >
      <el-form label-width="90px">
        <div class="form-grid">
          <el-form-item label="工单" required>
            <el-select
              v-model="form.order_id"
              placeholder="选择生产中工单"
              filterable
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
            <el-select v-model="form.location_id" placeholder="选择库位" style="width: 100%">
              <el-option v-for="l in locations" :key="l.id" :label="l.name" :value="l.id" />
            </el-select>
          </el-form-item>
          <el-form-item label="备注">
            <el-input v-model="form.remark" maxlength="200" />
          </el-form-item>
        </div>
        <el-table :data="form.items" size="small" max-height="360" class="data-table">
          <el-table-column prop="product_name" label="物料" min-width="140" />
          <el-table-column
            prop="product_code"
            label="编码"
            class-name="font-code"
            min-width="110"
          />
          <el-table-column
            prop="required_qty"
            label="需求"
            align="right"
            width="90"
            class-name="font-code"
          />
          <el-table-column label="剩余" align="right" width="90">
            <template #default="{ row }"
              ><span class="remain-cell">{{ Number(row.remaining_qty) }}</span></template
            >
          </el-table-column>
          <el-table-column label="本次领用" width="140">
            <template #default="{ row }">
              <el-input-number
                v-model="row.pick_qty"
                :min="0"
                :precision="2"
                :controls="false"
                :max="row.remaining_qty"
                style="width: 100%"
                @blur="validatePickQty(row)"
              />
            </template>
          </el-table-column>
        </el-table>
      </el-form>
      <template #footer>
        <el-button @click="dialogVisible = false">取 消</el-button>
        <el-button class="btn-primary" :loading="saving" @click="save">保 存</el-button>
      </template>
    </el-dialog>

    <!-- 详情弹窗：头信息 + 明细（商品/需求/本次领用/已发） -->
    <el-dialog v-model="detailVisible" title="领料单详情" width="900px">
      <template v-if="detail">
        <el-descriptions :column="3" border size="small">
          <el-descriptions-item label="单号">{{ detail.no }}</el-descriptions-item>
          <el-descriptions-item label="工单">
            <span class="font-code">{{ detail.order_no }}</span>
          </el-descriptions-item>
          <el-descriptions-item label="状态">
            <el-tag :type="statusTagType(detail.status)">{{ detail.status_label }}</el-tag>
          </el-descriptions-item>
          <el-descriptions-item label="发料状态">
            <el-tag :type="issueTagType(detail.issue_status)">{{
              detail.issue_status_label
            }}</el-tag>
          </el-descriptions-item>
          <el-descriptions-item label="仓库">{{ detail.warehouse_name }}</el-descriptions-item>
          <el-descriptions-item label="库位">{{ detail.location_name }}</el-descriptions-item>
          <el-descriptions-item label="审核人">{{ detail.operator ?? '—' }}</el-descriptions-item>
          <el-descriptions-item label="备注">{{ detail.remark ?? '—' }}</el-descriptions-item>
        </el-descriptions>
        <el-table :data="detail.items" size="small" class="data-table" style="margin-top: 16px">
          <el-table-column
            prop="product_code"
            label="编码"
            class-name="font-code"
            min-width="110"
          />
          <el-table-column prop="product_name" label="商品" min-width="140" />
          <el-table-column
            prop="required_qty"
            label="需求"
            align="right"
            width="90"
            class-name="font-code"
          />
          <el-table-column
            prop="pick_qty"
            label="本次领用"
            align="right"
            width="90"
            class-name="font-code"
          />
          <el-table-column
            prop="issued_qty"
            label="已发"
            align="right"
            width="90"
            class-name="font-code"
          />
        </el-table>
      </template>
      <template #footer>
        <el-button @click="detailVisible = false">关 闭</el-button>
      </template>
    </el-dialog>
  </div>
</template>
<style scoped>
/* 领料单页样式（nexus-factory）：骨架与销售出库单页一致；生产特有样式见 pages/production.md §4 */
.page-card {
  background: #fff;
  border-radius: 8px;
  box-shadow: var(--shadow-sm);
  padding: var(--space-2xl);
}
.toolbar {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: var(--space-md);
  margin-bottom: var(--space-lg);
}
.page-title {
  font-size: 18px;
  font-weight: 600;
  color: var(--color-foreground);
  margin-right: var(--space-lg);
}
.spacer {
  flex: 1;
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
.form-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 0 var(--space-lg);
}
/* 明细「剩余」列加粗（Fira Code，production.md §2） */
.remain-cell {
  font-family: 'Fira Code', monospace;
  font-weight: 700;
}
</style>
