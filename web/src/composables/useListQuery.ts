// 列表查询状态组合式函数：统一 query/list/total/loading + 防抖加载 + 查询/重置/刷新。
// 消除 6+ 列表页手写 loadList/search/load 样板；请求序号守卫（bug #4 模式）保证并发响应以最后一次为准。
import { reactive, ref } from 'vue'
import { debounce } from '../utils/async'

export interface ListPage<T> {
  items: T[]
  total: number
}

export interface UseListQueryOptions<T extends Record<string, unknown>> {
  /** 默认筛选值（重置按钮恢复的目标；不含 page/per_page） */
  defaultQuery: T
  /** 列表请求：返回统一分页结构 { items, total }；query 已含 page/per_page */
  fetch: (q: T & { page: number; per_page: number }) => Promise<ListPage<unknown>>
  /** 筛选变更触发的防抖间隔（关键字输入/下拉变更共用） */
  debounceMs?: number
  /** 请求失败回调（页面统一 ElMessage.error 展示后端 message） */
  onError?: (e: Error) => void
}

export function useListQuery<T extends Record<string, unknown>>(opts: UseListQueryOptions<T>) {
  const query = reactive({
    ...opts.defaultQuery,
    page: 1,
    per_page: 10,
  }) as T & { page: number; per_page: number }

  const list = ref<unknown[]>([])
  const total = ref(0)
  const loading = ref(false)
  let requestSeq = 0

  async function run(keepPage: boolean) {
    if (!keepPage) query.page = 1
    const seq = ++requestSeq
    loading.value = true
    try {
      const res = await opts.fetch({ ...query })
      if (seq !== requestSeq) return // 已有更新的请求，丢弃本次过期响应
      list.value = res.items
      total.value = res.total
    } catch (e) {
      if (seq !== requestSeq) return
      opts.onError?.(e instanceof Error ? e : new Error(String(e)))
    } finally {
      if (seq === requestSeq) loading.value = false
    }
  }

  // 防抖加载：关键字输入/筛选变更触发（自动回首页）；keepPage=true 供分页跳页（同步场景用 refresh 替代）
  // keepPage 经 debounce 约束推断为 unknown，此处按契约收窄为 boolean
  const load = debounce((keepPage = false) => run(keepPage as boolean), opts.debounceMs ?? 300)

  /** 查询：立即执行 + 回首页（取消挂起的防抖，以本次为准） */
  function search() {
    load.cancel()
    void run(false)
  }

  /** 重置：恢复默认筛选 + 回首页 + 立即查询 */
  function reset() {
    load.cancel()
    // 仅回写默认筛选字段，page/per_page 保持不变（回首页由 run(false) 负责）
    const target = query as T
    for (const key of Object.keys(opts.defaultQuery) as (keyof T)[]) {
      target[key] = opts.defaultQuery[key]
    }
    void run(false)
  }

  /** 刷新：按当前筛选重载当前页（不重置页码，配合分页 current-change 与刷新按钮） */
  function refresh() {
    load.cancel()
    void run(true)
  }

  /** 卸载清理：取消挂起防抖并作废在途响应，防止卸载后 setState */
  function cancel() {
    load.cancel()
    requestSeq++
  }

  return { query, list, total, loading, load, search, reset, refresh, cancel }
}
