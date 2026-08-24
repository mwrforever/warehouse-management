// 委外组件全链路 E2E：TC-OS-01~04（委外全链路/余料退回/委外节点不可报工/工序网络委外联动）
// 数据策略：主数据/工序/BOM/工艺路线（钻石 DAG，OP30 焊接=委外节点）/库存全部经 API 幂等自建（OS2- 前缀），
//   不动 MAT-001/SEMI-001/FIN-002 种子基线；库存基线经采购 PO→PI 审核注入（勿用 FI——
//   成品入库会置工单 completed_qty>0，污染 stats-report TC-RPT-03 的「已完成工单」前置定位；TC-RTG-04 教训）
// 结构（钻石，基准 3，节点工序全部复用种子 下料(PROC-01)——不补建工序，防污染 production.spec 的
//   无路线展开行数断言；同后端 DagOrderFactory 口径）：OP10 下料(产 OS2-SEMI-A×2 耗 OS2-RAW×2) →
//   分支 OP20 冲压(B×3 耗 A×1) / OP40 组装(D×2 耗 A×1)；OP30 焊接(委外，C×2 耗 原料×2+B×1，
//   is_outsourced=1，B 经 OP20 直连供料) → OP50 质检(产 OS2-FIN×1 耗 B×2+C×2+D×2)
//   数量闭合 1704：A 产出 2=OP20 1+OP40 1；B 产出 3=OP30 1+OP50 2；C/D 产出 2=OP50 消耗 2（并行分支产物互异）
// 委外对象=工艺路线节点（spec 5 §4 规则定义）：发料组件=节点输入材料（原料×2/半成品B×1 单位用量，应发=数量×单位用量自动带出），
//   回收品=节点输出 OS2-SEMI-C（发出扣组件、回收回补产出，旧成品口径已废弃）
// 用例串行（后续用例复用 TC-OS-01 建的单据/库存基线）：主数据幂等自建（OS2- 前缀）+ 串行复用；
//   已审核单据不可删，跨 spec 残留由 production/purchase 用例的 .first() 口径规避（CI migrate:fresh 兜底）
import { expect, test, type Page } from '@playwright/test'
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

// 商品总余额：balances 按 商品×仓库×库位 分行，汇总求和作为「当时余额」增量断言基线
async function totalBalance(page: Page, keyword: string): Promise<number> {
  const rows = await apiGet(page, '/api/v1/inventory/balances', { keyword })
  return (rows.items as { quantity: number }[]).reduce((sum, r) => sum + Number(r.quantity), 0)
}

