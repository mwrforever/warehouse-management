<!-- 角色管理页：列表 + 新建/编辑弹窗（权限树勾选） -->
<template>
  <div>
    <ListFilterBar title="角色管理" @search="search" @reset="reset" @refresh="refresh">
      <template #actions>
        <el-button v-if="auth.has('role.create')" class="btn-primary" @click="openEdit()"
          >新 建</el-button
        >
      </template>
    </ListFilterBar>
    <el-table v-loading="loading" :data="list">
      <el-table-column prop="id" label="ID" width="70" />
      <el-table-column prop="name" label="名称" />
      <el-table-column prop="code" label="编码" class-name="font-code" />
      <el-table-column label="权限" width="120">
        <template #default="{ row }"> {{ row.permissions.length }} 项 </template>
      </el-table-column>
      <el-table-column prop="remark" label="备注" />
      <el-table-column label="操作" width="140" fixed="right">
        <template #default="{ row }">
          <el-button v-if="auth.has('role.update')" link type="primary" @click="openEdit(row)"
            >编 辑</el-button
          >
          <el-button v-if="auth.has('role.delete')" link type="danger" @click="remove(row)"
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

    <!-- 新建/编辑弹窗：表单 + 权限树勾选 -->
    <el-dialog v-model="dialogVisible" :title="form.id ? '编辑角色' : '新建角色'" width="560px">
      <el-form :model="form" label-width="80px">
        <el-form-item label="名称" required><el-input v-model="form.name" /></el-form-item>
        <el-form-item label="编码" required><el-input v-model="form.code" /></el-form-item>
        <el-form-item label="备注"><el-input v-model="form.remark" /></el-form-item>
        <el-form-item label="权限">
          <el-tree
            ref="treeRef"
            :data="treeData"
            show-checkbox
            node-key="id"
            :props="{ label: 'label', children: 'children' }"
            default-expand-all
            class="perm-tree"
          />
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
import { useAuthStore } from '../../stores/auth'
import ListFilterBar from '../../components/ListFilterBar.vue'
import { useListQuery } from '../../composables/useListQuery'

const auth = useAuthStore()

// 列表查询状态（统一组合式：防抖加载/查询/重置/刷新，请求序号守卫并发）
const { query, list, total, loading, search, reset, refresh } = useListQuery({
  defaultQuery: {},
  fetch: (q) => roleApi.list(q),
  onError: (e) => ElMessage.error(e.message),
})
const dialogVisible = ref(false)

// 角色表单：id 为空表示新建
interface RoleForm {
  id: number | null
  name: string
  code: string
  remark: string
}

const form = reactive<RoleForm>({ id: null, name: '', code: '', remark: '' })
const treeRef = ref()
// 权限树节点：分组父节点（id 带 g- 前缀）与权限叶子节点共用结构；叶子节点带 code 供回填映射
interface PermTreeNode {
  id: string | number
  label: string
  code?: string
  children?: PermTreeNode[]
}
// 权限树数据：groups 分组 → 树节点（node-key 统一用 permission id；父节点 id 用 'g-' 前缀区分）
const treeData = ref<PermTreeNode[]>([])
// 编辑回填的已勾选权限 id 集合
const checkedKeys = ref<number[]>([])
// 权限树加载 Promise 缓存：列表与权限树并行加载，编辑弹窗回填前复用同一请求（防竞态丢勾选）
let permTreePromise: Promise<void> | null = null

// 加载权限清单并构造权限树（label 用权限 name）
function loadPermissions(): Promise<void> {
  if (!permTreePromise) {
    permTreePromise = roleApi
      .permissions()
      .then((res) => {
        treeData.value = res.groups.map((g) => ({
          id: 'g-' + g.group,
          label: g.group,
          children: g.permissions.map((p: PermissionItem) => ({
            id: p.id,
            label: p.name,
            code: p.code,
          })),
        }))
      })
      // 失败清除缓存：下次重试重新请求，避免永久缓存 rejection
      .catch((e) => {
        permTreePromise = null
        throw e
      })
  }
  return permTreePromise
}

// 打开弹窗：新建清空；编辑回填已勾选权限（后端返回 code 集合，映射为树节点 id 后回填）
async function openEdit(row?: RoleItem) {
  Object.assign(
    form,
    row
      ? { id: row.id, name: row.name, code: row.code, remark: row.remark }
      : { id: null, name: '', code: '', remark: '' },
  )
  // 权限树未就绪时等待加载完成：确保 code→id 映射完整（否则空勾选保存会全量重挂、静默清空角色权限）
  try {
    await loadPermissions()
  } catch {
    ElMessage.error('权限清单加载失败，请重试')
    return
  }
  // 构建 code→id 映射：角色列表返回的是权限 code，而树 node-key 为权限 id（叶子节点必带 code）
  const codeToId = new Map<string, number>()
  treeData.value.forEach((g) =>
    g.children?.forEach((p) => codeToId.set(p.code as string, p.id as number)),
  )
  checkedKeys.value = row
    ? row.permissions
        .map((code) => codeToId.get(code))
        .filter((id): id is number => id !== undefined)
    : []
  dialogVisible.value = true
  // 树挂载完成后回填勾选状态（否则 setCheckedKeys 找不到节点）
  await nextTick()
  treeRef.value?.setCheckedKeys(checkedKeys.value)
}

// 保存：提交 permission_ids（树的已勾选 id，父节点字符串 id 过滤掉，仅叶子权限 id）
async function save() {
  const permissionIds = (treeRef.value?.getCheckedKeys() ?? []).filter(
    (k: unknown) => typeof k === 'number',
  )
  try {
    if (form.id)
      await roleApi.update(form.id, {
        name: form.name,
        code: form.code,
        remark: form.remark,
        permission_ids: permissionIds,
      })
    else
      await roleApi.create({
        name: form.name,
        code: form.code,
        remark: form.remark,
        permission_ids: permissionIds,
      })
    ElMessage.success('保存成功')
    dialogVisible.value = false
    refresh()
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
    refresh()
  } catch (e) {
    ElMessage.error((e as Error).message)
  }
}

onMounted(async () => {
  search()
  try {
    await loadPermissions()
  } catch {
    // 预加载失败不阻塞列表：编辑弹窗打开时会重新请求并提示
  }
})
</script>

<style scoped>
/* 主按钮样式（btn-primary 语义色） */
.btn-primary {
  background: var(--color-accent);
  border-color: var(--color-accent);
  cursor: pointer;
}
.perm-tree {
  width: 100%;
  max-height: 280px;
  overflow: auto;
  border: 1px solid var(--color-border);
  border-radius: 4px;
  padding: var(--space-md);
}
</style>
