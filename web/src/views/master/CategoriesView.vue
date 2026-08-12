<!-- 分类管理页：树形展示 + 新建/编辑弹窗 + 删除保护提示 -->
<template>
  <div class="page-card">
    <div class="toolbar">
      <span class="page-title">分类管理</span>
      <el-button v-if="auth.has('category.create')" class="btn-primary" @click="openCreate()"
        >新 建</el-button
      >
    </div>
    <el-tree
      :data="tree"
      :props="{ label: 'name', children: 'children' }"
      default-expand-all
      node-key="id"
    >
      <template #default="{ data }">
        <div class="tree-node">
          <span>{{ data.name }}</span>
          <span class="tree-actions">
            <el-button
              v-if="auth.has('category.update')"
              link
              type="primary"
              @click.stop="openEdit(data)"
              >编 辑</el-button
            >
            <el-button
              v-if="auth.has('category.delete')"
              link
              type="danger"
              :disabled="hasChildren(data)"
              @click.stop="remove(data)"
              >删 除</el-button
            >
          </span>
        </div>
      </template>
    </el-tree>

    <!-- 新建/编辑弹窗：上级分类下拉（仅顶级 + 根） -->
    <el-dialog v-model="dialogVisible" :title="form.id ? '编辑分类' : '新建分类'" width="420px">
      <el-form :model="form" label-width="80px">
        <el-form-item label="上级分类">
          <el-select v-model="form.parent_id" style="width: 100%">
            <el-option :label="'无（顶级分类）'" :value="0" />
            <el-option v-for="c in topLevel" :key="c.id" :label="c.name" :value="c.id" />
          </el-select>
        </el-form-item>
        <el-form-item label="名称" required><el-input v-model="form.name" /></el-form-item>
        <el-form-item label="排序"><el-input-number v-model="form.sort" :min="0" /></el-form-item>
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
// 分类管理页：树形加载/新建/编辑/删除；含子分类禁用删除按钮，后端 1101/1102 双保险
import { computed, onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { categoryApi, type CategoryItem } from '../../api/category'
import { useAuthStore } from '../../stores/auth'

const auth = useAuthStore()
const tree = ref<CategoryItem[]>([])
const dialogVisible = ref(false)
const saving = ref(false)

// 分类表单：parent_id=0 表示顶级分类（新建时由 openCreate(parentId) 决定）
interface CategoryForm {
  id: number | null
  name: string
  parent_id: number
  sort: number
  status: number
}

const form = reactive<CategoryForm>({ id: null, name: '', parent_id: 0, sort: 0, status: 1 })

// 顶级分类列表（上级下拉数据源）
const topLevel = computed(() => tree.value.map((n) => ({ id: n.id, name: n.name })))

// 含子分类时禁用删除（后端 1101 双保险）
function hasChildren(node: CategoryItem) {
  return !!node.children?.length
}

// 加载树
async function load() {
  tree.value = await categoryApi.tree()
}

// 新建（默认顶级）
function openCreate(parentId = 0) {
  Object.assign(form, { id: null, name: '', parent_id: parentId, sort: 0, status: 1 })
  dialogVisible.value = true
}

// 编辑回填
function openEdit(node: CategoryItem) {
  Object.assign(form, {
    id: node.id,
    name: node.name,
    parent_id: node.parent_id,
    sort: node.sort,
    status: node.status,
  })
  dialogVisible.value = true
}

// 保存：失败展示后端业务错误（1101/1102/1124）
async function save() {
  if (!form.name) return ElMessage.warning('请输入分类名称')
  saving.value = true
  try {
    if (form.id)
      await categoryApi.update(form.id, {
        name: form.name,
        parent_id: form.parent_id,
        sort: form.sort,
        status: form.status,
      })
    else
      await categoryApi.create({
        name: form.name,
        parent_id: form.parent_id,
        sort: form.sort,
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

// 删除：二次确认；取消/关闭静默返回
async function remove(node: CategoryItem) {
  try {
    await ElMessageBox.confirm(`确定删除分类「${node.name}」？`, '提示', { type: 'warning' })
  } catch {
    return
  }
  try {
    await categoryApi.remove(node.id)
    ElMessage.success('删除成功')
    load()
  } catch (e) {
    ElMessage.error((e as Error).message)
  }
}

onMounted(load)
</script>

<style scoped>
/* 树节点行内操作：hover 显示，点击不冒泡到节点选中 */
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
.tree-node {
  display: flex;
  justify-content: space-between;
  align-items: center;
  flex: 1;
  padding-right: var(--space-lg);
}
.tree-actions {
  visibility: hidden;
}
.tree-node:hover .tree-actions {
  visibility: visible;
}
</style>
