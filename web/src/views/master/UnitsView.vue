<!-- 单位管理页：列表 + 新建/编辑弹窗 + 删除确认 -->
<template>
  <div class="page-card">
    <ListFilterBar title="单位管理" @search="search" @reset="reset" @refresh="refresh">
      <template #actions>
        <el-button v-if="auth.has('unit.create')" class="btn-primary" @click="openCreate"
          >新 建</el-button
        >
      </template>
    </ListFilterBar>
    <el-table v-loading="loading" :data="list">
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
      :page-size="query.per_page"
      layout="total, prev, pager, next"
      @current-change="refresh"
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
import ListFilterBar from '../../components/ListFilterBar.vue'
import { useListQuery } from '../../composables/useListQuery'

const auth = useAuthStore()
// 列表查询状态（无关键字筛选，仅分页；统一组合式）
const { query, list, total, loading, search, reset, refresh } = useListQuery({
  defaultQuery: {},
  fetch: (q) => unitApi.list(q),
  onError: (e) => ElMessage.error(e.message),
})
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
    refresh()
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
    refresh()
  } catch (e) {
    ElMessage.error((e as Error).message)
  }
}

onMounted(search)
</script>

<style scoped>
/* 与 CategoriesView 相同页面骨架（主按钮），页面间保持一致 */
.page-card {
  background: #fff;
  border-radius: 8px;
  box-shadow: var(--shadow-sm);
  padding: var(--space-2xl);
}
.btn-primary {
  background: var(--color-accent);
  border-color: var(--color-accent);
  cursor: pointer;
}
</style>
