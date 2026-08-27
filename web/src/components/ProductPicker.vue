<!-- 商品选择器：选项多（各类型合计 >50）时点击输入框弹出分页搜索弹窗（300ms 防抖实时搜索 +
     查询按钮 1s 节流 + 分页表格），弹窗行展示「名称正文 + 紧跟编号标签 + 类型标签」；
     选项少（≤50）回退 el-select 下拉本地过滤。支持多类型档位合并（如半成品+成品），
     形态由初载各档 total 合计一次性判定（搜索/翻页只更新列表与分页 total，不翻转形态）。
     modelValue 绑定商品 id；change 事件携带选中行（含 unit_id 供 BOM 物料单位带出）；
     商品数据模块级缓存：同类型组合跨实例复用，卸载后再挂载不重复请求 -->
<script lang="ts">
// 商品选项模块级缓存：跨组件挂载复用（主数据会话内稳定，避免每次打开重复请求）。
// 缓存键 = 类型组合排序拼接；缓存真实 total（后端 per_page 钳制 100，items.length 可能 < total）。
// 测试隔离钩子：用例 beforeEach 调用（与 UserSelect.__resetUserSelectCache 同模式）
import type { ProductType } from '../api/product'

export interface ProductOptionRow {
  id: number
  name: string
  code: string
  type?: ProductType
  type_label?: string
  unit_id?: number | null
}

interface CacheEntry {
  options: ProductOptionRow[]
  total: number
  mode: 'select' | 'dialog'
}

let cache = new Map<string, CacheEntry>()

export function __resetProductPickerCache() {
  cache = new Map()
}
</script>

<script setup lang="ts">
import { computed, onMounted, onScopeDispose, ref, watch } from 'vue'
// ProductType 由上方普通 script 块导入（两块共享模块作用域，避免重复声明）
import { productApi } from '../api/product'
import { throttle, useDebouncedRef } from '../utils/async'

interface Props {
  modelValue: number | null
  /** 可选类型档位（合并为一组选择源，如半成品+成品） */
  types: ProductType[]
  clearable?: boolean
  disabled?: boolean
  placeholder?: string
  /** 回显/已选项（编辑时不在选项列表内的历史商品）：并入选项尾部保证名称展示 */
  pin?: ProductOptionRow | null
}
const props = withDefaults(defineProps<Props>(), {
  clearable: true,
  disabled: false,
  placeholder: '选择商品',
  pin: null,
})
const emit = defineEmits<{
  'update:modelValue': [value: number | null]
  /** 选中行（含 unit_id 供单位带出）；清除时 null */
  change: [row: ProductOptionRow | null]
}>()

// 组件形态：首次加载按各档 total 合计一次性判定，后续搜索/翻页不翻转（与 UserSelect 同策略）
const mode = ref<'select' | 'dialog'>('select')
const options = ref<ProductOptionRow[]>([])
const total = ref(0)
const loading = ref(false)
const dialogLoading = ref(false)
// 弹窗搜索：300ms 防抖实时搜索 + 查询按钮 1s 节流防连点
const searchKw = useDebouncedRef('', 300)
const searchPage = ref(1)
// 请求序号守卫（UserSelect 同款）：防抖搜索与查询/翻页并发时，先发后至的响应直接丢弃
let requestSeq = 0

// 缓存键：类型组合排序拼接（'finished,semi_finished'）
const cacheKey = computed(() => [...props.types].sort().join(','))

// 类型标签语义色（与商品管理页类型列一致：原料 info/半成品 warning/成品 success）
const PRODUCT_TYPE_TAGS = {
  raw_material: 'info',
  semi_finished: 'warning',
  finished: 'success',
} as const

function typeTag(type: ProductType | undefined) {
  return PRODUCT_TYPE_TAGS[type ?? 'raw_material']
}

/** 选中行反查：选项集 → pin 项；均无则 null（父级负责 pin 保证回显名称） */
const selected = computed<ProductOptionRow | null>(() => {
  const id = props.modelValue
  if (id == null) return null
  return (
    options.value.find((o) => o.id === id) ??
    (props.pin && props.pin.id === id ? props.pin : null) ??
    null
  )
})

/** 选中显示文案：名字正文 + 编号括号标注；pin 回显项无编码时仅名字 */
const selectedLabel = computed(() => {
  const s = selected.value
  if (!s) return ''
  return s.code ? `${s.name}（${s.code}）` : s.name
})

