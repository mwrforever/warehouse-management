// 工艺路线画布 DAG 纯逻辑：环路预检（保存前本地拦截 1701 同口径文案）与自动分层布局

/** 拓扑排序（Kahn）；返回分层结果（同层可并行渲染在同一列/行） */
export function topoLayers(nodeIds: string[], edges: { from: string; to: string }[]): string[][] {
  const indeg = new Map<string, number>()
  const succs = new Map<string, string[]>()
  for (const id of nodeIds) {
    indeg.set(id, 0)
    succs.set(id, [])
  }
  for (const e of edges) {
    succs.get(e.from)?.push(e.to)
    indeg.set(e.to, (indeg.get(e.to) ?? 0) + 1)
  }
  // 最长路径分层：节点层深 = max(前驱层深) + 1（BFS 逐层剥离入度 0）
  const depth = new Map<string, number>()
  let queue = nodeIds.filter((id) => (indeg.get(id) ?? 0) === 0)
  for (const id of queue) depth.set(id, 0)
  const processed: string[] = []
  while (queue.length > 0) {
    const next: string[] = []
    for (const id of queue) {
      processed.push(id)
      for (const s of succs.get(id) ?? []) {
        depth.set(s, Math.max(depth.get(s) ?? 0, (depth.get(id) ?? 0) + 1))
        indeg.set(s, (indeg.get(s) ?? 1) - 1)
        if ((indeg.get(s) ?? 0) === 0) next.push(s)
      }
    }
    queue = next
  }
  if (processed.length !== nodeIds.length) return [] // 有环（调用方经 hasCycle 先行拦截）
  const layers: string[][] = []
  for (const id of nodeIds) {
    const d = depth.get(id) ?? 0
    ;(layers[d] ??= []).push(id)
  }
  return layers.filter((l) => l.length > 0)
}

/** 环路检测（含自环）：与后端 1701 同判定语义 */
export function hasCycle(nodeIds: string[], edges: { from: string; to: string }[]): boolean {
  // 空图短路：空画布无环可言（Kahn 空集会误报成环），保存/校验应走空节点守卫提示而非"环路"
  if (nodeIds.length === 0) return false
  if (edges.some((e) => e.from === e.to)) return true
  return topoLayers(nodeIds, edges).length === 0
}

/** 自动布局：层深定 x（280px 步进）、层内序定 y（150px 步进），画布初始加载与未拖动节点使用 */
export function layoutPositions(
  nodeIds: string[],
  edges: { from: string; to: string }[],
): Record<string, { x: number; y: number }> {
  const pos: Record<string, { x: number; y: number }> = {}
  const layers = topoLayers(nodeIds, edges)
  if (layers.length === 0) {
    // 有环兜底：按输入顺序线性排布，保证编辑器仍可渲染
    nodeIds.forEach((id, i) => {
      pos[id] = { x: 40, y: 40 + i * 150 }
    })
    return pos
  }
  const layerIndex = new Map<string, number>()
  layers.forEach((layer, d) => layer.forEach((id) => layerIndex.set(id, d)))
  const inLayerPos = new Map<string, number>()
  nodeIds.forEach((id) => {
    const d = layerIndex.get(id) ?? 0
    const y = inLayerPos.get(`${d}`) ?? 0
    inLayerPos.set(`${d}`, y + 1)
    pos[id] = { x: 40 + d * 280, y: 40 + y * 150 }
  })
  return pos
}

/** 下一节点号：OP 起步十位递增（OP10、OP20…；向上取整到大于最大号的下一个十位倍数，跳过已占用区间） */
export function nextNodeNo(existing: string[]): string {
  let max = 0
  for (const no of existing) {
    const m = /^OP(\d+)$/.exec(no)
    if (m) max = Math.max(max, Number.parseInt(m[1] ?? '0', 10))
  }
  // OP25 视为占用 20 段：下号取大于 max 的最小十位倍数（25 → 30，而非 25+10=35）
  return `OP${Math.floor(max / 10) * 10 + 10}`
}
