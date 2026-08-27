// 工具函数单测：debounce/throttle/useDebouncedRef 的行为语义（尾调用防抖/首调用节流/取消清理/防抖 ref 延迟更新）
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { nextTick, watch } from 'vue'
import { debounce, throttle, useDebouncedRef } from '../utils/async'

describe('debounce', () => {
  beforeEach(() => vi.useFakeTimers())
  afterEach(() => vi.useRealTimers())

  it('尾调用防抖：连续触发只在停止后执行一次', () => {
    const fn = vi.fn()
    const d = debounce(fn, 100)
    d()
    d()
    d()
    expect(fn).not.toHaveBeenCalled()
    vi.advanceTimersByTime(100)
    expect(fn).toHaveBeenCalledTimes(1)
  })

  it('immediate=true 时首调立即执行，随后等待期内的调用被合并', () => {
    const fn = vi.fn()
    const d = debounce(fn, 100, true)
    d()
    expect(fn).toHaveBeenCalledTimes(1)
    d()
    vi.advanceTimersByTime(100)
    // 等待期结束后的下一次调用立即执行
    d()
    expect(fn).toHaveBeenCalledTimes(2)
  })

  it('cancel 清理：取消后不再执行', () => {
    const fn = vi.fn()
    const d = debounce(fn, 100)
    d()
    d.cancel()
    vi.advanceTimersByTime(200)
    expect(fn).not.toHaveBeenCalled()
  })
})

describe('throttle', () => {
  beforeEach(() => vi.useFakeTimers())
  afterEach(() => vi.useRealTimers())

  it('首调用节流：窗口期内只执行首次，尾部补一次尾调用', () => {
    const fn = vi.fn()
    const t = throttle(fn, 100)
    t()
    t()
    t()
    expect(fn).toHaveBeenCalledTimes(1)
    vi.advanceTimersByTime(100)
    expect(fn).toHaveBeenCalledTimes(2) // 尾调用执行
  })
})

describe('useDebouncedRef', () => {
  beforeEach(() => vi.useFakeTimers())
  afterEach(() => vi.useRealTimers())

  it('source 立即更新，debounced 延迟 300ms 后同步', async () => {
    const { source, debounced } = useDebouncedRef('a', 300)
    expect(source.value).toBe('a')
    expect(debounced.value).toBe('a')
    source.value = 'b'
    expect(debounced.value).toBe('a')
    vi.advanceTimersByTime(300)
    await nextTick()
    expect(debounced.value).toBe('b')
  })

  it('cancel 清理：取消后 debounced 不再更新', async () => {
    const { source, debounced, cancel } = useDebouncedRef('a', 300)
    source.value = 'b'
    cancel()
    vi.advanceTimersByTime(500)
    await nextTick()
    expect(debounced.value).toBe('a')
  })

  it('flush 立即同步 debounced=source 并取消挂起防抖', async () => {
    const { source, debounced, flush } = useDebouncedRef('a', 300)
    source.value = 'b'
    source.value = 'c'
    expect(debounced.value).toBe('a')
    flush()
    // 无需等待防抖窗口，立即拿到最新输入（回车查询/重置等场景要求）
    expect(debounced.value).toBe('c')
    vi.advanceTimersByTime(500)
    await nextTick()
    // 挂起的防抖计时器已被取消，不再产生重复变化
    expect(debounced.value).toBe('c')
  })

  it('flush 幂等：值未变化时不产生重复通知', async () => {
    const { source, debounced, flush } = useDebouncedRef('a', 300)
    const seen: string[] = []
    watch(debounced, (v) => seen.push(v))
    source.value = 'b'
    source.value = 'c'
    flush()
    await nextTick()
    // 窗口内连续输入合并为一次最新值通知
    expect(seen).toEqual(['c'])
    vi.advanceTimersByTime(500)
    await nextTick()
    // 挂起计时器已取消，不重复通知
    expect(seen).toEqual(['c'])
  })
})
