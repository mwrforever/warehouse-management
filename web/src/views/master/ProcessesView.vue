<!-- 工序管理页：全量列表（分类筛选）+ 新建/编辑弹窗（编码自动生成）+ 删除确认 -->
<template>
  <div class="page-card">
    <div class="toolbar">
      <span class="page-title">工序管理</span>
      <el-select
        v-model="filterCategoryId"
        placeholder="分类"
        clearable
        style="width: 130px"
        @change="load"
      >
        <el-option v-for="c in categoryOptions" :key="c.id" :label="c.label" :value="c.id" />
      </el-select>
      <el-button v-if="auth.has('process.create')" class="btn-primary" @click="openCreate"
        >新 建</el-button
      >
    </div>
    <el-table v-loading="loading" :data="rows">
      <el-table-column prop="sort" label="排序" width="80" />
      <el-table-column prop="name" label="名称" />
      <el-table-column prop="code" label="编码" class-name="font-code" />
      <el-table-column label="分类" width="110">
        <template #default="{ row }">{{ row.category_label ?? '-' }}</template>
      </el-table-column>
      <el-table-column prop="description" label="说明" show-overflow-tooltip />
      <el-table-column label="状态" width="90">
        <template #default="{ row }">
          <el-tag :type="row.status === 1 ? 'success' : 'info'">{{
            row.status === 1 ? '启用' : '停用'
          }}</el-tag>
        </template>
      </el-table-column>
      <el-table-column label="操作" width="160" fixed="right">
        <template #default="{ row }">
          <el-button v-if="auth.has('process.update')" link type="primary" @click="openEdit(row)"
            >编 辑</el-button
          >
          <el-button v-if="auth.has('process.delete')" link type="danger" @click="remove(row)"
            >删 除</el-button
          >
        </template>
      </el-table-column>
    </el-table>

    <el-dialog v-model="dialogVisible" :title="form.id ? '编辑工序' : '新建工序'" width="480px">
      <el-form :model="form" label-width="80px">
        <el-form-item label="名称" required><el-input v-model="form.name" /></el-form-item>
        <!-- 编码：新建自动生成不手填（提示），编辑只读展示历史编码 -->
        <el-form-item label="编码">
          <el-input v-if="form.id" v-model="form.code" disabled />
          <div v-else class="hint">编码自动生成</div>
        </el-form-item>
        <el-form-item label="标签分类">
          <el-select
            v-model="form.category_id"
            clearable
            placeholder="选择分类"
            style="width: 100%"
          >
            <el-option v-for="c in categoryOptions" :key="c.id" :label="c.label" :value="c.id" />
          </el-select>
        </el-form-item>
        <el-form-item label="排序"><el-input-number v-model="form.sort" :min="0" /></el-form-item>
        <el-form-item label="说明"
          ><el-input v-model="form.description" type="textarea" :rows="2"
        /></el-form-item>
        <el-form-item label="状态"
          ><el-switch v-model="form.status" :active-value="1" :inactive-value="0"
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
// 工序管理页：全量 CRUD + 标签分类（字典 process_category）；编码由后端自动生成（PROC 前缀）
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { dictionaryApi, type DictItem } from '../../api/dictionary'
import { processApi, type ProcessItem } from '../../api/process'
import { useAuthStore } from '../../stores/auth'

const auth = useAuthStore()
const rows = ref<ProcessItem[]>([])
const loading = ref(false)
const dialogVisible = ref(false)
const saving = ref(false)
// 列表分类筛选值（清空即不筛选）
const filterCategoryId = ref<number | null>(null)
// 分类下拉选项（字典 process_category 的启用项）
const categoryOptions = ref<DictItem[]>([])

// 工序表单：sort 决定生产模块下拉顺序；code 仅编辑回显（只读），新建不填由后端自动生成
interface ProcessForm {
  id: number | null
  name: string
  code: string
  category_id: number | null
  sort: number
  description: string
  status: number
}

const form = reactive<ProcessForm>({
  id: null,
  name: '',
  code: '',
  category_id: null,
  sort: 0,
  description: '',
  status: 1,
})

// 加载全量列表（后端已按 sort 升序，支持分类筛选）；失败弹错避免首屏静默空白（对齐 AlertsView 显式 catch 先例）
async function load() {
  loading.value = true
  try {
    rows.value = (await processApi.list({ category_id: filterCategoryId.value ?? undefined })).items
  } catch (e) {
    ElMessage.error((e as Error).message)
  } finally {
    loading.value = false
  }
}

// 分类下拉加载：按字典 code 找「工序分类」后取启用项；失败只提示不阻塞主列表（对齐 BomsView BF-6 口径）
async function loadCategories() {
  try {
    const dicts = await dictionaryApi.list({ per_page: 100 })
    const dict = dicts.items.find((d) => d.code === 'process_category')
    categoryOptions.value = dict ? (await dictionaryApi.items(dict.id)).items : []
  } catch (e) {
    ElMessage.error((e as Error).message)
  }
}

function openCreate() {
  Object.assign(form, {
    id: null,
    name: '',
    code: '',
    category_id: null,
    sort: 0,
    description: '',
    status: 1,
  })
  dialogVisible.value = true
}
function openEdit(row: ProcessItem) {
  Object.assign(form, {
    id: row.id,
    name: row.name,
    code: row.code,
    category_id: row.category_id,
    sort: row.sort,
    description: row.description,
    status: row.status,
  })
  dialogVisible.value = true
}

// 保存：新建/编辑；编码不由前端提交（新建后端自动生成、编辑保持不变），后端 1113 删除拦截
async function save() {
  if (!form.name) return ElMessage.warning('请填写名称')
  saving.value = true
  try {
    const payload = {
      name: form.name,
      category_id: form.category_id,
      sort: form.sort,
      description: form.description,
      status: form.status,
    }
    if (form.id) await processApi.update(form.id, payload)
    else await processApi.create(payload)
    ElMessage.success('保存成功')
    dialogVisible.value = false
    load()
  } catch (e) {
    ElMessage.error((e as Error).message)
  } finally {
    saving.value = false
  }
}

// 删除：二次确认；被工单引用时后端 1113 提示
async function remove(row: ProcessItem) {
  try {
    await ElMessageBox.confirm(`确定删除工序「${row.name}」？`, '提示', { type: 'warning' })
  } catch {
    return
  }
  try {
    await processApi.remove(row.id)
    ElMessage.success('删除成功')
    load()
  } catch (e) {
    ElMessage.error((e as Error).message)
  }
}

onMounted(() => {
  load()
  loadCategories()
})
</script>

<style scoped>
/* 页面骨架同上 */
.page-card {
  background: var(--surface);
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
</style>
