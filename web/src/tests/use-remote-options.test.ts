// useRemoteOptions 单测（BF-3）：初载填充、防抖关键字搜索、已选/回显项 pin 保留、慢响应乱序丢弃
// 背景：大数据量下拉（商品/单据）原一次性 per_page=100 预拉 + el-select 本地过滤，
// 第 101 条起无法被选中（后端 per_page 硬钳制 100）；本组合式函数支撑 remote 服务端搜索模式
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { useRemoteOptions } from '../composables/useRemoteOptions'

interface Item {
  id: number
  name: string
}

describe('useRemoteOptions（远程搜索下拉数据源，BF-3）', () => {
  beforeEach(() => {
    vi.useFakeTimers()
  })
  afterEach(() => vi.useRealTimers())

  it('初载 load：以空关键字请求并填充选项（保留前 N 条作初始选项）', async () => {
    // 正常路径：弹窗/页面打开即初载，空关键字 = 接口默认排序前 N 条
    const fetcher = vi.fn().mockResolvedValue([{ id: 1, name: '铝材' }])
    const { options, load } = useRemoteOptions<Item>({ fetch: fetcher, keyOf: (i) => i.id })
    expect(options.value).toEqual([])
    await load()
    expect(fetcher).toHaveBeenCalledWith('')
    expect(options.value).toEqual([{ id: 1, name: '铝材' }])
  })

  it('输入关键字 300ms 防抖后以 keyword 请求并替换选项（逐字符输入只发最后一次）', async () => {
    // 正常路径 + 边界：防抖窗口内连续输入合并为最后一次请求
    const fetcher = vi.fn().mockResolvedValue([{ id: 9, name: '铝材-9' }])
    const { options, search } = useRemoteOptions<Item>({ fetch: fetcher, keyOf: (i) => i.id })
    search('铝')
    search('铝材')
    expect(fetcher, '防抖窗口内不得发请求').not.toHaveBeenCalled()
    await vi.advanceTimersByTimeAsync(300)
    expect(fetcher).toHaveBeenCalledTimes(1)
    expect(fetcher).toHaveBeenCalledWith('铝材')
    expect(options.value).toEqual([{ id: 9, name: '铝材-9' }])
  })

  it('pin 已选/回显项在搜索替换选项后仍保留在下拉中（回显保障：显示名称而非裸 id）', async () => {
    // 正常路径：编辑回填的商品/单据可能不在初载前 100 条内，pin 并入选项保证 el-select 显示 label
    const fetcher = vi.fn().mockResolvedValue([{ id: 2, name: '钢材' }])
    const { options, search, pin } = useRemoteOptions<Item>({ fetch: fetcher, keyOf: (i) => i.id })
    pin({ id: 101, name: '第101号商品' })
    await vi.advanceTimersByTimeAsync(300)
    // 搜索结果不含 id=101，但 pin 项仍并入选项尾部
    expect(options.value).toEqual([
      { id: 2, name: '钢材' },
      { id: 101, name: '第101号商品' },
    ])
    // 再次搜索命中 pin 项时不重复出现（按 key 去重）
    fetcher.mockResolvedValue([{ id: 101, name: '第101号商品' }])
    search('101')
    await vi.advanceTimersByTimeAsync(300)
    expect(options.value).toEqual([{ id: 101, name: '第101号商品' }])
  })

  it('慢响应乱序丢弃：旧关键字响应晚到不覆盖新结果（请求序号守卫）', async () => {
    // 异常路径：先搜「铝」（慢），再搜「钢」（快）；「铝」的迟到响应不得覆盖「钢」的结果
    const slow = new Promise<Item[]>((resolve) =>
      setTimeout(() => resolve([{ id: 1, name: '铝材' }]), 1000),
    )
    const fast = Promise.resolve<Item[]>([{ id: 2, name: '钢材' }])
    const fetcher = vi.fn().mockImplementation((kw: string) => (kw === '铝' ? slow : fast))
    const { options, search } = useRemoteOptions<Item>({ fetch: fetcher, keyOf: (i) => i.id })
    search('铝')
    await vi.advanceTimersByTimeAsync(300)
    search('钢')
    await vi.advanceTimersByTimeAsync(300)
    expect(options.value).toEqual([{ id: 2, name: '钢材' }])
    // 「铝」的慢响应到达：序号已过期，丢弃
    await vi.advanceTimersByTimeAsync(700)
    expect(options.value, '迟到的旧响应不得覆盖新结果').toEqual([{ id: 2, name: '钢材' }])
  })

  it('搜索失败保持现列表且 loading 复位（下拉不空白中断使用）', async () => {
    // 异常路径：接口失败时保留已加载选项（错误提示由调用方 onError / 全局 http 处理）
    // vitest 4 的 vi.fn 泛型为单一函数类型（旧版 Return/[Args] 双参形式已废弃）
    const fetcher = vi
      .fn<(kw: string) => Promise<Item[]>>()
      .mockResolvedValueOnce([{ id: 1, name: '铝材' }])
      .mockRejectedValueOnce(new Error('接口不可用'))
    const { options, loading, search } = useRemoteOptions<Item>({
      fetch: fetcher,
      keyOf: (i) => i.id,
    })
    search('')
    await vi.advanceTimersByTimeAsync(300)
    expect(options.value).toEqual([{ id: 1, name: '铝材' }])
    search('钢材')
    await vi.advanceTimersByTimeAsync(300)
    expect(options.value, '失败时保持原选项').toEqual([{ id: 1, name: '铝材' }])
    expect(loading.value).toBe(false)
  })

  it('同关键字二次请求命中会话缓存：不发重复请求且选项立即可用（下拉闪烁修复）', async () => {
    // 正常路径：remote 下拉每次打开会再次触发空关键字拉取，缓存命中直接返回既有选项
    const fetcher = vi.fn().mockResolvedValue([{ id: 1, name: '铝材' }])
    const { options, loading, load, search } = useRemoteOptions<Item>({
      fetch: fetcher,
      keyOf: (i) => i.id,
    })
    await load()
    expect(fetcher).toHaveBeenCalledTimes(1)
    expect(options.value).toEqual([{ id: 1, name: '铝材' }])

    // 再次空关键字加载（模拟下拉打开）：命中缓存，不重复请求、不置 loading、选项立即就位
    await load()
    expect(fetcher).toHaveBeenCalledTimes(1)
    expect(loading.value).toBe(false)
    expect(options.value).toEqual([{ id: 1, name: '铝材' }])

    // 新关键字未缓存：正常请求并写入缓存
    fetcher.mockResolvedValue([{ id: 2, name: '钢材' }])
    search('钢')
    await vi.advanceTimersByTimeAsync(300)
    expect(fetcher).toHaveBeenCalledTimes(2)
    expect(options.value).toEqual([{ id: 2, name: '钢材' }])
  })

  it('clearPins 清回显项但保留选项与缓存（弹窗关闭防串单且重开不闪）', async () => {
    // 正常路径：编辑弹窗关闭时只清 pin 集，通用选项列表与关键字缓存保留，重开直接复用
    const fetcher = vi.fn().mockResolvedValue([{ id: 1, name: '铝材' }])
    const { options, load, pin, clearPins } = useRemoteOptions<Item>({
      fetch: fetcher,
      keyOf: (i) => i.id,
    })
    await load()
    pin({ id: 101, name: '回显商品' })
    expect(options.value).toHaveLength(2)

    clearPins()
    expect(options.value).toEqual([{ id: 1, name: '铝材' }])
    // 缓存未清：load 命中缓存不发请求
    await load()
    expect(fetcher).toHaveBeenCalledTimes(1)
  })

  it('reset 清空选项与缓存（会话隔离，重新进入重新拉取保持数据新鲜）', async () => {
    // 边界条件：reset 后选项与缓存一并清空，再 load 重新请求
    const fetcher = vi.fn().mockResolvedValue([{ id: 1, name: '铝材' }])
    const { options, load, reset } = useRemoteOptions<Item>({ fetch: fetcher, keyOf: (i) => i.id })
    await load()
    reset()
    expect(options.value).toEqual([])
    await load()
    expect(fetcher).toHaveBeenCalledTimes(2)
  })
})
