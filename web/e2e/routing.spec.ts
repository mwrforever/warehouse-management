// 工艺路线 DAG E2E：TC-RTG-01~05（画布保存/环路拦截/工单展开/并行推进/兼容回退）
// 数据策略：主数据/半成品/工序/BOM/供应商全部经 API 幂等自建（编码 RTG- 前缀），
//   不动 MAT-001/SEMI-001/FIN-002 种子基线；库存不走盘点（1205 拒无余额商品录盘）
//   ——RTG-SEMI-A 经采购入库（PO→PI）注入 6@B-01 供 TC-RTG-04 委外发出扣减（组件口径；
//   成品入库会置工单 completed_qty>0，污染 stats-report TC-RPT-03 的「已完成工单」前置定位）；
//   原料不备库（下达仅告警不阻断）
// 结构（钻石，基准 3）：OP10 下料(产 RTG-SEMI-A×3 耗 RTG-RAW×3) → OP20 冲压(B×2 耗 A×1) /
//   OP30 焊接(C×2 耗 A×1，is_outsourced=1) / OP40 组装(D×2 耗 A×1) → OP50 质检(产 RTG-FIN×1 耗 B/C/D×2)
// 并行分支产出互异半成品：后端 1704 逐节点数量闭合要求「半成品产出 = 直接后继消耗合计」，同产物会重复闭合冲突
// 节点选中策略（TC-RTG-01 实测）：工具栏「添加节点」自动选中新节点 → 面板直接配置；
//   画布卡片点选为主、面板「节点」下拉兜底（配置过程零选择开销，E2E 不依赖卡片点击定位）
import { expect, test, type Locator, type Page } from '@playwright/test'
import { loginByAPI } from './helpers'

// 已登录页面的认证请求辅助：token 取自 localStorage（与 production.spec 同构）
async function apiGet(page: Page, url: string, params: Record<string, string | number> = {}) {
  const token = await page.evaluate(() => localStorage.getItem('token'))
  const res = await page.request.get(url, { headers: { Authorization: `Bearer ${token}` }, params })
  expect(res.ok()).toBeTruthy()
  return (await res.json()).data
}
async function apiPost(page: Page, url: string, body?: unknown) {
  const token = await page.evaluate(() => localStorage.getItem('token'))
  const res = await page.request.post(url, {
    headers: { Authorization: `Bearer ${token}` },
    data: body,
  })
  return (await res.json()) as { code: number; message?: string; data?: unknown }
}

// 下拉项选择：等待唯一可见 option 后点击（隐藏的旧 popper 不参与 getByRole 匹配）
async function pickOption(page: Page, name: string) {
  const opt = page.getByRole('option', { name })
  await expect(opt).toHaveCount(1)
  await opt.click()
}

// 画布选中节点：优先点选节点卡片（轨道交互），选中值不对时回退面板「节点」下拉切换。
// 注意：EP 选中项渲染在第 2 个 .el-select__selected-item span（第 1 个是 input-wrapper，文本恒空）
async function selectNode(page: Page, dialog: Locator, no: string) {
  const title = dialog
    .locator('.rn-card', { hasText: `${no} ·` })
    .locator('.rn-title')
    .first()
  try {
    await title.click({ timeout: 3000 })
  } catch {
    // 卡片被遮挡/命中不稳定：降级走面板下拉
  }
  const panelSelect = dialog.locator('.rc-panel .panel-node .el-select')
  const shown = (await panelSelect.locator('.el-select__selected-item').nth(1).textContent()) ?? ''
  if (!shown.startsWith(no)) {
    await panelSelect.click()
    await pickOption(page, `${no} ·`)
  }
  await expect(panelSelect.locator('.el-select__selected-item').nth(1)).toHaveText(
    new RegExp(`^${no} ·`),
  )
}

