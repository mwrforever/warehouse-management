import { describe, expect, it } from 'vitest'
import { hasCycle, layoutPositions, nextNodeNo, topoLayers } from '../utils/dag'

describe('dag 工具（工艺路线画布纯逻辑）', () => {
  it('环路检测：A→B→A 判环，并行无环通过', () => {
    expect(
      hasCycle(
        ['A', 'B'],
        [
          { from: 'A', to: 'B' },
          { from: 'B', to: 'A' },
        ],
      ),
    ).toBe(true)
    expect(
      hasCycle(
        ['A', 'B', 'C', 'D', 'E'],
        [
          { from: 'A', to: 'B' },
          { from: 'A', to: 'C' },
          { from: 'A', to: 'D' },
          { from: 'B', to: 'E' },
          { from: 'C', to: 'E' },
          { from: 'D', to: 'E' },
        ],
      ),
    ).toBe(false)
    expect(hasCycle(['A'], [{ from: 'A', to: 'A' }])).toBe(true)
  })

  it('拓扑分层：并行分支同层', () => {
    const layers = topoLayers(
      ['OP10', 'OP20', 'OP30', 'OP40', 'OP50'],
      [
        { from: 'OP10', to: 'OP20' },
        { from: 'OP10', to: 'OP30' },
        { from: 'OP10', to: 'OP40' },
        { from: 'OP20', to: 'OP50' },
        { from: 'OP30', to: 'OP50' },
        { from: 'OP40', to: 'OP50' },
      ],
    )
    expect(layers).toEqual([['OP10'], ['OP20', 'OP30', 'OP40'], ['OP50']])
  })

  it('自动布局：层深定 x、层内序定 y', () => {
    const pos = layoutPositions(
      ['OP10', 'OP20', 'OP30'],
      [
        { from: 'OP10', to: 'OP20' },
        { from: 'OP10', to: 'OP30' },
      ],
    )
    expect(pos['OP10']).toEqual({ x: 40, y: 40 })
    expect(pos['OP20']).toEqual({ x: 320, y: 40 })
    expect(pos['OP30']).toEqual({ x: 320, y: 190 })
  })

  it('节点号自动递增：OP10 起步十位递增', () => {
    expect(nextNodeNo([])).toBe('OP10')
    expect(nextNodeNo(['OP10', 'OP20'])).toBe('OP30')
    expect(nextNodeNo(['OP10', 'OP25'])).toBe('OP30')
  })
})
