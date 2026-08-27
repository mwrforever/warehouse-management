<!-- 统一列表筛选栏：标题 + 可选关键字框（300ms 防抖实时搜索）+ 默认插槽（筛选项）+ 查询/重置/刷新
     行为约定（spec §4.2）：关键字输入防抖自动查询（回首页）、查询按钮 1s 节流防连点、重置恢复默认、刷新保持当前页 -->
<script setup lang="ts">
import { onScopeDispose, watch } from 'vue'
import { throttle, useDebouncedRef } from '../utils/async'

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

// 关键字内部防抖：输入即时回写父级（v-model），300ms 后同步 debounced 并通知父级查询。
// 实时搜索实际延迟为两层防抖串联：本组件内层 300ms + useListQuery.load 外层 300ms = 600ms（N-01 口径）；
// 外层防抖同时服务下拉等其它筛选变更的合并，两层职责不同不做压缩。回车/查询经 flush 立即生效，不受串联影响
const kw = useDebouncedRef(props.keyword ?? '', 300)
// 父级外部改动关键字（如重置恢复默认）时同步进内部 source，避免 v-model 双向失步
watch(
  () => props.keyword,
  (v) => {
    if (kw.source.value !== (v ?? '')) kw.source.value = v ?? ''
  },
)
// sync 冲刷：flush() 同步改写 debounced 后，本 watch 同步 emit，确保「查询/重置」动作里
// 最新关键字先于 search/reset 事件到达父级——父级 search()/reset() 内的 load.cancel()
// 才能取消 keyword-change 刚排定的防抖查询，避免旧关键字请求与随后的重复请求
watch(
  kw.debounced,
  (v) => {
    emit('update:keyword', v)
    emit('keyword-change', v)
  },
  { flush: 'sync' },
)

// 查询动作 1s 节流防连点（spec §4.1 查询按钮口径）：窗口内连点只立即执行首次，
// 被吞的最后一次在窗口结束补一次尾调用，连点的最终意图不丢失。
// 统一走 utils/async.ts throttle 工具，替代此前同语义的手写时间戳节流（手写版窗口内直接吞掉、无尾调用）
const onSearch = throttle(() => {
  // 先冲刷内部防抖再通知查询：防抖窗口内输入后立即回车时，父级 query.keyword 尚未收到新值，
  // 若不冲刷会先按旧关键字请求、约 600ms 后防抖到期再重复请求一次（BUG-07）
  kw.flush()
  emit('search')
}, 1000)
// 卸载时取消节流尾调用：1s 窗口内离开页面后不得再补发 search 触发已卸载页面的查询
onScopeDispose(onSearch.cancel)

// 重置：清空内部关键字并通知父级（父级恢复默认筛选后统一查询）。
// 先清空再冲刷：source 置 '' 后 flush 立即把 debounced 同步为空串并取消挂起计时器——
// 若 debounced 滞留旧关键字（双击重置吞掉计时器、或单击重置后 300ms 内重输同词时窗口到期赋回同值），
// 同值赋值不触发 watch，重输同词将静默失效（BUG-06）。冲刷触发的 keyword-change('') 排定的
// 防抖查询由父级 reset() 内的 load.cancel() 取消，不再于 600ms 后重复请求。
// 同步 emit update:keyword('')：父级 v-model 立即收到清空（pre-flight Finding C 裁决：改实现，
// 与测试"点重置后立即断言 update:keyword=['']"一致）
function onReset() {
  kw.source.value = ''
  kw.flush()
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
/* 日期范围选择器保持固有宽度（Element Plus 根类 flex-grow:1 会被 flex 容器拉伸），
   与 main.css .toolbar 下 .el-date-editor { flex: none } 口径一致 */
.filter-bar :deep(.el-date-editor) {
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
