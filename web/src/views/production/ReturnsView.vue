<!-- 退料单页：筛选列表 + 从工单生成新建弹窗（预填已领数量 + on-blur 上限校验）+ 审核冲销已领（库存增加） -->
<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { useRoute } from 'vue-router'
import { ElMessage, ElMessageBox } from 'element-plus'
import { productionApi, type ProductionOrderItem, type ReturnItem } from '../../api/production'
import { warehouseApi, type LocationItem, type WarehouseItem } from '../../api/warehouse'
import { useAuthStore } from '../../stores/auth'

const auth = useAuthStore()
const route = useRoute()
const loading = ref(false)
const saving = ref(false)
// 列表行（ReturnItem 含仓库/库位名，直接来自 production.ts 类型）
const list = ref<ReturnItem[]>([])
const total = ref(0)
const warehouses = ref<WarehouseItem[]>([])
const locations = ref<LocationItem[]>([])
// 生产中工单下拉（label 单号+成品，仅 status=2 可退料）
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
const form = reactive({
  order_id: undefined as number | undefined,
  pick_id: null as number | null,
  warehouse_id: undefined as number | undefined,
  location_id: undefined as number | undefined,
  remark: '',
  items: [] as {
    product_id: number
    product_name: string
    product_code: string
    issued_qty: number
    quantity: number
  }[],
})

// 退料单状态标签语义色（production.md：草稿灰/已审核绿）
function statusTagType(status: number) {
  return status === 0 ? 'info' : 'success'
}

