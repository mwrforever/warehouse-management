<!-- 商品管理页：筛选列表 + 新建/编辑弹窗（条码扫枪自动聚焦）+ 类型标签语义色 -->
<template>
  <div class="page-card">
    <ListFilterBar
      v-model:keyword="query.keyword"
      title="商品管理"
      keyword-placeholder="编码/名称/条码"
      @keyword-change="() => load()"
      @search="search"
      @reset="reset"
      @refresh="refresh"
    >
      <el-select
        v-model="query.type"
        placeholder="类型"
        clearable
        style="width: 130px"
        @change="() => load()"
      >
        <el-option label="原料" value="raw_material" />
        <el-option label="半成品" value="semi_finished" />
        <el-option label="成品" value="finished" />
      </el-select>
      <template #actions>
        <el-button v-if="auth.has('product.create')" class="btn-primary" @click="openCreate"
          >新 建</el-button
        >
      </template>
    </ListFilterBar>
    <el-table v-loading="loading" :data="list">
      <el-table-column prop="code" label="编码" width="120" class-name="font-code" />
      <el-table-column prop="name" label="名称" min-width="140" />
      <el-table-column label="类型" width="90">
        <template #default="{ row }">
          <span :class="typeTagClass(row.type)">{{ row.type_label }}</span>
        </template>
      </el-table-column>
      <el-table-column prop="category_name" label="分类" width="110" />
      <el-table-column prop="spec" label="规格" width="100" />
      <el-table-column prop="unit_name" label="单位" width="70" />
      <!-- 条码列：有值渲染一维码图形，空值显示占位符 -->
      <el-table-column label="条码" width="140" align="center">
        <template #default="{ row }">
          <BarcodeSvg v-if="row.barcode" :value="row.barcode" />
          <span v-else class="no-barcode">—</span>
        </template>
      </el-table-column>
      <el-table-column label="安全库存" width="130">
        <template #default="{ row }">{{ row.safety_min }} ~ {{ row.safety_max }}</template>
      </el-table-column>
      <el-table-column label="状态" width="80">
        <template #default="{ row }">
          <el-tag :type="row.status === 1 ? 'success' : 'info'">{{
            row.status === 1 ? '启用' : '停用'
          }}</el-tag>
        </template>
      </el-table-column>
      <el-table-column label="操作" width="160" fixed="right">
        <template #default="{ row }">
          <el-button v-if="auth.has('product.update')" link type="primary" @click="openEdit(row)"
            >编 辑</el-button
          >
          <el-button v-if="auth.has('product.delete')" link type="danger" @click="remove(row)"
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

    <!-- 新建/编辑弹窗：条码框自动聚焦；扫枪回车即时校验 -->
    <el-dialog
      v-model="dialogVisible"
      :title="form.id ? '编辑商品' : '新建商品'"
      width="600px"
      @opened="focusBarcode"
    >
      <el-form :model="form" label-width="100px">
        <el-form-item label="名称" required><el-input v-model="form.name" /></el-form-item>
        <el-form-item label="编码" :required="!!form.id">
          <el-input v-model="form.code" :placeholder="form.id ? '请输入编码' : '留空则自动生成'" />
        </el-form-item>
        <el-form-item label="类型" required>
          <el-radio-group v-model="form.type">
            <el-radio value="raw_material">原料</el-radio>
            <el-radio value="semi_finished">半成品</el-radio>
            <el-radio value="finished">成品</el-radio>
          </el-radio-group>
          <div v-if="form.type === 'finished'" class="hint">
            成品可为其维护 BOM（基础资料 → BOM 管理）
          </div>
        </el-form-item>
        <!-- 分类树选择：node-key 必须与数据 id 字段一致，否则选择无法绑定 category_id（E2E TC-MST-07 回归） -->
        <el-form-item label="分类" required>
          <el-tree-select
            v-model="form.category_id"
            :data="categoryTree"
            node-key="id"
            :props="{ label: 'name', children: 'children' }"
            check-strictly
            style="width: 100%"
            placeholder="选择分类"
          />
        </el-form-item>
        <el-form-item label="单位" required>
          <el-select v-model="form.unit_id" style="width: 100%">
            <el-option v-for="u in units" :key="u.id" :label="u.name" :value="u.id" />
          </el-select>
        </el-form-item>
        <el-form-item label="规格"><el-input v-model="form.spec" /></el-form-item>
        <el-form-item label="条码">
          <el-input
            ref="barcodeRef"
            v-model="form.barcode"
            placeholder="扫码枪输入后回车"
            clearable
            @keyup.enter="scanBarcode"
          />
          <div v-if="scanHint" class="hint">{{ scanHint }}</div>
          <div v-else-if="!form.id" class="hint">留空则自动生成且默认等于编码</div>
        </el-form-item>
        <el-form-item label="安全库存下限"
          ><el-input-number v-model="form.safety_min" :min="0"
        /></el-form-item>
        <el-form-item label="安全库存上限"
          ><el-input-number v-model="form.safety_max" :min="0"
        /></el-form-item>
        <el-form-item label="状态"
          ><el-switch v-model="form.status" :active-value="1" :inactive-value="0"
        /></el-form-item>
        <el-form-item label="备注"
          ><el-input v-model="form.remark" type="textarea" :rows="2"
        /></el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="dialogVisible = false">取 消</el-button>
        <el-button type="primary" class="btn-primary" :loading="saving" @click="save"
          >保 存</el-button
        >
      </template>
    </el-dialog>
  </div>
