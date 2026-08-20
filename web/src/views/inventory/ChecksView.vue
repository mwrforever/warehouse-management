<!-- 库存盘点页：草稿 CRUD + 审核（确认/结果弹窗）+ 详情只读 -->
<template>
  <div class="page-card">
    <ListFilterBar
      v-model:keyword="query.keyword"
      title="库存盘点"
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
        <el-button v-if="auth.has('check.create')" class="btn-primary" @click="openCreate"
          >新 建</el-button
        >
      </template>
    </ListFilterBar>

    <el-table v-loading="loading" :data="list">
      <el-table-column prop="no" label="单号" width="180" class-name="font-code" />
      <el-table-column prop="warehouse_name" label="仓库" width="110" />
      <el-table-column label="状态" width="100">
        <template #default="{ row }">
          <el-tag :type="row.status === 1 ? 'success' : 'info'">{{
            row.status === 1 ? '已审核' : '草稿'
          }}</el-tag>
        </template>
      </el-table-column>
      <el-table-column prop="checker" label="审核人" width="100" />
      <el-table-column prop="check_time" label="审核时间" width="170" />
      <el-table-column prop="remark" label="备注" min-width="120" />
      <el-table-column label="操作" width="200" fixed="right">
        <template #default="{ row }">
          <template v-if="row.status === 0">
            <el-button v-if="auth.has('check.update')" link type="primary" @click="openEdit(row)"
              >编 辑</el-button
            >
            <el-button v-if="auth.has('check.delete')" link type="danger" @click="remove(row)"
              >删 除</el-button
            >
            <el-button v-if="auth.has('check.update')" link type="success" @click="approve(row)"
              >审 核</el-button
            >
          </template>
          <el-button v-else link type="primary" @click="openDetail(row.id)">查 看</el-button>
        </template>
      </el-table-column>
    </el-table>
    <el-pagination
      v-model:current-page="query.page"
      :total="total"
      :page-size="query.per_page"
      layout="total, prev, pager, next"
      @current-change="refresh"
    />

    <!-- 新建/编辑弹窗：仓库 + 加载账面数 + 扫码 + 明细行实盘录入 -->
    <el-dialog
      v-model="dialogVisible"
      :title="form.id ? '编辑盘点单' : '新建盘点单'"
      width="900px"
      :close-on-click-modal="false"
    >
      <div class="dialog-body">
        <div class="check-toolbar">
          <el-select
            v-model="form.warehouse_id"
            placeholder="盘点仓库"
            :disabled="!!form.id"
            style="width: 180px"
          >
            <el-option v-for="w in warehouses" :key="w.id" :label="w.name" :value="w.id" />
          </el-select>
          <el-button
            class="btn-secondary"
            :disabled="!form.warehouse_id"
            :loading="loadingBooks"
            @click="loadBooks"
            >加 载账面数</el-button
          >
          <el-input
            ref="barcodeInput"
            v-model="barcode"
            placeholder="扫描条码回车添加商品"
            clearable
            style="width: 240px"
            @keyup.enter="scanAdd"
          />
        </div>
        <el-table :data="form.items" size="small" max-height="360">
          <el-table-column label="商品" min-width="200">
            <template #default="{ row }">{{ row.product_name }}（{{ row.product_code }}）</template>
          </el-table-column>
          <el-table-column prop="location_name" label="库位" width="90" />
          <el-table-column label="账面数" width="110" align="right">
            <template #default="{ row }"
              ><span class="book-qty">{{ row.book_qty }}</span></template
            >
          </el-table-column>
          <el-table-column label="实盘数" width="160">
            <template #default="{ row }">
              <el-input-number
                v-model="row.actual_qty"
                :min="0"
                :precision="2"
                :controls="false"
                style="width: 110px"
              />
            </template>
          </el-table-column>
          <el-table-column label="操作" width="70">
            <template #default="{ $index }">
              <el-button link type="danger" @click="form.items.splice($index, 1)">删 除</el-button>
            </template>
          </el-table-column>
        </el-table>
        <div class="dialog-remark">
          <el-input v-model="form.remark" placeholder="备注（可选）" />
        </div>
      </div>
      <template #footer>
        <el-button @click="dialogVisible = false">取 消</el-button>
        <el-button type="primary" class="btn-primary" :loading="saving" @click="save"
          >保 存</el-button
        >
      </template>
    </el-dialog>

    <!-- 详情只读弹窗：含差异列（红负/绿正） -->
    <el-dialog v-model="detailVisible" title="盘点单详情" width="800px">
      <div v-if="detail" class="detail-head">
        <span class="font-code">单号 {{ detail.no }}</span>
        <span>仓库 {{ detail.warehouse_name }}</span>
        <el-tag :type="detail.status === 1 ? 'success' : 'info'">{{
          detail.status === 1 ? '已审核' : '草稿'
        }}</el-tag>
      </div>
      <el-table :data="detail?.items ?? []" size="small">
        <el-table-column label="商品" min-width="200">
          <template #default="{ row }">{{ row.product_name }}（{{ row.product_code }}）</template>
        </el-table-column>
        <el-table-column prop="location_name" label="库位" width="90" />
        <el-table-column prop="book_qty" label="账面数" width="110" align="right" />
        <el-table-column prop="actual_qty" label="实盘数" width="110" align="right" />
        <el-table-column label="差异" width="110" align="right">
          <template #default="{ row }">
            <span
              :class="
                Number(row.diff_qty) > 0 ? 'diff-in' : Number(row.diff_qty) < 0 ? 'diff-out' : ''
              "
              >{{ row.diff_qty }}</span
            >
          </template>
        </el-table-column>
      </el-table>
      <template #footer>
        <el-button @click="detailVisible = false">关 闭</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup lang="ts">