async function loadList() {
  loading.value = true
  try {
    const res = await productionApi.returns(query)
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

// 选工单 → fromOrderPicks 预填物料行：取 issued_qty（已领）而非剩余，默认全退可改
async function onOrderChange(orderId: number | undefined) {
  if (!orderId) return
  try {
    const data = await productionApi.fromOrderPicks(orderId)
    form.order_id = data.order_id
    form.items = data.items.map((i) => ({
      product_id: i.product_id,
      product_name: i.product_name,
      product_code: i.product_code,
      issued_qty: Number(i.issued_qty),
      quantity: Number(i.issued_qty),
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

// 行内退料量 on-blur 校验：≤ 已领（1517 文案，超量回弹已领值）
function validateQuantity(row: { quantity: number; issued_qty: number }) {
  if (row.quantity == null) return
  if (Number(row.quantity) > Number(row.issued_qty)) {
    ElMessage.warning('退料数量超过已领数量')
    row.quantity = Number(row.issued_qty)
  }
}

// 新建：清空表单；路由直达时携带工单自动预填
function openCreate(orderId?: number) {
  editingId.value = null
  Object.assign(form, {
    order_id: undefined,
    pick_id: null,
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

// 编辑草稿：详情回填 + fromOrderPicks 取已领量（退料详情接口不含已领字段，按 product_id 合并）
async function openEdit(row: ReturnItem) {
  try {
    const d = await productionApi.returnsDetail(row.id)
    const pre = await productionApi.fromOrderPicks(d.order_id)
    editingId.value = row.id
    form.order_id = d.order_id
    // 关联领料单回填（编辑保真，保存时随载荷提交）
    form.pick_id = d.pick_id
    form.warehouse_id = d.warehouse_id
    form.location_id = d.location_id
    form.remark = d.remark ?? ''
    form.items = pre.items.map((i) => {
      const cur = d.items.find((it) => it.product_id === i.product_id)
      return {
        product_id: i.product_id,
        product_name: i.product_name,
        product_code: i.product_code,
        issued_qty: Number(i.issued_qty),
        quantity: Number(cur?.quantity ?? i.issued_qty),
      }
    })
    locations.value = (await warehouseApi.locations(d.warehouse_id)).items
    dialogVisible.value = true
  } catch (e) {
    ElMessage.error((e as Error).message)
  }
}

// 保存：校验链（工单 → 仓库/库位 → 明细非空 → 每行数量>0 且 ≤ 已领）→ 新建/更新
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
    ElMessage.warning('退料数量必须大于 0')
    return
  }
  if (form.items.some((i) => Number(i.quantity) > Number(i.issued_qty))) {
    ElMessage.warning('退料数量超过已领数量')
    return
  }
  const payload = {
    order_id: form.order_id,
    ...(form.pick_id ? { pick_id: form.pick_id } : {}),
    warehouse_id: form.warehouse_id,
    location_id: form.location_id,
    remark: form.remark,
    items: form.items.map((i) => ({ product_id: i.product_id, quantity: i.quantity })),
  }
  saving.value = true
  try {
    if (editingId.value) {
      await productionApi.updateReturn(editingId.value, payload)
    } else {
      await productionApi.createReturn(payload)
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

async function removeRowAction(row: ReturnItem) {
  try {
    await ElMessageBox.confirm(`确认删除退料单 ${row.no}？删除后不可恢复`, '提示', {
      type: 'warning',
      confirmButtonText: '确 定',
      cancelButtonText: '取 消',
    })
  } catch {
    return
  }
  try {
    await productionApi.deleteReturn(row.id)
    ElMessage.success('删除成功')
    loadList()
  } catch (e) {
    ElMessage.error((e as Error).message)
  }
}

// 审核：确认后库存将增加并冲销已领（失败 http 层红色提示后端精确消息）
async function approveRow(row: ReturnItem) {
  try {
    await ElMessageBox.confirm(`确认审核退料单 ${row.no}？审核后库存将增加并冲销已领`, '提示', {
      type: 'warning',
      confirmButtonText: '确 定',
      cancelButtonText: '取 消',
    })
  } catch {
    return
  }
  try {
    await productionApi.approveReturn(row.id)
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
    // 仅生产中工单可退料（status=2，per_page 100 覆盖全量）
    orders.value = (await productionApi.orders({ status: 2, per_page: 100 })).items
  } catch {
    // 下拉加载失败不阻塞主流程
  }
  // 工单列表「退 料」跳转直达：打开从工单生成弹窗并自动预填
  const orderId = Number(route.query.order_id)
  if (orderId) {
    openCreate(orderId)
  }
})
</script>
<template>
  <div class="page-card">
    <div class="toolbar">
      <span class="page-title">退料单</span>
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
        @change="loadList"
      >
        <el-option label="草稿" :value="0" />
        <el-option label="已审核" :value="1" />
      </el-select>
      <el-button class="btn-primary" @click="search">查 询</el-button>
      <div class="spacer" />
      <el-button
        v-if="auth.has('production.return.create')"
        class="btn-primary"
        @click="openCreate()"
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
      <el-table-column prop="operator" label="审核人" min-width="100">
        <template #default="{ row }">{{ row.operator ?? '—' }}</template>
      </el-table-column>
      <el-table-column label="操作" width="180" fixed="right">
        <template #default="{ row }">
          <!-- 草稿：编辑/删除/审核；已审核无操作 -->
          <el-button
            v-if="row.status === 0 && auth.has('production.return.update')"
            link
            type="primary"
            @click="openEdit(row)"
            >编 辑</el-button
          >
          <el-button
            v-if="row.status === 0 && auth.has('production.return.delete')"
            link
            type="danger"
            @click="removeRowAction(row)"
            >删 除</el-button
          >
          <el-button
            v-if="row.status === 0 && auth.has('production.return.update')"
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

    <!-- 从工单生成弹窗：工单 → 明细行内退料量（≤ 已领）→ 仓库/库位 -->
    <el-dialog
      v-model="dialogVisible"
      :title="editingId ? '编辑退料单' : '从工单生成退料单'"
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
            prop="issued_qty"
            label="已领"
            align="right"
            width="90"
            class-name="font-code"
          />
          <el-table-column label="本次退回" width="140">
            <template #default="{ row }">
              <el-input-number
                v-model="row.quantity"
                :min="0"
                :precision="2"
                :controls="false"
                :max="row.issued_qty"
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
/* 退料单页样式（nexus-factory）：骨架与领料单页一致；生产特有样式见 pages/production.md §5 */
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
</style>
