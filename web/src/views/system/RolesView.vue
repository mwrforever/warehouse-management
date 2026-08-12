<!-- 角色管理页：列表 + 新建/编辑弹窗（权限树勾选） -->
<template>
  <div>
    <div class="toolbar">
      <el-button class="btn-primary" @click="openEdit()">新 建</el-button>
    </div>
    <el-table :data="rows" v-loading="loading">
      <el-table-column prop="id" label="ID" width="70" />
      <el-table-column prop="name" label="名称" />
      <el-table-column prop="code" label="编码" class-name="font-code" />
      <el-table-column label="权限" width="120">
        <template #default="{ row }">
          {{ row.permissions.length }} 项
        </template>
      </el-table-column>
      <el-table-column prop="remark" label="备注" />
      <el-table-column label="操作" width="140" fixed="right">
        <template #default="{ row }">
          <el-button link type="primary" @click="openEdit(row)">编 辑</el-button>
          <el-button link type="danger" @click="remove(row)">删 除</el-button>
        </template>
      </el-table-column>
    </el-table>
    <el-pagination v-model:current-page="query.page" :total="total" :page-size="10" layout="total, prev, pager, next" @current-change="load" />

    <!-- 新建/编辑弹窗：表单 + 权限树勾选 -->
    <el-dialog v-model="dialogVisible" :title="form.id ? '编辑角色' : '新建角色'" width="560px">
      <el-form :model="form" label-width="80px">
        <el-form-item label="名称" required><el-input v-model="form.name" /></el-form-item>
        <el-form-item label="编码" required><el-input v-model="form.code" /></el-form-item>
        <el-form-item label="备注"><el-input v-model="form.remark" /></el-form-item>
        <el-form-item label="权限">
          <el-tree ref="treeRef" :data="treeData" show-checkbox node-key="id" :props="{ label: 'label', children: 'children' }" default-expand-all class="perm-tree" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="dialogVisible = false">取 消</el-button>
        <el-button type="primary" class="btn-primary" @click="save">保 存</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup lang="ts">
// 角色管理页：CRUD + 权限树分配；删除被引用角色时展示后端 1004 提示
import { nextTick, onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { roleApi, type PermissionItem, type RoleItem } from '../../api/role'

const rows = ref<RoleItem[]>([])
const total = ref(0)
const loading = ref(false)
const query = reactive({ page: 1 })
const dialogVisible = ref(false)
const form = reactive<any>({})
const treeRef = ref()
// 权限树数据：groups 分组 → 树节点（node-key 统一用 permission id；父节点 id 用 'g-' 前缀区分）
const treeData = ref<any[]>([])
// 编辑回填的已勾选权限 id 集合
const checkedKeys = ref<number[]>([])

// 加载角色列表
async function load() {
  loading.value = true
  try {
    const res = await roleApi.list({ page: query.page, per_page: 10 })
    rows.value = res.items
    total.value = res.total
  } finally {
    loading.value = false
  }
}

// 加载权限清单并构造权限树（label 用权限 name）
async function loadPermissions() {
  const res = await roleApi.permissions()
  treeData.value = res.groups.map((g) => ({
    id: 'g-' + g.group,
    label: g.group,
    children: g.permissions.map((p: PermissionItem) => ({ id: p.id, label: p.name, code: p.code })),
  }))
}

// 打开弹窗：新建清空；编辑回填已勾选权限（后端返回 code 集合，映射为树节点 id 后回填）
async function openEdit(row?: RoleItem) {
  Object.assign(form, row ? { id: row.id, name: row.name, code: row.code, remark: row.remark } : { id: null, name: '', code: '', remark: '' })
  // 构建 code→id 映射：角色列表返回的是权限 code，而树 node-key 为权限 id
  const codeToId = new Map<string, number>()
  treeData.value.forEach((g) => g.children.forEach((p: PermissionItem) => codeToId.set(p.code, p.id)))
  checkedKeys.value = row ? row.permissions.map((code) => codeToId.get(code)).filter((id): id is number => id !== undefined) : []
  dialogVisible.value = true
  // 树挂载完成后回填勾选状态（否则 setCheckedKeys 找不到节点）
  await nextTick()
  treeRef.value?.setCheckedKeys(checkedKeys.value)
}

// 保存：提交 permission_ids（树的已勾选 id，父节点字符串 id 过滤掉，仅叶子权限 id）
async function save() {
  const permissionIds = (treeRef.value?.getCheckedKeys() ?? []).filter((k: unknown) => typeof k === 'number')
  try {
    if (form.id) await roleApi.update(form.id, { name: form.name, code: form.code, remark: form.remark, permission_ids: permissionIds })
    else await roleApi.create({ name: form.name, code: form.code, remark: form.remark, permission_ids: permissionIds })
    ElMessage.success('保存成功')
    dialogVisible.value = false
    load()
  } catch (e) {
    ElMessage.error((e as Error).message)
  }
}

// 删除：二次确认后调用；被引用角色由后端 1004 拦截并提示
async function remove(row: RoleItem) {
  try {
    await ElMessageBox.confirm(`确定删除角色 ${row.name}？`, '提示', { type: 'warning' })
  } catch {
    return // 用户取消
  }
  try {
    await roleApi.remove(row.id)
    ElMessage.success('删除成功')
    load()
  } catch (e) {
    ElMessage.error((e as Error).message)
  }
}

onMounted(async () => {
  load()
  await loadPermissions()
})
</script>

<style scoped>
/* 工具栏间距与主按钮样式（btn-primary 语义色） */
.toolbar { display: flex; gap: var(--space-lg); margin-bottom: var(--space-xl); }
.btn-primary { background: var(--color-accent); border-color: var(--color-accent); cursor: pointer; }
.perm-tree { width: 100%; max-height: 280px; overflow: auto; border: 1px solid var(--color-border); border-radius: 4px; padding: var(--space-md); }
</style>
