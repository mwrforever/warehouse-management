<!-- BOM 管理页：列表 + 新建/编辑大弹窗（明细动态行）+ 明细查看 + 启用/停用切换 -->
<template>
  <div class="page-card">
    <div class="toolbar">
      <span class="page-title">BOM 管理</span>
      <div class="toolbar-right">
        <el-input
          v-model="query.keyword"
          placeholder="BOM 编码"
          clearable
          style="width: 220px"
          @keyup.enter="load"
        />
        <el-button v-if="auth.has('bom.create')" class="btn-primary" @click="openCreate"
          >新 建</el-button
        >
      </div>
    </div>
    <el-table v-loading="loading" :data="rows">
      <el-table-column prop="code" label="BOM 编码" width="170" class-name="font-code" />
      <el-table-column prop="product_name" label="成品名称" min-width="140" />
      <el-table-column prop="version" label="版本" width="80" class-name="font-code" />
      <el-table-column prop="quantity" label="基准数量" width="90" />
      <el-table-column label="状态" width="90">
        <template #default="{ row }">
          <el-tag :type="row.status === 1 ? 'success' : 'info'">{{
            row.status === 1 ? '启用' : '停用'
          }}</el-tag>
        </template>
      </el-table-column>
      <el-table-column label="操作" width="270" fixed="right">
        <template #default="{ row }">
          <el-button v-if="auth.has('bom.list')" link type="primary" @click="openItems(row)"
            >明 细</el-button
          >
          <el-button v-if="auth.has('bom.update')" link type="primary" @click="openEdit(row)"
            >编 辑</el-button
          >
          <el-button
            v-if="auth.has('bom.update')"
            link
            :type="row.status === 1 ? 'warning' : 'success'"
            @click="toggle(row)"
            >{{ row.status === 1 ? '停 用' : '启 用' }}</el-button
          >
          <el-button v-if="auth.has('bom.delete')" link type="danger" @click="remove(row)"
            >删 除</el-button
          >
        </template>
      </el-table-column>
    </el-table>
    <el-pagination
      v-model:current-page="query.page"
      :total="total"
      :page-size="10"
      layout="total, prev, pager, next"
      @current-change="load"
    />

    <!-- 新建/编辑弹窗：单头 + 明细动态行（宽 800px） -->
    <el-dialog v-model="dialogVisible" :title="form.id ? '编辑 BOM' : '新建 BOM'" width="800px">
      <el-form :model="form" label-width="90px">
        <el-form-item label="成品" required>
          <el-select
            v-model="form.product_id"
            filterable
            style="width: 100%"
            placeholder="仅成品类型商品"
          >
            <el-option
              v-for="p in finishedProducts"
              :key="p.id"
              :label="`${p.code} ${p.name}`"
              :value="p.id"
            />
          </el-select>
        </el-form-item>
        <el-form-item label="版本" required
          ><el-input v-model="form.version" style="width: 160px"
        /></el-form-item>
        <el-form-item label="基准数量"
          ><el-input-number v-model="form.quantity" :min="0.01" :precision="2"
        /></el-form-item>
        <el-form-item label="备注"
          ><el-input v-model="form.remark" type="textarea" :rows="2"
        /></el-form-item>
        <el-form-item label="保存即启用"
          ><el-switch v-model="form.status" :active-value="1" :inactive-value="0"
        /></el-form-item>
        <el-form-item label="物料明细">
          <div class="items-wrap">
            <div v-for="(row, idx) in form.items" :key="idx" class="item-row">
              <el-select
                v-model="row.material_id"
                filterable
                placeholder="物料（原料/半成品）"
                style="width: 260px"
                @change="(id: number) => applyMaterialUnit(row, id)"
              >
                <el-option
                  v-for="m in materialProducts"
                  :key="m.id"
                  :label="`${m.code} ${m.name}`"
                  :value="m.id"
                />
              </el-select>
              <el-input-number
                v-model="row.quantity"
                :min="0.01"
                :precision="2"
                placeholder="用量"
                style="width: 120px"
              />
              <span class="unit-name">{{ unitName(row.unit_id) }}</span>
              <el-button link type="danger" @click="removeItem(idx)">删 除</el-button>
            </div>
            <el-button class="add-item" @click="addItem">+ 添加物料行</el-button>
          </div>
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="dialogVisible = false">取 消</el-button>
        <el-button type="primary" class="btn-primary" :loading="saving" @click="save"
          >保 存</el-button
        >
      </template>
    </el-dialog>

    <!-- 明细查看弹窗：只读表格 -->
    <el-dialog v-model="itemsVisible" :title="`BOM 明细 - ${currentBom?.code}`" width="560px">
      <el-table :data="itemsRows" size="small">
        <el-table-column prop="material_name" label="物料" />
        <el-table-column prop="quantity" label="用量" width="100" />
        <el-table-column prop="unit_name" label="单位" width="80" />
      </el-table>
    </el-dialog>
  </div>
