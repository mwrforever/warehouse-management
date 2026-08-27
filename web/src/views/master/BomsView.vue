<!-- BOM 管理页：列表 + 新建/编辑大弹窗（明细动态行）+ 明细查看 + 启用/停用切换 -->
<template>
  <div class="page-card">
    <ListFilterBar
      v-model:keyword="query.keyword"
      title="BOM 管理"
      keyword-placeholder="BOM 编码"
      @keyword-change="() => load()"
      @search="search"
      @reset="reset"
      @refresh="refresh"
    >
      <template #actions>
        <el-button v-if="auth.has('bom.create')" class="btn-primary" @click="openCreate"
          >新 建</el-button
        >
      </template>
    </ListFilterBar>
    <el-table v-loading="loading" :data="list">
      <el-table-column prop="code" label="BOM 编码" width="170" class-name="font-code" />
      <el-table-column prop="product_name" label="商品名称" min-width="140" />
      <el-table-column label="商品类型" width="90">
        <template #default="{ row }">{{ row.type_label ?? '-' }}</template>
      </el-table-column>
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
      :page-size="query.per_page"
      layout="total, prev, pager, next"
      @current-change="refresh"
    />

    <!-- 新建/编辑弹窗：单头 + 明细动态行（宽 800px）；禁点遮罩关闭防误触丢失已录明细 -->
    <el-dialog
      v-model="dialogVisible"
      :title="form.id ? '编辑 BOM' : '新建 BOM'"
      width="800px"
      :close-on-click-modal="false"
    >
      <el-form ref="formRef" :model="form" :rules="rules" label-width="90px">
        <el-form-item label="商品" prop="product_id" required>
          <ProductPicker
            v-model="form.product_id"
            :types="['semi_finished', 'finished']"
            :pin="editProductPin"
            placeholder="选择商品（半成品/成品）"
          />
        </el-form-item>
        <el-form-item label="版本" prop="version" required
          ><el-input v-model="form.version" style="width: 160px"
        /></el-form-item>
        <el-form-item label="基准数量" prop="quantity"
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
              <el-form-item
                :prop="`items.${idx}.material_id`"
                :rules="itemMaterialRules"
                label-width="0"
              >
                <ProductPicker
                  v-model="row.material_id"
                  :types="['raw_material', 'semi_finished']"
                  :pin="row.pin"
                  placeholder="选择物料（原料/半成品）"
                  @change="(picked: ProductOptionRow | null) => applyMaterialUnit(row, picked)"
                />
              </el-form-item>
              <el-form-item
                :prop="`items.${idx}.quantity`"
                :rules="itemQuantityRules"
                label-width="0"
              >
                <el-input-number
                  v-model="row.quantity"
                  :min="0.01"
                  :precision="2"
                  placeholder="用量"
                  style="width: 120px"
                />
              </el-form-item>
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
// BOM 管理页：单头+明细一次保存/全量替换；启用切换自动停用同商品其他版本（后端 1120 兜底）
import { onMounted, reactive, ref, watch } from 'vue'
import {
  ElMessage,
  ElMessageBox,
  type FormInstance,
  type FormItemRule,
  type FormRules,
} from 'element-plus'
import { bomApi, type BomItem, type BomRow } from '../../api/bom'
import { unitApi, type UnitItem } from '../../api/unit'
import { useAuthStore } from '../../stores/auth'
import ListFilterBar from '../../components/ListFilterBar.vue'
import ProductPicker, { type ProductOptionRow } from '../../components/ProductPicker.vue'
import { useListQuery } from '../../composables/useListQuery'
import { quantityRule } from '../../utils/formRules'

const auth = useAuthStore()
// 列表查询状态（统一组合式：防抖加载/查询/重置/刷新，请求序号守卫并发）
const { query, list, total, loading, load, search, reset, refresh } = useListQuery({
  defaultQuery: { keyword: '' },
  fetch: (q) => bomApi.list(q),
  onError: (e) => ElMessage.error(e.message),
})
const dialogVisible = ref(false)
const saving = ref(false)