// 当日日期字符串（工单计划日期默认今天，按本地时区拼装）
function todayStr(): string {
  const d = new Date()
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

test.describe('工艺路线 DAG 全链路 E2E（TC-RTG-01~05）', () => {
  // 用例间共享数据（路线/工单），串行执行
  test.describe.configure({ mode: 'serial', timeout: 90_000 })

  // 共享：TC-RTG-01 建钻石路线 + 主数据；TC-RTG-03 建 DAG 工单；TC-RTG-04 报工推进
  let routingId = 0
  let moId = 0
  let moNo = ''
  // 主数据 id（TC-RTG-01 幂等准备）
  let finId = 0
  let rawId = 0
  let pcId = 0
  let supId = 0
  let whId = 0
  let a01Id = 0
  let b01Id = 0

  test('TC-RTG-01 画布建并行工艺路线并保存', async ({ page }) => {
    await loginByAPI(page, 'admin', 'admin123')
    // —— 数据准备（幂等：按编码/名称存在性复用，RTG- 前缀不与基线冲突）——
    const units = await apiGet(page, '/api/v1/units', { per_page: 100 })
    pcId = (units.items as { code: string; id: number }[]).find((u) => u.code === 'pc')?.id ?? 0
    expect(pcId).toBeGreaterThan(0)
    const cats = await apiGet(page, '/api/v1/categories')
    // 分类接口返回顶级树数组（非分页 {items} 结构）
    const catId = (name: string) =>
      (cats as { name: string; id: number }[]).find((c) => c.name === name)?.id ?? 0
    // 工序：种子仅 下料(PROC-01)；冲压/焊接/组装/质检按名称幂等补建（production.spec 已建同名则复用）
    const procList = await apiGet(page, '/api/v1/processes', { per_page: 100 })
    const procNames = (procList.items as { name: string }[]).map((p) => p.name)
    for (const p of [
      { name: '下料', code: 'PROC-01', sort: 1 },
      { name: '组装', code: 'PROC-02', sort: 2 },
      { name: '质检', code: 'PROC-03', sort: 3 },
      { name: '冲压', code: 'PROC-04', sort: 4 },
      { name: '焊接', code: 'PROC-05', sort: 5 },
    ]) {
      if (!procNames.includes(p.name)) {
        const created = await apiPost(page, '/api/v1/processes', p)
        expect(created.code).toBe(0)
      }
    }
    // 商品 8 个（原料/半成品A-D/成品×3），按编码复用
    const prods = [
      { name: 'RTG原料', code: 'RTG-RAW', type: 'raw_material' },
      { name: 'RTG半成品A', code: 'RTG-SEMI-A', type: 'semi_finished' },
      { name: 'RTG半成品B', code: 'RTG-SEMI-B', type: 'semi_finished' },
      { name: 'RTG半成品C', code: 'RTG-SEMI-C', type: 'semi_finished' },
      { name: 'RTG半成品D', code: 'RTG-SEMI-D', type: 'semi_finished' },
      { name: 'RTG最终成品', code: 'RTG-FIN', type: 'finished' },
      { name: 'RTG循环成品', code: 'RTG-FIN2', type: 'finished' },
      { name: 'RTG线性成品', code: 'RTG-FIN3', type: 'finished' },
    ]
    const prodIdByCode: Record<string, number> = {}
    for (const p of prods) {
      const exist = await apiGet(page, '/api/v1/products', { keyword: p.code, per_page: 100 })
      if (exist.total > 0) {
        prodIdByCode[p.code] = exist.items[0].id as number
        continue
      }
      const created = await apiPost(page, '/api/v1/products', {
        name: p.name,
        code: p.code,
        type: p.type,
        category_id: catId(
          p.type === 'raw_material' ? '原材料' : p.type === 'semi_finished' ? '半成品' : '成品',
        ),
        unit_id: pcId,
      })
      expect(created.code).toBe(0)
      prodIdByCode[p.code] = (created.data as { id: number }).id
    }
    rawId = prodIdByCode['RTG-RAW']
    finId = prodIdByCode['RTG-FIN']
    // 仓库/库位 id（主仓 A-01/B-01）
    const whs = await apiGet(page, '/api/v1/warehouses', { keyword: '主仓' })
    whId = whs.items[0].id as number
    const locs = await apiGet(page, `/api/v1/warehouses/${whId}/locations`)
    a01Id = (locs.items as { code: string; id: number }[]).find((l) => l.code === 'A-01')?.id ?? 0
    b01Id = (locs.items as { code: string; id: number }[]).find((l) => l.code === 'B-01')?.id ?? 0
    expect(a01Id).toBeGreaterThan(0)
    expect(b01Id).toBeGreaterThan(0)
    // 供应商 SUP-001（TC-RTG-04 委外 OP30 用）
    const supList = await apiGet(page, '/api/v1/suppliers', { keyword: 'SUP-001', per_page: 100 })
    if (supList.total > 0) {
      supId = supList.items[0].id as number
    } else {
      const sup = await apiPost(page, '/api/v1/suppliers', {
        name: '测试供应商',
        code: 'SUP-001',
        status: 1,
      })
      expect(sup.code).toBe(0)
      const supAfter = await apiGet(page, '/api/v1/suppliers', {
        keyword: 'SUP-001',
        per_page: 100,
      })
      supId = supAfter.items[0].id as number
    }
    // BOM：RTG-FIN（原料×3，基准1）供 TC-RTG-03 建工单；RTG-FIN3（原料×2）供 TC-RTG-05
    for (const [pid, qty] of [
      [finId, 3],
      [prodIdByCode['RTG-FIN3'], 2],
    ] as const) {
      const boms = await apiGet(page, '/api/v1/boms', { product_id: pid, per_page: 100 })
      if (!(boms.items as { status: number }[]).some((b) => b.status === 1)) {
        const bom = await apiPost(page, '/api/v1/boms', {
          product_id: pid,
          version: 'V1',
          quantity: 1,
          status: 1,
          items: [{ material_id: rawId, quantity: qty, unit_id: pcId }],
        })
        expect(bom.code).toBe(0)
      }
    }
    // 库存策略：盘点 1205 拒「无余额商品录盘」→ 新商品库存一律走真实单据。
    //   RAW-RTG 不做库存（下达仅告警不阻断，TC-RTG-03/05 处理缺料警告弹窗）；
    //   FIN-RTG 在 TC-RTG-04 经成品入库（FI）注入 6@B-01 供委外发出扣减（避免采购入库留 PI 行）

    // —— 画布新建：成品 RTG-FIN / 版本 v1 / 基准 3 ——
    await page.goto('/master/routings')
    await page.getByRole('button', { name: /新\s*建/ }).click()
    const dialog = page.locator('.el-dialog')
    await dialog.locator('.rc-header .el-select').click()
    await pickOption(page, 'RTG-FIN RTG最终成品')
    await dialog.locator('.rc-header .el-input-number input').fill('3')
    const panel = dialog.locator('.rc-panel')
    // 逐节点添加：工具栏工序下拉 + 添加节点（自动选中新节点 → 面板直接配置）
    async function addNode(proc: string) {
      await dialog.locator('.rc-toolbar .el-select').click()
      await pickOption(page, proc)
      await dialog.getByRole('button', { name: '添加节点' }).click()
    }
    async function setOutput(code: string, name: string, qty: string) {
      await panel.locator('.el-form-item', { hasText: '输出产品' }).locator('.el-select').click()
      await pickOption(page, `${code} ${name}`)
      await panel
        .locator('.el-form-item', { hasText: '产出数量' })
        .locator('.el-input-number input')
        .fill(qty)
    }
    async function setMaterial(materialCode: string, matName: string, qty: string) {
      await panel
        .locator('.el-form-item', { hasText: '输入材料' })
        .getByRole('button', { name: '添加材料' })
        .click()
      const matRow = panel.locator('.mat-row').last()
      await matRow.locator('.el-select').click()
      await pickOption(page, `${materialCode} ${matName}`)
      await matRow.locator('.el-input-number input').fill(qty)
    }
    // 添加连线（底部工具栏：从节点 → 到节点）
    async function addEdge(fromNo: string, toNo: string) {
      const footer = dialog.locator('.rc-footer')
      await footer.locator('.edge-from .el-select').click()
      await pickOption(page, `${fromNo} ·`)
      await footer.locator('.edge-to .el-select').click()
      await pickOption(page, `${toNo} ·`)
      await footer.getByRole('button', { name: '添加连线' }).click()
    }
    // OP10 下料：产 A×3 耗原料×3
    await addNode('下料')
    await selectNode(page, dialog, 'OP10')
    await setOutput('RTG-SEMI-A', 'RTG半成品A', '3')
    await setMaterial('RTG-RAW', 'RTG原料', '3')
    // OP20 冲压：产 B×2 耗 A×1
    await addNode('冲压')
    await selectNode(page, dialog, 'OP20')
    await setOutput('RTG-SEMI-B', 'RTG半成品B', '2')
    await setMaterial('RTG-SEMI-A', 'RTG半成品A', '1')
    // OP30 焊接：产 C×2 耗 A×1，委外标记
    await addNode('焊接')
    await selectNode(page, dialog, 'OP30')
    await setOutput('RTG-SEMI-C', 'RTG半成品C', '2')
    await setMaterial('RTG-SEMI-A', 'RTG半成品A', '1')
    await panel.locator('.el-form-item', { hasText: '委外工序' }).locator('.el-switch').click()
    // OP40 组装：产 D×2 耗 A×1
    await addNode('组装')
    await selectNode(page, dialog, 'OP40')
    await setOutput('RTG-SEMI-D', 'RTG半成品D', '2')
    await setMaterial('RTG-SEMI-A', 'RTG半成品A', '1')
    // OP50 质检：产成品×1 耗 B×2+C×2+D×2
    await addNode('质检')
    await selectNode(page, dialog, 'OP50')
    await setOutput('RTG-FIN', 'RTG最终成品', '1')
    await setMaterial('RTG-SEMI-B', 'RTG半成品B', '2')
    await setMaterial('RTG-SEMI-C', 'RTG半成品C', '2')
    await setMaterial('RTG-SEMI-D', 'RTG半成品D', '2')
    // 连线：OP10→OP20/30/40，OP20/30/40→OP50
    await addEdge('OP10', 'OP20')
    await addEdge('OP10', 'OP30')
    await addEdge('OP10', 'OP40')
    await addEdge('OP20', 'OP50')
    await addEdge('OP30', 'OP50')
    await addEdge('OP40', 'OP50')
    // 校验 DAG：无环 + 材料闭环无警告 → 成功
    await dialog.getByRole('button', { name: /校验\s*DAG/ }).click()
    await expect(page.locator('.el-message--success').last()).toContainText('DAG 校验通过')
    // 保存：成功 → 弹窗关闭 → 列表出现 RTG 编码行
    await dialog.getByRole('button', { name: /保\s*存/ }).click()
    await expect(page.locator('.el-message--success').last()).toContainText('保存成功')
    await expect(dialog).toBeHidden()
    const row = page.locator('.el-table__row', { hasText: /^RTG\d{15}/ }).first()
    await expect(row).toBeVisible()
    // API graph 权威断言：5 节点 / 6 边 / OP30 委外 / 产出与材料落库
    const rtgs = await apiGet(page, '/api/v1/routings', { keyword: 'RTG', per_page: 100 })
    routingId = rtgs.items[0].id as number
    const g = await apiGet(page, `/api/v1/routings/${routingId}/graph`)
    expect((g.nodes as { node_no: string }[]).map((n) => n.node_no)).toEqual([
      'OP10',
      'OP20',
      'OP30',
      'OP40',
      'OP50',
    ])
    expect(g.routing.product_name).toBe('RTG最终成品')
    expect(Number(g.routing.quantity)).toBe(3)
    const nodeNo = (n: string) => (g.nodes as { node_no: string }[]).find((x) => x.node_no === n)!
    expect(nodeNo('OP30').is_outsourced).toBe(1)
    expect(nodeNo('OP10').output_product_name).toBe('RTG半成品A')
    expect(Number(nodeNo('OP10').materials[0].qty_per_unit)).toBe(3)
    expect(nodeNo('OP30').is_outsourced).toBe(1)
    expect(
      (g.edges as { from_node_no: string; to_node_no: string }[])
        .map((e) => `${e.from_node_no}->${e.to_node_no}`)
        .sort(),
    ).toEqual(['OP10->OP20', 'OP10->OP30', 'OP10->OP40', 'OP20->OP50', 'OP30->OP50', 'OP40->OP50'])
  })

  test('TC-RTG-02 环路拦截（OP20→OP10 闭环保存被拒）', async ({ page }) => {
    await loginByAPI(page, 'admin', 'admin123')
    const before = await apiGet(page, '/api/v1/routings', { per_page: 100 })
    // 新建最小 2 节点路线（OP10→OP20 + 追加 OP20→OP10 闭环）
    await page.goto('/master/routings')
    await page.getByRole('button', { name: /新\s*建/ }).click()
    const dialog = page.locator('.el-dialog')
    await dialog.locator('.rc-header .el-select').click()
    await pickOption(page, 'RTG-FIN2 RTG循环成品')
    async function addNode(proc: string) {
      await dialog.locator('.rc-toolbar .el-select').click()
      await pickOption(page, proc)
      await dialog.getByRole('button', { name: '添加节点' }).click()
    }
    async function addEdge(fromNo: string, toNo: string) {
      const footer = dialog.locator('.rc-footer')
      await footer.locator('.edge-from .el-select').click()
      await pickOption(page, `${fromNo} ·`)
      await footer.locator('.edge-to .el-select').click()
      await pickOption(page, `${toNo} ·`)
      await footer.getByRole('button', { name: '添加连线' }).click()
    }
    await addNode('下料')
    await selectNode(page, dialog, 'OP10')
    await addNode('冲压')
    await selectNode(page, dialog, 'OP20')
    await addEdge('OP10', 'OP20')
    await addEdge('OP20', 'OP10')
    // 保存：本地环预检（与后端 1701 同口径）拦截，不调接口
    await dialog.getByRole('button', { name: /保\s*存/ }).click()
    await expect(page.locator('.el-message--error').last()).toContainText('工艺路线存在工序环路')
    // API 权威：路线数量未新增（仅 TC-RTG-01 的 1 条）
    const after = await apiGet(page, '/api/v1/routings', { per_page: 100 })
    expect(after.total).toBe(before.total)
  })

  test('TC-RTG-03 按路线建工单并展开工序网络', async ({ page }) => {
    await loginByAPI(page, 'admin', 'admin123')
    // —— 工单创建（UI）：RTG-FIN ×6 ——
    await page.goto('/production/orders')
    await page.getByRole('button', { name: /新\s*建/ }).click()
    const dialog = page.locator('.el-dialog')
    await dialog.locator('.el-select', { hasText: '选择成品' }).click()
    await pickOption(page, 'RTG最终成品（RTG-FIN）')
    await dialog.locator('.el-input-number input').fill('6')
    await dialog.getByRole('button', { name: /保\s*存/ }).click()
    // BOM 展开确认弹窗：物料 1 行（原料 18）+ 工序 5 行（DAG 拓扑序）
    const exp = page.locator('.el-dialog', { hasText: 'BOM 展开确认' })
    await expect(exp).toBeVisible()
    const expTables = exp.locator('.data-table')
    await expect(expTables.nth(0).locator('.el-table__row')).toHaveCount(1)
    await expect(expTables.nth(0).locator('.el-table__row', { hasText: 'RTG-RAW' })).toContainText(
      '18',
    )
    await expect(expTables.nth(1).locator('.el-table__row')).toHaveCount(5)
    await exp.getByRole('button', { name: /确\s*定/ }).click()
    // 列表出现 MO 草稿 → 下达 → 开工
    const row = page.locator('.el-table__row', { hasText: /^MO\d{15}/ }).first()
    await expect(row).toContainText('草稿')
    moNo = (await row.locator('td').first().textContent())?.trim() ?? ''
    expect(moNo).toMatch(/^MO\d{12}\d{3}$/)
    await row.getByRole('button', { name: /下\s*达/ }).click()
    await expect(page.locator('.el-message-box')).toContainText('确认下达工单')
    await page
      .locator('.el-message-box')
      .getByRole('button', { name: /确\s*定/ })
      .click()
    // RAW-RTG 未备库存 → 缺料警告弹窗（不阻断下达，仅提示）
    const warn = page.locator('.el-dialog', { hasText: '缺料警告' })
    await expect(warn).toContainText('RTG-RAW')
    await warn.getByRole('button', { name: /确\s*定/ }).click()
    await expect(page.locator('.el-table__row', { hasText: moNo })).toContainText('已下达')
    await page
      .locator('.el-table__row', { hasText: moNo })
      .getByRole('button', { name: /开\s*工/ })
      .click()
    await expect(page.locator('.el-message-box')).toContainText('确认开工工单')
    await page
      .locator('.el-message-box')
      .getByRole('button', { name: /确\s*定/ })
      .click()
    await expect(page.locator('.el-message--success').last()).toContainText('开工成功')
    // 详情「工序网络」tab：5 节点卡片 + OP10 进行中其余待开工 + OP30 委外角标
    const list = await apiGet(page, '/api/v1/production/orders', { keyword: moNo })
    moId = list.items[0].id as number
    await page
      .locator('.el-table__row', { hasText: moNo })
      .getByRole('button', { name: /详\s*情/ })
      .click()
    const ddialog = page.locator('.el-dialog', { hasText: '工单详情' })
    await ddialog.getByRole('tab', { name: '工序网络' }).click()
    await expect(ddialog.locator('.og-node')).toHaveCount(5)
    await expect(
      ddialog.locator('.og-node', { hasText: /OP10 ·/ }).locator('.og-status'),
    ).toHaveText('进行中')
    for (const no of ['OP20', 'OP30', 'OP40', 'OP50']) {
      await expect(
        ddialog.locator('.og-node', { hasText: new RegExp(`${no} ·`) }).locator('.og-status'),
      ).toHaveText('待开工')
    }
    await expect(
      ddialog.locator('.og-node', { hasText: /OP30 ·/ }).locator('.og-badge'),
    ).toHaveText('委外')
    // API graph 权威断言：开工后仅入度 0 起点 OP10 进行中，其余待开工；边快照 6 条
    const detail = await apiGet(page, `/api/v1/production/orders/${moId}`)
    expect(detail.routing_id).toBeGreaterThan(0)
    expect((detail.graph.nodes as { node_no: string }[]).map((n) => n.node_no)).toEqual([
      'OP10',
      'OP20',
      'OP30',
      'OP40',
      'OP50',
    ])
    expect(detail.graph.edges).toHaveLength(6)
    const st = (no: string) =>
      (detail.graph.nodes as { node_no: string; status: number }[]).find(
        (n) => n.node_no === no,
      ) as { status: number } | undefined
    expect(st('OP10')?.status).toBe(1)
    for (const no of ['OP20', 'OP30', 'OP40', 'OP50']) expect(st(no)?.status).toBe(0)
    // 委外标记随快照落到工序行
    expect(
      (detail.operations as { node_no: string; is_outsourced: number }[]).find(
        (o) => o.node_no === 'OP30',
      )?.is_outsourced,
    ).toBe(1)
  })

  test('TC-RTG-04 并行报工推进与委外分支汇合', async ({ page }) => {
    await loginByAPI(page, 'admin', 'admin123')
    // 工序 id 按 node_no 定位（详情 operations）
    const detail = await apiGet(page, `/api/v1/production/orders/${moId}`)
    const opId = (no: string) =>
      (detail.operations as { node_no: string; id: number }[]).find((o) => o.node_no === no)!.id
    const report = async (no: string, qty: number) => {
      const res = await apiPost(page, `/api/v1/production/operations/${opId(no)}/reports`, {
        qualified_qty: qty,
        defective_qty: 0,
        hours: 1,
      })
      expect(res.code).toBe(0)
    }
    const graphStatus = async (no: string): Promise<number> => {
      const d = await apiGet(page, `/api/v1/production/orders/${moId}`)
      return (
        (d.graph.nodes as { node_no: string; status: number }[]).find((n) => n.node_no === no)
          ?.status ?? -1
      )
    }
    // OP10 报满 → 三分支并行进行中、汇合点 OP50 待开工
    await report('OP10', 6)
    expect(await graphStatus('OP10')).toBe(2)
    for (const no of ['OP20', 'OP30', 'OP40']) expect(await graphStatus(no)).toBe(1)
    expect(await graphStatus('OP50')).toBe(0)
    // UI 刷新详情：画布节点状态同步（OP20 进行中 / OP50 待开工）
    await page.goto('/production/orders')
    await page
      .locator('.el-table__row', { hasText: moNo })
      .getByRole('button', { name: /详\s*情/ })
      .click()
    const ddialog = page.locator('.el-dialog', { hasText: '工单详情' })
    await ddialog.getByRole('tab', { name: '工序网络' }).click()
    await expect(
      ddialog.locator('.og-node', { hasText: /OP20 ·/ }).locator('.og-status'),
    ).toHaveText('进行中')
    await expect(
      ddialog.locator('.og-node', { hasText: /OP50 ·/ }).locator('.og-status'),
    ).toHaveText('待开工')
    // OP20/OP40 报满 → OP30（委外）仍进行中、OP50 仍待开工（前驱未全部完成）
    await report('OP20', 6)
    await report('OP40', 6)
    expect(await graphStatus('OP30')).toBe(1)
    expect(await graphStatus('OP50')).toBe(0)
    // 注入组件库存 RTG-SEMI-A（委外发出扣减对象，采购 PO→PI 审核）：盘点 1205 拒无余额商品录盘；
    //   成品入库（FI）会把工单 completed_qty 置 >0，污染 stats-report TC-RPT-03 的
    //   「找一台已完成工单」前置定位（本 DAG 工单无领料，展开无 MAT-001 行）→ 弃用 FI 改走采购；
    //   组件口径（spec 5 §4 规则定义）：委外发出扣节点输入材料（OP30 焊接 耗 RTG-SEMI-A×1/单位），
    //   回收回补节点产出（RTG-SEMI-C）——旧成品口径（扣 FIN-RTG）已随 Spec 5 废弃
    const semiAProds = await apiGet(page, '/api/v1/products', {
      keyword: 'RTG-SEMI-A',
      per_page: 100,
    })
    const semiAId = semiAProds.items[0].id as number
    const po = await apiPost(page, '/api/v1/purchase/orders', {
      supplier_id: supId,
      order_date: todayStr(),
      items: [{ product_id: semiAId, quantity: 6, price: 1 }],
    })
    expect(po.code).toBe(0)
    const poNo = (po.data as { no: string }).no
    const poList = await apiGet(page, '/api/v1/purchase/orders', { keyword: poNo })
    const poAppr = await apiPost(page, `/api/v1/purchase/orders/${poList.items[0].id}/approve`)
    expect(poAppr.code).toBe(0)
    const pi = await apiPost(page, '/api/v1/purchase/inbounds', {
      order_id: poList.items[0].id,
      supplier_id: supId,
      warehouse_id: whId,
      location_id: b01Id,
      items: [{ product_id: semiAId, quantity: 6, price: 1 }],
    })
    expect(pi.code).toBe(0)
    const piNo = (pi.data as { no: string }).no
    const piList = await apiGet(page, '/api/v1/purchase/inbounds', { keyword: piNo })
    const piAppr = await apiPost(page, `/api/v1/purchase/inbounds/${piList.items[0].id}/approve`)
    expect(piAppr.code).toBe(0)
    // 组件余额（关键字按名称唯一匹配，避免子串误中 RTG-FIN2/3 等其它商品）
    const semiABal = async (): Promise<number> => {
      const rows = await apiGet(page, '/api/v1/inventory/balances', { keyword: 'RTG半成品A' })
      return (rows.items as { quantity: number }[]).reduce((s, r) => s + Number(r.quantity), 0)
    }
    const semiCBal = async (): Promise<number> => {
      const rows = await apiGet(page, '/api/v1/inventory/balances', { keyword: 'RTG半成品C' })
      return (rows.items as { quantity: number }[]).reduce((s, r) => s + Number(r.quantity), 0)
    }
    expect(await semiABal()).toBe(6)
    // OP30 委外（组件口径）：items 取自 from-operation 预填（A×1/单位 → 委外 6 应发 6）；
    //   发出扣组件库存 6 → 回收回补节点产出 C 6（口径后 FIN-RTG 不再参与委外扣减）
    const pref = await apiGet(
      page,
      `/api/v1/production/outsourcings/from-operation/${opId('OP30')}`,
    )
    const items = (
      pref.items as { material_id: number; qty_per_unit: number; unit_id: number }[]
    ).map((i) => ({
      material_id: i.material_id,
      required_qty: Number(i.qty_per_unit) * 6,
      unit_id: i.unit_id,
    }))
    expect(items).toHaveLength(1)
    expect(items[0].required_qty).toBe(6)
    const os = await apiPost(page, '/api/v1/production/outsourcings', {
      order_id: moId,
      operation_id: opId('OP30'),
      supplier_id: supId,
      warehouse_id: whId,
      location_id: b01Id,
      quantity: 6,
      items,
    })
    expect(os.code).toBe(0)
    const osNo = (os.data as { no: string }).no
    const osList = await apiGet(page, '/api/v1/production/outsourcings', { keyword: osNo })
    const osId = osList.items[0].id as number
    const appr = await apiPost(page, `/api/v1/production/outsourcings/${osId}/approve`)
    expect(appr.code).toBe(0)
    expect(await semiABal()).toBe(0)
    const rc = await apiPost(page, `/api/v1/production/outsourcings/${osId}/receipts`, {
      quantity: 6,
      warehouse_id: whId,
      location_id: b01Id,
    })
    expect(rc.code).toBe(0)
    expect(await semiCBal()).toBe(6)
    // 委外节点经回收完成；全部前驱已完成 → 汇合点 OP50 进行中
    expect(await graphStatus('OP30')).toBe(2)
    expect(await graphStatus('OP50')).toBe(1)
  })

  test('TC-RTG-05 无路线成品兼容回退', async ({ page }) => {
    await loginByAPI(page, 'admin', 'admin123')
    // 另建无路线成品（仅 BOM）：UI 建工单成功 → 详情无「工序网络」tab、线性表正常
    await page.goto('/production/orders')
    await page.getByRole('button', { name: /新\s*建/ }).click()
    const dialog = page.locator('.el-dialog')
    await dialog.locator('.el-select', { hasText: '选择成品' }).click()
    await pickOption(page, 'RTG线性成品（RTG-FIN3）')
    await dialog.locator('.el-input-number input').fill('5')
    await dialog.getByRole('button', { name: /保\s*存/ }).click()
    const exp = page.locator('.el-dialog', { hasText: 'BOM 展开确认' })
    await expect(exp).toBeVisible()
    await exp.getByRole('button', { name: /确\s*定/ }).click()
    const row = page.locator('.el-table__row', { hasText: /^MO\d{15}/ }).first()
    await expect(row).toContainText('草稿')
    const mo5No = (await row.locator('td').first().textContent())?.trim() ?? ''
    // 草稿行无「详 情」入口（仅 status>0）→ 先下达再进详情
    await row.getByRole('button', { name: /下\s*达/ }).click()
    await expect(page.locator('.el-message-box')).toContainText('确认下达工单')
    await page
      .locator('.el-message-box')
      .getByRole('button', { name: /确\s*定/ })
      .click()
    // RTG-RAW 未备库存 → 缺料警告弹窗（不阻断下达，仅提示）
    const warn = page.locator('.el-dialog', { hasText: '缺料警告' })
    await expect(warn).toBeVisible()
    await warn.getByRole('button', { name: /确\s*定/ }).click()
    // API 权威：routing_id=null、graph=null（旧逻辑线性展开）
    const list = await apiGet(page, '/api/v1/production/orders', { keyword: mo5No })
    const mo5Id = list.items[0].id as number
    const detail = await apiGet(page, `/api/v1/production/orders/${mo5Id}`)
    expect(detail.routing_id).toBeNull()
    expect(detail.graph).toBeNull()
    expect(
      (detail.operations as { node_no: string | null }[]).every((o) => o.node_no === null),
    ).toBeTruthy()
    // UI 详情：无「工序网络」tab；「工序流转」线性表正常展示（行数=API 工序数，首行下料）
    await row.getByRole('button', { name: /详\s*情/ }).click()
    const ddialog = page.locator('.el-dialog', { hasText: '工单详情' })
    await expect(ddialog.getByRole('tab', { name: '工序网络' })).toHaveCount(0)
    await ddialog.getByRole('tab', { name: '工序流转' }).click()
    // 工序流转 tab 内线性表：行数 = API 工序数（隐藏 tab 仍驻留 DOM，必须限定 tab 面板作用域）
    const flowPane = ddialog.locator('.el-tab-pane').nth(1)
    await expect(flowPane.locator('.el-table__row')).toHaveCount(
      (detail.operations as unknown[]).length,
    )
    await expect(flowPane.locator('.el-table__row').first()).toContainText('下料')
  })
})