// 库存盘点页：草稿增删改 + 账面预填 + 扫码录入 + 审核（确认与结果弹窗）+ 详情查看
import { onMounted, reactive, ref, nextTick } from 'vue'
import { useRoute } from 'vue-router'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  inventoryApi,
  type CheckItem,
  type CheckDetailItem,
  type AutoBookItem,
} from '../../api/inventory'
import { productApi } from '../../api/product'
import { warehouseApi } from '../../api/warehouse'
import ListFilterBar from '../../components/ListFilterBar.vue'
import { useListQuery } from '../../composables/useListQuery'
import { useAuthStore } from '../../stores/auth'

const auth = useAuthStore()
const route = useRoute()
const warehouses = ref<{ id: number; name: string }[]>([])

// 列表查询状态（统一组合式：防抖加载/查询/重置/刷新，请求序号守卫并发）
const { query, list, total, loading, load, search, reset, refresh } = useListQuery({
  defaultQuery: { keyword: '', status: undefined as number | undefined },
  fetch: (q) => inventoryApi.checks({ ...q, keyword: q.keyword || undefined }),
  onError: (e) => ElMessage.error(e.message),
})

// 新建/编辑弹窗
const dialogVisible = ref(false)
const saving = ref(false)
const loadingBooks = ref(false)
const barcode = ref('')
const barcodeInput = ref<{ focus: () => void } | null>(null)
// 账面缓存：autoBooks 结果（扫码回填账面数用）
const books = ref<AutoBookItem[]>([])
interface CheckRow {
  product_id: number
  product_name: string
  product_code: string
  location_id: number
  location_name: string
  book_qty: number
  actual_qty: number
}
interface CheckForm {
  id: number | null
  warehouse_id?: number
  remark: string
  items: CheckRow[]
}
const form = reactive<CheckForm>({ id: null, warehouse_id: undefined, remark: '', items: [] })

// 详情弹窗
const detailVisible = ref(false)
const detail = ref<{
  no: string
  warehouse_name: string
  status: number
  items: CheckDetailItem[]
} | null>(null)

// 删除草稿
async function remove(row: CheckItem) {
  try {
    await ElMessageBox.confirm('确认删除该盘点单？', '提示', { type: 'warning' })
  } catch {
    // 用户取消删除
    return
  }
  try {
    await inventoryApi.deleteCheck(row.id)
    ElMessage.success('删除成功')
    refresh()
  } catch (e) {
    ElMessage.error((e as Error).message)
  }
}
// 新建：打开弹窗并聚焦扫码框
function openCreate() {
  Object.assign(form, { id: null, warehouse_id: undefined, remark: '', items: [] })
  books.value = []
  dialogVisible.value = true
  nextTick(() => barcodeInput.value?.focus())
}

// 编辑：回填明细（仓库锁定）
async function openEdit(row: CheckItem) {
  try {
    const d = await inventoryApi.checkDetail(row.id)
    form.id = d.id
    form.warehouse_id = d.warehouse_id
    form.remark = d.remark ?? ''
    form.items = d.items.map((i) => ({
      product_id: i.product_id,
      product_name: i.product_name,
      product_code: i.product_code,
      location_id: i.location_id,
      location_name: i.location_name,
      book_qty: i.book_qty,
      actual_qty: i.actual_qty,
    }))
    // 清空账面缓存，避免残留上一仓库数据导致扫码误判
    books.value = []
    dialogVisible.value = true
  } catch (e) {
    ElMessage.error((e as Error).message)
  }
}

// 加载账面数：明细重置为该仓库全部有余额行（实盘默认=账面）
async function loadBooks() {
  if (!form.warehouse_id) return
  loadingBooks.value = true
  try {
    const res = await inventoryApi.autoBooks(form.warehouse_id)
    books.value = res.items
    form.items = res.items.map((b) => ({
      product_id: b.product_id,
      product_name: b.product_name,
      product_code: b.product_code,
      location_id: b.location_id,
      location_name: b.location_name,
      book_qty: b.book_qty,
      actual_qty: b.book_qty,
    }))
  } catch (e) {
    ElMessage.error((e as Error).message)
  } finally {
    loadingBooks.value = false
  }
}

