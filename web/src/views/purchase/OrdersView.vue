<!-- 采购订单页：筛选列表 + 新建/编辑弹窗（900px 明细/扫码/实时合计）+ 详情弹窗（含入库记录） -->
<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  purchaseApi,
  type OrderInboundItem,
  type PurchaseOrderDetail,
  type PurchaseOrderItem,
} from '../../api/purchase'
import { supplierApi, type SupplierItem } from '../../api/supplier'
import { productApi } from '../../api/product'
import ListFilterBar from '../../components/ListFilterBar.vue'
import ScanInboundForm, { type ScanItem } from '../../components/ScanInboundForm.vue'
import { useListQuery } from '../../composables/useListQuery'
import { useAuthStore } from '../../stores/auth'
import { formatThousand, formatYuan, toLocalDateString } from '../../utils/format'

const auth = useAuthStore()
const saving = ref(false)
const suppliers = ref<SupplierItem[]>([])
const products = ref<{ id: number; name: string; code: string; barcode: string | null }[]>([])

// 列表查询状态（统一组合式：防抖加载/查询/重置/刷新，请求序号守卫并发）
const { query, list, total, loading, load, search, reset, refresh } = useListQuery({
  defaultQuery: { keyword: '', supplier_id: undefined, status: undefined },
  fetch: (q) => purchaseApi.orders(q),
  onError: (e) => ElMessage.error(e.message),
})

// 弹窗状态
const dialogVisible = ref(false)
const editing = ref(false)
const editingId = ref<number | null>(null)
const detailVisible = ref(false)
const detail = ref<PurchaseOrderDetail | null>(null)
const inboundRows = ref<OrderInboundItem[]>([])
const form = reactive({
  supplier_id: undefined as number | undefined,
  order_date: toLocalDateString(new Date()),
  expected_date: undefined as string | undefined,
  remark: '',
  items: [] as { product_id: number | undefined; quantity: number; price: number }[],
})

// 状态标签语义色（purchase.md 五态：草稿灰/已审核绿/部分入库蓝/已完成深绿/关闭红）
function statusTagType(status: number) {
  if (status === 0) return 'info'
  if (status === 1) return 'success'
  if (status === 2) return 'primary'
  if (status === 3) return 'success'
  return 'danger'
}

// 明细合计（元，实时计算：Σ 数量×单价元）
function lineAmountYuan(item: { quantity: number; price: number }): number {
  return Number((Number(item.quantity) * Number(item.price)).toFixed(2))
}
function totalYuan(): string {
  // 合计展示千分位统一走 utils/format（D-16）；行金额折算（lineAmountYuan）属计算逻辑保持本地
  return formatThousand(form.items.reduce((sum, i) => sum + lineAmountYuan(i), 0))
}

// 行金额（分→元展示，仅读列）
function rowAmountFen(item: { product_id?: number; quantity: number; price: number }): string {
  return formatYuan(Math.round(Number(item.quantity) * Number(item.price) * 100))
}

// 添加明细行
function addRow() {
  form.items.push({ product_id: undefined, quantity: 1, price: 0 })
}

// 删除明细行
function removeRow(index: number) {
  form.items.splice(index, 1)
}

// 商品选择（重复商品即时拦截）
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

// 扫码弹窗关闭：扫描行按商品合并进明细（同商品数量相加；累加关时弹窗内已拦重复，不会撞已有行）
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

// 新建/编辑弹窗
function openCreate() {
  editing.value = false
  editingId.value = null
  Object.assign(form, {
    supplier_id: undefined,
    order_date: toLocalDateString(new Date()),
    expected_date: undefined,
    remark: '',
    items: [],
  })
  form.items.push({ product_id: undefined, quantity: 1, price: 0 })
  dialogVisible.value = true
}

async function openEdit(row: PurchaseOrderItem) {
  try {
    const d = await purchaseApi.orderDetail(row.id)
    editing.value = true
    editingId.value = row.id
    Object.assign(form, {
      supplier_id: d.supplier_id,
      order_date: d.order_date,
      expected_date: d.expected_date ?? undefined,
      remark: d.remark ?? '',
      items: d.items.map((i) => ({
        product_id: i.product_id,
        quantity: Number(i.quantity),
        price: Number(i.price) / 100,
      })),
    })
    dialogVisible.value = true
  } catch (e) {
    ElMessage.error((e as Error).message)
  }
}

