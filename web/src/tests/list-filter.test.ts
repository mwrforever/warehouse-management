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

  it('作用域销毁后挂起防抖不再发请求（卸载自动作废，BUG-04）', () => {
    const fetchStub = vi.fn(async () => ({ items: [] as unknown[], total: 0 }))
    // 组件卸载本质即停止其 effect scope，用 effectScope.stop() 等价模拟 unmount
    const scope = effectScope()
    const api = scope.run(() =>
      useListQuery({ defaultQuery: { keyword: '' }, fetch: fetchStub, debounceMs: 300 }),
    )!
    api.load()
    scope.stop()
    vi.advanceTimersByTime(500)
    expect(fetchStub).not.toHaveBeenCalled()
  })

  it('作用域销毁后在途成功响应不回写列表（卸载自动作废，BUG-04）', async () => {
    let resolve!: (v: { items: unknown[]; total: number }) => void
    const fetchStub = vi.fn(
      () =>
        new Promise<{ items: unknown[]; total: number }>((r) => {
          resolve = r
        }),
    )
    const scope = effectScope()
    const api = scope.run(() =>
      useListQuery({ defaultQuery: { keyword: '' }, fetch: fetchStub, debounceMs: 300 }),
    )!
    api.search()
    scope.stop()
    resolve({ items: [{ n: '迟到响应' }], total: 99 })
    await flushPromises()
    expect(api.list.value).toEqual([])
  })

  it('作用域销毁后在途失败响应不触发 onError（卸载自动作废，BUG-04）', async () => {
    let reject!: (e: Error) => void
    const fetchStub = vi.fn(
      () =>
        new Promise<{ items: unknown[]; total: number }>((_, rej) => {
          reject = rej
        }),
    )
    const onError = vi.fn()
    const scope = effectScope()
    const api = scope.run(() =>
      useListQuery({
        defaultQuery: { keyword: '' },
        fetch: fetchStub,
        debounceMs: 300,
        onError,
      }),
    )!
    api.search()
    scope.stop()
    reject(new Error('网络错误'))
    await flushPromises()
    // 用户已离开页面，迟到失败不得再触发页面 onError 弹错
    expect(onError).not.toHaveBeenCalled()
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

// ListFilterBar 组件单测：关键字防抖、按钮事件、重置清空关键字
import { mount, flushPromises } from '@vue/test-utils'
import { defineComponent, effectScope, h } from 'vue'
import ElementPlus from 'element-plus'
import ListFilterBar from '../components/ListFilterBar.vue'

describe('ListFilterBar', () => {
  beforeEach(() => vi.useFakeTimers())
  afterEach(() => vi.useRealTimers())

  it('关键字输入 300ms 防抖后发 keyword-change', async () => {
    const wrapper = mount(ListFilterBar, {
      props: { title: '采购订单', keyword: '' },
      global: { plugins: [ElementPlus] },
    })
    const input = wrapper.find('input')
    await input.setValue('abc')
    expect(wrapper.emitted('keyword-change')).toBeUndefined()
    vi.advanceTimersByTime(300)
    await flushPromises()
    const emitted = wrapper.emitted('keyword-change')
    expect(emitted).toBeTruthy()
    expect(emitted![emitted!.length - 1]).toEqual(['abc'])
    expect(wrapper.emitted('update:keyword')!.at(-1)).toEqual(['abc'])
  })

  it('无 keyword prop 时不渲染关键字输入框', () => {
    const wrapper = mount(ListFilterBar, {
      props: { title: '单位管理' },
      global: { plugins: [ElementPlus] },
    })
    expect(wrapper.find('input').exists()).toBe(false)
  })

  it('查询按钮 1s 节流：连点立即执行首次，窗口结束补一次尾调用（throttle 工具语义）', async () => {
    const wrapper = mount(ListFilterBar, {
      props: { title: '采购订单', keyword: '' },
      global: { plugins: [ElementPlus] },
    })
    const btns = wrapper.findAll('button')
    const queryBtn = btns.find((b) => b.text().includes('查'))
    await queryBtn!.trigger('click')
    await queryBtn!.trigger('click')
    // 窗口内连点只立即执行首次，防连点不发冗余请求
    expect(wrapper.emitted('search')).toHaveLength(1)
    vi.advanceTimersByTime(1000)
    // 被吞的最后一次点击在窗口结束补一次尾调用（连点最终意图不丢失，原手写节流直接吞掉的语义差异在此固化）
    expect(wrapper.emitted('search')).toHaveLength(2)
  })

  it('卸载后节流尾调用不再补发 search（卸载清理）', async () => {
    // unmount 后 wrapper.emitted 不可读，用显式事件回调捕获
    const onSearch = vi.fn()
    const wrapper = mount(ListFilterBar, {
      props: { title: '采购订单', keyword: '', onSearch },
      global: { plugins: [ElementPlus] },
    })
    const btns = wrapper.findAll('button')
    const queryBtn = btns.find((b) => b.text().includes('查'))
    await queryBtn!.trigger('click') // 立即执行
    await queryBtn!.trigger('click') // 窗口内被吞，排定尾调用
    wrapper.unmount()
    vi.advanceTimersByTime(1000)
    // 离开页面后不得补发 search 触发已卸载页面的查询
    expect(onSearch).toHaveBeenCalledTimes(1)
  })

  it('重置按钮清空关键字并发 reset', async () => {
    const wrapper = mount(ListFilterBar, {
      props: { title: '采购订单', keyword: 'abc' },
      global: { plugins: [ElementPlus] },
    })
    const btns = wrapper.findAll('button')
    const resetBtn = btns.find((b) => b.text().includes('重'))
    await resetBtn!.trigger('click')
    expect(wrapper.emitted('reset')).toHaveLength(1)
    expect(wrapper.emitted('update:keyword')!.at(-1)).toEqual([''])
  })

  it('刷新按钮发 refresh', async () => {
    const wrapper = mount(ListFilterBar, {
      props: { title: '采购订单', keyword: '' },
      global: { plugins: [ElementPlus] },
    })
    const btns = wrapper.findAll('button')
    const refreshBtn = btns.find((b) => b.text().includes('刷'))
    await refreshBtn!.trigger('click')
    expect(wrapper.emitted('refresh')).toHaveLength(1)
  })

  it('300ms 内双击重置后重输同一关键字仍触发 keyword-change（BUG-06 防抖滞留）', async () => {
    const wrapper = mount(ListFilterBar, {
      props: { title: '采购订单', keyword: '' },
      global: { plugins: [ElementPlus] },
    })
    const input = wrapper.find('input')
    await input.setValue('abc')
    vi.advanceTimersByTime(300)
    await flushPromises()
    const btns = wrapper.findAll('button')
    const resetBtn = btns.find((b) => b.text().includes('重'))
    // 第一次重置排定 300ms 同步计时器，第二次重置取消该计时器——
    // 若未同步 debounced，其将永久滞留 'abc'，重输同词时同值赋值不触发 watch（静默失效）
    await resetBtn!.trigger('click')
    await resetBtn!.trigger('click')
    await flushPromises()
    const countBefore = wrapper.emitted('keyword-change')?.length ?? 0
    await input.setValue('abc')
    vi.advanceTimersByTime(300)
    await flushPromises()
    const emitted = wrapper.emitted('keyword-change')
    // 重输与滞留值相同的关键字必须重新触发查询通知
    expect(emitted!.length).toBeGreaterThan(countBefore)
    expect(emitted!.at(-1)).toEqual(['abc'])
  })

  it('单击重置后 300ms 内重输相同关键字仍触发 keyword-change（BUG-06 单击变体）', async () => {
    const wrapper = mount(ListFilterBar, {
      props: { title: '采购订单', keyword: '' },
      global: { plugins: [ElementPlus] },
    })
    const input = wrapper.find('input')
    await input.setValue('abc')
    vi.advanceTimersByTime(300)
    await flushPromises()
    const btns = wrapper.findAll('button')
    const resetBtn = btns.find((b) => b.text().includes('重'))
    // 单击重置后立即（300ms 防抖窗口内）重输同一词：若重置时未把 debounced
    // 同步为空串，窗口到期赋回 'abc' 与滞留值相同，同值赋值不触发 watch（静默失效）
    await resetBtn!.trigger('click')
    await flushPromises()
    const countBefore = wrapper.emitted('keyword-change')?.length ?? 0
    await input.setValue('abc')
    vi.advanceTimersByTime(300)
    await flushPromises()
    const emitted = wrapper.emitted('keyword-change')
    expect(emitted!.length).toBeGreaterThan(countBefore)
    expect(emitted!.at(-1)).toEqual(['abc'])
  })

  it('输入后 300ms 内回车：立即用新关键字查询且 600ms 内不重复请求（BUG-07）', async () => {
    // 按真实页面接线组装宿主：v-model:keyword + keyword-change 触发防抖 load + search 立即查询
    const fetchStub = vi.fn(async (q: { keyword: string; page: number; per_page: number }) => ({
      items: [{ page: q.page }],
      total: 0,
    }))
    const wrapper = mount(
      defineComponent({
        setup() {
          const { query, load, search } = useListQuery({
            defaultQuery: { keyword: '' },
            fetch: fetchStub,
            debounceMs: 300,
          })
          return () =>
            h(ListFilterBar, {
              keyword: query.keyword,
              'onUpdate:keyword': (v: string) => {
                query.keyword = v
              },
              onKeywordChange: () => load(),
              onSearch: () => search(),
            })
        },
      }),
      { global: { plugins: [ElementPlus] } },
    )
    const input = wrapper.find('input')
    await input.setValue('新词')
    // 防抖窗口内立即回车：查询必须立即用最新关键字（修复前先按旧空关键字请求）
    await input.trigger('keyup', { key: 'Enter' })
    await flushPromises()
    expect(fetchStub).toHaveBeenCalledTimes(1)
    expect(fetchStub).toHaveBeenCalledWith(expect.objectContaining({ keyword: '新词' }))
    // 组件内 300ms 防抖到期 + 父级 300ms 防抖串联的 600ms 内不得再发重复请求
    vi.advanceTimersByTime(600)
    await flushPromises()
    expect(fetchStub).toHaveBeenCalledTimes(1)
  })
})
