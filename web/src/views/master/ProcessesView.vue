<!-- 工序管理页：全量列表（sort 升序）+ 新建/编辑弹窗 + 删除确认 -->
<template>
  <div class="page-card">
    <div class="toolbar">
      <span class="page-title">工序管理</span>
      <el-button v-if="auth.has('process.create')" class="btn-primary" @click="openCreate"
        >新 建</el-button
      >
    </div>
    <el-table v-loading="loading" :data="rows">
      <el-table-column prop="sort" label="排序" width="80" />
      <el-table-column prop="name" label="名称" />
      <el-table-column prop="code" label="编码" class-name="font-code" />
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
        <el-form-item label="编码" required><el-input v-model="form.code" /></el-form-item>
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
// 工序管理页：全量 CRUD；排序字段决定生产模块工序下拉顺序
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { processApi, type ProcessItem } from '../../api/process'
import { useAuthStore } from '../../stores/auth'

const auth = useAuthStore()
const rows = ref<ProcessItem[]>([])
const loading = ref(false)
const dialogVisible = ref(false)
const saving = ref(false)

// 工序表单：sort 决定生产模块下拉顺序
interface ProcessForm {
  id: number | null
  name: string
  code: string
  sort: number
  description: string
  status: number
}

const form = reactive<ProcessForm>({
  id: null,
  name: '',
  code: '',
  sort: 0,
  description: '',
  status: 1,
})

// 加载全量列表（后端已按 sort 升序）；失败弹错避免首屏静默空白（对齐 AlertsView 显式 catch 先例）
async function load() {
  loading.value = true
  try {
    rows.value = (await processApi.list()).items
  } catch (e) {
    ElMessage.error((e as Error).message)
  } finally {
    loading.value = false
  }
}

function openCreate() {
  Object.assign(form, { id: null, name: '', code: '', sort: 0, description: '', status: 1 })
  dialogVisible.value = true
}
function openEdit(row: ProcessItem) {
  Object.assign(form, {
    id: row.id,
    name: row.name,
    code: row.code,
    sort: row.sort,
    description: row.description,
    status: row.status,
  })
  dialogVisible.value = true
}

// 保存：新建/编辑；后端 1112 重复编码提示展示
async function save() {
  if (!form.name || !form.code) return ElMessage.warning('请填写名称与编码')
  saving.value = true
  try {
    if (form.id)
      await processApi.update(form.id, {
        name: form.name,
        code: form.code,
        sort: form.sort,
        description: form.description,
        status: form.status,
      })
    else
      await processApi.create({
        name: form.name,
        code: form.code,
        sort: form.sort,
        description: form.description,
        status: form.status,
      })
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

onMounted(load)
</script>

<style scoped>
/* 页面骨架同上 */
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
}
.btn-primary {
  background: var(--color-accent);
  border-color: var(--color-accent);
  cursor: pointer;
}
</style>
