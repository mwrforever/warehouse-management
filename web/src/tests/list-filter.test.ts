// useListQuery 单测：防抖加载/重置分页/恢复默认/保持页码/卸载清理/并发以最后一次为准
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { useListQuery } from '../composables/useListQuery'

const fetchMock = vi.fn()
function makeFetch() {
  fetchMock.mockImplementation(async (q: { page: number }) => ({
    items: [{ page: q.page }],
    total: 10,
  }))
  return fetchMock
}

describe('useListQuery', () => {
  // 共享模块级 fetchMock，须在用例间清空调用历史，避免跨用例累计（与 repo 既有 api 测试的 beforeEach 模式一致）
  beforeEach(() => {
    vi.clearAllMocks()
    vi.useFakeTimers()
  })
  afterEach(() => vi.useRealTimers())

  it('初始 search 立即请求且 page=1', async () => {
    const fetch = makeFetch()
    const { query, search } = useListQuery({
      defaultQuery: { keyword: '', status: undefined },
      fetch: fetch as never,
      debounceMs: 300,
    })
    expect(query.page).toBe(1)
    search()
    expect(fetch).toHaveBeenCalledTimes(1)
    expect(fetch).toHaveBeenCalledWith(expect.objectContaining({ page: 1 }))
  })

  it('防抖期内连续 load 只发一次请求', async () => {
    const fetch = makeFetch()
    const { load, search } = useListQuery({
      defaultQuery: { keyword: '', status: undefined },
      fetch: fetch as never,
      debounceMs: 300,
    })
    search() // 立即一次
    load() // 防抖 1
    load() // 防抖 2（合并）
    vi.advanceTimersByTime(300)
    await vi.waitFor(() => expect(fetch).toHaveBeenCalledTimes(2))
  })

  it('load 重置 page=1（筛选变更回首页）', async () => {
    const fetch = makeFetch()
    const { query, load, search } = useListQuery({
      defaultQuery: { keyword: '', status: undefined },
      fetch: fetch as never,
      debounceMs: 300,
    })
    search()
    query.page = 3
    load()
    vi.advanceTimersByTime(300)
    await vi.waitFor(() =>
      expect(fetch).toHaveBeenLastCalledWith(expect.objectContaining({ page: 1 })),
    )
  })

  it('reset 恢复默认筛选并回首页', async () => {
    const fetch = makeFetch()
    const { query, search, reset } = useListQuery({
      defaultQuery: { keyword: '', status: 1 },
      fetch: fetch as never,
      debounceMs: 300,
    })
    search()
    query.keyword = 'abc'
    query.status = 2
    query.page = 4
    reset()
    expect(query.keyword).toBe('')
    expect(query.status).toBe(1)
    expect(query.page).toBe(1)
    await vi.waitFor(() =>
      expect(fetch).toHaveBeenLastCalledWith(
        expect.objectContaining({ keyword: '', status: 1, page: 1 }),
      ),
    )
  })

  it('refresh 保持当前页码重载', async () => {
    const fetch = makeFetch()
    const { query, search, refresh } = useListQuery({
      defaultQuery: { keyword: '' },
      fetch: fetch as never,
      debounceMs: 300,
    })
    search()
    query.page = 3
    refresh()
    await vi.waitFor(() =>
      expect(fetch).toHaveBeenLastCalledWith(expect.objectContaining({ page: 3 })),
    )
  })

  it('cancel 后防抖不再触发请求（卸载清理）', () => {
    const fetch = makeFetch()
    const { load, cancel } = useListQuery({
      defaultQuery: { keyword: '' },
      fetch: fetch as never,
      debounceMs: 300,
    })
    load()
    cancel()
    vi.advanceTimersByTime(500)
    expect(fetch).not.toHaveBeenCalled()
  })

  it('并发响应以最后一次为准：过期响应不覆盖新结果（bug #4 守卫）', async () => {
    let resolveOld!: (v: { items: unknown[]; total: number }) => void
    const old = new Promise((resolve) => {
      resolveOld = resolve as (v: { items: unknown[]; total: number }) => void
    })
    fetchMock
      .mockImplementationOnce(() => old)
      .mockImplementationOnce(async () => ({ items: [{ n: 'new' }], total: 1 }))
    const { search, list } = useListQuery({
      defaultQuery: { keyword: '' },
      fetch: fetchMock as never,
      debounceMs: 300,
    })
    search()
    search()
    await vi.waitFor(() => expect(list.value).toEqual([{ n: 'new' }]))
    resolveOld({ items: [{ n: 'old' }], total: 99 })
    await vi.waitFor(() => expect(list.value).toEqual([{ n: 'new' }])) // 旧响应被丢弃
  })
})