// 当日日期字符串（工单计划日期默认今天，按本地时区拼装）
function todayStr(): string {
  const d = new Date()
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

test.describe('委外加工模块 E2E（TC-OS-01~04）', () => {
  // 用例间单据/库存状态相互依赖，串行执行（链路长，放宽单用例超时）
  test.describe.configure({ mode: 'serial', timeout: 120_000 })

  // 用例共享（describe 顶层声明，用例间传递）：委外单/工单 id/单号、主数据 id
  let osId = 0
  let osNo = ''
  let osMoNo = ''
  let osMoId = 0
  // 主数据 id（TC-OS-01 幂等准备；TC-OS-03 自建工单沿用）
  let pcId = 0
  let supId = 0
  let finId = 0
  let rawId = 0
  let semiAId = 0
  let semiBId = 0
  let semiCId = 0
  let semiDId = 0
  let whId = 0
  let a01Id = 0
  let b01Id = 0
  // 组件库存基线（TC-OS-01 注入后记录；委外发出/退回增量断言基准）
  let rawBase = 0
  let bBase = 0

  test('TC-OS-01 委外全链路（建单-发出-分批回收-节点联动）', async ({ page }) => {
    await loginByAPI(page, 'admin', 'admin123')
    // —— 数据准备（幂等：按编码/名称存在性复用，OS2- 前缀不与基线冲突）——
    const units = await apiGet(page, '/api/v1/units', { per_page: 100 })
    pcId = (units.items as { code: string; id: number }[]).find((u) => u.code === 'pc')?.id ?? 0
    expect(pcId).toBeGreaterThan(0)
    const cats = await apiGet(page, '/api/v1/categories')
    // 分类接口返回顶级树数组（非分页 {items} 结构）
    const catId = (name: string) =>
      (cats as { name: string; id: number }[]).find((c) => c.name === name)?.id ?? 0
    // 工序：仅复用种子 下料(PROC-01)，不补建工序——无路线工单展开工序序列=全部启用工序（sort 升序），
    //   本 spec 运行于 production.spec 之前，新增工序会污染 TC-PRD-01 的「BOM 展开 3 行」断言；
    //   全节点复用单一工序与后端 DagOrderFactory（钻石基线全用 CUT）口径一致（工序名仅展示，不影响节点语义）
    const procList = await apiGet(page, '/api/v1/processes', { per_page: 100 })
    const cutId =
      (procList.items as { name: string; id: number }[]).find((p) => p.name === '下料')?.id ?? 0
    expect(cutId).toBeGreaterThan(0)
    // 商品 6 个（原料/半成品A-D/成品），按编码复用（category 按类型归原材料/半成品/成品）
    const prods = [
      { name: 'OS2原料', code: 'OS2-RAW', type: 'raw_material' },
      { name: 'OS2半成品A', code: 'OS2-SEMI-A', type: 'semi_finished' },
      { name: 'OS2半成品B', code: 'OS2-SEMI-B', type: 'semi_finished' },
      { name: 'OS2半成品C', code: 'OS2-SEMI-C', type: 'semi_finished' },
      { name: 'OS2半成品D', code: 'OS2-SEMI-D', type: 'semi_finished' },
      { name: 'OS2最终成品', code: 'OS2-FIN', type: 'finished' },
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
    rawId = prodIdByCode['OS2-RAW']
    semiAId = prodIdByCode['OS2-SEMI-A']
    semiBId = prodIdByCode['OS2-SEMI-B']
    semiCId = prodIdByCode['OS2-SEMI-C']
    semiDId = prodIdByCode['OS2-SEMI-D']
    finId = prodIdByCode['OS2-FIN']
    // 供应商 SUP-001（委外/采购注入共用；采购模块已建则复用）
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
    // 仓库/库位 id（主仓 A-01/B-01；委外发出/退回走 A-01）
    const whs = await apiGet(page, '/api/v1/warehouses', { keyword: '主仓' })
    whId = whs.items[0].id as number
    const locs = await apiGet(page, `/api/v1/warehouses/${whId}/locations`)
    a01Id = (locs.items as { code: string; id: number }[]).find((l) => l.code === 'A-01')?.id ?? 0
    b01Id = (locs.items as { code: string; id: number }[]).find((l) => l.code === 'B-01')?.id ?? 0
    expect(a01Id).toBeGreaterThan(0)
    expect(b01Id).toBeGreaterThan(0)
    // BOM：OS2-FIN（原料×3，基准1）供工单创建（工单展开物料口径走 BOM，工序口径走工艺路线）
    const boms = await apiGet(page, '/api/v1/boms', { product_id: finId, per_page: 100 })
    if (!(boms.items as { status: number }[]).some((b) => b.status === 1)) {
      const bom = await apiPost(page, '/api/v1/boms', {
        product_id: finId,
        version: 'V1',
        quantity: 1,
        status: 1,
        items: [{ material_id: rawId, quantity: 3, unit_id: pcId }],
      })
      expect(bom.code).toBe(0)
    }
    // 工艺路线（钻石 DAG，基准 3，OP30 焊接=委外节点）：API 直建（画布 E2E 见 routing.spec TC-RTG-01）。
    // 半成品输入须有直接前驱产出（1702）：OP30 耗 B×1 → B 由 OP20 产出并直连供料（OP20→OP30 边）
    const rtgExist = await apiGet(page, '/api/v1/routings', { product_id: finId, per_page: 100 })
    if (rtgExist.total === 0) {
      const rtg = await apiPost(page, '/api/v1/routings', {
        product_id: finId,
        version: 'V1',
        quantity: 3,
        status: 1,
        nodes: [
          {
            node_no: 'OP10',
            process_id: cutId,
            name: '下料',
            output_product_id: semiAId,
            output_qty: 2,
            is_outsourced: 0,
            materials: [{ material_id: rawId, qty_per_unit: 2, unit_id: pcId }],
          },
          {
            node_no: 'OP20',
            process_id: cutId,
            name: '冲压',
            output_product_id: semiBId,
            output_qty: 3,
            is_outsourced: 0,
            materials: [{ material_id: semiAId, qty_per_unit: 1, unit_id: pcId }],
          },
          {
            node_no: 'OP30',
            process_id: cutId,
            name: '焊接',
            output_product_id: semiCId,
            output_qty: 2,
            is_outsourced: 1,
            materials: [
              { material_id: rawId, qty_per_unit: 2, unit_id: pcId },
              { material_id: semiBId, qty_per_unit: 1, unit_id: pcId },
            ],
          },
          {
            node_no: 'OP40',
            process_id: cutId,
            name: '组装',
            output_product_id: semiDId,
            output_qty: 2,
            is_outsourced: 0,
            materials: [{ material_id: semiAId, qty_per_unit: 1, unit_id: pcId }],
          },
          {
            node_no: 'OP50',
            process_id: cutId,
            name: '质检',
            output_product_id: finId,
            output_qty: 1,
            is_outsourced: 0,
            materials: [
              { material_id: semiBId, qty_per_unit: 2, unit_id: pcId },
              { material_id: semiCId, qty_per_unit: 2, unit_id: pcId },
              { material_id: semiDId, qty_per_unit: 2, unit_id: pcId },
            ],
          },
        ],
        edges: [
          { from_node_no: 'OP10', to_node_no: 'OP20' },
          { from_node_no: 'OP10', to_node_no: 'OP40' },
          { from_node_no: 'OP20', to_node_no: 'OP30' },
          { from_node_no: 'OP20', to_node_no: 'OP50' },
          { from_node_no: 'OP30', to_node_no: 'OP50' },
          { from_node_no: 'OP40', to_node_no: 'OP50' },
        ],
      })
      expect(rtg.code).toBe(0)
    }
    // 库存基线：委外发料组件 原料 12/半成品B 6 @A-01 经采购 PO→PI 审核注入（盘点 1205 拒无余额商品录盘；
    //   FI 会置 completed_qty>0 污染 stats-report TC-RPT-03 前置定位 → 弃 FI 走采购，TC-RTG-04 同口径）
    const injectStock = async (pid: number, name: string, qty: number) => {
      const bal = await totalBalance(page, name)
      if (bal >= qty) return
      const need = qty - bal
      const po = await apiPost(page, '/api/v1/purchase/orders', {
        supplier_id: supId,
        order_date: todayStr(),
        items: [{ product_id: pid, quantity: need, price: 1 }],
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
        location_id: a01Id,
        items: [{ product_id: pid, quantity: need, price: 1 }],
      })
      expect(pi.code).toBe(0)
      const piNo = (pi.data as { no: string }).no
      const piList = await apiGet(page, '/api/v1/purchase/inbounds', { keyword: piNo })
      const piAppr = await apiPost(page, `/api/v1/purchase/inbounds/${piList.items[0].id}/approve`)
      expect(piAppr.code).toBe(0)
      await expect.poll(async () => totalBalance(page, name)).toBeGreaterThanOrEqual(qty)
    }
    await injectStock(rawId, 'OS2原料', 12)
    await injectStock(semiBId, 'OS2半成品B', 6)
    rawBase = await totalBalance(page, 'OS2原料')
    bBase = await totalBalance(page, 'OS2半成品B')

    // —— 工单创建（UI）：OS2-FIN ×6 ——
    await page.goto('/production/orders')
    await page.getByRole('button', { name: /新\s*建/ }).click()
    const dialog = page.locator('.el-dialog')
    await dialog.locator('.el-select', { hasText: '选择成品' }).click()
    await pickOption(page, 'OS2最终成品（OS2-FIN）')
    await dialog.locator('.el-input-number input').fill('6')
    await dialog.getByRole('button', { name: /保\s*存/ }).click()
    // BOM 展开确认弹窗：物料 1 行（原料 18）+ 工序 5 行（DAG 拓扑序 OP10~OP50）
    const exp = page.locator('.el-dialog', { hasText: 'BOM 展开确认' })
    await expect(exp).toBeVisible()
    const expTables = exp.locator('.data-table')
    await expect(expTables.nth(0).locator('.el-table__row')).toHaveCount(1)
    await expect(expTables.nth(0).locator('.el-table__row', { hasText: 'OS2-RAW' })).toContainText(
      '18',
    )
    await expect(expTables.nth(1).locator('.el-table__row')).toHaveCount(5)
    await exp.getByRole('button', { name: /确\s*定/ }).click()
    // 列表出现 MO 草稿
    const row = page.locator('.el-table__row', { hasText: /^MO\d{15}/ }).first()
    await expect(row).toContainText('草稿')
    osMoNo = (await row.locator('td').first().textContent())?.trim() ?? ''
    expect(osMoNo).toMatch(/^MO\d{12}\d{3}$/)
    const moList = await apiGet(page, '/api/v1/production/orders', { keyword: osMoNo })
    osMoId = moList.items[0].id as number

    // —— 下达 → 开工（OS2-RAW 备库 12 < 需求 18 → 缺料警告弹窗，不阻断）——
    await row.getByRole('button', { name: /下\s*达/ }).click()
    await expect(page.locator('.el-message-box')).toContainText('确认下达工单')
    await page
      .locator('.el-message-box')
      .getByRole('button', { name: /确\s*定/ })
      .click()
    const warn = page.locator('.el-dialog', { hasText: '缺料警告' })
    await expect(warn).toContainText('OS2-RAW')
    await warn.getByRole('button', { name: /确\s*定/ }).click()
    await expect(page.locator('.el-table__row', { hasText: osMoNo })).toContainText('已下达')
    await page
      .locator('.el-table__row', { hasText: osMoNo })
      .getByRole('button', { name: /开\s*工/ })
      .click()
    await expect(page.locator('.el-message-box')).toContainText('确认开工工单')
    await page
      .locator('.el-message-box')
      .getByRole('button', { name: /确\s*定/ })
      .click()
    await expect(page.locator('.el-message--success').last()).toContainText('开工成功')

    // —— 报工 OP10 满量（UI）：下料 6 → OP20/OP40 进行中（OP30 待前驱 OP20 供料，汇合点 OP50 待开工）——
    await page
      .locator('.el-table__row', { hasText: osMoNo })
      .getByRole('button', { name: /报\s*工/ })
      .click()
    await expect(page).toHaveURL(/\/production\/reports/)
    const steps = page.locator('.el-step__description')
    await expect(steps).toHaveCount(5)
    const inputs = page.locator('.report-card .el-input-number input')
    await inputs.nth(0).fill('6')
    await inputs.nth(2).fill('2')
    await page.getByRole('button', { name: /提\s*交报工/ }).click()
    await expect(page.locator('.el-message--success').last()).toContainText('报工成功')
    await expect(steps.nth(0)).toHaveText('已完成')
    // API 权威：OP10 完成 → OP20/OP40 进行中；OP30 待 OP20 供料完成后推进，OP50 待开工
    const graphStatus = async (no: string): Promise<number> => {
      const d = await apiGet(page, `/api/v1/production/orders/${osMoId}`)
      return (
        (d.graph.nodes as { node_no: string; status: number }[]).find((n) => n.node_no === no)
          ?.status ?? -1
      )
    }
    expect(await graphStatus('OP10')).toBe(2)
    for (const no of ['OP20', 'OP40']) expect(await graphStatus(no)).toBe(1)
    expect(await graphStatus('OP30')).toBe(0)
    expect(await graphStatus('OP50')).toBe(0)
    // OP20/OP40 报满（API）→ OP30（委外）进行中、OP50 仍待开工（前驱未全部完成，汇合不提前）
    const opId = async (no: string): Promise<number> => {
      const d = await apiGet(page, `/api/v1/production/orders/${osMoId}`)
      return (d.operations as { node_no: string; id: number }[]).find((o) => o.node_no === no)!.id
    }
    for (const no of ['OP20', 'OP40']) {
      const rp = await apiPost(page, `/api/v1/production/operations/${await opId(no)}/reports`, {
        qualified_qty: 6,
        defective_qty: 0,
        hours: 1,
      })
      expect(rp.code).toBe(0)
    }
    expect(await graphStatus('OP30')).toBe(1)
    expect(await graphStatus('OP50')).toBe(0)

    // —— 委外单新建（UI）：工单行「委 外」直达 → OP30 节点预填（组件应发自动折算）——
    await page.goto('/production/orders')
    await page
      .locator('.el-table__row', { hasText: osMoNo })
      .getByRole('button', { name: /委\s*外/ })
      .click()
    await expect(page).toHaveURL(/\/production\/outsourcings/)
    const osDialog = page.locator('.el-dialog')
    await expect(osDialog).toBeVisible()
    // 只显示委外工序（OP30，label=节点号.工序（产出：回收品）；工序名=种子 下料——本 spec 不补建工序）
    await osDialog.locator('.el-select', { hasText: '选择工序' }).click()
    await pickOption(page, 'OP30. 下料（产出：OS2半成品C）')
    // 节点预填区：回收品 + 可用量 + 组件表 2 行（原料/半成品B，应发初始 0）
    await expect(osDialog.locator('.prefill-block')).toBeVisible()
    await expect(osDialog.locator('.prefill-product')).toHaveText('OS2半成品C')
    await expect(osDialog.locator('.prefill-meta .remain-cell')).toContainText('6')
    const itemRows = osDialog.locator('.item-table .el-table__row')
    await expect(itemRows).toHaveCount(2)
    await expect(itemRows.nth(0)).toContainText('OS2原料')
    await expect(itemRows.nth(1)).toContainText('OS2半成品B')
    // 数量 6 → blur 触发应发重算：应发 = 数量×单位用量（原料×2 → 12，半成品B×1 → 6）
    const qtyFormItem = osDialog.locator('.el-form-item', { hasText: '数量' })
    await qtyFormItem.locator('.el-input-number input').fill('6')
    await qtyFormItem.locator('.el-input-number input').press('Tab')
    const itemInputs = osDialog.locator('.item-table .el-input-number input')
    await expect(itemInputs).toHaveCount(2)
    await expect(itemInputs.nth(0)).toHaveValue('12.00')
    await expect(itemInputs.nth(1)).toHaveValue('6.00')
    // 供应商/仓库/库位 → 保存 → OS 草稿
    await osDialog.locator('.el-select', { hasText: '选择供应商' }).click()
    await pickOption(page, '测试供应商（SUP-001）')
    await osDialog.locator('.el-select', { hasText: '选择仓库' }).click()
    await pickOption(page, '主仓')
    await osDialog.locator('.el-select', { hasText: '选择库位' }).click()
    await pickOption(page, 'A-01')
    await osDialog.getByRole('button', { name: /保\s*存/ }).click()
    await expect(page.locator('.el-message--success')).toContainText('保存成功')
    // 列表行（弹窗预填组件表的「OS2原料…」行也在 DOM，须按完整单号前缀 OS+15 位数字定位）
    const osRow = page.locator('.el-table__row', { hasText: /^OS\d{15}/ })
    await expect(osRow).toContainText('草稿')
    // 新口径列表列：委外工序=节点号+工序名、回收品=节点输出
    await expect(osRow).toContainText('OP30下料')
    await expect(osRow).toContainText('OS2半成品C')
    osNo = (await osRow.locator('td').first().textContent())?.trim() ?? ''
    expect(osNo).toMatch(/^OS\d{12}\d{3}$/)

    // —— 发出（审核）：confirm「库存将减少」→ 组件扣减 + outsourcing_out 流水 + issued_qty 回写 ——
    await osRow.getByRole('button', { name: /审\s*核/ }).click()
    await expect(page.locator('.el-message-box')).toContainText('库存将减少')
    await page
      .locator('.el-message-box')
      .getByRole('button', { name: /确\s*定/ })
      .click()
    await expect(page.locator('.el-message--success').last()).toContainText('发出成功')
    await expect(page.locator('.el-table__row', { hasText: osNo })).toContainText('已审核')
    const osList = await apiGet(page, '/api/v1/production/outsourcings', { keyword: osNo })
    osId = osList.items[0].id as number
    // 组件余额减（基线 − 应发：原料 −12、半成品B −6）
    expect(await totalBalance(page, 'OS2原料')).toBe(rawBase - 12)
    expect(await totalBalance(page, 'OS2半成品B')).toBe(bBase - 6)
    // outsourcing_out 流水：原料 -12 / 半成品B -6（变动后余额归零），source_no=委外单号
    const mvOut = await apiGet(page, '/api/v1/inventory/movements', {
      source_type: 'outsourcing_out',
      source_no: osNo,
    })
    expect(mvOut.total).toBe(2)
    for (const it of mvOut.items as {
      product_name: string
      direction: number
      quantity: number
      balance_after: number
    }[]) {
      expect(it.direction).toBe(-1)
      // 余额增量断言（变动后余额 = 基线 − 应发）：与文件内 rawBase/bBase 基线变量同口径，重跑幂等
      expect(Number(it.balance_after)).toBe(
        it.product_name === 'OS2原料' ? rawBase - 12 : bBase - 6,
      )
    }
    const qtyByName = (name: string) =>
      Number(
        (mvOut.items as { product_name: string; quantity: number }[]).find(
          (i) => i.product_name === name,
        )?.quantity ?? 0,
      )
    expect(qtyByName('OS2原料')).toBe(12)
    expect(qtyByName('OS2半成品B')).toBe(6)
    // 详情：组件实发=应发（原料 12/半成品B 6）
    const osDetail = await apiGet(page, `/api/v1/production/outsourcings/${osId}`)
    expect(osDetail.status_label).toBe('已审核')
    expect(osDetail.received_qty).toBe('0.00')
    expect((osDetail.items as unknown[]).length).toBe(2)
    for (const it of osDetail.items as {
      material_name: string
      required_qty: string
      issued_qty: string
    }[]) {
      expect(Number(it.required_qty)).toBe(it.material_name === 'OS2原料' ? 12 : 6)
      expect(Number(it.issued_qty)).toBe(it.material_name === 'OS2原料' ? 12 : 6)
    }

    // —— 分批回收（UI）：第一次 2（未满量仍已审核）→ 第二次 4（满量已回收，节点联动）——
    await page
      .locator('.el-table__row', { hasText: osNo })
      .getByRole('button', { name: '回 收', exact: true })
      .click()
    const rc = page.locator('.el-dialog', { hasText: '委外回收' })
    await expect(rc).toBeVisible()
    await expect(rc.locator('.receipt-product')).toHaveText('OS2半成品C')
    await expect(rc.locator('.remain-cell').first()).toContainText('6')
    await rc.locator('.el-input-number input').fill('2')
    await rc.locator('.el-select', { hasText: '选择仓库' }).click()
    await pickOption(page, '主仓')
    await rc.locator('.el-select', { hasText: '选择库位' }).click()
    await pickOption(page, 'A-01')
    await rc.getByRole('button', { name: /提\s*交回收/ }).click()
    await expect(page.locator('.el-message--success').last()).toContainText('回收成功')
    // 未满量：状态仍已审核，已回收累计 2
    await expect(page.locator('.el-table__row', { hasText: osNo })).toContainText('已审核')
    const osAfter1 = await apiGet(page, `/api/v1/production/outsourcings/${osId}`)
    expect(Number(osAfter1.received_qty)).toBe(2)
    expect(await totalBalance(page, 'OS2半成品C')).toBe(2)
    // 第二次 4 → 满量已回收
    await page
      .locator('.el-table__row', { hasText: osNo })
      .getByRole('button', { name: '回 收', exact: true })
      .click()
    const rc2 = page.locator('.el-dialog', { hasText: '委外回收' })
    await expect(rc2.locator('.remain-cell').first()).toContainText('4')
    await rc2.locator('.el-input-number input').fill('4')
    await rc2.locator('.el-select', { hasText: '选择仓库' }).click()
    await pickOption(page, '主仓')
    await rc2.locator('.el-select', { hasText: '选择库位' }).click()
    await pickOption(page, 'A-01')
    await rc2.getByRole('button', { name: /提\s*交回收/ }).click()
    await expect(page.locator('.el-message--success').last()).toContainText('回收成功')
    await expect(page.locator('.el-table__row', { hasText: osNo })).toContainText('已回收')
    // API 权威：累计回收 6 + outsourcing_in 流水（第二次 +4，变动后余额 6）+ 回收记录 2 条
    const osAfter2 = await apiGet(page, `/api/v1/production/outsourcings/${osId}`)
    expect(Number(osAfter2.received_qty)).toBe(6)
    expect(osAfter2.status_label).toBe('已回收')
    expect(await totalBalance(page, 'OS2半成品C')).toBe(6)
    const receipts = await apiGet(page, `/api/v1/production/outsourcings/${osId}/receipts`)
    expect(receipts.total).toBe(2)
    expect((receipts.items as { no: string; quantity: string }[])[0].quantity).toBe('4.00')
    expect((receipts.items as { no: string; quantity: string }[])[1].quantity).toBe('2.00')
    const rcNo = (receipts.items as { no: string }[])[0].no
    expect(rcNo).toMatch(/^OSR\d{12}\d{3}$/)
    const mvIn = await apiGet(page, '/api/v1/inventory/movements', {
      source_type: 'outsourcing_in',
      source_no: rcNo,
    })
    expect(mvIn.total).toBe(1)
    expect(mvIn.items[0].direction).toBe(1)
    expect(Number(mvIn.items[0].quantity)).toBe(4)
    expect(Number(mvIn.items[0].balance_after)).toBe(6)
    // 节点联动：委外节点经回收完成（DONE）+ 全部前驱已完成 → 汇合点 OP50 进行中
    expect(await graphStatus('OP30')).toBe(2)
    expect(await graphStatus('OP50')).toBe(1)
  })

  test('TC-OS-02 余料退回（超退拦截 + 全退自动关闭）', async ({ page }) => {
    await loginByAPI(page, 'admin', 'admin123')
    // 接 TC-OS-01：委外单已发出（原料已发 12/半成品B 已发 6）且已回收（status 2）→「退回余料」入口可用
    await page.goto('/production/outsourcings')
    const osRow = page.locator('.el-table__row', { hasText: osNo })
    await expect(osRow).toContainText('已回收')
    await osRow.getByRole('button', { name: '退回余料' }).click()
    const dialog = page.locator('.el-dialog', { hasText: '余料退回' })
    await expect(dialog).toBeVisible()
    // 明细行：原料 已发12/可退12，半成品B 已发6/可退6（可退为 0 的行不渲染，两行都在）
    await expect(dialog.locator('.el-table__row')).toHaveCount(2)
    await expect(dialog.locator('.el-table__row').nth(0)).toContainText('OS2原料')
    await expect(dialog.locator('.el-table__row').nth(1)).toContainText('OS2半成品B')
    const returnInputs = dialog.locator('.el-table .el-input-number input')
    await expect(returnInputs).toHaveCount(2)
    // 超退拦截（前端 on-blur）：退回量 13 > 可退 12 → warning + 值回弹 12（422 精确消息走后端 API 断言）
    await returnInputs.nth(0).fill('13')
    await returnInputs.nth(0).press('Tab')
    await expect(page.locator('.el-message--warning').last()).toContainText(
      '退回数量超过已发未退数量',
    )
    await expect(returnInputs.nth(0)).toHaveValue('12.00')
    // 超退拦截（后端 422）：同 13 载荷 → 整单回滚（无流水产生）
    const detail = await apiGet(page, `/api/v1/production/outsourcings/${osId}`)
    const itemRaw = (detail.items as { id: number; material_name: string }[]).find(
      (i) => i.material_name === 'OS2原料',
    )
    expect(itemRaw).toBeTruthy()
    const over = await apiPost(page, `/api/v1/production/outsourcings/${osId}/returns`, {
      items: [{ item_id: (itemRaw as { id: number }).id, quantity: 13 }],
      warehouse_id: whId,
      location_id: a01Id,
    })
    expect(over.code).toBe(422)
    expect(over.message).toBe('退回数量超过已发未退数量')
    // 退回量=可退（原料 12/半成品B 6）→ 提交 → 全部组件退回后委外单自动关闭（列表 tag 已关闭）
    await returnInputs.nth(0).fill('12')
    await returnInputs.nth(1).fill('6')
    await dialog.locator('.el-select', { hasText: '选择仓库' }).click()
    await pickOption(page, '主仓')
    await dialog.locator('.el-select', { hasText: '选择库位' }).click()
    await pickOption(page, 'A-01')
    await dialog.getByRole('button', { name: /提\s*交退回/ }).click()
    await expect(page.locator('.el-message--success').last()).toContainText('退回成功')
    await expect(page.locator('.el-table__row', { hasText: osNo })).toContainText('已关闭')
    // API 权威：returned_qty 回写 12/6 + 委外单已关闭 + 库存回补基线
    const after = await apiGet(page, `/api/v1/production/outsourcings/${osId}`)
    expect(after.status).toBe(3)
    expect(after.status_label).toBe('已关闭')
    for (const it of after.items as {
      material_name: string
      issued_qty: string
      returned_qty: string
    }[]) {
      expect(Number(it.issued_qty)).toBe(it.material_name === 'OS2原料' ? 12 : 6)
      expect(Number(it.returned_qty)).toBe(it.material_name === 'OS2原料' ? 12 : 6)
    }
    expect(await totalBalance(page, 'OS2原料')).toBe(rawBase)
    expect(await totalBalance(page, 'OS2半成品B')).toBe(bBase)
    // outsourcing_return 流水：原料 +12 / 半成品B +6（source_no=退回单号 ORT；多行提交仅记首行——偏离记录③）
    const retList = await apiGet(page, `/api/v1/production/outsourcings/${osId}/returns`)
    expect(retList.total).toBe(1)
    const retNo = (retList.items as { no: string }[])[0].no
    expect(retNo).toMatch(/^ORT\d{12}\d{3}$/)
    const mvR = await apiGet(page, '/api/v1/inventory/movements', { source_no: retNo })
    expect(mvR.total).toBe(2)
    for (const it of mvR.items as { direction: number; quantity: number }[]) {
      expect(it.direction).toBe(1)
    }
    const mvQtyByName = (name: string) =>
      Number(
        (mvR.items as { product_name: string; quantity: number }[]).find(
          (i) => i.product_name === name,
        )?.quantity ?? 0,
      )
    expect(mvQtyByName('OS2原料')).toBe(12)
    expect(mvQtyByName('OS2半成品B')).toBe(6)
    const mvRaw = (mvR.items as { product_name: string; balance_after: number }[]).find(
      (i) => i.product_name === 'OS2原料',
    )
    expect(mvRaw).toBeTruthy()
    expect(Number((mvRaw as { balance_after: number }).balance_after)).toBe(rawBase)
  })

  test('TC-OS-03 委外节点不可报工（1509）', async ({ page }) => {
    await loginByAPI(page, 'admin', 'admin123')
    // 自建新工单（TC-OS-01 的委外节点已回收完成，报工会命中「已完成」前置拦截）：
    // 沿用 TC-OS-01 路线，OP10 报满后经 OP20 报满推进 OP30（委外节点）进行中，直接报工 → 1509 委外专属消息
    const mo = await apiPost(page, '/api/v1/production/orders', {
      product_id: finId,
      quantity: 6,
      plan_date: todayStr(),
    })
    expect(mo.code).toBe(0)
    const moId = (mo.data as { id: number }).id
    const rel = await apiPost(page, `/api/v1/production/orders/${moId}/release`)
    expect(rel.code).toBe(0)
    const st = await apiPost(page, `/api/v1/production/orders/${moId}/start`)
    expect(st.code).toBe(0)
    const detail = await apiGet(page, `/api/v1/production/orders/${moId}`)
    const opId = (no: string) =>
      (detail.operations as { node_no: string; id: number }[]).find((o) => o.node_no === no)!.id
    // OP10 报满 → OP20 进行中；OP20 报满 → OP30（委外节点）进行中（前驱 OP20 已完）
    for (const no of ['OP10', 'OP20']) {
      const rp = await apiPost(page, `/api/v1/production/operations/${opId(no)}/reports`, {
        qualified_qty: 6,
        defective_qty: 0,
        hours: 1,
      })
      expect(rp.code).toBe(0)
    }
    // 委外节点不可报工 → 1509（进度只能经委外单回收回写）
    const outsourced = await apiPost(
      page,
      `/api/v1/production/operations/${opId('OP30')}/reports`,
      {
        qualified_qty: 1,
        defective_qty: 0,
        hours: 1,
      },
    )
    expect(outsourced.code).toBe(1509)
    expect(outsourced.message).toBe('委外工序不可报工，经委外单回收完成')
  })

  test('TC-OS-04 工序网络委外节点联动委外页', async ({ page }) => {
    await loginByAPI(page, 'admin', 'admin123')
    // 工单详情 → 工序网络 → 委外节点「委 外 单」→ 委外单弹窗（含 TC-OS-01 单）→ 打开委外页定位
    await page.goto('/production/orders')
    const row = page.locator('.el-table__row', { hasText: osMoNo })
    await row.getByRole('button', { name: /详\s*情/ }).click()
    const ddialog = page.locator('.el-dialog', { hasText: '工单详情' })
    await ddialog.getByRole('tab', { name: '工序网络' }).click()
    const op30 = ddialog.locator('.og-node', { hasText: /OP30 ·/ })
    await expect(op30.locator('.og-badge')).toHaveText('委外')
    await op30.getByRole('button', { name: /委\s*外\s*单/ }).click()
    // 弹窗列表：含 TC-OS-01 委外单（单号/供应商；状态=已关闭——TC-OS-02 全退后关闭）
    const osDialog = page.locator('.el-dialog', { hasText: '委外单' })
    await expect(osDialog).toBeVisible()
    const osRow = osDialog.locator('.el-table__row', { hasText: osNo })
    await expect(osRow).toContainText('测试供应商')
    await expect(osRow).toContainText('已关闭')
    // 打开委外页 → 按单号直达列表，行仍在（已关闭）
    await osRow.getByRole('button', { name: '打开委外页' }).click()
    await expect(page).toHaveURL(/\/production\/outsourcings\?keyword=/)
    const listRow = page.locator('.el-table__row', { hasText: osNo })
    await expect(listRow).toContainText('已关闭')
    // BF-1 回归：委外页消费 keyword 参数——按单号过滤后列表仅剩目标单（此前不消费参数展示全量列表，定位名存实亡）
    await expect(page.locator('.el-table__row')).toHaveCount(1)
  })
})
