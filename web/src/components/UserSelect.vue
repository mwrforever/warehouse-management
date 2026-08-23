<!-- 用户选择器：用户数 ≤50 渲染 el-select 下拉直选；>50 切换为点击输入框弹出分页搜索弹窗（300ms 防抖实时搜索 + 分页表格）
     形态由首次加载的用户总数一次性判定（mode），搜索/翻页只更新列表与分页 total，不翻转形态（BUG-01）
     modelValue 绑定用户姓名（与报工 operator 字段口径一致）；数据模块级缓存，卸载后再次挂载不重复请求 -->
<script lang="ts">
// 用户选项模块级缓存：跨组件挂载复用（同一 SPA 会话内用户列表稳定，避免每次进页重复请求）。
// 缓存置于普通 script 块而非 setup 块：可导出重置钩子供测试隔离（模块级变量跨用例共享会污染断言）。
// UserItem 类型由下方 <script setup> 块导入（import 提升，两块共享同一模块作用域）
let cachedUsers: UserItem[] | null = null

// 测试隔离钩子：重置模块级缓存（pre-flight Finding B 裁决：用例 beforeEach 调用）
export function __resetUserSelectCache() {
  cachedUsers = null
}
</script>

<script setup lang="ts">
import { onMounted, ref, watch } from 'vue'
import { ElMessage } from 'element-plus'
import { userApi, type UserItem } from '../api/user'
import { useDebouncedRef } from '../utils/async'

interface Props {
  modelValue: string | null
  clearable?: boolean
  disabled?: boolean
  placeholder?: string
}
withDefaults(defineProps<Props>(), {
  clearable: true,
  disabled: false,
  placeholder: '选择用户',
})
const emit = defineEmits<{
  'update:modelValue': [value: string | null]
  change: [value: string | null]
}>()

const users = ref<UserItem[]>([])
// total 仅作弹窗分页器总数（搜索命中数）；组件形态由 mode 决定，不依赖此值（BUG-01）
const total = ref(0)
// 组件形态：首次加载按用户总数一次性判定，后续搜索/翻页不翻转（防止弹窗交互中被卸载且无法恢复）
const mode = ref<'select' | 'dialog'>('select')
const loading = ref(false)
const dialogLoading = ref(false)

// 弹窗搜索：300ms 防抖实时搜索 + 查询按钮
const searchKw = useDebouncedRef('', 300)
const searchPage = ref(1)

// 选中值：即用户姓名
function pick(name: string) {
  emit('update:modelValue', name)
  emit('change', name)
}

// 首次加载：拉取全量用户判断走下拉还是弹窗；失败降级为占位不阻塞页面（spec §7）
async function loadOptions() {
  loading.value = true
  try {
    if (cachedUsers !== null) {
      users.value = cachedUsers
      total.value = cachedUsers.length
      mode.value = cachedUsers.length <= 50 ? 'select' : 'dialog'
      return
    }
    const res = await userApi.list({ per_page: 100 })
    cachedUsers = res.items
    users.value = res.items
    total.value = res.total
    // 形态只在此处按用户总数判定一次；searchDialog/changeDialogPage 回写搜索 total 时不触碰
    mode.value = res.total <= 50 ? 'select' : 'dialog'
  } catch (e) {
    // 用户接口失败：清缓存以便下次重试，页面仅显示占位
    cachedUsers = null
    ElMessage.error((e as Error).message)
  } finally {
    loading.value = false
  }
}

// 弹窗内搜索：防抖自动查 + 手动查询按钮
async function searchDialog() {
  dialogLoading.value = true
  searchPage.value = 1
  try {
    const res = await userApi.list({ per_page: 10, keyword: searchKw.debounced.value || undefined })
    users.value = res.items
    total.value = res.total
  } catch (e) {
    ElMessage.error((e as Error).message)
  } finally {
    dialogLoading.value = false
  }
}
watch(searchKw.debounced, () => searchDialog())

async function changeDialogPage(p: number) {
  dialogLoading.value = true
  try {
    const res = await userApi.list({
      page: p,
      per_page: 10,
      keyword: searchKw.debounced.value || undefined,
    })
    users.value = res.items
    total.value = res.total
  } catch (e) {
    ElMessage.error((e as Error).message)
  } finally {
    dialogLoading.value = false
  }
}

onMounted(loadOptions)
</script>

<template>
  <!-- 下拉模式：用户 ≤50，el-select 直接选择（形态由 mode 决定，与搜索回写的分页 total 解耦） -->
  <el-select
    v-if="mode === 'select'"
    :model-value="modelValue"
    filterable
    :clearable="clearable"
    :disabled="disabled"
    :placeholder="placeholder"
    :loading="loading"
    style="width: 100%"
    @update:model-value="(v: unknown) => pick(v as string)"
  >
    <el-option v-for="u in users" :key="u.id" :label="u.name" :value="u.name" />
  </el-select>
  <!-- 分页弹窗模式：用户 >50，点击输入框弹出搜索弹窗。
       teleported=false：内容留在组件 DOM 树内，单测 wrapper.find 可查（pre-flight Finding E 裁决），
       且本场景无父级 overflow 裁剪风险 -->
  <el-popover
    v-else
    :width="520"
    trigger="click"
    placement="bottom-start"
    :disabled="disabled"
    :teleported="false"
  >
    <template #reference>
      <el-input
        :model-value="modelValue ?? ''"
        readonly
        :clearable="clearable"
        :disabled="disabled"
        :placeholder="placeholder"
      />
    </template>
    <div class="user-dialog">
      <div class="search-row">
        <el-input
          v-model="searchKw.source.value"
          placeholder="搜索姓名/用户名"
          clearable
          @keyup.enter="searchDialog"
        />
        <el-button class="btn-primary" @click="searchDialog">查 询</el-button>
      </div>
      <el-table
        v-loading="dialogLoading"
        :data="users"
        size="small"
        max-height="300"
        @row-click="(row: UserItem) => pick(row.name)"
      >
        <el-table-column prop="name" label="姓名" min-width="100" />
        <el-table-column prop="username" label="用户名" min-width="100" />
        <el-table-column prop="roles" label="角色" min-width="120">
          <template #default="{ row }">
            <span>{{ (row as UserItem).roles.map((r) => r.name).join('、') || '—' }}</span>
          </template>
        </el-table-column>
      </el-table>
      <div class="pager">
        <el-pagination
          v-model:current-page="searchPage"
          :page-size="10"
          :total="total"
          layout="prev, pager, next"
          @current-change="changeDialogPage"
        />
      </div>
    </div>
  </el-popover>
</template>

<style scoped>
.user-dialog .search-row {
  display: flex;
  gap: var(--space-md);
  margin-bottom: var(--space-md);
}
.user-dialog .search-row .el-input {
  flex: 1;
}
.user-dialog .pager {
  margin-top: var(--space-md);
  display: flex;
  justify-content: flex-end;
}
</style>
