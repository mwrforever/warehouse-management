<!-- 仓库管理页：列表 + 库位管理弹窗（库位 CRUD 在弹窗内完成） -->
<template>
  <div class="page-card">
    <div class="toolbar">
      <span class="page-title">仓库管理</span>
      <div class="toolbar-right">
        <el-input v-model="query.keyword" placeholder="名称/编码" clearable style="width: 200px" @keyup.enter="load" />
        <el-button v-if="auth.has('warehouse.create')" class="btn-primary" @click="openCreate">新 建</el-button>
      </div>
    </div>
    <el-table :data="rows" v-loading="loading">
      <el-table-column prop="code" label="编码" width="100" class-name="font-code" />
      <el-table-column prop="name" label="名称" min-width="120" />
      <el-table-column prop="address" label="地址" show-overflow-tooltip />
      <el-table-column prop="manager" label="负责人" width="100" />
      <el-table-column label="状态" width="80">
        <template #default="{ row }">
          <el-tag :type="row.status === 1 ? 'success' : 'info'">{{ row.status === 1 ? '启用' : '停用' }}</el-tag>
        </template>
      </el-table-column>
      <el-table-column label="操作" width="230" fixed="right">
        <template #default="{ row }">
          <el-button v-if="auth.has('warehouse.list')" link type="primary" @click="openLocations(row)">库 位</el-button>
          <el-button v-if="auth.has('warehouse.update')" link type="primary" @click="openEdit(row)">编 辑</el-button>
          <el-button v-if="auth.has('warehouse.delete')" link type="danger" @click="remove(row)">删 除</el-button>
        </template>
      </el-table-column>
    </el-table>
    <el-pagination v-model:current-page="query.page" :total="total" :page-size="10" layout="total, prev, pager, next" @current-change="load" />

    <!-- 仓库新建/编辑弹窗 -->
    <el-dialog v-model="dialogVisible" :title="form.id ? '编辑仓库' : '新建仓库'" width="480px">
      <el-form :model="form" label-width="80px">
        <el-form-item label="名称" required><el-input v-model="form.name" /></el-form-item>
        <el-form-item label="编码" required><el-input v-model="form.code" /></el-form-item>
        <el-form-item label="地址"><el-input v-model="form.address" /></el-form-item>
        <el-form-item label="负责人"><el-input v-model="form.manager" /></el-form-item>
        <el-form-item label="状态"><el-switch v-model="form.status" :active-value="1" :inactive-value="0" /></el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="dialogVisible = false">取 消</el-button>
        <el-button type="primary" class="btn-primary" :loading="saving" @click="save">保 存</el-button>
      </template>
    </el-dialog>

    <!-- 库位管理弹窗 -->
    <el-dialog v-model="locationVisible" :title="`库位管理 - ${currentWarehouse?.name}`" width="640px">
      <div class="loc-toolbar">
        <el-button v-if="auth.has('warehouse.create')" class="btn-primary" @click="openCreateLocation">新 增</el-button>
      </div>
      <el-table :data="locations" size="small">
        <el-table-column prop="name" label="库位名称" />
        <el-table-column prop="code" label="编码" class-name="font-code" />
        <el-table-column label="状态" width="80">
          <template #default="{ row }">
            <el-tag :type="row.status === 1 ? 'success' : 'info'">{{ row.status === 1 ? '启用' : '停用' }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="140">
          <template #default="{ row }">
            <el-button v-if="auth.has('warehouse.update')" link type="primary" @click="openEditLocation(row)">编 辑</el-button>
            <el-button v-if="auth.has('warehouse.delete')" link type="danger" @click="removeLocation(row)">删 除</el-button>
          </template>
        </el-table-column>
      </el-table>
      <!-- 库位新增/编辑小弹窗 -->
      <el-dialog v-model="locFormVisible" :title="locForm.id ? '编辑库位' : '新增库位'" width="380px" append-to-body>
        <el-form :model="locForm" label-width="80px">
          <el-form-item label="名称" required><el-input v-model="locForm.name" /></el-form-item>
          <el-form-item label="编码" required><el-input v-model="locForm.code" /></el-form-item>
          <el-form-item label="状态"><el-switch v-model="locForm.status" :active-value="1" :inactive-value="0" /></el-form-item>
        </el-form>
        <template #footer>
          <el-button @click="locFormVisible = false">取 消</el-button>
          <el-button type="primary" class="btn-primary" :loading="locSaving" @click="saveLocation">保 存</el-button>
        </template>
      </el-dialog>
    </el-dialog>
  </div>
</template>

<script setup lang="ts">
// 仓库管理页：CRUD + 库位弹窗子管理；删除有库存仓库时后端 1106 提示
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { warehouseApi, type LocationItem, type WarehouseItem } from '../../api/warehouse'
import { useAuthStore } from '../../stores/auth'

const auth = useAuthStore()
const rows = ref<WarehouseItem[]>([])
const total = ref(0)
const loading = ref(false)
const query = reactive({ page: 1, keyword: '' })
const dialogVisible = ref(false)
const saving = ref(false)
const form = reactive<any>({})

const locationVisible = ref(false)
const currentWarehouse = ref<WarehouseItem | null>(null)
const locations = ref<LocationItem[]>([])
const locFormVisible = ref(false)
const locSaving = ref(false)
const locForm = reactive<any>({})

// 加载仓库列表
async function load() {
  loading.value = true
  try {
    const res = await warehouseApi.list({ page: query.page, per_page: 10, keyword: query.keyword })
    rows.value = res.items
    total.value = res.total
  } finally {
    loading.value = false
  }
}

function openCreate() {
  Object.assign(form, { id: null, name: '', code: '', address: '', manager: '', status: 1 })
  dialogVisible.value = true
}
function openEdit(row: WarehouseItem) {
  Object.assign(form, { id: row.id, name: row.name, code: row.code, address: row.address, manager: row.manager, status: row.status })
  dialogVisible.value = true
}

// 保存仓库：后端 1105 重复编码提示展示
async function save() {
  if (!form.name || !form.code) return ElMessage.warning('请填写名称与编码')
  saving.value = true
  try {
    if (form.id) await warehouseApi.update(form.id, { name: form.name, code: form.code, address: form.address, manager: form.manager, status: form.status })
    else await warehouseApi.create({ name: form.name, code: form.code, address: form.address, manager: form.manager, status: form.status })
    ElMessage.success('保存成功')
    dialogVisible.value = false
    load()
  } catch (e) {
    ElMessage.error((e as Error).message)
  } finally {
    saving.value = false
  }
}

// 删除仓库：二次确认；有库存时后端 1106 提示
async function remove(row: WarehouseItem) {
  try {
    await ElMessageBox.confirm(`确定删除仓库「${row.name}」？`, '提示', { type: 'warning' })
  } catch {
    return
  }
  try {
    await warehouseApi.remove(row.id)
    ElMessage.success('删除成功')
    load()
  } catch (e) {
    ElMessage.error((e as Error).message)
  }
}

// 打开库位弹窗并加载该仓库库位
async function openLocations(row: WarehouseItem) {
  currentWarehouse.value = row
  locationVisible.value = true
  locations.value = (await warehouseApi.locations(row.id)).items
}

function openCreateLocation() {
  Object.assign(locForm, { id: null, name: '', code: '', status: 1 })
  locFormVisible.value = true
}
function openEditLocation(row: LocationItem) {
  Object.assign(locForm, { id: row.id, name: row.name, code: row.code, status: row.status })
  locFormVisible.value = true
}

// 保存库位：后端编码重复 422 提示展示
async function saveLocation() {
  if (!locForm.name || !locForm.code) return ElMessage.warning('请填写名称与编码')
  locSaving.value = true
  try {
    if (locForm.id) await warehouseApi.updateLocation(locForm.id, { name: locForm.name, code: locForm.code, status: locForm.status })
    else await warehouseApi.createLocation(currentWarehouse.value!.id, { name: locForm.name, code: locForm.code, status: locForm.status })
    ElMessage.success('保存成功')
    locFormVisible.value = false
    locations.value = (await warehouseApi.locations(currentWarehouse.value!.id)).items
  } catch (e) {
    ElMessage.error((e as Error).message)
  } finally {
    locSaving.value = false
  }
}

// 删除库位：二次确认；有库存时后端 1107 提示
async function removeLocation(row: LocationItem) {
  try {
    await ElMessageBox.confirm(`确定删除库位「${row.name}」？`, '提示', { type: 'warning' })
  } catch {
    return
  }
  try {
    await warehouseApi.removeLocation(row.id)
    ElMessage.success('删除成功')
    locations.value = (await warehouseApi.locations(currentWarehouse.value!.id)).items
  } catch (e) {
    ElMessage.error((e as Error).message)
  }
}

onMounted(load)
</script>

<style scoped>
/* 页面骨架 + 库位弹窗工具栏 */
.page-card { background: #fff; border-radius: 8px; box-shadow: var(--shadow-sm); padding: var(--space-2xl); }
.toolbar { display: flex; justify-content: space-between; align-items: center; margin-bottom: var(--space-xl); }
.toolbar-right { display: flex; gap: var(--space-lg); align-items: center; }
.page-title { font-size: 18px; font-weight: 600; color: var(--color-foreground); }
.btn-primary { background: var(--color-accent); border-color: var(--color-accent); cursor: pointer; }
.loc-toolbar { display: flex; justify-content: flex-end; margin-bottom: var(--space-lg); }
</style>