</template>

<script setup lang="ts">
// BOM 管理页：单头+明细一次保存/全量替换；启用切换自动停用同成品其他版本（后端 1120 兜底）
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { bomApi, type BomItem, type BomRow } from '../../api/bom'
import { productApi, type ProductItem } from '../../api/product'
import { unitApi, type UnitItem } from '../../api/unit'
import { useAuthStore } from '../../stores/auth'

const auth = useAuthStore()
const rows = ref<BomRow[]>([])
const total = ref(0)
const loading = ref(false)
const query = reactive({ page: 1, keyword: '' })
const dialogVisible = ref(false)
const saving = ref(false)

// BOM 明细行表单：物料/单位未选择时为空（null），保存前通过 some 校验必填
interface BomItemForm {
  material_id: number | null
  quantity: number
  unit_id: number | null
}

// BOM 单头表单：id 为空表示新建；明细行为动态数组（items 每次弹窗打开时重置）
interface BomForm {
  id: number | null
  product_id: number | null
  version: string
  quantity: number
  remark: string
  status: number
  items: BomItemForm[]
}

const form = reactive<BomForm>({
  id: null,
  product_id: null,
  version: '',
  quantity: 1,
  remark: '',
  status: 1,
  items: [],
})
const finishedProducts = ref<ProductItem[]>([])
const materialProducts = ref<ProductItem[]>([])
const units = ref<UnitItem[]>([])
const itemsVisible = ref(false)
const currentBom = ref<BomRow | null>(null)
const itemsRows = ref<BomItem[]>([])

// 单位名映射（明细行显示；unit_id 未选时为空）
function unitName(id: number | null) {
  return units.value.find((u) => u.id === id)?.name ?? ''
}

// 加载列表
async function load() {
  loading.value = true
  try {
    const res = await bomApi.list({ page: query.page, per_page: 10, keyword: query.keyword })
    rows.value = res.items
    total.value = res.total
  } finally {
    loading.value = false
  }
}

// 弹窗数据源：成品仅 finished；物料仅 raw_material/semi_finished
function openCreate() {
  Object.assign(form, {
    id: null,
    product_id: null,
    version: 'v1',
    quantity: 1,
    remark: '',
    status: 1,
    items: [newRow()],
  })
  dialogVisible.value = true
}
// 编辑回填明细：先取明细再开弹窗，避免异步回填晚到覆盖新表单（审查 I-1 竞态修复）
async function openEdit(row: BomRow) {
  Object.assign(form, {
    id: row.id,
    product_id: row.product_id,
    version: row.version,
    quantity: row.quantity,
    remark: row.remark,
    status: row.status,
    items: [newRow()],
  })
  try {
    // 明细请求成功后组装行数据并打开弹窗，请求期间表单不会被旧数据回写
    const res = await bomApi.items(row.id)
    form.items = res.items.map((i) => ({
      material_id: i.material_id,
      quantity: i.quantity,
      unit_id: i.unit_id,
    }))
    dialogVisible.value = true
  } catch (e) {
    // 明细加载失败：提示错误且不打开弹窗，避免半成品表单
    ElMessage.error((e as Error).message)
  }
}