</template>

<script setup lang="ts">
// 商品管理页：筛选/CRUD/扫码；类型联动提示；安全库存 min>max 前端拦截（后端 1122 双保险）
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { categoryApi, type CategoryItem } from '../../api/category'
import { productApi, type ProductItem, type ProductType } from '../../api/product'
import { unitApi, type UnitItem } from '../../api/unit'
import { useAuthStore } from '../../stores/auth'
import BarcodeSvg from '../../components/BarcodeSvg.vue'
import ListFilterBar from '../../components/ListFilterBar.vue'
import { useListQuery } from '../../composables/useListQuery'

const auth = useAuthStore()
// 列表查询状态（统一组合式：防抖加载/查询/重置/刷新，请求序号守卫并发）
const { query, list, total, loading, load, search, reset, refresh } = useListQuery({
  defaultQuery: {
    keyword: '',
    type: '' as '' | 'raw_material' | 'semi_finished' | 'finished',
  },
  fetch: (q) => productApi.list({ ...q, type: q.type || undefined }),
  onError: (e) => ElMessage.error(e.message),
})
const dialogVisible = ref(false)
const saving = ref(false)

// 商品表单：category_id/unit_id 未选择时为空（null），保存前经校验必填；
// barcode 空串提交时转 null（后端按无条码处理），类型上收窄为 string
interface ProductForm {
  id: number | null
  name: string
  code: string
  type: ProductType
  category_id: number | null
  unit_id: number | null
  spec: string
  barcode: string
  safety_min: number
  safety_max: number
  status: number
  remark: string
}

const form = reactive<ProductForm>({
  id: null,
  name: '',
  code: '',
  type: 'raw_material',
  category_id: null,
  unit_id: null,
  spec: '',
  barcode: '',
  safety_min: 0,
  safety_max: 0,
  status: 1,
  remark: '',
})
const categoryTree = ref<CategoryItem[]>([])
const units = ref<UnitItem[]>([])
const barcodeRef = ref()
const scanHint = ref('')

// 类型标签语义色（设计系统页覆盖 master-data.md）
function typeTagClass(type: ProductType) {
  return (
    { raw_material: 'tag-raw', semi_finished: 'tag-semi', finished: 'tag-fin' }[type] ?? 'tag-raw'
  )
}

// 弹窗打开后聚焦条码框（扫枪输入就绪）
function focusBarcode() {
  barcodeRef.value?.focus()
}

// 扫码校验：回车即时查询；未匹配不清空输入（便于重扫）
async function scanBarcode() {
  if (!form.barcode) return
  try {
    const hit = await productApi.byBarcode(form.barcode)
    scanHint.value = `条码匹配：${hit.name}（${hit.code}）`
    ElMessage.success(`条码匹配：${hit.name}`)
  } catch (e) {
    scanHint.value = ''
    ElMessage.error((e as Error).message)
  }
}

