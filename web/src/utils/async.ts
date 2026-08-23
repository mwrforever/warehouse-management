// 异步工具：防抖/节流/防抖 ref（不引 lodash/vueuse，手写约 30 行；全项目实时搜索/按钮防连点复用）
import { ref, watch, type Ref } from 'vue'

// 尾调用防抖：连续调用在停止触发 ms 后执行最后一次；immediate=true 时首调立即执行、
// 等待期内后续调用合并（触发节奏的"开关"，供搜索框回车等场景）。
// cancel() 供组件卸载时清理，防止卸载后 setState（spec §7 边界场景）。
export function debounce<T extends (...args: unknown[]) => unknown>(
  fn: T,
  ms = 300,
  immediate = false,
): T & { cancel(): void } {
  let timer: ReturnType<typeof setTimeout> | null = null
  let lastArgs: unknown[] = []
  const debounced = (...args: unknown[]) => {
    lastArgs = args
    if (immediate) {
      // immediate 模式：首调立即执行，随后 ms 作为冷却窗口，窗口内调用全部吞掉（不调度尾调用），
      // 与测试"首调立即执行、等待期内调用合并"语义一致（pre-flight Finding A 裁决：改实现）
      if (timer === null) {
        fn(...args)
        timer = setTimeout(() => {
          timer = null
        }, ms)
      }
      return
    }
    if (timer !== null) clearTimeout(timer)
    timer = setTimeout(() => {
      timer = null
      fn(...lastArgs)
    }, ms)
  }
  debounced.cancel = () => {
    if (timer !== null) clearTimeout(timer)
    timer = null
  }
  return debounced as T & { cancel(): void }
}

// 首调用节流：窗口期 ms 内只执行首次（leading），尾部若有被吞的调用补一次尾调用；
// 供「查询」按钮防连点与滚动加载复用。
export function throttle<T extends (...args: unknown[]) => unknown>(
  fn: T,
  ms = 300,
): T & { cancel(): void } {
  let last = 0
  let timer: ReturnType<typeof setTimeout> | null = null
  let lastArgs: unknown[] = []
  const throttled = (...args: unknown[]) => {
    lastArgs = args
    const now = Date.now()
    const remain = ms - (now - last)
    if (remain <= 0) {
      if (timer !== null) clearTimeout(timer)
      timer = null
      last = now
      fn(...args)
    } else if (timer === null) {
      // 窗口内仅保留一次尾调用，避免拖尾重复执行
      timer = setTimeout(() => {
        timer = null
        last = Date.now()
        fn(...lastArgs)
      }, remain)
    }
  }
  throttled.cancel = () => {
    if (timer !== null) clearTimeout(timer)
    timer = null
  }
  return throttled as T & { cancel(): void }
}

// 防抖 ref：source 立即响应输入（v-model），debounced 在停止输入 ms 后同步，
// 供 ListFilterBar 关键字实时搜索与 UserSelect 搜索框复用（spec §4.1）。
export function useDebouncedRef<T>(initial: T, ms = 300) {
  const source = ref(initial) as Ref<T>
  const debounced = ref(initial) as Ref<T>
  let timer: ReturnType<typeof setTimeout> | null = null
  watch(
    source,
    (v) => {
      if (timer !== null) clearTimeout(timer)
      timer = setTimeout(() => {
        timer = null
        debounced.value = v
      }, ms)
    },
    { flush: 'sync' },
  )
  const cancel = () => {
    if (timer !== null) clearTimeout(timer)
    timer = null
  }
  // 立即冲刷：取消挂起的防抖计时器并把 debounced 同步为 source 当前值。
  // 供「查询/重置」等需立即以最新输入执行的动作调用，消除等待窗口内的旧值滞留
  // （双击重置吞掉计时器导致 debounced 永久滞留、回车先用旧关键字查询两类缺陷的同根修复）。
  // 同值赋值不触发下游 watch，天然幂等。
  const flush = () => {
    cancel()
    debounced.value = source.value
  }
  return { source, debounced, cancel, flush }
}
