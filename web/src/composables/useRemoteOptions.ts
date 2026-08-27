// 远程搜索下拉数据源组合式函数（BF-3）：大数据量下拉（商品等超 100 条档案）改为 el-select remote
// 服务端搜索模式——初载以空关键字取前 N 条，输入关键字防抖 300ms 后按 keyword 拉取并替换选项，
// 解决原"一次性 per_page=100 预拉 + 本地过滤"第 101 条起不可选的缺陷（后端 per_page 硬钳制 100）。
// 请求序号守卫（与 useListQuery bug #4 同模式）保证慢响应乱序到达时不覆盖新结果；
// pin 并入编辑回显项（可能不在前 100 条内），保证 el-select 显示名称而非裸 id。
import { getCurrentScope, onScopeDispose, ref, type Ref } from 'vue'
import { debounce } from '../utils/async'

export interface UseRemoteOptionsOptions<T> {
  /** 拉取选项：kw 为空串表示初载（接口默认排序前 N 条），非空为关键字搜索 */
  fetch: (kw: string) => Promise<T[]>
  /** 选项唯一键（如商品 id）：pin 去重与搜索结果合并的依据 */
  keyOf: (item: T) => string | number
  /** 关键字输入防抖间隔（默认 300ms，窗口内连击只发最后一次） */
  debounceMs?: number
  /** 请求失败回调（页面统一 ElMessage.error 展示；失败时保持现列表不中断使用） */
  onError?: (e: Error) => void
}

export function useRemoteOptions<T>(opts: UseRemoteOptionsOptions<T>) {
  /** 下拉选项集：初载/搜索整体替换，pin 项始终并入尾部 */
  const options = ref<T[]>([]) as Ref<T[]>
  const loading = ref(false)
  // 请求序号守卫：每次发请求递增；响应落地前发现序号已变（有更新请求）则丢弃，防旧响应覆盖新结果
  let requestSeq = 0
  // pin 集（已选/回显项）：搜索替换选项后仍未命中的 pin 项并入尾部，命中同 key 的按 key 去重
  const pinned = new Map<string | number, T>()
  // 会话级关键字结果缓存（下拉闪烁修复）：同 (kw) 已加载的结果直接复用不发重复请求。
  // remote 下拉每次打开会再次触发空关键字拉取，选项整体替换造成"闪一下"；缓存命中时
  // 立即用既有选项返回（无 loading 无替换）。reset 清空保持会话隔离，新鲜数据靠调用方
  // 在会话边界（弹窗关闭/页面卸载）调用 reset 重新拉取
  const cache = new Map<string, T[]>()
  // 最近一次生效的关键字：clearPins 按它重建纯结果（移除 pin 并入项）
  let lastKw = ''

  /** 执行拉取并替换选项：结果在前、未命中的 pin 项并入尾部（按 key 去重不重复出现） */
  async function run(kw: string) {
    lastKw = kw
    const cached = cache.get(kw)
    if (cached) {
      // 缓存命中：直接以缓存结果 + pin 尾部填充，不发请求、不置 loading（消除重复拉取闪烁）
      const seen = new Set(cached.map(opts.keyOf))
      const tail = [...pinned.values()].filter((p) => !seen.has(opts.keyOf(p)))
      options.value = [...cached, ...tail]
      return
    }
    const seq = ++requestSeq
    loading.value = true
    try {
      const items = await opts.fetch(kw)
      if (seq !== requestSeq) return // 已有更新的请求，丢弃本次过期响应
      cache.set(kw, items)
      const seen = new Set(items.map(opts.keyOf))
      const tail = [...pinned.values()].filter((p) => !seen.has(opts.keyOf(p)))
      options.value = [...items, ...tail]
    } catch (e) {
      if (seq !== requestSeq) return
      // 失败保持现列表：下拉不空白中断使用，错误提示交调用方 onError / 全局 http 处理
      opts.onError?.(e instanceof Error ? e : new Error(String(e)))
    } finally {
      if (seq === requestSeq) loading.value = false
    }
  }

  // 防抖搜索：窗口内连击只发最后一次（kw 经 debounce 约束推断为 unknown，按契约收窄为 string）
  const search = debounce((kw = '') => run(kw as string), opts.debounceMs ?? 300)

  /** 初载：取消挂起的防抖搜索，立即以空关键字拉取并整体填充选项（弹窗打开取前 N 条） */
  function load() {
    search.cancel()
    return run('')
  }

  /** 并入已选/回显项：不在当前选项集时追加到尾部并安排一次初载刷新（防抖合并，回显保障显示名称）；已存在则仅登记 pin 供后续去重 */
  function pin(item: T) {
    const key = opts.keyOf(item)
    pinned.set(key, item)
    if (options.value.some((o) => opts.keyOf(o) === key)) return
    options.value = [...options.value, item]
    search('')
  }

  /** 清 pin 并作废在途（弹窗关闭时调用）：移除上一会话回显项防串单，按最近关键字重建纯结果，保留选项与缓存避免重开闪烁 */
  function clearPins() {
    search.cancel()
    requestSeq++
    pinned.clear()
    const base = cache.get(lastKw)
    if (base) options.value = [...base]
  }

  /** 会话重置：清空选项、pin 集与结果缓存、取消挂起防抖并作废在途响应（页面卸载/重进时调用，保持数据新鲜） */
  function reset() {
    search.cancel()
    requestSeq++
    pinned.clear()
    cache.clear()
    options.value = []
  }

  // 卸载清理：取消挂起防抖并作废在途响应，防止卸载后回写已卸载组件的 refs
  // （getCurrentScope 守卫让纯单测等无作用域上下文跳过注册而不产生 Vue 警告，与 useListQuery 同惯例）
  if (getCurrentScope()) onScopeDispose(reset)

  return { options, loading, load, search, pin, clearPins, reset }
}
