// 浏览器能力检测（Z-1 兼容降级）：判定当前浏览器是否满足工艺画布（Vue Flow）的运行基线。
// 声明基线：Chrome/Edge ≥ 100（2022 年起主流版本），配套 CSS :has/广色域/现代 flex。
// Vue Flow 挂载时直接实例化 ResizeObserver 与 DOMMatrixReadOnly（缺失即抛错导致白屏），
// 拖拽连线/节点点选依赖 Pointer Events——三者任一缺失，画布编辑不可用，需降级提示而非静默失败。

/** 单项能力缺失说明：key 供排查定位，message 为用户可读中文文案 */
export interface CanvasSupportIssue {
  key: 'pointerEvent' | 'resizeObserver' | 'domMatrix'
  message: string
}

/** 能力检测所需的浏览器全局形状（字段可选，便于单测注入假全局对象覆盖正/反路径，不触碰真实 window） */
interface BrowserGlobals {
  PointerEvent?: unknown
  ResizeObserver?: unknown
  DOMMatrixReadOnly?: unknown
}

/** 检测条目表：逐项核对基线能力，能力为构造函数即视为可用 */
const CANVAS_CHECKS: ReadonlyArray<{
  key: CanvasSupportIssue['key']
  message: string
  /** 能力是否可用（typeof 判定：构造函数存在即可用） */
  present: (g: BrowserGlobals) => boolean
}> = [
  {
    key: 'pointerEvent',
    message: '缺少 Pointer Events 支持，画布无法响应拖拽连线与节点点选',
    present: (g) => typeof g.PointerEvent === 'function',
  },
  {
    key: 'resizeObserver',
    message: '缺少 ResizeObserver 支持，画布尺寸无法计算（Vue Flow 挂载即报错，可能白屏）',
    present: (g) => typeof g.ResizeObserver === 'function',
  },
  {
    key: 'domMatrix',
    message: '缺少 DOMMatrixReadOnly 支持，画布缩放/平移计算不可用',
    present: (g) => typeof g.DOMMatrixReadOnly === 'function',
  },
]

/** 纯检测函数：给定浏览器全局对象，返回缺失能力清单（单测经注入覆盖正常/降级两条路径） */
export function detectCanvasSupportIssues(g: BrowserGlobals): CanvasSupportIssue[] {
  return CANVAS_CHECKS.filter((c) => !c.present(g)).map((c) => ({ key: c.key, message: c.message }))
}

/** 组件入口：jsdom 单测环境缺失 ResizeObserver/DOMMatrixReadOnly 实现，直接放行保证既有用例零回归
 *  （画布交互单测以 stub 驱动，能力检测不参与）；真实浏览器按声明基线检测 */
export function getCanvasSupportIssues(): CanvasSupportIssue[] {
  if (import.meta.env.MODE === 'test') return []
  return detectCanvasSupportIssues(window)
}