function openCreate() {
  Object.assign(form, {
    id: null,
    name: '',
    code: '',
    type: 'raw_material',
    category_id: null,
    unit_id: null,
    spec: '',
    barcode: '',
    safety_min: 0,
    safety_max: 0,
    status: 1,
    remark: '',
  })
  scanHint.value = ''
  dialogVisible.value = true
}
function openEdit(row: ProductItem) {
  Object.assign(form, {
    id: row.id,
    name: row.name,
    code: row.code,
    type: row.type,
    category_id: row.category_id,
    unit_id: row.unit_id,
    spec: row.spec,
    barcode: row.barcode,
    safety_min: row.safety_min,
    safety_max: row.safety_max,
    status: row.status,
    remark: row.remark,
  })
  scanHint.value = ''
  dialogVisible.value = true
}

// 保存：前端拦截 min>max；编码新建可留空（后端自动生成）；后端 1114/1115/1122 双保险
async function save() {
  if (!form.name || !form.category_id || !form.unit_id) return ElMessage.warning('请填写必填项')
  if (form.id && !form.code) return ElMessage.warning('请填写编码')
  if (form.safety_max > 0 && form.safety_min > form.safety_max)
    return ElMessage.error('安全库存下限不能大于上限')
  saving.value = true
  try {
    const payload = {
      name: form.name,
      // 新建留空 → 不传 code（后端按 prd 配置自动生成）；编辑必填
      code: form.code || undefined,
      type: form.type,
      category_id: form.category_id,
      unit_id: form.unit_id,
      spec: form.spec,
      // 空条码转 null 提交（保持原语义），API 载荷类型为 string，此处仅类型收窄
      barcode: (form.barcode || null) as string,
      safety_min: form.safety_min,
      safety_max: form.safety_max,
      status: form.status,
      remark: form.remark,
    }
    if (form.id) {
      // 编辑场景 code 必填（上方已校验非空），此处收窄类型以满足 update 契约
      await productApi.update(form.id, { ...payload, code: form.code })
    } else {
      const res = await productApi.create(payload)
      ElMessage.success(res.code ? `保存成功，编码 ${res.code}` : '保存成功')
    }
    dialogVisible.value = false
    refresh()
  } catch (e) {
    ElMessage.error((e as Error).message)
  } finally {
    saving.value = false
  }
}

// 删除：二次确认；被业务单据引用时后端 1116 提示
async function remove(row: ProductItem) {
  try {
    await ElMessageBox.confirm(`确定删除商品「${row.name}」？`, '提示', { type: 'warning' })
  } catch {
    return
  }
  try {
    await productApi.remove(row.id)
    ElMessage.success('删除成功')
    refresh()
  } catch (e) {
    ElMessage.error((e as Error).message)
  }
}

onMounted(async () => {
  search()
  try {
    categoryTree.value = await categoryApi.tree()
    units.value = (await unitApi.list({ page: 1, per_page: 100 })).items
  } catch (e) {
    // 分类树/单位下拉加载失败提示：新建/编辑弹窗仍可用，仅下拉选项缺失
    ElMessage.error((e as Error).message)
  }
})
</script>

<style scoped>
/* 页面骨架 + 类型标签语义色（master-data.md 页覆盖） */
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
.hint {
  color: var(--color-secondary);
  font-size: 12px;
  margin-top: var(--space-sm);
}
.no-barcode {
  color: var(--p-300);
}
.tag-raw {
  background: var(--info-12);
  color: var(--info);
  border: 1px solid var(--info-30);
  border-radius: 4px;
  padding: 2px 8px;
  font-size: 12px;
}
.tag-semi {
  background: var(--warn-12);
  color: var(--warn);
  border: 1px solid var(--warn-30);
  border-radius: 4px;
  padding: 2px 8px;
  font-size: 12px;
}
.tag-fin {
  background: var(--a-600-12);
  color: var(--a-600);
  border: 1px solid var(--a-600-30);
  border-radius: 4px;
  padding: 2px 8px;
  font-size: 12px;
}
</style>
