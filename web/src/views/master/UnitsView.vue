<!-- 单位管理页：列表 + 新建/编辑弹窗 + 删除确认 -->
<template>
  <div class="page-card">
    <div class="toolbar">
      <span class="page-title">单位管理</span>
      <el-button v-if="auth.has('unit.create')" class="btn-primary" @click="openCreate"
        >新 建</el-button
      >
    </div>
    <el-table v-loading="loading" :data="rows">
      <el-table-column prop="id" label="ID" width="70" />
      <el-table-column prop="name" label="名称" />
      <el-table-column prop="code" label="编码" class-name="font-code" />
      <el-table-column label="状态" width="100">
        <template #default="{ row }">
          <el-tag :type="row.status === 1 ? 'success' : 'info'">{{
            row.status === 1 ? '启用' : '停用'
          }}</el-tag>
        </template>
      </el-table-column>
      <el-table-column label="操作" width="160" fixed="right">
        <template #default="{ row }">
          <el-button v-if="auth.has('unit.update')" link type="primary" @click="openEdit(row)"
            >编 辑</el-button
          >
          <el-button v-if="auth.has('unit.delete')" link type="danger" @click="remove(row)"
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

    <el-dialog v-model="dialogVisible" :title="form.id ? '编辑单位' : '新建单位'" width="420px">
      <el-form :model="form" label-width="80px">
        <el-form-item label="名称" required><el-input v-model="form.name" /></el-form-item>
        <el-form-item label="编码" required><el-input v-model="form.code" /></el-form-item>
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
// 单位管理页：CRUD；删除被商品引用时展示后端 1104 提示
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { unitApi, type UnitItem } from '../../api/unit'
import { useAuthStore } from '../../stores/auth'

const auth = useAuthStore()
const rows = ref<UnitItem[]>([])
const total = ref(0)
const loading = ref(false)
const query = reactive({ page: 1 })
const dialogVisible = ref(false)
const saving = ref(false)

// 单位表单：code 重复由后端 1103 拦截
interface UnitForm {
  id: number | null
  name: string
  code: string
  status: number
}

const form = reactive<UnitForm>({ id: null, name: '', code: '', status: 1 })

// 加载列表
async function load() {
  loading.value = true
  try {
    const res = await unitApi.list({ page: query.page, per_page: 10 })
    rows.value = res.items
    total.value = res.total
  } finally {
    loading.value = false
  }
}

function openCreate() {
  Object.assign(form, { id: null, name: '', code: '', status: 1 })
  dialogVisible.value = true
}
function openEdit(row: UnitItem) {
  Object.assign(form, { id: row.id, name: row.name, code: row.code, status: row.status })
  dialogVisible.value = true
}

// 保存：新建/编辑；后端 1103 重复编码提示展示
async function save() {
  if (!form.name || !form.code) return ElMessage.warning('请填写名称与编码')
  saving.value = true
  try {
    if (form.id)
      await unitApi.update(form.id, { name: form.name, code: form.code, status: form.status })
    else await unitApi.create({ name: form.name, code: form.code, status: form.status })
    ElMessage.success('保存成功')
    dialogVisible.value = false
    load()
  } catch (e) {
    ElMessage.error((e as Error).message)
  } finally {
    saving.value = false
  }
}

// 删除：二次确认；被商品引用时后端 1104 提示
async function remove(row: UnitItem) {
  try {
    await ElMessageBox.confirm(`确定删除单位「${row.name}」？`, '提示', { type: 'warning' })
  } catch {
    return
  }
  try {
    await unitApi.remove(row.id)
    ElMessage.success('删除成功')
    load()
  } catch (e) {
    ElMessage.error((e as Error).message)
  }
}

onMounted(load)
</script>

<style scoped>
/* 与 CategoriesView 相同页面骨架（工具栏/标题/主按钮），页面间保持一致 */
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
