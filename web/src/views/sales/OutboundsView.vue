<!-- 销售出库单页：筛选列表 + 双入口新建弹窗（从订单生成/独立新建）+ 详情弹窗 + 审核（扣库存防超卖） -->
<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  salesApi,
  type AvailableOrder,
  type FromOrderItem,
  type SalesOutboundDetail,
  type SalesOutboundItem,
  type TodaySummaryItem,
} from '../../api/sales'
import { customerApi, type CustomerItem } from '../../api/customer'
import { productApi } from '../../api/product'
import ListFilterBar from '../../components/ListFilterBar.vue'
import ScanInboundForm, { type ScanItem } from '../../components/ScanInboundForm.vue'
import { useListQuery } from '../../composables/useListQuery'
import { warehouseApi, type LocationItem, type WarehouseItem } from '../../api/warehouse'
import { useAuthStore } from '../../stores/auth'
import { formatYuan } from '../../utils/format'

const auth = useAuthStore()
const route = useRoute()
const router = useRouter()
const saving = ref(false)
const customers = ref<CustomerItem[]>([])
const warehouses = ref<WarehouseItem[]>([])
const locations = ref<LocationItem[]>([])
const availableOrders = ref<AvailableOrder[]>([])
// 可售商品：仅成品/半成品（原料禁售 SAL-10，两次调用合并）
const products = ref<{ id: number; name: string; code: string; barcode: string | null }[]>([])
// 今日已出库汇总（列表页顶部汇总行；空则隐藏）
const todaySummary = ref<TodaySummaryItem[]>([])

// 列表查询状态（统一组合式：防抖加载/查询/重置/刷新，请求序号守卫并发）
const { query, list, total, loading, load, search, reset, refresh } = useListQuery({
  defaultQuery: { keyword: '', warehouse_id: undefined, status: undefined },
  fetch: (q) => salesApi.outbounds(q),
  onError: (e) => ElMessage.error(e.message),
})

// 弹窗状态
const dialogVisible = ref(false)
const detailVisible = ref(false)
const detail = ref<SalesOutboundDetail | null>(null)
const mode = ref<'from-order' | 'standalone'>('from-order') // 新建入口
const editingId = ref<number | null>(null) // 当前编辑草稿 id（null 表示新建）
const fromOrderId = ref<number | undefined>(undefined)
const form = reactive({
  customer_id: undefined as number | undefined,
  warehouse_id: undefined as number | undefined,
  location_id: undefined as number | undefined,
  remark: '',
  items: [] as {
    product_id: number | undefined
    quantity: number
    price: number
    order_item_id?: number
  }[],
})

// 状态标签语义色（sales.md：草稿灰/已审核绿）
function statusTagType(status: number) {
  return status === 0 ? 'info' : 'success'
}

// 明细行金额（元，实时计算：数量×单价，保留 2 位小数）
function lineAmountYuan(item: { quantity: number; price: number }): number {
  return Number((Number(item.quantity) * Number(item.price)).toFixed(2))
}

