<!-- 仓库管理页：列表 + 新建/编辑弹窗（编码自动生成、四级地址+详细地址、负责人选择）+ 库位管理弹窗（库位 CRUD 在弹窗内完成） -->

<template>
  <div class="page-card">
    <ListFilterBar
      v-model:keyword="query.keyword"
      title="仓库管理"
      keyword-placeholder="名称/编码"
      @keyword-change="() => load()"
      @search="search"
      @reset="reset"
      @refresh="refresh"
    >
      <template #actions>
        <el-button v-if="auth.has('warehouse.create')" class="btn-primary" @click="openCreate"
          >新 建</el-button
        >
      </template>
    </ListFilterBar>
    <el-table v-loading="loading" :data="list">
      <el-table-column prop="code" label="编码" width="100" class-name="font-code" />
      <el-table-column prop="name" label="名称" min-width="120" />
      <!-- 地址列：四级地址 + 详细地址拼接展示（任一级为空跳过） -->
      <el-table-column label="地址" min-width="160" show-overflow-tooltip>
        <template #default="{ row }">{{ formatFullAddress(row) }}</template>
      </el-table-column>
      <el-table-column prop="manager" label="负责人" width="100" />
      <el-table-column label="状态" width="80">
        <template #default="{ row }">
          <el-tag :type="row.status === 1 ? 'success' : 'info'">{{
            row.status === 1 ? '启用' : '停用'
          }}</el-tag>
        </template>
      </el-table-column>
      <el-table-column label="操作" width="230" fixed="right">
        <template #default="{ row }">
          <el-button
            v-if="auth.has('warehouse.list')"
            link
            type="primary"
            @click="openLocations(row)"
            >库 位</el-button
          >
          <el-button v-if="auth.has('warehouse.update')" link type="primary" @click="openEdit(row)"
            >编 辑</el-button
          >
          <el-button v-if="auth.has('warehouse.delete')" link type="danger" @click="remove(row)"
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

    <!-- 仓库新建/编辑弹窗 -->
    <el-dialog v-model="dialogVisible" :title="form.id ? '编辑仓库' : '新建仓库'" width="520px">
      <el-form :model="form" label-width="80px">
        <el-form-item label="名称" required><el-input v-model="form.name" /></el-form-item>
        <!-- 编码由后端号段自动生成：新建仅提示不输入，编辑只读展示 -->
        <el-form-item label="编码">
          <span v-if="form.id" class="font-code">{{ form.code }}</span>
          <span v-else class="code-auto-tip">编码自动生成</span>
        </el-form-item>
        <!-- 地址两段式：四级地区级联（各级可留空）+ 详细地址 -->
        <el-form-item label="地址"><AreaCascader v-model="form.region" /></el-form-item>
        <el-form-item label="详细地址"
          ><el-input v-model="form.address" placeholder="详细地址"
        /></el-form-item>
        <el-form-item label="负责人"><UserSelect v-model="form.manager" /></el-form-item>
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

    <!-- 库位管理弹窗 -->
    <el-dialog
      v-model="locationVisible"
      :title="`库位管理 - ${currentWarehouse?.name}`"
      width="640px"
    >
      <div class="loc-toolbar">
        <el-button
          v-if="auth.has('warehouse.create')"
          class="btn-primary"
          @click="openCreateLocation"
          >新 增</el-button
        >
      </div>
      <el-table :data="locations" size="small">
        <el-table-column prop="name" label="库位名称" />
        <el-table-column prop="code" label="编码" class-name="font-code" />
        <el-table-column label="状态" width="80">
          <template #default="{ row }">
            <el-tag :type="row.status === 1 ? 'success' : 'info'">{{
              row.status === 1 ? '启用' : '停用'
            }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="140">
          <template #default="{ row }">
            <el-button
              v-if="auth.has('warehouse.update')"
              link
              type="primary"
              @click="openEditLocation(row)"
              >编 辑</el-button
            >
            <el-button
              v-if="auth.has('warehouse.delete')"
              link
              type="danger"
              @click="removeLocation(row)"
              >删 除</el-button
            >
          </template>
        </el-table-column>
      </el-table>
      <!-- 库位新增/编辑小弹窗 -->
      <el-dialog
        v-model="locFormVisible"
        :title="locForm.id ? '编辑库位' : '新增库位'"
        width="380px"
        append-to-body
      >
        <el-form :model="locForm" label-width="80px">
          <el-form-item label="名称" required><el-input v-model="locForm.name" /></el-form-item>
          <el-form-item label="编码" required><el-input v-model="locForm.code" /></el-form-item>
          <el-form-item label="状态"
            ><el-switch v-model="locForm.status" :active-value="1" :inactive-value="0"
          /></el-form-item>
        </el-form>
        <template #footer>
          <el-button @click="locFormVisible = false">取 消</el-button>
          <el-button type="primary" class="btn-primary" :loading="locSaving" @click="saveLocation"
            >保 存</el-button
          >
        </template>
      </el-dialog>
    </el-dialog>
  </div>
</template>

<script setup lang="ts">
// 仓库管理页：CRUD + 库位弹窗子管理；编码后端自动生成、地址四级级联；删除有库存仓库时后端 1106 提示
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { warehouseApi, type LocationItem, type WarehouseItem } from '../../api/warehouse'
import { useAuthStore } from '../../stores/auth'
import ListFilterBar from '../../components/ListFilterBar.vue'
import AreaCascader from '../../components/AreaCascader.vue'
import UserSelect from '../../components/UserSelect.vue'
import { useListQuery } from '../../composables/useListQuery'
import {
  emptyRegion,
  formatFullAddress,
  regionToPayload,
  type RegionAddress,
} from '../../utils/region'

const auth = useAuthStore()
// 列表查询状态（统一组合式：防抖加载/查询/重置/刷新，请求序号守卫并发）
const { query, list, total, loading, load, search, reset, refresh } = useListQuery({
  defaultQuery: { keyword: '' },
  fetch: (q) => warehouseApi.list(q),
  onError: (e) => ElMessage.error(e.message),
})
const dialogVisible = ref(false)
const saving = ref(false)

// 仓库表单：address/manager 为空字符串；region 为四级地址（空串=未选该级）；code 仅编辑时只读展示
interface WarehouseForm {
  id: number | null
  name: string
  code: string
  region: RegionAddress
  address: string
  manager: string
  status: number
}

const form = reactive<WarehouseForm>({
  id: null,
  name: '',
  code: '',
  region: emptyRegion(),
  address: '',
  manager: '',
  status: 1,
})

const locationVisible = ref(false)
const currentWarehouse = ref<WarehouseItem | null>(null)
const locations = ref<LocationItem[]>([])
const locFormVisible = ref(false)
const locSaving = ref(false)

// 库位表单：编码重复由后端 422 拦截
interface LocationForm {
  id: number | null
  name: string
  code: string
  status: number
}

const locForm = reactive<LocationForm>({ id: null, name: '', code: '', status: 1 })

function openCreate() {
  Object.assign(form, {
    id: null,
    name: '',
    code: '',
    region: emptyRegion(),
    address: '',
    manager: '',
    status: 1,
  })
  dialogVisible.value = true
}
function openEdit(row: WarehouseItem) {
  // 编辑回填：四级地址由后端四列倒推 region（列可空，null 归一为空串），编码只读展示
  Object.assign(form, {
    id: row.id,
    name: row.name,
    code: row.code,
    region: {
      province: row.province ?? '',
      city: row.city ?? '',
      district: row.district ?? '',
      town: row.town ?? '',
    },
    address: row.address ?? '',
    manager: row.manager ?? '',
    status: row.status,
  })
  dialogVisible.value = true
}

// 保存仓库：名称必填（编码由后端自动生成，提交不含 code）
async function save() {
  if (!form.name) return ElMessage.warning('请填写名称')
  saving.value = true
  try {
    // 四级地址拆分后与详细地址一并提交（region 空字段经 regionToPayload 过滤，后端落库 null）
    const region = regionToPayload(form.region)
    if (form.id)
      await warehouseApi.update(form.id, {
        name: form.name,
        ...region,
        address: form.address,
        manager: form.manager,
        status: form.status,
      })
    else
      await warehouseApi.create({
        name: form.name,
        ...region,
        address: form.address,
        manager: form.manager,
        status: form.status,
      })
    ElMessage.success('保存成功')
    dialogVisible.value = false
    refresh()
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
    refresh()
  } catch (e) {
    ElMessage.error((e as Error).message)
  }
}

// 打开库位弹窗并加载该仓库库位
async function openLocations(row: WarehouseItem) {
  currentWarehouse.value = row
  locationVisible.value = true
  try {
    locations.value = (await warehouseApi.locations(row.id)).items
  } catch (e) {
    // 加载失败提示：避免弹窗空白列表无提示（与其他请求风格一致）
    ElMessage.error((e as Error).message)
  }
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
    if (locForm.id)
      await warehouseApi.updateLocation(locForm.id, {
        name: locForm.name,
        code: locForm.code,
        status: locForm.status,
      })
    else
      await warehouseApi.createLocation(currentWarehouse.value!.id, {
        name: locForm.name,
        code: locForm.code,
        status: locForm.status,
      })
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

onMounted(search)
</script>

<style scoped>
/* 页面骨架 + 库位弹窗工具栏 */
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
/* 新建态编码提示：次要文本色，与只读展示区分（编码由后端自动生成） */
.code-auto-tip {
  color: var(--el-text-color-secondary);
}
.loc-toolbar {
  display: flex;
  justify-content: flex-end;
  margin-bottom: var(--space-lg);
}
</style>
