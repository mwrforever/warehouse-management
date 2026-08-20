<!-- 字典管理页：字典列表 + 字典项管理弹窗 -->
<template>
  <div>
    <ListFilterBar title="字典管理" @search="search" @reset="reset" @refresh="refresh">
      <template #actions>
        <el-button v-if="auth.has('dictionary.create')" class="btn-primary" @click="openCreate"
          >新 建</el-button
        >
      </template>
    </ListFilterBar>
    <el-table v-loading="loading" :data="list">
      <el-table-column prop="id" label="ID" width="70" />
      <el-table-column prop="name" label="名称" />
      <el-table-column prop="code" label="编码" class-name="font-code" />
      <el-table-column prop="remark" label="备注" />
      <el-table-column label="操作" width="220" fixed="right">
        <template #default="{ row }">
          <el-button link type="primary" @click="openItems(row)">字典项</el-button>
          <el-button v-if="auth.has('dictionary.update')" link type="primary" @click="openEdit(row)"
            >编 辑</el-button
          >
          <el-button v-if="auth.has('dictionary.delete')" link type="danger" @click="remove(row)"
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
      @current-change="refresh"
    />

    <!-- 字典新建/编辑弹窗 -->
    <el-dialog v-model="dialogVisible" :title="form.id ? '编辑字典' : '新建字典'" width="480px">
      <el-form :model="form" label-width="80px">
        <el-form-item label="名称" required><el-input v-model="form.name" /></el-form-item>
        <el-form-item label="编码" required><el-input v-model="form.code" /></el-form-item>
        <el-form-item label="备注"><el-input v-model="form.remark" /></el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="dialogVisible = false">取 消</el-button>
        <el-button type="primary" class="btn-primary" @click="save">保 存</el-button>
      </template>
    </el-dialog>

    <!-- 字典项管理弹窗：当前字典的启用项列表 + 新增/编辑/删除 -->
    <el-dialog v-model="itemDialogVisible" :title="`字典项 - ${currentDict?.name}`" width="640px">
      <div class="toolbar">
        <el-button v-if="auth.has('dictionary.create')" class="btn-primary" @click="openItemCreate"
          >新 增</el-button
        >
      </div>
      <el-table :data="items">
        <el-table-column prop="label" label="标签" />
        <el-table-column prop="value" label="值" class-name="font-code" />
        <el-table-column prop="sort" label="排序" width="80" />
        <el-table-column label="状态" width="90">
          <template #default="{ row }">
            <el-tag :type="row.status === 1 ? 'success' : 'info'">{{
              row.status === 1 ? '启用' : '禁用'
            }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="140">
          <template #default="{ row }">
            <el-button
              v-if="auth.has('dictionary.update')"
              link
              type="primary"
              @click="openItemEdit(row)"
              >编 辑</el-button
            >
            <el-button
              v-if="auth.has('dictionary.delete')"
              link
              type="danger"
              @click="removeItem(row)"
              >删 除</el-button
            >
          </template>
        </el-table-column>
      </el-table>
      <template #footer>
        <el-button @click="itemDialogVisible = false">关 闭</el-button>
      </template>
    </el-dialog>

    <!-- 字典项新增/编辑弹窗 -->
    <el-dialog
      v-model="itemFormVisible"
      :title="itemForm.id ? '编辑字典项' : '新增字典项'"
      width="440px"
    >
      <el-form :model="itemForm" label-width="80px">
        <el-form-item label="标签" required><el-input v-model="itemForm.label" /></el-form-item>
        <el-form-item label="值" required><el-input v-model="itemForm.value" /></el-form-item>
        <el-form-item label="排序"
          ><el-input-number v-model="itemForm.sort" :min="0"
        /></el-form-item>
        <el-form-item label="状态"
          ><el-switch v-model="itemForm.status" :active-value="1" :inactive-value="0"
        /></el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="itemFormVisible = false">取 消</el-button>
        <el-button type="primary" class="btn-primary" @click="saveItem">保 存</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup lang="ts">
// 字典管理页：字典/字典项 CRUD；删除字典前提示引用风险；重复编码由后端 1005 拦截
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { dictionaryApi, type DictItem, type DictionaryItem } from '../../api/dictionary'
import { useAuthStore } from '../../stores/auth'
import ListFilterBar from '../../components/ListFilterBar.vue'
import { useListQuery } from '../../composables/useListQuery'

const auth = useAuthStore()

// 列表查询状态（统一组合式：防抖加载/查询/重置/刷新，请求序号守卫并发）
const { query, list, total, loading, search, reset, refresh } = useListQuery({
  defaultQuery: {},
  fetch: (q) => dictionaryApi.list(q),
  onError: (e) => ElMessage.error(e.message),
})
const dialogVisible = ref(false)

// 字典表单：code 重复由后端 1005 拦截
interface DictForm {
  id: number | null
  name: string
  code: string
  remark: string
}

const form = reactive<DictForm>({ id: null, name: '', code: '', remark: '' })

// 字典项弹窗状态（当前字典 + 启用项列表 + 项表单）
const itemDialogVisible = ref(false)
const currentDict = ref<DictionaryItem | null>(null)
const items = ref<DictItem[]>([])
const itemFormVisible = ref(false)

// 字典项表单：sort/status 由输入控件绑定
interface DictItemForm {
  id: number | null
  label: string
  value: string
  sort: number
  status: number
}

const itemForm = reactive<DictItemForm>({ id: null, label: '', value: '', sort: 0, status: 1 })

// 新建/编辑字典弹窗初始化
function openCreate() {
  Object.assign(form, { id: null, name: '', code: '', remark: '' })
  dialogVisible.value = true
}
function openEdit(row: DictionaryItem) {
  Object.assign(form, { id: row.id, name: row.name, code: row.code, remark: row.remark })
  dialogVisible.value = true
}

// 保存字典：失败（如重复编码 1005）ElMessage 展示后端 message
async function save() {
  try {
    if (form.id)
      await dictionaryApi.update(form.id, { name: form.name, code: form.code, remark: form.remark })
    else await dictionaryApi.create({ name: form.name, code: form.code, remark: form.remark })
    ElMessage.success('保存成功')
    dialogVisible.value = false
    refresh()
  } catch (e) {
    ElMessage.error((e as Error).message)
  }
}

// 打开字典项弹窗：加载当前字典的启用项列表
async function openItems(dict: DictionaryItem) {
  currentDict.value = dict
  itemDialogVisible.value = true
  try {
    items.value = (await dictionaryApi.items(dict.id)).items
  } catch (e) {
    // 加载失败提示：避免弹窗空白列表无提示（与其他请求风格一致）
    ElMessage.error((e as Error).message)
  }
}

// 删除字典：确认框提示引用风险（引用此字典的下拉将失效）
async function remove(row: DictionaryItem) {
  try {
    await ElMessageBox.confirm(
      `确定删除字典「${row.name}」？删除后引用此字典的下拉将失效`,
      '提示',
      { type: 'warning' },
    )
  } catch {
    return // 用户取消
  }
  try {
    await dictionaryApi.remove(row.id)
    ElMessage.success('删除成功')
    refresh()
  } catch (e) {
    ElMessage.error((e as Error).message)
  }
}

// 字典项表单初始化：新增清空；编辑回填
function openItemCreate() {
  Object.assign(itemForm, { id: null, label: '', value: '', sort: 0, status: 1 })
  itemFormVisible.value = true
}
function openItemEdit(row: DictItem) {
  Object.assign(itemForm, {
    id: row.id,
    label: row.label,
    value: row.value,
    sort: row.sort,
    status: row.status,
  })
  itemFormVisible.value = true
}

// 保存字典项（编辑走 updateItem，新增走 createItem），成功后刷新当前字典项列表
async function saveItem() {
  try {
    if (itemForm.id)
      await dictionaryApi.updateItem(itemForm.id, {
        label: itemForm.label,
        value: itemForm.value,
        sort: itemForm.sort,
        status: itemForm.status,
      })
    else
      await dictionaryApi.createItem(currentDict.value!.id, {
        label: itemForm.label,
        value: itemForm.value,
        sort: itemForm.sort,
        status: itemForm.status,
      })
    ElMessage.success('保存成功')
    itemFormVisible.value = false
    items.value = (await dictionaryApi.items(currentDict.value!.id)).items
  } catch (e) {
    ElMessage.error((e as Error).message)
  }
}

// 删除字典项：二次确认后调用
async function removeItem(row: DictItem) {
  try {
    await ElMessageBox.confirm(`确定删除字典项「${row.label}」？`, '提示', { type: 'warning' })
  } catch {
    return // 用户取消
  }
  try {
    await dictionaryApi.removeItem(row.id)
    ElMessage.success('删除成功')
    items.value = (await dictionaryApi.items(currentDict.value!.id)).items
  } catch (e) {
    ElMessage.error((e as Error).message)
  }
}

onMounted(search)
</script>

<style scoped>
/* 工具栏间距与主按钮样式（btn-primary 语义色） */
.toolbar {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: var(--space-lg);
  margin-bottom: var(--space-xl);
}
.btn-primary {
  background: var(--color-accent);
  border-color: var(--color-accent);
  cursor: pointer;
}
</style>