async function save() {
  if (!form.supplier_id) {
    ElMessage.warning('请选择供应商')
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
  if (form.items.some((i) => Number(i.price) < 0)) {
    ElMessage.warning('价格不能为负数')
    return
  }
  // 载荷：价格 元 → 分（×100 取整）；数量原样（2 位小数）
  const payload = {
    supplier_id: form.supplier_id!,
    order_date: form.order_date,
    expected_date: form.expected_date,
    remark: form.remark,
    items: form.items.map((i) => ({
      product_id: i.product_id!,
      quantity: i.quantity,
      price: Math.round(Number(i.price) * 100),
    })),
  }
  saving.value = true
  try {
    if (editing.value && editingId.value) {
      await purchaseApi.updateOrder(editingId.value, payload)
    } else {
      await purchaseApi.createOrder(payload)
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

async function removeRowAction(row: PurchaseOrderItem) {
  try {
    await ElMessageBox.confirm(`确认删除订单 ${row.no}？删除后不可恢复`, '提示', {
      type: 'warning',
      confirmButtonText: '确 定',
      cancelButtonText: '取 消',
    })
  } catch {
    return
  }
  try {
    await purchaseApi.deleteOrder(row.id)
    ElMessage.success('删除成功')
    refresh()
  } catch (e) {
    ElMessage.error((e as Error).message)
  }
}

async function approveRow(row: PurchaseOrderItem) {
  try {
    await ElMessageBox.confirm(`确认审核订单 ${row.no}？审核后不可修改`, '提示', {
      type: 'warning',
      confirmButtonText: '确 定',
      cancelButtonText: '取 消',
    })
  } catch {
    return
  }
  try {
    await purchaseApi.approveOrder(row.id)
    ElMessage.success('审核成功')
    refresh()
  } catch (e) {
    ElMessage.error((e as Error).message)
  }
}

async function closeRow(row: PurchaseOrderItem) {
  try {
    await ElMessageBox.confirm(`确认关闭订单 ${row.no}？关闭后不可再入库`, '提示', {
      type: 'warning',
      confirmButtonText: '确 定',
      cancelButtonText: '取 消',
    })
  } catch {
    return
  }
  try {
    await purchaseApi.closeOrder(row.id)
    ElMessage.success('关闭成功')
    refresh()
  } catch (e) {
    ElMessage.error((e as Error).message)
  }
}

// 详情弹窗 + 入库记录 tab
async function openDetail(row: PurchaseOrderItem) {
  try {
    detail.value = await purchaseApi.orderDetail(row.id)
    inboundRows.value = (await purchaseApi.orderInbounds(row.id)).items
    detailVisible.value = true
  } catch (e) {
    ElMessage.error((e as Error).message)
  }
}

onMounted(async () => {
  search()
  try {
    const res = await supplierApi.list({ per_page: 100, status: 1 })
    suppliers.value = res.items
    const p = await productApi.list({ per_page: 100 })
    products.value = p.items
  } catch {
    // 下拉加载失败不阻塞主流程
  }
})
</script>
<template>
  <div class="page-card">
    <ListFilterBar
      v-model:keyword="query.keyword"
      title="采购订单"
      keyword-placeholder="单号"
      @keyword-change="() => load()"
      @search="search"
      @reset="reset"
      @refresh="refresh"
    >
      <el-select
        v-model="query.supplier_id"
        placeholder="供应商"
        clearable
        style="width: 180px"
        @change="() => load()"
      >
        <el-option v-for="s in suppliers" :key="s.id" :label="s.name" :value="s.id" />
      </el-select>
      <el-select
        v-model="query.status"
        placeholder="状态"
        clearable
        style="width: 130px"
        @change="() => load()"
      >
        <el-option label="草稿" :value="0" />
        <el-option label="已审核" :value="1" />
        <el-option label="部分入库" :value="2" />
        <el-option label="已完成" :value="3" />
        <el-option label="关闭" :value="4" />
      </el-select>
      <template #actions>
        <el-button v-if="auth.has('purchase.order.create')" class="btn-primary" @click="openCreate"
          >新 建</el-button
        >
      </template>
    </ListFilterBar>

    <el-table v-loading="loading" :data="list" class="data-table">
      <el-table-column prop="no" label="单号" class-name="font-code" min-width="150" />
      <el-table-column prop="supplier_name" label="供应商" min-width="140" />
      <el-table-column prop="order_date" label="下单日期" width="110" />
      <el-table-column label="金额合计" width="130" align="right">
        <template #default="{ row }">
          <span class="amount-cell">¥{{ formatYuan(row.total_amount) }}</span>
        </template>
      </el-table-column>
      <el-table-column label="状态" width="100">
        <template #default="{ row }">
          <el-tag :type="statusTagType(row.status)" :class="{ 'tag-done': row.status === 3 }">{{
            row.status_label
          }}</el-tag>
        </template>
      </el-table-column>
      <el-table-column prop="created_by_name" label="创建人" width="90" />
      <el-table-column label="操作" width="220" fixed="right">
        <template #default="{ row }">
          <el-button
            v-if="row.status === 0 && auth.has('purchase.order.update')"
            link
            type="primary"
            @click="openEdit(row)"
            >编 辑</el-button
          >
          <el-button
            v-if="row.status === 0 && auth.has('purchase.order.delete')"
            link
            type="danger"
            @click="removeRowAction(row)"
            >删 除</el-button
          >
          <el-button
            v-if="row.status === 0 && auth.has('purchase.order.update')"
            link
            type="success"
            @click="approveRow(row)"
            >审 核</el-button
          >
          <el-button
            v-if="(row.status === 1 || row.status === 2) && auth.has('purchase.order.update')"
            link
            type="warning"
            @click="closeRow(row)"
            >关 闭</el-button
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

    <!-- 新建/编辑弹窗 -->
    <el-dialog
      v-model="dialogVisible"
      :title="editing ? '编辑订单' : '新 建订单'"
      width="900px"
      :close-on-click-modal="false"
    >
      <el-form :model="form" label-width="90px">
        <div class="form-grid">
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
          <el-form-item label="下单日期" required>
            <el-date-picker
              v-model="form.order_date"
              type="date"
              value-format="YYYY-MM-DD"
              style="width: 100%"
            />
          </el-form-item>
          <el-form-item label="预计到货">
            <el-date-picker
              v-model="form.expected_date"
              type="date"
              value-format="YYYY-MM-DD"
              style="width: 100%"
            />
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
          <el-table-column label="含税单价（元）" width="150">
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
          <el-table-column label="" width="60">
            <template #default="{ $index }">
              <el-button link type="danger" @click="removeRow($index)">删 除</el-button>
            </template>
          </el-table-column>
        </el-table>
        <div class="add-row">
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

    <!-- 扫码录入独立弹窗（spec §4.4：扫描行关闭时回带合并） -->
    <ScanInboundForm
      v-model:open="scanVisible"
      title="扫码录入明细"
      :excluded-ids="scanExcludedIds"
      @add-items="onScanItems"
    />

    <!-- 详情弹窗（含入库记录 tab） -->
    <el-dialog v-model="detailVisible" title="订单详情" width="900px">
      <template v-if="detail">
        <el-descriptions :column="3" border size="small">
          <el-descriptions-item label="单号">{{ detail.no }}</el-descriptions-item>
          <el-descriptions-item label="供应商">{{ detail.supplier_name }}</el-descriptions-item>
          <el-descriptions-item label="状态">
            <el-tag
              :type="statusTagType(detail.status)"
              :class="{ 'tag-done': detail.status === 3 }"
              >{{ detail.status_label }}</el-tag
            >
          </el-descriptions-item>
          <el-descriptions-item label="下单日期">{{ detail.order_date }}</el-descriptions-item>
          <el-descriptions-item label="预计到货">{{
            detail.expected_date ?? '—'
          }}</el-descriptions-item>
          <el-descriptions-item label="金额合计">
            <span class="amount-cell">¥{{ formatYuan(detail.total_amount) }}</span>
          </el-descriptions-item>
          <el-descriptions-item label="审核时间">{{
            detail.approved_at ?? '—'
          }}</el-descriptions-item>
          <el-descriptions-item label="备注" :span="2">{{
            detail.remark ?? '—'
          }}</el-descriptions-item>
        </el-descriptions>
        <el-tabs style="margin-top: 16px">
          <el-tab-pane label="明细">
            <el-table :data="detail.items" size="small" class="data-table">
              <el-table-column
                prop="product_code"
                label="商品编码"
                class-name="font-code"
                min-width="110"
              />
              <el-table-column prop="product_name" label="商品名称" min-width="140" />
              <el-table-column prop="quantity" label="订购数" align="right" width="100" />
              <el-table-column prop="received_qty" label="已入库" align="right" width="100" />
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
          </el-tab-pane>
          <el-tab-pane label="入库记录">
            <el-table v-if="inboundRows.length" :data="inboundRows" size="small" class="data-table">
              <el-table-column prop="no" label="入库单号" class-name="font-code" min-width="150" />
              <el-table-column prop="status_label" label="状态" width="90" />
              <el-table-column prop="inbound_at" label="入库时间" width="160" />
              <el-table-column prop="operator" label="审核人" width="90" />
              <el-table-column label="金额" align="right" width="120">
                <template #default="{ row }"
                  ><span class="amount-cell">¥{{ formatYuan(row.total_amount) }}</span></template
                >
              </el-table-column>
            </el-table>
            <el-empty v-else description="暂无入库记录" />
          </el-tab-pane>
        </el-tabs>
      </template>
      <template #footer>
        <el-button @click="detailVisible = false">关 闭</el-button>
      </template>
    </el-dialog>
  </div>
</template>
<style scoped>
/* 采购订单页样式（nexus-factory）：骨架与基础资料页一致，采购特有样式见 pages/purchase.md */
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
.pager {
  display: flex;
  justify-content: flex-end;
  margin-top: var(--space-lg);
}
/* 金额列：Fira Code + 右对齐 + ¥ 前缀（purchase.md §1） */
.amount-cell {
  font-family: 'Fira Code', monospace;
  font-weight: 600;
}
/* 已完成深绿（与已审核绿同族但明度更低，防同态混淆） */
.tag-done {
  background: #ecfdf5 !important;
  color: #047857 !important;
  border-color: #047857 !important;
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