// 初载：每档 per_page=1 轻量取 total 判定形态（下拉模式再拉全量选项；弹窗模式表格数据
// 由弹窗打开时的首屏搜索提供）；失败静默降级不弹错（与 UserSelect 同取舍）
async function loadOptions() {
  loading.value = true
  try {
    const key = cacheKey.value
    const hit = cache.get(key)
    if (hit) {
      options.value = hit.options
      total.value = hit.total
      mode.value = hit.mode
      // 弹窗模式挂载即预载第一页：弹窗打开时表格数据就绪（避免打开瞬间空表/闪烁）
      if (hit.mode === 'dialog') void searchDialog()
      return
    }
    const metas = await Promise.all(
      props.types.map((t) => productApi.list({ page: 1, per_page: 1, type: t })),
    )
    const sum = metas.reduce((acc, l) => acc + l.total, 0)
    const isDialog = sum > 50
    let items: ProductOptionRow[] = []
    if (!isDialog) {
      // 下拉模式：选项合计 ≤50，一次拉全量供本地过滤
      const full = await Promise.all(
        props.types.map((t) => productApi.list({ page: 1, per_page: 100, type: t })),
      )
      items = full.flatMap((l) => l.items)
    }
    cache.set(key, { options: items, total: sum, mode: isDialog ? 'dialog' : 'select' })
    total.value = sum
    mode.value = isDialog ? 'dialog' : 'select'
    // 初载/搜索响应会整体替换选项，pin 项需重新并入尾部（编辑回显保障）
    options.value = [...items, ...pinnedRows.value.filter((p) => !items.some((o) => o.id === p.id))]
    // 弹窗模式挂载即预载第一页（同上，缓存未命中路径）
    if (isDialog) void searchDialog()
  } catch {
    // 初载失败静默降级（含 403 无权限）：下拉/弹窗空选项不阻塞宿主页，清缓存以便下次重试
    cache.delete(cacheKey.value)
  } finally {
    loading.value = false
  }
}

// pin 并入选项尾部（回显名称保障）：记录真实并入的项（不在初载列表内），
// pin 清空（弹窗关闭）时仅移除并入项，避免上一单回显项串入下一单，初载列表与缓存保留
const pinnedRows = ref<ProductOptionRow[]>([])
watch(
  () => props.pin,
  (p) => {
    if (p) {
      pinnedRows.value = [...pinnedRows.value.filter((r) => r.id !== p.id), p]
      if (!options.value.some((o) => o.id === p.id)) options.value = [...options.value, p]
    } else {
      const ids = new Set(pinnedRows.value.map((r) => r.id))
      options.value = options.value.filter((o) => !ids.has(o.id))
      pinnedRows.value = []
    }
  },
  { immediate: true },
)

/** 结果并入 pin 尾部（初载/搜索/翻页响应整体替换选项后，回显项不丢失） */
function mergeResults(items: ProductOptionRow[]) {
  options.value = [...items, ...pinnedRows.value.filter((p) => !items.some((o) => o.id === p.id))]
}

// 选中（弹窗行点击 / 下拉选择）：emit 行数据供单位带出
function pick(row: ProductOptionRow) {
  emit('update:modelValue', row.id)
  emit('change', { id: row.id, name: row.name, code: row.code, unit_id: row.unit_id ?? null })
}

// 清除：值与行一并置空（保持 string|null 语义归一，对齐 UserSelect 口径）
function clear() {
  emit('update:modelValue', null)
  emit('change', null)
}

// 弹窗搜索：防抖自动查 + 手动查询按钮（节流防连点）；各档独立分页后合并展示
async function searchDialog() {
  const seq = ++requestSeq
  dialogLoading.value = true
  searchPage.value = 1
  try {
    const kw = searchKw.debounced.value || undefined
    const lists = await Promise.all(
      props.types.map((t) => productApi.list({ page: 1, per_page: 10, type: t, keyword: kw })),
    )
    if (seq !== requestSeq) return // 已有更新的请求，丢弃本次过期响应
    mergeResults(lists.flatMap((l) => l.items))
    total.value = lists.reduce((acc, l) => acc + l.total, 0)
  } catch {
    // 搜索失败静默降级（含 403 无权限）：保留表格已有结果，再次输入/翻页即天然重试
  } finally {
    if (seq === requestSeq) dialogLoading.value = false
  }
}
// 查询按钮 1s 节流（ListFilterBar 同口径）：窗口内连点只执行首次，尾调用补发最终意图。
// 执行前先冲刷输入防抖：300ms 窗口内点查询/回车时立即用最新关键字（否则会先按旧关键字发一次，
// 300ms 后再补发正确请求——慢网络下短暂展示旧结果）
const searchThrottled = throttle(() => {
  searchKw.flush()
  void searchDialog()
}, 1000)
watch(searchKw.debounced, () => searchDialog())

// 点击输入框加载第一页：挂载预载之外的手动刷新（options 保留上次搜索结果，避免每次打开空表/闪烁）
function onPopoverShow() {
  void searchDialog()
}

async function changeDialogPage(p: number) {
  const seq = ++requestSeq
  dialogLoading.value = true
  try {
    const kw = searchKw.debounced.value || undefined
    const lists = await Promise.all(
      props.types.map((t) => productApi.list({ page: p, per_page: 10, type: t, keyword: kw })),
    )
    if (seq !== requestSeq) return // 翻页与搜索并发时同理，只认最后一次请求
    mergeResults(lists.flatMap((l) => l.items))
    total.value = lists.reduce((acc, l) => acc + l.total, 0)
  } catch {
    // 翻页失败同搜索失败：静默降级
  } finally {
    if (seq === requestSeq) dialogLoading.value = false
  }
}