// 扫码：条码回车 → 商品匹配 → 有余额则追加行（账面数回填），无余额提示
async function scanAdd() {
  const code = barcode.value.trim()
  if (!code) return
  try {
    const p = await productApi.byBarcode(code)
    // 未加载账面缓存时先加载缓存（不重置明细，扫码只追加命中行）
    if (form.warehouse_id && books.value.length === 0) {
      try {
        const res = await inventoryApi.autoBooks(form.warehouse_id)
        books.value = res.items
      } catch (e) {
        ElMessage.error((e as Error).message)
        return
      }
    }
    const book = books.value.find((b) => b.product_id === p.id)
    // 无账面行的商品不可录盘：账外资产盘盈（book_qty=0 建账）功能暂不做，
    // 裁决与实施改动点见 docs/bugs/2026-08-13-盘点盘盈无余额行误拒.md
    if (!book) {
      ElMessage.error('商品在该仓库无库存，无需盘点')
      return
    }
    if (form.items.some((i) => i.product_id === p.id)) {
      ElMessage.warning('该商品已在明细中')
      return
    }
    appendRow(book)
    barcode.value = ''
  } catch (e) {
    // 条码未匹配（后端 1117）：保留输入便于重扫
    ElMessage.error((e as Error).message)
  }
}

// 追加盘点行（实盘默认=账面）
function appendRow(book: AutoBookItem) {
  form.items.push({
    product_id: book.product_id,
    product_name: book.product_name,
    product_code: book.product_code,
    location_id: book.location_id,
    location_name: book.location_name,
    book_qty: book.book_qty,
    actual_qty: book.book_qty,
  })
}

// 保存草稿：新建/更新；负数由 el-input-number min=0 前端拦截
async function save() {
  if (!form.warehouse_id) return ElMessage.warning('请选择盘点仓库')
  if (form.items.length === 0) return ElMessage.warning('请先加载账面数或扫码添加明细')
  saving.value = true
  try {
    const payload = {
      warehouse_id: form.warehouse_id,
      remark: form.remark || undefined,
      items: form.items.map((i) => ({
        product_id: i.product_id,
        location_id: i.location_id,
        actual_qty: i.actual_qty,
      })),
    }
    if (form.id) await inventoryApi.updateCheck(form.id, payload)
    else await inventoryApi.createCheck(payload)
    ElMessage.success('保存成功')
    dialogVisible.value = false
    // 保存后按当前页立即刷新（与既有行为一致：不重置页码）
    refresh()
  } catch (e) {
    ElMessage.error((e as Error).message)
  } finally {
    saving.value = false
  }
}

// 审核：二次确认 → approve → 结果弹窗
async function approve(row: CheckItem) {
  try {
    await ElMessageBox.confirm('确认审核？差异将生成盘盈/盘亏流水并更新库存', '审核确认', {
      confirmButtonText: '确 定',
      cancelButtonText: '取 消',
      type: 'warning',
    })
  } catch {
    // 用户取消审核
    return
  }
  try {
    const res = await inventoryApi.approveCheck(row.id)
    if (res.changed_items > 0) {
      await ElMessageBox.alert(
        `盘盈 ${res.increased_items} 项 +${res.increased}、盘亏 ${res.decreased_items} 项 -${res.decreased}`,
        '审核完成',
        { confirmButtonText: '确 定' },
      )
    } else {
      await ElMessageBox.alert('本次无差异，未生成流水', '审核完成', { confirmButtonText: '确 定' })
    }
    // 审核后按当前页立即刷新（状态流转后行变化，保持分页位置）
    refresh()
  } catch (e) {
    ElMessage.error((e as Error).message)
  }
}

// 详情查看（含从流水页单号跳转直达）
async function openDetail(id: number) {
  try {
    const d = await inventoryApi.checkDetail(id)
    detail.value = {
      no: d.no,
      warehouse_name: d.warehouse_name,
      status: d.status,
      items: d.items,
    }
    detailVisible.value = true
  } catch (e) {
    ElMessage.error((e as Error).message)
  }
}

onMounted(async () => {
  search()
  // 流水页单号跳转直达详情
  const id = route.params.id
  if (id) openDetail(Number(id))
  try {
    const w = await warehouseApi.list({ per_page: 100 })
    warehouses.value = w.items.map((i) => ({ id: i.id, name: i.name }))
  } catch {
    // 下拉失败不阻断列表
  }
})
</script>

<style scoped>
/* 弹窗布局与差异色（设计系统 inventory.md §4） */
.check-toolbar {
  display: flex;
  gap: var(--space-md);
  margin-bottom: var(--space-lg);
}
.dialog-remark {
  margin-top: var(--space-lg);
}
.book-qty {
  color: #64748b;
}
.diff-in {
  color: #059669;
  font-weight: 700;
}
.diff-out {
  color: #dc2626;
  font-weight: 700;
}
.detail-head {
  display: flex;
  gap: var(--space-xl);
  align-items: center;
  margin-bottom: var(--space-lg);
}
</style>
