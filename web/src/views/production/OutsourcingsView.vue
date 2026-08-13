<!-- 委外加工页：筛选列表 + 新建弹窗（工单工序/供应商/数量 ≤ 计划 + 1520 校验）+ 发出扣库存（1522 失败红色提示）+ 回收弹窗（剩余可回收/独立入库仓库库位）+ 回收记录弹窗 -->
<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { useRoute } from 'vue-router'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  productionApi,
  type OutsourcingItem,
  type OutsourcingReceiptRecord,
  type ProductionOperation,
  type ProductionOrderItem,
} from '../../api/production'
import { supplierApi, type SupplierItem } from '../../api/supplier'
import { warehouseApi, type LocationItem, type WarehouseItem } from '../../api/warehouse'
import { useAuthStore } from '../../stores/auth'

const auth = useAuthStore()
const route = useRoute()
const loading = ref(false)
const saving = ref(false)
const list = ref<OutsourcingItem[]>([])
const total = ref(0)
// 生产中工单下拉（label 单号+成品，仅 status=2 可委外）
const orders = ref<ProductionOrderItem[]>([])
const suppliers = ref<SupplierItem[]>([])
const warehouses = ref<WarehouseItem[]>([])
const locations = ref<LocationItem[]>([])
// 当前工单的未完成工序（label「seq. 工序名」）
const processOptions = ref<ProductionOperation[]>([])
// 当前工单计划数（委外量上限校验数据源）
const orderPlanQty = ref(0)

// 列表筛选（keyword 单号 / status 三态）
const query = reactive({
  keyword: '',
  status: undefined as number | undefined,
  page: 1,
  per_page: 10,
})

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

// 回收弹窗状态（剩余可回收 = 委外量 - 已回收累计；入库仓库/库位独立选择）
const receiptVisible = ref(false)
const receiptId = ref<number | null>(null)
const receiptRemaining = ref(0)
const receiptLocations = ref<LocationItem[]>([])
const receiptForm = reactive({
  quantity: undefined as number | undefined,
  warehouse_id: undefined as number | undefined,
  location_id: undefined as number | undefined,
  remark: '',
})

// 回收记录弹窗状态
const recordsVisible = ref(false)
const receiptRecords = ref<OutsourcingReceiptRecord[]>([])

// 委外状态标签语义色（production.md：草稿灰/已审核蓝/已回收绿）
function statusTagType(status: number) {
  if (status === 0) return 'info'
  if (status === 1) return 'primary'
  return 'success'
}