// 动态行：默认单位取第一个单位（选择物料后由 applyMaterialUnit 带出该物料单位）
function newRow() {
  return { material_id: null, quantity: 1, unit_id: units.value[0]?.id ?? null }
}
// 选择物料后自动带出其计量单位（spec §5.7：单位自动带出；E2E TC-MST-09 回归）
function applyMaterialUnit(row: { unit_id: number | null }, materialId: number) {
  const m = materialProducts.value.find((p) => p.id === materialId)
  row.unit_id = m?.unit_id ?? null
}
function addItem() {
  form.items.push(newRow())
}
// 删除明细行：模板 v-for 下标经 vue-tsc 推断为 string | number，此处放宽签名并运行时强转索引
function removeItem(idx: string | number) {
  form.items.splice(idx as number, 1)
}

// 保存：单头+明细一次提交；后端 1118/1119/1120/1123 错误提示展示
async function save() {
  if (!form.product_id) return ElMessage.warning('请选择成品')
  if (!form.items.length) return ElMessage.warning('请至少添加一条物料明细')
  if (form.items.some((i) => !i.material_id || !i.quantity || !i.unit_id))
    return ElMessage.warning('请补全物料行信息')
  saving.value = true
  try {
    const payload = {
      product_id: form.product_id,
      version: form.version,
      quantity: form.quantity,
      remark: form.remark,
      status: form.status,
      // 经上面 some 校验后物料/单位必填，此处用 as number 收窄类型（纯类型层面，运行时值不变）
      items: form.items.map((i) => ({
        material_id: i.material_id as number,
        quantity: i.quantity,
        unit_id: i.unit_id as number,
      })),
    }
    if (form.id) await bomApi.update(form.id, payload)
    else await bomApi.create(payload)
    ElMessage.success('保存成功')
    dialogVisible.value = false
    load()
  } catch (e) {
    ElMessage.error((e as Error).message)
  } finally {
    saving.value = false
  }
}

// 明细查看
async function openItems(row: BomRow) {
  currentBom.value = row
  itemsVisible.value = true
  try {
    itemsRows.value = (await bomApi.items(row.id)).items
  } catch (e) {
    // 加载失败提示：避免弹窗空白明细无提示（与其他请求风格一致）
    ElMessage.error((e as Error).message)
  }
}

// 启用/停用切换：后端保证同成品启用唯一
async function toggle(row: BomRow) {
  try {
    await bomApi.toggle(row.id, row.status === 1 ? 0 : 1)
    ElMessage.success(row.status === 1 ? '已停用' : '已启用')
    load()
  } catch (e) {
    ElMessage.error((e as Error).message)
  }
}

// 删除：二次确认；被工单引用时后端 1121 提示
async function remove(row: BomRow) {
  try {
    await ElMessageBox.confirm(`确定删除 BOM「${row.code}」？`, '提示', { type: 'warning' })
  } catch {
    return
  }
  try {
    await bomApi.remove(row.id)
    ElMessage.success('删除成功')
    load()
  } catch (e) {
    ElMessage.error((e as Error).message)
  }
}

onMounted(async () => {
  load()
  const [fin, mat, unit] = await Promise.all([
    productApi.list({ page: 1, per_page: 100, type: 'finished' }),
    productApi.list({ page: 1, per_page: 100 }),
    unitApi.list({ page: 1, per_page: 100 }),
  ])
  finishedProducts.value = fin.items
  // 物料下拉：仅原料/半成品（成品嵌套由后端 1119 兜底，前端直接过滤）
  materialProducts.value = mat.items.filter((p) => p.type !== 'finished')
  units.value = unit.items
})
</script>

<style scoped>
/* 页面骨架 + 明细动态行布局 */
.page-card {
  background: #fff;
  border-radius: 8px;
  box-shadow: var(--shadow-sm);
  padding: var(--space-2xl);
}
.toolbar {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: var(--space-xl);
}
.toolbar-right {
  display: flex;
  gap: var(--space-lg);
  align-items: center;
}
.page-title {
  font-size: 18px;
  font-weight: 600;
  color: var(--color-foreground);
}
.btn-primary {
  background: var(--color-accent);
  border-color: var(--color-accent);
  cursor: pointer;
}
.items-wrap {
  width: 100%;
}
.item-row {
  display: flex;
  gap: var(--space-md);
  align-items: center;
  margin-bottom: var(--space-md);
}
.unit-name {
  width: 50px;
  color: var(--color-secondary);
}
.add-item {
  width: 100%;
  border-style: dashed;
  cursor: pointer;
}
</style>