onMounted(loadOptions)
// 卸载清理：取消节流尾调用（1s 窗口内离开页面后不得再补发查询触发已卸载组件请求）
onScopeDispose(() => searchThrottled.cancel())
</script>

<template>
  <!-- 下拉模式：选项合计 ≤50，el-select 本地过滤直选（形态由 mode 决定，与搜索回写的分页 total 解耦） -->
  <el-select
    v-if="mode === 'select'"
    class="product-picker"
    :model-value="props.modelValue"
    filterable
    :clearable="clearable"
    :disabled="disabled"
    :placeholder="placeholder"
    :loading="loading"
    style="width: 100%"
    @update:model-value="
      (v: unknown) => {
        if (v == null) return clear()
        const row = options.find((o) => o.id === v)
        // 选中值不在选项集（正常不会出现，pin 已并入）：只回写值，不发虚假 change 行
        if (!row) return emit('update:modelValue', v as number)
        pick(row)
      }
    "
    @clear="clear"
  >
    <el-option
      v-for="p in options"
      :key="p.id"
      :label="p.code ? `${p.name}（${p.code}）` : p.name"
      :value="p.id"
    >
      <div class="opt-line">
        <span class="opt-name">{{ p.name }}</span>
        <el-tag v-if="p.code" size="small" class="opt-code">{{ p.code }}</el-tag>
        <el-tag v-if="p.type_label" size="small" :type="typeTag(p.type)" class="opt-tag">{{
          p.type_label
        }}</el-tag>
      </div>
    </el-option>
  </el-select>
  <!-- 分页弹窗模式：选项合计 >50，点击输入框弹出搜索弹窗。
       teleported=false：内容留在组件 DOM 树内，单测 wrapper.find 可查（与 UserSelect 同裁决），
       且本场景无父级 overflow 裁剪风险 -->
  <el-popover
    v-else
    class="product-picker"
    :width="560"
    trigger="click"
    placement="bottom-start"
    :disabled="disabled"
    :teleported="false"
  >
    <template #reference>
      <!-- 点击输入框即加载第一页：仅绑定 @click（popover show 事件在 jsdom 与真实浏览器
           事件时序不一致，双绑定会重复触发首屏搜索），点击与展开语义一致 -->
      <el-input
        :model-value="selectedLabel"
        readonly
        :clearable="clearable"
        :disabled="disabled"
        :placeholder="placeholder"
        @click="onPopoverShow"
        @clear="clear"
      />
    </template>
    <div class="product-dialog">
      <div class="search-row">
        <el-input
          v-model="searchKw.source.value"
          placeholder="搜索名称/编码"
          clearable
          @keyup.enter="searchThrottled"
        />
        <el-button class="btn-primary" @click="searchThrottled">查 询</el-button>
      </div>
      <el-table
        v-loading="dialogLoading"
        :data="options"
        size="small"
        max-height="300"
        @row-click="(row: ProductOptionRow) => pick(row)"
      >
        <!-- 行展示：名称正文 + 紧跟编号标签（用户指定样式），类型标签尾随 -->
        <el-table-column label="名称" min-width="200">
          <template #default="{ row }">
            <span class="name-text">{{ (row as ProductOptionRow).name }}</span>
            <el-tag v-if="(row as ProductOptionRow).code" size="small" class="code-tag">{{
              (row as ProductOptionRow).code
            }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="类型" width="90">
          <template #default="{ row }">
            <el-tag
              v-if="(row as ProductOptionRow).type_label"
              size="small"
              :type="typeTag((row as ProductOptionRow).type)"
              >{{ (row as ProductOptionRow).type_label }}</el-tag
            >
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
.product-dialog .search-row {
  display: flex;
  gap: var(--space-md);
  margin-bottom: var(--space-md);
}
.product-dialog .search-row .el-input {
  flex: 1;
}
.product-dialog .pager {
  margin-top: var(--space-md);
  display: flex;
  justify-content: flex-end;
}
.btn-primary {
  background: var(--color-accent);
  border-color: var(--color-accent);
  cursor: pointer;
}
/* 行展示：名称正文为主，编号标签紧跟（弹窗与下拉共用视觉） */
.name-text {
  font-weight: 500;
  margin-right: var(--space-sm);
}
.code-tag {
  margin-right: var(--space-sm);
}
.opt-line {
  display: flex;
  align-items: center;
  min-width: 0;
}
.opt-name {
  flex: 1;
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  margin-right: var(--space-sm);
}
.opt-code {
  flex: none;
}
.opt-tag {
  flex: none;
  margin-left: var(--space-sm);
}
</style>