// 明细合计（元，实时计算）
function totalYuan(): string {
  return form.items
    .reduce((sum, i) => sum + lineAmountYuan(i), 0)
    .toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

// 行金额（分→元展示，仅读列）
function rowAmountFen(item: { quantity: number; price: number }): string {
  return formatYuan(Math.round(Number(item.quantity) * Number(item.price) * 100))
}

// 从订单生成：选订单 → 预填客户+明细（剩余量）
async function onOrderChange(orderId: number | undefined) {
  if (!orderId) return
  try {
    const data = await salesApi.fromOrder(orderId)
    form.customer_id = data.customer_id
    form.items = data.items.map((i: FromOrderItem) => ({
      product_id: i.product_id,
      quantity: Number(i.remaining_qty),
      price: Number(i.price) / 100,
      order_item_id: i.order_item_id,
    }))
  } catch (e) {
    ElMessage.error((e as Error).message)
    fromOrderId.value = undefined
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

// 明细行操作（独立模式）
function addRow() {
  form.items.push({ product_id: undefined, quantity: 1, price: 0 })
}
function removeRow(index: number) {
  form.items.splice(index, 1)
}
function onProductChange(row: { product_id: number | undefined }, index: number) {
  const dup = form.items.findIndex((i, idx) => idx !== index && i.product_id === row.product_id)
  if (dup >= 0) {
    ElMessage.warning('明细存在重复商品')
    form.items[index].product_id = undefined
  }
}

// 扫码弹窗状态 + 已存在行商品 id（累加关时弹窗内判重，避免撞已有行）
const scanVisible = ref(false)
const scanExcludedIds = computed(() =>
  form.items.map((i) => i.product_id).filter((x): x is number => x != null),
)

// 扫码弹窗关闭：扫描行按商品合并进明细（同商品数量相加；累加关时弹窗内已拦重复，不会撞已有行；
// 扫码新增行不带 order_item_id，仅从订单生成的行保留订单关联）
function onScanItems(items: ScanItem[]) {
  for (const it of items) {
    const existing = form.items.find((i) => i.product_id === it.product_id)
    if (existing) {
      existing.quantity = Number((existing.quantity + it.quantity).toFixed(2))
    } else {
      form.items.push({ product_id: it.product_id, quantity: it.quantity, price: 0 })
    }
  }
}

// 新建弹窗（双入口）
function openCreate(m: 'from-order' | 'standalone') {
  mode.value = m
  editingId.value = null
  fromOrderId.value = undefined
  Object.assign(form, {
    customer_id: undefined,
    warehouse_id: undefined,
    location_id: undefined,
    remark: '',
    items: [],
  })
  if (m === 'standalone') {
    form.items.push({ product_id: undefined, quantity: 1, price: 0 })
  }
  dialogVisible.value = true
}

// 编辑草稿（独立/关联均可，按详情回填）
async function openEdit(row: SalesOutboundItem) {
  try {
    const d = await salesApi.outboundDetail(row.id)
    mode.value = d.order_id ? 'from-order' : 'standalone'
    editingId.value = row.id
    fromOrderId.value = d.order_id ?? undefined
    Object.assign(form, {
      customer_id: d.customer_id,
      warehouse_id: d.warehouse_id,
      location_id: d.location_id,
      remark: d.remark ?? '',
      items: d.items.map((i) => ({
        product_id: i.product_id,
        quantity: Number(i.quantity),
        price: Number(i.price) / 100,
        order_item_id: i.order_item_id ?? undefined,
      })),
    })
    locations.value = (await warehouseApi.locations(d.warehouse_id)).items
    dialogVisible.value = true
  } catch (e) {
    ElMessage.error((e as Error).message)
  }
}

// 保存（载荷：价格 元→分；从订单生成模式带 order_id 与 order_item_id；编辑走更新接口）
async function save() {
  if (mode.value === 'from-order' && !fromOrderId.value) {
    ElMessage.warning('请选择来源订单')
    return
  }
  if (!form.customer_id) {
    ElMessage.warning('请选择客户')
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
  if (form.items.some((i) => !i.product_id)) {
    ElMessage.warning('请选择商品')
    return
  }
  if (form.items.some((i) => Number(i.quantity) <= 0)) {
    ElMessage.warning('数量必须大于 0')
    return
  }
  const payload = {
    customer_id: form.customer_id!,
    warehouse_id: form.warehouse_id!,
    location_id: form.location_id!,
    order_id: mode.value === 'from-order' ? fromOrderId.value : undefined,
    remark: form.remark,
    items: form.items.map((i) => ({
      product_id: i.product_id!,
      quantity: i.quantity,
      price: Math.round(Number(i.price) * 100),
      ...(i.order_item_id ? { order_item_id: i.order_item_id } : {}),
    })),
  }
  saving.value = true
  try {
    if (editingId.value) {
      await salesApi.updateOutbound(editingId.value, payload)
    } else {
      await salesApi.createOutbound(payload)
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

async function removeRowAction(row: SalesOutboundItem) {
  try {
    await ElMessageBox.confirm(`确认删除出库单 ${row.no}？删除后不可恢复`, '提示', {
      type: 'warning',
      confirmButtonText: '确 定',
      cancelButtonText: '取 消',
    })
  } catch {
    return
  }
  try {
    await salesApi.deleteOutbound(row.id)
    ElMessage.success('删除成功')
    refresh()
  } catch (e) {
    ElMessage.error((e as Error).message)
  }
}

// 审核：确认后库存减少（失败 1409 时红色 ElMessage 直接显示后端消息，单据保持草稿）
async function approveRow(row: SalesOutboundItem) {
  try {
    await ElMessageBox.confirm(`确认审核出库单 ${row.no}？审核后库存将减少且不可修改`, '提示', {
      type: 'warning',
      confirmButtonText: '确 定',
      cancelButtonText: '取 消',
    })
  } catch {
    return
  }
  try {
    await salesApi.approveOutbound(row.id)
    ElMessage.success('出库成功，库存已更新')
    refresh()
  } catch (e) {
    // 1409 库存不足：红色错误显示后端精确消息（含商品名与当前库存），不刷新列表（单据仍草稿）
    ElMessage.error((e as Error).message)
  }
}

async function openDetail(row: SalesOutboundItem) {
  try {
    detail.value = await salesApi.outboundDetail(row.id)
    detailVisible.value = true
  } catch (e) {
    ElMessage.error((e as Error).message)
  }
}

onMounted(async () => {
  search()
  try {
    customers.value = (await customerApi.list({ per_page: 100, status: 1 })).items
    warehouses.value = (await warehouseApi.list({ per_page: 100, status: 1 })).items
    availableOrders.value = (await salesApi.availableOrders()).items
    // 可售商品 = 成品 + 半成品（原料禁售；两次调用合并）
    const [fin, semi] = await Promise.all([
      productApi.list({ per_page: 100, type: 'finished' }),
      productApi.list({ per_page: 100, type: 'semi_finished' }),
    ])
    products.value = [...fin.items, ...semi.items]
    // 今日已出库汇总（当日无出库返回空数组，汇总行隐藏）
    todaySummary.value = (await salesApi.todaySummary()).items
  } catch {
    // 下拉加载失败不阻塞主流程
  }
  // 流水页单号跳转直达：/sales/outbounds/{id}
  const id = Number(route.params.id)
  if (id) {
    try {
      detail.value = await salesApi.outboundDetail(id)
      detailVisible.value = true
      router.replace({ name: 'sales-outbounds' })
    } catch {
      // 无效 id 静默（页面正常展示列表）
    }
  }
})
</script>
<template>
  <div class="page-card">
    <ListFilterBar
      v-model:keyword="query.keyword"
      title="销售出库单"
      keyword-placeholder="单号"
      @keyword-change="() => load()"
      @search="search"
      @reset="reset"
      @refresh="refresh"
    >
      <el-select
        v-model="query.warehouse_id"
        placeholder="仓库"
        clearable
        style="width: 140px"
        @change="() => load()"
      >
        <el-option v-for="w in warehouses" :key="w.id" :label="w.name" :value="w.id" />
      </el-select>
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
        <template v-if="auth.has('sales.outbound.create')">
          <el-button class="btn-primary" @click="openCreate('from-order')">从订单生成</el-button>
          <el-button class="btn-secondary" @click="openCreate('standalone')">新 建</el-button>
        </template>
      </template>
    </ListFilterBar>

    <!-- 今日已出库汇总行（当日累计，轻量统计；空隐藏） -->
    <div v-if="todaySummary.length" class="summary-bar">
      <span class="summary-label">今日已出库：</span>
      <span v-for="s in todaySummary" :key="s.product_id" class="summary-chip">
        {{ s.product_code }} {{ s.product_name }} ×{{ Number(s.quantity) }}
      </span>
    </div>

    <el-table v-loading="loading" :data="list" class="data-table">
      <el-table-column prop="no" label="单号" class-name="font-code" min-width="150" />
      <el-table-column prop="customer_name" label="客户" min-width="140" />
      <el-table-column prop="warehouse_name" label="仓库" width="90" />
      <el-table-column prop="location_name" label="库位" width="90" />
      <el-table-column label="来源订单" min-width="140">
        <template #default="{ row }">
          <span v-if="row.order_no" class="font-code">{{ row.order_no }}</span>
          <span v-else class="muted">—</span>
        </template>
      </el-table-column>
      <el-table-column label="金额" width="130" align="right">
        <template #default="{ row }">
          <span class="amount-cell">¥{{ formatYuan(row.total_amount) }}</span>
        </template>
      </el-table-column>
      <el-table-column label="状态" width="90">
        <template #default="{ row }">
          <el-tag :type="statusTagType(row.status)">{{ row.status_label }}</el-tag>
        </template>
      </el-table-column>
      <el-table-column label="操作" width="200" fixed="right">
        <template #default="{ row }">
          <el-button
            v-if="row.status === 0 && auth.has('sales.outbound.update')"
            link
            type="primary"
            @click="openEdit(row)"
            >编 辑</el-button
          >
          <el-button
            v-if="row.status === 0 && auth.has('sales.outbound.delete')"
            link
            type="danger"
            @click="removeRowAction(row)"
            >删 除</el-button
          >
          <el-button
            v-if="row.status === 0 && auth.has('sales.outbound.update')"
            link
            type="success"
            @click="approveRow(row)"
            >审 核</el-button
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
        @current-change="refresh"
      />
    </div>

    <!-- 新建弹窗（双入口） -->
    <el-dialog
      v-model="dialogVisible"
      :title="editingId ? '编辑出库单' : mode === 'from-order' ? '从订单生成出库单' : '新 建出库单'"
      width="900px"
      :close-on-click-modal="false"
    >
      <el-form label-width="90px">
        <div class="form-grid">
          <el-form-item v-if="mode === 'from-order'" label="来源订单" required>
            <el-select
              v-model="fromOrderId"
              placeholder="选择已审核/部分出库订单"
              filterable
              style="width: 100%"
              @change="onOrderChange"
            >
              <el-option
                v-for="o in availableOrders"
                :key="o.id"
                :label="`${o.no}（${o.customer_name}）`"
                :value="o.id"
              />
            </el-select>
          </el-form-item>
          <el-form-item label="客户" required>
            <el-select
              v-model="form.customer_id"
              placeholder="选择客户"
              filterable
              style="width: 100%"
              :disabled="mode === 'from-order'"
            >
              <el-option
                v-for="c in customers"
                :key="c.id"
                :label="`${c.name}（${c.code}）`"
                :value="c.id"
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
        <div class="scan-entry">
          <el-button class="btn-secondary" @click="scanVisible = true">扫码添加</el-button>
        </div>
        <el-table :data="form.items" size="small" max-height="360" class="data-table">
          <el-table-column label="商品" min-width="220">
            <template #default="{ row, $index }">
              <el-select
                v-model="row.product_id"
                placeholder="选择商品"
                filterable
                style="width: 100%"
                :disabled="mode === 'from-order'"
                @change="onProductChange(row, $index)"
              >
                <el-option
                  v-for="p in products"
                  :key="p.id"
                  :label="`${p.name}（${p.code}）`"
                  :value="p.id"
                />
              </el-select>
            </template>
          </el-table-column>
          <el-table-column label="数量" width="130">
            <template #default="{ row }">
              <el-input-number
                v-model="row.quantity"
                :min="1"
                :precision="2"
                :controls="false"
                style="width: 100%"
              />
            </template>
          </el-table-column>
          <el-table-column label="单价（元）" width="150">
            <template #default="{ row }">
              <el-input-number
                v-model="row.price"
                :min="0"
                :precision="2"
                :controls="false"
                style="width: 100%"
              />
            </template>
          </el-table-column>
          <el-table-column label="金额" width="130" align="right">
            <template #default="{ row }">
              <span class="amount-cell">¥{{ rowAmountFen(row) }}</span>
            </template>
          </el-table-column>
          <el-table-column v-if="mode === 'standalone'" label="" width="60">
            <template #default="{ $index }">
              <el-button link type="danger" @click="removeRow($index)">删 除</el-button>
            </template>
          </el-table-column>
        </el-table>
        <div v-if="mode === 'standalone'" class="add-row">
          <el-button link type="primary" @click="addRow">+ 添加明细行</el-button>
        </div>
        <div class="total-bar">
          合计：<span class="total-amount">¥{{ totalYuan() }}</span>
        </div>
      </el-form>
      <template #footer>
        <el-button @click="dialogVisible = false">取 消</el-button>
        <el-button class="btn-primary" :loading="saving" @click="save">保 存</el-button>
      </template>
    </el-dialog>
    <!-- 扫码录入独立弹窗（spec §4.4：原料禁售，扫描行关闭时回带合并；独立/从订单模式均可用） -->
    <ScanInboundForm
      v-model:open="scanVisible"
      title="扫码录入明细"
      :excluded-ids="scanExcludedIds"
      :blocked-type="'raw_material'"
      @add-items="onScanItems"
    />
    <!-- 详情弹窗 -->
    <el-dialog v-model="detailVisible" title="出库单详情" width="900px">
      <template v-if="detail">
        <el-descriptions :column="3" border size="small">
          <el-descriptions-item label="单号">{{ detail.no }}</el-descriptions-item>
          <el-descriptions-item label="客户">{{ detail.customer_name }}</el-descriptions-item>
          <el-descriptions-item label="状态">
            <el-tag :type="statusTagType(detail.status)">{{ detail.status_label }}</el-tag>
          </el-descriptions-item>
          <el-descriptions-item label="仓库">{{ detail.warehouse_name }}</el-descriptions-item>
          <el-descriptions-item label="库位">{{ detail.location_name }}</el-descriptions-item>
          <el-descriptions-item label="金额合计">
            <span class="amount-cell">¥{{ formatYuan(detail.total_amount) }}</span>
          </el-descriptions-item>
          <el-descriptions-item label="来源订单">
            <span v-if="detail.order_no" class="font-code">{{ detail.order_no }}</span>
            <span v-else class="muted">—（独立出库）</span>
          </el-descriptions-item>
          <el-descriptions-item label="审核人">{{ detail.operator ?? '—' }}</el-descriptions-item>
          <el-descriptions-item label="出库时间">{{
            detail.outbound_at ?? '—'
          }}</el-descriptions-item>
        </el-descriptions>
        <el-table :data="detail.items" size="small" class="data-table" style="margin-top: 16px">
          <el-table-column
            prop="product_code"
            label="商品编码"
            class-name="font-code"
            min-width="110"
          />
          <el-table-column prop="product_name" label="商品名称" min-width="140" />
          <el-table-column prop="quantity" label="数量" align="right" width="100" />
          <el-table-column label="单价" align="right" width="110">
            <template #default="{ row }"
              ><span class="amount-cell">¥{{ formatYuan(row.price) }}</span></template
            >
          </el-table-column>
          <el-table-column label="金额" align="right" width="120">
            <template #default="{ row }"
              ><span class="amount-cell">¥{{ formatYuan(row.amount) }}</span></template
            >
          </el-table-column>
        </el-table>
      </template>
      <template #footer>
        <el-button @click="detailVisible = false">关 闭</el-button>
      </template>
    </el-dialog>
  </div>
</template>
<style scoped>
/* 销售出库单页样式（nexus-factory）：与采购入库单页同骨架；顶部汇总行/独立出库「来源订单」列灰字占位 */
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
.btn-secondary {
  border-color: var(--color-primary);
  color: var(--color-primary);
}
.btn-secondary:hover {
  background: var(--color-muted);
}
/* 今日已出库汇总行（当日累计，轻量统计） */
.summary-bar {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: var(--space-sm);
  margin-bottom: var(--space-lg);
  padding: var(--space-sm) var(--space-md);
  background: #ecfdf5;
  border: 1px solid #a7f3d0;
  border-radius: 6px;
  font-size: 13px;
}
.summary-label {
  color: var(--color-foreground);
  font-weight: 600;
}
.summary-chip {
  font-family: 'Fira Code', monospace;
  font-weight: 600;
  color: #047857;
}
.pager {
  display: flex;
  justify-content: flex-end;
  margin-top: var(--space-lg);
}
.amount-cell {
  font-family: 'Fira Code', monospace;
  font-weight: 600;
}
.muted {
  color: var(--color-muted-text, #94a3b8);
}
.form-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 0 var(--space-lg);
}
.scan-entry {
  margin-bottom: var(--space-md);
}
.add-row {
  margin: var(--space-md) 0;
}
.total-bar {
  text-align: right;
  font-size: 14px;
  color: var(--color-foreground);
  padding-top: var(--space-md);
  border-top: 1px solid var(--color-border);
}
.total-amount {
  font-family: 'Fira Code', monospace;
  font-weight: 700;
  font-size: 16px;
  color: var(--color-accent);
}
</style>
