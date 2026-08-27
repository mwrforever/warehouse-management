<!-- 供应商管理页：关键字搜索 + 列表 + 新建/编辑弹窗 + 删除确认 -->
<template>
  <div class="page-card">
    <ListFilterBar
      v-model:keyword="query.keyword"
      title="供应商管理"
      keyword-placeholder="名称/编码/联系人"
      @keyword-change="() => load()"
      @search="search"
      @reset="reset"
      @refresh="refresh"
    >
      <template #actions>
        <el-button v-if="auth.has('supplier.create')" class="btn-primary" @click="openCreate"
          >新 建</el-button
        >
      </template>
    </ListFilterBar>
    <el-table v-loading="loading" :data="list">
      <el-table-column prop="code" label="编码" width="120" class-name="font-code" />
      <el-table-column prop="name" label="名称" />
      <el-table-column prop="contact" label="联系人" width="100" />
      <el-table-column prop="phone" label="电话" width="140" />
      <!-- 地址列：四级地址 + 详细地址拼接展示（任一级为空跳过） -->
      <el-table-column label="地址" show-overflow-tooltip>
        <template #default="{ row }">{{ formatFullAddress(row) }}</template>
      </el-table-column>
      <el-table-column label="状态" width="90">
        <template #default="{ row }">
          <el-tag :type="row.status === 1 ? 'success' : 'info'">{{
            row.status === 1 ? '启用' : '停用'
          }}</el-tag>
        </template>
      </el-table-column>
      <el-table-column label="操作" width="160" fixed="right">
        <template #default="{ row }">
          <el-button v-if="auth.has('supplier.update')" link type="primary" @click="openEdit(row)"
            >编 辑</el-button
          >
          <el-button v-if="auth.has('supplier.delete')" link type="danger" @click="remove(row)"
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

    <el-dialog v-model="dialogVisible" :title="form.id ? '编辑供应商' : '新建供应商'" width="520px">
      <el-form :model="form" label-width="80px">
        <el-form-item label="名称" required><el-input v-model="form.name" /></el-form-item>
        <el-form-item label="编码" required><el-input v-model="form.code" /></el-form-item>
        <el-form-item label="联系人"><el-input v-model="form.contact" /></el-form-item>
        <el-form-item label="电话"><el-input v-model="form.phone" /></el-form-item>
        <!-- 地址两段式：四级地区级联（各级可留空）+ 详细地址 -->
        <el-form-item label="地址"><AreaCascader v-model="form.region" /></el-form-item>
        <el-form-item label="详细地址"
          ><el-input v-model="form.address" placeholder="详细地址"
        /></el-form-item>
        <el-form-item label="备注"
          ><el-input v-model="form.remark" type="textarea" :rows="2"
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
// 供应商管理页：搜索 + CRUD；删除被采购单据引用时后端 1109 提示
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { supplierApi, type SupplierItem } from '../../api/supplier'
import { useAuthStore } from '../../stores/auth'
import ListFilterBar from '../../components/ListFilterBar.vue'
import AreaCascader from '../../components/AreaCascader.vue'
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
  fetch: (q) => supplierApi.list(q),
  onError: (e) => ElMessage.error(e.message),
})
const dialogVisible = ref(false)
const saving = ref(false)

// 供应商表单：contact/phone/address/remark 为空字符串；region 为四级地址（空串=未选该级）
interface SupplierForm {
  id: number | null
  name: string
  code: string
  contact: string
  phone: string
  region: RegionAddress
  address: string
  remark: string
  status: number
}

const form = reactive<SupplierForm>({
  id: null,
  name: '',
  code: '',
  contact: '',
  phone: '',
  region: emptyRegion(),
  address: '',
  remark: '',
  status: 1,
})

function openCreate() {
  Object.assign(form, {
    id: null,
    name: '',
    code: '',
    contact: '',
    phone: '',
    region: emptyRegion(),
    address: '',
    remark: '',
    status: 1,
  })
  dialogVisible.value = true
}
function openEdit(row: SupplierItem) {
  // 编辑回填：四级地址由后端四列倒推 region（列可空，null 归一为空串）
  Object.assign(form, {
    id: row.id,
    name: row.name,
    code: row.code,
    contact: row.contact,
    phone: row.phone,
    region: {
      province: row.province ?? '',
      city: row.city ?? '',
      district: row.district ?? '',
      town: row.town ?? '',
    },
    address: row.address,
    remark: row.remark,
    status: row.status,
  })
  dialogVisible.value = true
}

// 保存：新建/编辑；后端 1108 重复编码提示展示
async function save() {
  if (!form.name || !form.code) return ElMessage.warning('请填写名称与编码')
  saving.value = true
  try {
    // 四级地址拆分后与原详细地址一并提交（region 空字段被 regionToPayload 过滤，后端落库 null）
    const region = regionToPayload(form.region)
    if (form.id)
      await supplierApi.update(form.id, {
        name: form.name,
        code: form.code,
        contact: form.contact,
        phone: form.phone,
        ...region,
        address: form.address,
        remark: form.remark,
        status: form.status,
      })
    else
      await supplierApi.create({
        name: form.name,
        code: form.code,
        contact: form.contact,
        phone: form.phone,
        ...region,
        address: form.address,
        remark: form.remark,
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

// 删除：二次确认；被引用时后端提示
async function remove(row: SupplierItem) {
  try {
    await ElMessageBox.confirm(`确定删除供应商「${row.name}」？`, '提示', { type: 'warning' })
  } catch {
    return
  }
  try {
    await supplierApi.remove(row.id)
    ElMessage.success('删除成功')
    refresh()
  } catch (e) {
    ElMessage.error((e as Error).message)
  }
}

onMounted(search)
</script>

<style scoped>
/* 页面骨架同上（page-card/btn-primary） */
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
</style>
