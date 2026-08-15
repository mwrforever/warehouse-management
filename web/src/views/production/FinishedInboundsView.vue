<!-- 成品入库页：筛选列表 + 从工单生成新建弹窗（自动带成品行，默认剩余产量 + on-blur 上限校验）+ 审核加库存联动工单进度 -->
<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { useRoute } from 'vue-router'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  productionApi,
  type FinishedInboundItem,
  type ProductionOrderItem,
} from '../../api/production'
import { warehouseApi, type LocationItem, type WarehouseItem } from '../../api/warehouse'
import { useAuthStore } from '../../stores/auth'

const auth = useAuthStore()
const route = useRoute()
const loading = ref(false)
const saving = ref(false)
const list = ref<FinishedInboundItem[]>([])
const total = ref(0)
const warehouses = ref<WarehouseItem[]>([])
const locations = ref<LocationItem[]>([])
// 生产中工单下拉（label 单号+成品，仅 status=2 可成品入库）
const orders = ref<ProductionOrderItem[]>([])

// 列表筛选（keyword 单号 / status 草稿/已审核两态）
const query = reactive({
  keyword: '',
  status: undefined as number | undefined,
  page: 1,
  per_page: 10,
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

// 成品入库单状态标签语义色（production.md：草稿灰/已审核绿）
function statusTagType(status: number) {
  return status === 0 ? 'info' : 'success'
}

async function loadList() {
  loading.value = true
  try {
    const res = await productionApi.finishedInbounds(query)
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

// 选工单 → 自动带出成品行（数量默认=剩余产量，可改；剩余=计划数-已完工）
async function onOrderChange(orderId: number | undefined) {
  if (!orderId) return
  try {
    const d = await productionApi.orderDetail(orderId)
    const remaining = Number(d.quantity) - Number(d.completed_qty)
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
async function openEdit(row: FinishedInboundItem) {
  try {
    const d = await productionApi.finishedInboundDetail(row.id)
    editingId.value = row.id
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
    locations.value = (await warehouseApi.locations(d.warehouse_id)).items
    dialogVisible.value = true
  } catch (e) {
    ElMessage.error((e as Error).message)
  }
}

// 保存：校验链（工单 → 仓库/库位 → 明细非空 → 每行数量>0 且 ≤ 剩余产量）→ 新建/更新
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
  if (form.items.some((i) => Number(i.quantity) <= 0)) {
    ElMessage.warning('入库数量必须大于 0')
    return
  }
  if (form.items.some((i) => Number(i.quantity) > formRemaining.value)) {
    ElMessage.warning('入库数量超过工单剩余产量')
    return
  }
  const payload = {
    order_id: form.order_id,
    warehouse_id: form.warehouse_id,
    location_id: form.location_id,
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
    loadList()
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
    loadList()
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
    loadList()
  } catch (e) {
    ElMessage.error((e as Error).message)
  }
}

onMounted(async () => {
  loadList()
  try {
    warehouses.value = (await warehouseApi.list({ per_page: 100, status: 1 })).items
    // 仅生产中工单可成品入库（status=2，per_page 100 覆盖全量）
    orders.value = (await productionApi.orders({ status: 2, per_page: 100 })).items
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
    <div class="toolbar">
      <span class="page-title">成品入库</span>
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
      <el-button
        v-if="auth.has('production.finished.create')"
        class="btn-primary"
        @click="openCreate()"
        >从工单生成</el-button
      >
    </div>

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
        @current-change="loadList"
      />
    </div>

    <!-- 从工单生成弹窗：工单 → 成品行（数量默认=剩余产量）→ 仓库/库位 -->
    <el-dialog
      v-model="dialogVisible"
      :title="editingId ? '编辑成品入库单' : '从工单生成成品入库单'"
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
          <el-form-item label="剩余产量">
            <span class="remain-cell">{{ formRemaining }}</span>
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
          <el-table-column prop="product_name" label="成品" min-width="180" />
          <el-table-column label="入库数量" width="160">
            <template #default="{ row }">
              <el-input-number
                v-model="row.quantity"
                :min="0"
                :precision="2"
                :controls="false"
                :max="formRemaining"
                style="width: 100%"
                @blur="validateQuantity(row)"
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
  </div>
</template>
<style scoped>
/* 成品入库页样式（nexus-factory）：骨架与领料单页一致；生产特有样式见 pages/production.md §7 */
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
  gap: var(--space-lg);
  margin-bottom: var(--space-xl);
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
/* 剩余产量（Fira Code 加粗，production.md §2） */
.remain-cell {
  font-family: 'Fira Code', monospace;
  font-weight: 700;
}
</style>
