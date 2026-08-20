<!-- 统一列表筛选栏：标题 + 可选关键字框（300ms 防抖实时搜索）+ 默认插槽（筛选项）+ 查询/重置/刷新
     行为约定（spec §4.2）：关键字输入防抖自动查询（回首页）、查询按钮 1s 节流防连点、重置恢复默认、刷新保持当前页 -->
<script setup lang="ts">
import { watch } from 'vue'
import { useDebouncedRef } from '../utils/async'

interface Props {
  title?: string
  /** 传了才渲染关键字输入框；undefined 表示本页无关键字筛选（如单位/角色/字典） */
  keyword?: string
  keywordPlaceholder?: string
}
const props = withDefaults(defineProps<Props>(), {
  title: '',
  keyword: undefined,
  keywordPlaceholder: '关键字',
})

const emit = defineEmits<{
  'update:keyword': [value: string]
  /** 防抖后的关键字变更（父级接到后触发 useListQuery.load 查询） */
  'keyword-change': [value: string]
  search: []
  reset: []
  refresh: []
}>()

// 关键字内部防抖：输入即时回写父级（v-model），300ms 后同步 debounced 并通知父级查询
const kw = useDebouncedRef(props.keyword ?? '', 300)
// 父级外部改动关键字（如重置恢复默认）时同步进内部 source，避免 v-model 双向失步
watch(
  () => props.keyword,
  (v) => {
    if (kw.source.value !== (v ?? '')) kw.source.value = v ?? ''
  },
)
watch(kw.debounced, (v) => {
  emit('update:keyword', v)
  emit('keyword-change', v)
})

// 查询按钮：1s 节流防连点（spec §4.1 查询按钮口径）
let lastSearch = 0
function onSearch() {
  const now = Date.now()
  if (now - lastSearch < 1000) return
  lastSearch = now
  emit('search')
}

// 重置：清空内部关键字并通知父级（父级恢复默认筛选后统一查询）。
// 同步 emit update:keyword('')：父级 v-model 立即收到清空（pre-flight Finding C 裁决：改实现，
// 与测试"点重置后立即断言 update:keyword=['']"一致），防抖 watch 300ms 后重复 emit 同值无害
function onReset() {
  kw.cancel()
  kw.source.value = ''
  emit('update:keyword', '')
  emit('reset')
}

function onRefresh() {
  emit('refresh')
}
</script>

<template>
  <div class="filter-bar">
    <span v-if="title" class="page-title">{{ title }}</span>
    <el-input
      v-if="keyword !== undefined"
      v-model="kw.source.value"
      :placeholder="keywordPlaceholder"
      clearable
      class="kw-input"
      @keyup.enter="onSearch"
    />
    <slot />
    <div class="actions">
      <slot name="actions" />
      <el-button class="btn-secondary" @click="onSearch">查 询</el-button>
      <el-button class="btn-secondary" @click="onReset">重 置</el-button>
      <el-button class="btn-secondary" @click="onRefresh">刷 新</el-button>
    </div>
  </div>
</template>

<style scoped>
/* 统一筛选栏：flex 单行/换行 + 等距间隔；与 main.css .toolbar 骨架一致，避免重复定义 */
.filter-bar {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: var(--space-lg);
  margin-bottom: var(--space-xl);
}
.page-title {
  font-size: 18px;
  font-weight: 600;
  color: var(--color-foreground);
  margin-right: var(--space-lg);
}
.kw-input {
  width: 200px;
  flex: none;
}
/* 动作区（查询/重置/刷新 + 插槽中的新建等）靠右，避免与筛选项混排 */
.actions {
  margin-left: auto;
  display: flex;
  gap: var(--space-lg);
  flex-wrap: wrap;
}
.actions :deep(.btn-secondary) {
  border-color: var(--color-primary);
  color: var(--color-primary);
}
.actions :deep(.btn-secondary:hover) {
  background: var(--color-muted);
}
</style>