async function loadList() {
  loading.value = true
  try {
    const res = await productionApi.outsourcings(query)
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

// 选工单 → 加载该工单未完成工序（委外工序下拉数据源）+ 计划数（数量上限）
async function onOrderChange(orderId: number | undefined) {
  form.operation_id = undefined
  processOptions.value = []
  if (!orderId) return
  try {
    const d = await productionApi.orderDetail(orderId)
    orderPlanQty.value = Number(d.quantity)
    // 仅未完成工序可委外（status 0 待开工 / 1 进行中；已完成 2 不展示）
    processOptions.value = d.operations.filter((op) => op.status !== 2)
  } catch (e) {
    ElMessage.error((e as Error).message)
    form.order_id = undefined
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

// 委外数量 on-blur 校验：≤ 工单计划数（1520 文案，超量回弹计划数）
function validateQuantity() {
  if (form.quantity == null) return
  if (Number(form.quantity) > orderPlanQty.value) {
    ElMessage.warning('委外数量超过工单计划数量')
    form.quantity = orderPlanQty.value
  }
}

// 新建：清空表单；路由直达时携带工单自动预填工序
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
  dialogVisible.value = true
  if (orderId) {
    form.order_id = orderId
    onOrderChange(orderId)
  }
}

// 编辑草稿：详情回填（含仓库/库位 id）+ 工单详情取工序与计划数
async function openEdit(row: OutsourcingItem) {
  try {
    const d = await productionApi.outsourcingDetail(row.id)
    await onOrderChange(d.order_id)
    editingId.value = row.id
    form.order_id = d.order_id
    form.operation_id = d.operation_id
    form.supplier_id = d.supplier_id
    form.warehouse_id = d.warehouse_id
    form.location_id = d.location_id
    form.quantity = Number(d.quantity)
    form.remark = d.remark ?? ''
    locations.value = (await warehouseApi.locations(d.warehouse_id)).items
    dialogVisible.value = true
  } catch (e) {
    ElMessage.error((e as Error).message)
  }
}

// 保存：校验链（工单 → 工序 → 供应商 → 数量>0 且 ≤ 计划 → 仓库/库位）→ 新建/更新
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
  if (Number(form.quantity) > orderPlanQty.value) {
    ElMessage.warning('委外数量超过工单计划数量')
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
    quantity: form.quantity,
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
    loadList()
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
    loadList()
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
    loadList()
  } catch (e) {
    ElMessage.error((e as Error).message)
  }
}

// 回收弹窗打开：取已回收累计，剩余可回收 = 委外量 - 已回收，默认全量回收
async function openReceipt(row: OutsourcingItem) {
  try {
    const d = await productionApi.outsourcingDetail(row.id)
    receiptId.value = row.id
    receiptRemaining.value = Number(d.quantity) - Number(d.received_qty)
    Object.assign(receiptForm, {
      quantity: receiptRemaining.value,
      warehouse_id: undefined,
      location_id: undefined,
      remark: '',
    })
    receiptLocations.value = []
    receiptVisible.value = true
  } catch (e) {
    ElMessage.error((e as Error).message)
  }
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
    loadList()
  } catch (e) {
    ElMessage.error((e as Error).message)
  } finally {
    saving.value = false
  }
}

// 回收记录弹窗：按委外单加载回收流水
async function openReceipts(row: OutsourcingItem) {
  try {
    receiptRecords.value = (await productionApi.outsourcingReceipts(row.id)).items
    recordsVisible.value = true
  } catch (e) {
    ElMessage.error((e as Error).message)
  }
}

onMounted(async () => {
  loadList()
  try {
    warehouses.value = (await warehouseApi.list({ per_page: 100, status: 1 })).items
    suppliers.value = (await supplierApi.list({ per_page: 100, status: 1 })).items
    // 仅生产中工单可委外（status=2，per_page 100 覆盖全量）
    orders.value = (await productionApi.orders({ status: 2, per_page: 100 })).items
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
    <div class="toolbar">
      <span class="page-title">委外加工</span>
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
        <el-option label="已回收" :value="2" />
      </el-select>
      <el-button class="btn-primary" @click="search">查 询</el-button>
      <div class="spacer" />
      <el-button
        v-if="auth.has('production.outsource.create')"
        class="btn-primary"
        @click="openCreate()"
        >新 建</el-button
      >
    </div>

    <el-table v-loading="loading" :data="list" class="data-table">
      <el-table-column prop="no" label="单号" min-width="150" class-name="font-code" />
      <el-table-column prop="order_no" label="工单" min-width="150" class-name="font-code" />
      <el-table-column prop="process_name" label="委外工序" min-width="140" />
      <el-table-column prop="supplier_name" label="供应商" min-width="140" />
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
      <el-table-column label="操作" width="180" fixed="right">
        <template #default="{ row }">
          <!-- 草稿：编辑/删除/审核（发出）；已审核：回收/回收记录；已回收：回收记录 -->
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
          <el-button link type="primary" @click="openReceipts(row)">回收记录</el-button>
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

    <!-- 新建/编辑弹窗：工单 → 委外工序/供应商/数量 → 仓库/库位 -->
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
          <el-form-item label="委外工序" required>
            <el-select
              v-model="form.operation_id"
              placeholder="选择工序"
              style="width: 100%"
              :disabled="!form.order_id"
            >
              <el-option
                v-for="op in processOptions"
                :key="op.id"
                :label="`${op.seq}. ${op.process_name}`"
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
            <el-select v-model="form.location_id" placeholder="选择库位" style="width: 100%">
              <el-option v-for="l in locations" :key="l.id" :label="l.name" :value="l.id" />
            </el-select>
          </el-form-item>
          <el-form-item label="备注">
            <el-input v-model="form.remark" maxlength="200" />
          </el-form-item>
        </div>
      </el-form>
      <template #footer>
        <el-button @click="dialogVisible = false">取 消</el-button>
        <el-button class="btn-primary" :loading="saving" @click="save">保 存</el-button>
      </template>
    </el-dialog>

    <!-- 回收弹窗：剩余可回收展示 + 回收量（≤ 剩余）+ 入库仓库/库位（独立选择） -->
    <el-dialog
      v-model="receiptVisible"
      title="委外回收"
      width="560px"
      :close-on-click-modal="false"
    >
      <el-form label-width="90px">
        <el-form-item label="剩余可回收">
          <span class="remain-cell">{{ receiptRemaining }}</span>
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
          <el-select v-model="receiptForm.location_id" placeholder="选择库位" style="width: 100%">
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

    <!-- 回收记录弹窗：该委外单的回收流水 -->
    <el-dialog v-model="recordsVisible" title="回收记录" width="700px">
      <el-table :data="receiptRecords" size="small" class="data-table">
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
      <template #footer>
        <el-button @click="recordsVisible = false">关 闭</el-button>
      </template>
    </el-dialog>
  </div>
</template>
<style scoped>
/* 委外加工页样式（nexus-factory）：骨架与其他生产页面一致；生产特有样式见 pages/production.md §6 */
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
/* 剩余可回收（Fira Code 加粗，production.md §2） */
.remain-cell {
  font-family: 'Fira Code', monospace;
  font-weight: 700;
}
</style>