// BOM 明细行表单：物料/单位未选择时为空（null），保存前通过 some 校验必填；
// pin 为编辑回显的历史物料摘要（不在选择器初载列表时保证名称展示），不参与提交
interface BomItemForm {
  material_id: number | null
  quantity: number
  unit_id: number | null
  pin: ProductOptionRow | null
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
// 弹窗表单引用：保存前统一触发 el-form 校验（D-17）
const formRef = ref<FormInstance>()
// 表单校验规则（D-17）：商品/版本必填；基准数量须 > 0 且最多 2 位小数。
// 明细行物料必选与用量格式由行内 rules 承载；「至少一行明细」「单位齐全」跨行校验保持保存侧手工
const rules: FormRules = {
  product_id: [{ required: true, message: '请选择商品', trigger: 'change' }],
  version: [{ required: true, message: '请填写版本号', trigger: 'blur' }],
  quantity: [quantityRule(false, '基准数量必须大于 0')],
}
// 明细行规则：物料必选；用量须 > 0（输入框 :min=0.01 已钳制，rules 兜底空值/精度）
const itemMaterialRules: FormItemRule[] = [
  { required: true, message: '请选择物料', trigger: 'change' },
]
const itemQuantityRules: FormItemRule[] = [quantityRule(false, '用量必须大于 0')]
// 编辑回显 pin：主商品（编辑中可能不在选择器初载列表内，pin 保证名称展示而非裸 id）
const editProductPin = ref<ProductOptionRow | null>(null)

const units = ref<UnitItem[]>([])
const itemsVisible = ref(false)
const currentBom = ref<BomRow | null>(null)
const itemsRows = ref<BomItem[]>([])

// 单位名映射（明细行显示；unit_id 未选时为空）
function unitName(id: number | null) {
  return units.value.find((u) => u.id === id)?.name ?? ''
}

// 弹窗数据源：商品仅半成品+成品；物料仅原料+半成品
function openCreate() {
  editProductPin.value = null
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
    // 回显 pin：主商品/物料可能不在选择器初载列表内，不 pin 则只显示裸 id（物料需带 unit_id 供单位带出）
    editProductPin.value = {
      id: row.product_id,
      name: row.product_name ?? '',
      code: '',
      unit_id: null,
    }
    form.items = res.items.map((i) => ({
      material_id: i.material_id,
      quantity: i.quantity,
      unit_id: i.unit_id,
      pin: { id: i.material_id, name: i.material_name, code: '', unit_id: i.unit_id },
    }))
    dialogVisible.value = true
  } catch (e) {
    // 明细加载失败：提示错误且不打开弹窗，避免半成品表单
    ElMessage.error((e as Error).message)
  }
}

// 弹窗关闭边界：清主商品 pin（ProductPicker 收到 null 后移除并入项防串单）；
// 选择器缓存保留，重开不重复请求不闪烁（与 useRemoteOptions clearPins 语义一致）
watch(dialogVisible, (open) => {
  if (!open) editProductPin.value = null
})

// 动态行：默认单位取第一个单位（选择物料后由 ProductPicker change 行带出该物料单位）
function newRow() {
  return { material_id: null, quantity: 1, unit_id: units.value[0]?.id ?? null, pin: null }
}
// 选择物料后自动带出其计量单位（spec §5.7：单位自动带出；E2E TC-MST-09 回归）；
// picked 来自 ProductPicker change 事件的选中行（含 unit_id），清除时置空
function applyMaterialUnit(row: { unit_id: number | null }, picked: ProductOptionRow | null) {
  row.unit_id = picked?.unit_id ?? null
}
function addItem() {
  form.items.push(newRow())
}
// 删除明细行：模板 v-for 下标经 vue-tsc 推断为 string | number，此处放宽签名并运行时强转索引
function removeItem(idx: string | number) {
  form.items.splice(idx as number, 1)
}

// 保存：单头+明细一次提交；跨行校验（至少一条明细/单位齐全）保持手工；后端 1118/1119/1120/1123 错误提示展示
async function save() {
  // 提交前统一 el-form 校验（D-17）：商品/版本/基准数量必填 + 明细行物料/用量格式在前端拦截，避免发出可预期的 422 请求
  const valid = await formRef.value?.validate().catch(() => false)
  if (!valid) return
  if (!form.items.length) return ElMessage.warning('请至少添加一条物料明细')
  // 单位随物料自动带出，无可见输入控件不进 rules；此处兜底校验未带出单位的情况
  if (form.items.some((i) => !i.unit_id)) return ElMessage.warning('请补全物料行信息')
  saving.value = true
  try {
    // 商品经上方 rules 校验必填，此处 ! 收窄类型（纯类型层面，运行时值不变）
    const payload = {
      product_id: form.product_id!,
      version: form.version,
      quantity: form.quantity,
      remark: form.remark,
      status: form.status,
      // 物料必选由行内 rules 拦截、单位由上方 some 校验兜底，此处用 as number 收窄类型（纯类型层面，运行时值不变）
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
    refresh()
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

// 启用/停用切换：后端保证同商品启用唯一
async function toggle(row: BomRow) {
  try {
    await bomApi.toggle(row.id, row.status === 1 ? 0 : 1)
    ElMessage.success(row.status === 1 ? '已停用' : '已启用')
    refresh()
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
    refresh()
  } catch (e) {
    ElMessage.error((e as Error).message)
  }
}

onMounted(async () => {
  search()
  try {
    // 单位下拉（小主数据，维持本地装载）
    units.value = (await unitApi.list({ page: 1, per_page: 100 })).items
  } catch {
    // 下拉加载失败不阻塞主流程（对齐 InboundsView 兜底；主列表由 useListQuery 独立提示）
  }
})
</script>

<style scoped>
/* 页面骨架 + 明细动态行布局 */
.page-card {
  background: var(--surface);
  border-radius: 8px;
  box-shadow: var(--shadow-sm);
  padding: var(--space-2xl);
}
.btn-primary {
  background: var(--color-accent);
  border-color: var(--color-accent);
  cursor: pointer;
}
.items-wrap {
  width: 100%;
}
/* 行内校验包裹：零化 el-form-item 默认下边距，保持动态行一行布局 */
.items-wrap :deep(.el-form-item) {
  margin-bottom: 0;
}
.item-row {
  display: flex;
  gap: var(--space-md);
  align-items: center;
  margin-bottom: var(--space-md);
}
/* 物料行商品选择器占位：弹性伸缩，数量输入保持固定宽 */
.item-row :deep(.product-picker) {
  flex: 1;
  min-width: 0;
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
