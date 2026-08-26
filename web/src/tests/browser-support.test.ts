// 工艺画布浏览器能力检测单测（Z-1）：纯检测函数经注入假全局对象覆盖正常/降级两条路径；
// 组件入口在 jsdom 单测环境（MODE=test）下必须放行，保证既有画布用例零回归
import { describe, it, expect } from 'vitest'
import {
  detectCanvasSupportIssues,
  getCanvasSupportIssues,
  type CanvasSupportIssue,
} from '../utils/browserSupport'

/** 模拟满足声明基线（Chrome/Edge ≥ 100）的浏览器全局：三项能力齐备 */
const baselineGlobals = {
  PointerEvent: class PointerEvent {},
  ResizeObserver: class ResizeObserver {},
  DOMMatrixReadOnly: class DOMMatrixReadOnly {},
}

describe('工艺画布浏览器能力检测（Z-1）', () => {
  it('正常路径：满足基线（Chrome/Edge ≥ 100）时返回空清单，不触发降级', () => {
    expect(detectCanvasSupportIssues(baselineGlobals)).toEqual([])
  })

  it('异常路径：缺少 ResizeObserver 时给出中文原因（Vue Flow 挂载即 new ResizeObserver，缺失会白屏）', () => {
    const issues = detectCanvasSupportIssues({
      PointerEvent: baselineGlobals.PointerEvent,
      DOMMatrixReadOnly: baselineGlobals.DOMMatrixReadOnly,
    })
    expect(issues.map((i) => i.key)).toContain('resizeObserver')
    expect(issues[0]!.message).toContain('ResizeObserver')
  })

  it('边界路径：三缺全无时逐项列出原因清单（含 pointerEvent 与 domMatrix）', () => {
    const issues = detectCanvasSupportIssues({})
    expect(issues.map((i) => i.key)).toEqual(['pointerEvent', 'resizeObserver', 'domMatrix'])
    expect(issues.every((i) => i.message.length > 0)).toBe(true)
  })

  it('jsdom 单测环境：组件入口默认放行（既有画布用例流程不受检测影响）', () => {
    expect(getCanvasSupportIssues()).toEqual([] satisfies CanvasSupportIssue[])
  })
})
