<!-- 用户管理页：搜索 + 列表 + 新建/编辑弹窗 + 删除确认 + 重置密码 -->
<template>
  <div>
    <div class="toolbar">
      <el-input
        v-model="query.keyword"
        placeholder="用户名/姓名"
        clearable
        style="width: 220px"
        @keyup.enter="load"
      />
      <el-button v-if="auth.has('user.create')" class="btn-primary" @click="openCreate"
        >新 建</el-button
      >
    </div>
    <el-table v-loading="loading" :data="rows">
      <el-table-column prop="id" label="ID" width="70" />
      <el-table-column prop="username" label="用户名" class-name="font-code" />
      <el-table-column prop="name" label="姓名" />
      <el-table-column prop="email" label="邮箱" />
      <el-table-column label="状态" width="100">
        <template #default="{ row }">
          <el-tag :type="row.status === 1 ? 'success' : 'info'">{{
            row.status === 1 ? '启用' : '已禁用'
          }}</el-tag>
        </template>
      </el-table-column>
      <el-table-column label="角色">
        <template #default="{ row }">
          <el-tag v-for="r in row.roles" :key="r.id" size="small" class="role-tag">{{
            r.name
          }}</el-tag>
        </template>
      </el-table-column>
      <el-table-column prop="last_login_at" label="最后登录" width="180" />
      <el-table-column label="操作" width="220" fixed="right">
        <template #default="{ row }">
          <el-button v-if="auth.has('user.update')" link type="primary" @click="openEdit(row)"
            >编 辑</el-button
          >
          <el-button v-if="auth.has('user.update')" link type="warning" @click="openReset(row)"
            >重置密码</el-button
          >
          <el-button v-if="auth.has('user.delete')" link type="danger" @click="remove(row)"
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

    <!-- 新建/编辑弹窗 -->
    <el-dialog v-model="dialogVisible" :title="form.id ? '编辑用户' : '新建用户'" width="480px">
      <el-form :model="form" label-width="80px">
        <el-form-item label="用户名" required><el-input v-model="form.username" /></el-form-item>
        <el-form-item label="姓名" required><el-input v-model="form.name" /></el-form-item>
        <el-form-item label="邮箱"><el-input v-model="form.email" /></el-form-item>
        <el-form-item v-if="!form.id" label="密码" required
          ><el-input v-model="form.password" type="password" show-password
        /></el-form-item>
        <el-form-item label="状态"
          ><el-switch v-model="form.status" :active-value="1" :inactive-value="0"
        /></el-form-item>
        <el-form-item label="角色">
          <el-select v-model="form.role_ids" multiple placeholder="选择角色" style="width: 100%">
            <el-option v-for="r in roles" :key="r.id" :label="r.name" :value="r.id" />
          </el-select>
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
// 用户管理页：列表查询/新建编辑/删除（内置 admin 删除被后端拦截并提示）/重置密码
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { userApi, type UserItem } from '../../api/user'
import { roleApi } from '../../api/role'
import { useAuthStore } from '../../stores/auth'

const auth = useAuthStore()

const rows = ref<UserItem[]>([])
const roles = ref<{ id: number; name: string }[]>([])
const total = ref(0)
const loading = ref(false)
const query = reactive({ page: 1, keyword: '' })
const dialogVisible = ref(false)

// 用户表单：password 仅新建时必填（编辑弹窗不展示）；role_ids 为多选角色 id 数组
interface UserForm {
  id: number | null
  username: string
  name: string
  email: string
  password: string
  status: number
  role_ids: number[]
}

const form = reactive<UserForm>({
  id: null,
  username: '',
  name: '',
  email: '',
  password: '',
  status: 1,
  role_ids: [],
})

// 加载列表：携带分页与关键字
async function load() {
  loading.value = true
  try {
    const res = await userApi.list({ page: query.page, per_page: 10, keyword: query.keyword })
    rows.value = res.items
    total.value = res.total
  } finally {
    loading.value = false
  }
}

// 新建/编辑弹窗初始化（角色下拉复用角色列表接口）
function openCreate() {
  Object.assign(form, {
    id: null,
    username: '',
    name: '',
    email: '',
    password: '',
    status: 1,
    role_ids: [],
  })
  dialogVisible.value = true
}
function openEdit(row: UserItem) {
  Object.assign(form, {
    id: row.id,
    username: row.username,
    name: row.name,
    email: row.email,
    status: row.status,
    role_ids: row.roles.map((r) => r.id),
  })
  dialogVisible.value = true
}

// 保存：新建走 create（必填密码），编辑走 update（不含密码）；失败 ElMessage 展示后端 message
async function save() {
  try {
    if (form.id) {
      await userApi.update(form.id, {
        name: form.name,
        username: form.username,
        email: form.email,
        status: form.status,
        role_ids: form.role_ids,
      })
    } else {
      await userApi.create({
        name: form.name,
        username: form.username,
        password: form.password,
        email: form.email,
        status: form.status,
        role_ids: form.role_ids,
      })
    }
    ElMessage.success('保存成功')
    dialogVisible.value = false
    load()
  } catch (e) {
    ElMessage.error((e as Error).message)
  }
}

// 删除：二次确认后调用；后端拒绝（如内置 admin）时展示错误
async function remove(row: UserItem) {
  try {
    await ElMessageBox.confirm(`确定删除用户 ${row.name}？此操作不可恢复`, '提示', {
      type: 'warning',
    })
  } catch {
    return // 用户取消
  }
  try {
    await userApi.remove(row.id)
    ElMessage.success('删除成功')
    load()
  } catch (e) {
    ElMessage.error((e as Error).message)
  }
}

// 重置密码：输入新密码（后端校验强度）；用户取消/关闭弹窗时静默退出，不产生未处理 rejection
async function openReset(row: UserItem) {
  try {
    const { value } = await ElMessageBox.prompt(
      '请输入新密码（至少8位，含字母和数字）',
      `重置密码 - ${row.username}`,
      { inputType: 'password' },
    )
    await userApi.resetPassword(row.id, value)
    ElMessage.success('密码重置成功')
  } catch (e) {
    // Element Plus 用户取消时以字符串 'cancel'/'close' reject：静默返回，不提示错误
    if (e === 'cancel' || e === 'close') return
    ElMessage.error((e as Error).message)
  }
}

onMounted(async () => {
  load()
  try {
    roles.value = (await roleApi.list({ page: 1, per_page: 100 })).items
  } catch (e) {
    // 角色下拉加载失败提示：新建/编辑弹窗仍可用，仅角色选项缺失
    ElMessage.error((e as Error).message)
  }
})
</script>

<style scoped>
/* 工具栏间距与主按钮样式（btn-primary 语义色） */
.toolbar {
  display: flex;
  gap: var(--space-lg);
  margin-bottom: var(--space-xl);
}
.btn-primary {
  background: var(--color-accent);
  border-color: var(--color-accent);
  cursor: pointer;
}
.role-tag {
  margin-right: var(--space-xs);
}
</style>
