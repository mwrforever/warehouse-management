// 生产管理模块 E2E：TC-PRD-01~10 完整生产闭环（建工单→下达→开工→领料→报工→委外→成品入库→退料→关闭）
// + 边界拦截（超领/超收/虚报/库存不足）+ 幂等验证 + TC-MST-1113 工序删除保护补测
// 种子基线（InventorySeeder）：MAT-001=100@A-01、SEMI-001=30@A-01、FIN-002=20@B-01（主仓）
// BOM/工序/供应商无种子 → TC-PRD-01 用 API 幂等自建（镜像销售客户自建模式）：
//   BOM(FIN-002, 启用版, MAT-001×2)；工序 下料/组装/质检（sort 1/2/3，BOM 展开按 sort 生成工序序列）；
//   供应商 SUP-001（委外用）；TC-PRD-10 清空/补回 MAT-001 与 TC-PRD-07 复原 FIN-002 均走库存盘点
//   （原料禁售不可销售出库；采购入库会留已审核 PI 行，破坏后跑 purchase.spec 的单 PI 行假设）
// 库存末态随库存/采购/前置用例变化 → 一律记录「当时余额」P₁/F₁ 按增量断言（balances 按 商品×仓库×库位 分行，汇总求和）
// 委外发出仓库/库位为 主仓/B-01（FIN-002 种子所在库位）：文档 TC-PRD-06 原写 A-01，但 FIN-002 无 A-01 余额，
// 且采购入库补库存会留下已审核 PI 行、破坏全量 E2E 中后跑的 purchase.spec（TC-PUR-03 假设「PI」行唯一），
// 销售用例同样假设 FIN-002 单行余额 → 委外发出/回收均走 B-01（净变动为 0，见文档 §5 注）
// 定位方式同 sales.spec：el-select 外壳点击 + getByRole('option') 唯一匹配
import { expect, test, type Page } from '@playwright/test'
import { loginByAPI } from './helpers'

// 已登录页面的认证请求辅助：token 取自 localStorage（与 sales.spec 同构）
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
// PUT 请求辅助：草稿更新类接口（退料单等）走 PUT 方法
async function apiPut(page: Page, url: string, body?: unknown) {
  const token = await page.evaluate(() => localStorage.getItem('token'))
  const res = await page.request.put(url, {
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
// 指定库位行余额（领料/委外/成品入库落在固定库位，流水「变动后余额」断言数据源）
async function locBalance(page: Page, keyword: string, locId: number): Promise<number> {
  const rows = await apiGet(page, '/api/v1/inventory/balances', { keyword })
  const row = (rows.items as { location_id: number; quantity: number }[]).find(
    (r) => r.location_id === locId,
  )
  return row ? Number(row.quantity) : 0
}

// 当日日期字符串（工单计划日期默认今天，按本地时区拼装）
function todayStr(): string {
  const d = new Date()
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

test.describe('生产管理模块 E2E（TC-PRD-01~10 + 1113 补测）', () => {
  // 用例间库存/单据状态相互依赖，串行执行（生产闭环链路长，放宽单用例超时）
  test.describe.configure({ mode: 'serial', timeout: 90_000 })

  // 用例共享（describe 顶层声明，用例间传递）：工单 id/单号、余额基线、单据单号、主数据 id
  let mo1Id = 0
  let mo2Id = 0
  let mo3Id = 0
  let mo1No = ''
  let mo2No = ''
  let mo3No = ''
  // 余额基线（TC-PRD-01 数据准备后记录，按「当时余额」增量断言）：
  // P₁/P₁a MAT-001 总余额/A-01 行；F₁/F₁b FIN-002 总余额/B-01 行
  let p1 = 0
  let p1a = 0
  let f1 = 0
  let f1b = 0
  // 单据单号：领料 PL / 退料 RL / 委外 OS / 委外回收 OSR / 成品入库 FI
  let plNo = ''
  let rlNo = ''
  let osNo = ''
  let osrNo = ''
  let fiNo = ''
  // 主数据 id（TC-PRD-01 幂等准备）
  let matId = 0
  let finId = 0
  let semiId = 0
  let pcId = 0
  let supId = 0
  let whId = 0
  let a01Id = 0
  let b01Id = 0

  test('TC-PRD-01 工单创建与 BOM 展开', async ({ page }) => {
    await loginByAPI(page, 'admin', 'admin123')
    // —— 数据准备（幂等，镜像销售 TC-SAL-01 客户自建模式）——
    // 商品 id（原料/成品/半成品，1501 边界用例用半成品）
    const prods = await apiGet(page, '/api/v1/products', { keyword: 'MAT-001', per_page: 100 })
    matId = prods.items[0].id as number
    const finProds = await apiGet(page, '/api/v1/products', { keyword: 'FIN-002', per_page: 100 })
    finId = finProds.items[0].id as number
    const semiProds = await apiGet(page, '/api/v1/products', { keyword: 'SEMI-001', per_page: 100 })
    semiId = semiProds.items[0].id as number
    // 计量单位 pc（BOM 明细必填 unit_id）
    const units = await apiGet(page, '/api/v1/units', { per_page: 100 })
    pcId = (units.items as { code: string; id: number }[]).find((u) => u.code === 'pc')?.id ?? 0
    expect(pcId).toBeGreaterThan(0)
    // 工序：种子仅 下料(PROC-01) → 补齐 组装/质检（sort 2/3，BOM 展开按 sort 升序生成工序序列）
    const procList = await apiGet(page, '/api/v1/processes')
    const procCodes = (procList.items as { code: string }[]).map((p) => p.code)
    for (const p of [
      { name: '下料', code: 'PROC-01', sort: 1 },
      { name: '组装', code: 'PROC-02', sort: 2 },
      { name: '质检', code: 'PROC-03', sort: 3 },
    ]) {
      if (!procCodes.includes(p.code)) {
        const created = await apiPost(page, '/api/v1/processes', p)
        expect(created.code).toBe(0)
      }
    }
    // 供应商 SUP-001（委外/补库存数据源；采购模块已建则复用）
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
    // 客户 CUS-001（销售模块后跑自建，本模块不用——MAT-001 为原料禁售，TC-PRD-10 清空库存走盘点而非销售出库）
    // BOM(FIN-002, 启用版)：MAT-001×2/成品（启用版本唯一，已存在则复用）
    const boms = await apiGet(page, '/api/v1/boms', { product_id: finId, per_page: 100 })
    if (!(boms.items as { status: number }[]).some((b) => b.status === 1)) {
      const bom = await apiPost(page, '/api/v1/boms', {
        product_id: finId,
        version: 'V1',
        quantity: 1,
        status: 1,
        items: [{ material_id: matId, quantity: 2, unit_id: pcId }],
      })
      expect(bom.code).toBe(0)
    }
    // 仓库/库位 id（主仓 A-01/B-01）
    const whs = await apiGet(page, '/api/v1/warehouses', { keyword: '主仓' })
    whId = whs.items[0].id as number
    const locs = await apiGet(page, `/api/v1/warehouses/${whId}/locations`)
    a01Id = (locs.items as { code: string; id: number }[]).find((l) => l.code === 'A-01')?.id ?? 0
    b01Id = (locs.items as { code: string; id: number }[]).find((l) => l.code === 'B-01')?.id ?? 0
    expect(a01Id).toBeGreaterThan(0)
    expect(b01Id).toBeGreaterThan(0)
    // 记录当时余额基线（后续增量断言；前置模块已消耗的库存一并计入）
    // 注：不做 FIN-002@A-01 采购入库补库存——已审核 PI 行会破坏后跑 purchase.spec 的单 PI 行假设
    p1 = await totalBalance(page, 'MAT-001')
    p1a = await locBalance(page, 'MAT-001', a01Id)
    f1 = await totalBalance(page, 'FIN-002')
    f1b = await locBalance(page, 'FIN-002', b01Id)
    // —— 工单创建（UI）——
    await page.goto('/production/orders')
    await page.getByRole('button', { name: /新\s*建/ }).click()
    const dialog = page.locator('.el-dialog')
    await dialog.locator('.el-select', { hasText: '选择成品' }).click()
    // 成品下拉仅成品：无 MAT-001（原料）选项，FIN-002 可选（BOM 启用校验通过）
    await expect(page.getByRole('option', { name: /MAT-001/ })).toHaveCount(0)
    await pickOption(page, '成品B（FIN-002）')
    await dialog.locator('.el-input-number input').fill('10')
    await dialog.getByRole('button', { name: /保\s*存/ }).click()
    // 新建成功无「保存成功」提示（OrdersView 创建分支直接进入 BOM 展开确认弹窗）→ 断言展开弹窗
    // BOM 展开确认弹窗：物料需求 MAT-001×20 + 工序序列 3 行（下料/组装/质检）
    const exp = page.locator('.el-dialog', { hasText: 'BOM 展开确认' })
    await expect(exp).toBeVisible()
    const expTables = exp.locator('.data-table')
    await expect(expTables.nth(0).locator('.el-table__row')).toHaveCount(1)
    await expect(expTables.nth(0).locator('.el-table__row', { hasText: 'MAT-001' })).toContainText(
      '20',
    )
    await expect(expTables.nth(1).locator('.el-table__row')).toHaveCount(3)
    await expect(expTables.nth(1).locator('.el-table__row').nth(0)).toContainText('下料')
    await exp.getByRole('button', { name: /确\s*定/ }).click()
    // 列表出现 MO 草稿
    const row = page.locator('.el-table__row', { hasText: 'MO' })
    await expect(row).toContainText('草稿')
    mo1No = (await row.locator('td').first().textContent())?.trim() ?? ''
    expect(mo1No).toMatch(/^MO\d{12}\d{3}$/)
    // 详情（API 精确断言）：物料需求 20 + 工序 3 行待开工
    const list = await apiGet(page, '/api/v1/production/orders', { keyword: mo1No })
    mo1Id = list.items[0].id as number
    const detail = await apiGet(page, `/api/v1/production/orders/${mo1Id}`)
    expect(detail.materials[0].material_code).toBe('MAT-001')
    expect(Number(detail.materials[0].required_qty)).toBe(20)
    expect(detail.operations).toHaveLength(3)
    expect(detail.operations.every((o: { status: number }) => o.status === 0)).toBeTruthy()
    // 边界：无 BOM 成品（SEMI-001）建工单 → 1501
    const noBom = await apiPost(page, '/api/v1/production/orders', {
      product_id: semiId,
      quantity: 10,
      plan_date: todayStr(),
    })
    expect(noBom.code).toBe(1501)
    expect(noBom.message).toBe('该成品没有启用版本的 BOM')
  })

  test('TC-PRD-02 下达与开工', async ({ page }) => {
    await loginByAPI(page, 'admin', 'admin123')
    await page.goto('/production/orders')
    const row = page.locator('.el-table__row', { hasText: mo1No })
    // 下达：confirm → 成功（MAT-001 充足 → 无缺料警告条）
    await row.getByRole('button', { name: /下\s*达/ }).click()
    await expect(page.locator('.el-message-box')).toContainText('确认下达工单')
    await page
      .locator('.el-message-box')
      .getByRole('button', { name: /确\s*定/ })
      .click()
    await expect(page.locator('.el-message--success').last()).toContainText('下达成功')
    await expect(page.locator('.el-table__row', { hasText: mo1No })).toContainText('已下达')
    // 幂等：重复下达 → 1505
    const rel2 = await apiPost(page, `/api/v1/production/orders/${mo1Id}/release`)
    expect(rel2.code).toBe(1505)
    expect(rel2.message).toBe('工单已下达')
    // 开工：confirm → 生产中；首工序（下料）进行中
    await row.getByRole('button', { name: /开\s*工/ }).click()
    await expect(page.locator('.el-message-box')).toContainText('确认开工工单')
    await page
      .locator('.el-message-box')
      .getByRole('button', { name: /确\s*定/ })
      .click()
    await expect(page.locator('.el-message--success').last()).toContainText('开工成功')
    await expect(page.locator('.el-table__row', { hasText: mo1No })).toContainText('生产中')
    // 幂等：重复开工 → 1506；首工序进行中
    const start2 = await apiPost(page, `/api/v1/production/orders/${mo1Id}/start`)
    expect(start2.code).toBe(1506)
    const detail = await apiGet(page, `/api/v1/production/orders/${mo1Id}`)
    expect(detail.operations[0].status).toBe(1)
  })

  test('TC-PRD-03 领料审核与发料（原料扣减）', async ({ page }) => {
    await loginByAPI(page, 'admin', 'admin123')
    await page.goto('/production/orders')
    const row = page.locator('.el-table__row', { hasText: mo1No })
    // 工单详情「领 料」→ 领料页弹窗自动预填：MAT-001 需求 20 / 剩余 20 / 本次领用 20
    await row.getByRole('button', { name: /领\s*料/ }).click()
    await expect(page).toHaveURL(/\/production\/picks/)
    const dialog = page.locator('.el-dialog')
    await expect(dialog).toBeVisible()
    await expect(dialog.locator('.el-table__row', { hasText: 'MAT-001' })).toContainText('20')
    const qtyInput = dialog.locator('.el-input-number input')
    await expect(qtyInput).toHaveValue('20.00')
    // 超领拦截（前端 on-blur 上限校验：el-input-number 的 :max 在 blur 时静默钳制回剩余值，
    // 组件 @blur 处理器已拿不到超量值 → 只断言值回弹；1513 精确消息走后端 API）
    await qtyInput.fill('25')
    await qtyInput.press('Tab')
    await expect(qtyInput).toHaveValue('20.00')
    // 超领拦截（后端草稿期 1513）
    const over = await apiPost(page, '/api/v1/production/picks', {
      order_id: mo1Id,
      warehouse_id: whId,
      location_id: a01Id,
      items: [{ product_id: matId, pick_qty: 25 }],
    })
    expect(over.code).toBe(1513)
    expect(over.message).toBe('领料数量超过需求数量')
    // 仓库主仓/A-01 → 保存 → PL 草稿
    await dialog.locator('.el-select', { hasText: '选择仓库' }).click()
    await pickOption(page, '主仓')
    await dialog.locator('.el-select', { hasText: '选择库位' }).click()
    await pickOption(page, 'A-01')
    await dialog.getByRole('button', { name: /保\s*存/ }).click()
    await expect(page.locator('.el-message--success')).toContainText('保存成功')
    const plRow = page.locator('.el-table__row', { hasText: 'PL' })
    await expect(plRow).toContainText('草稿')
    plNo = (await plRow.locator('td').first().textContent())?.trim() ?? ''
    expect(plNo).toMatch(/^PL\d{12}\d{3}$/)
    // 审核：confirm「库存将减少」→ 成功；余额 = P₁-20
    await plRow.getByRole('button', { name: /审\s*核/ }).click()
    await expect(page.locator('.el-message-box')).toContainText('审核后库存将减少')
    await page
      .locator('.el-message-box')
      .getByRole('button', { name: /确\s*定/ })
      .click()
    await expect(page.locator('.el-message--success').last()).toContainText('审核成功')
    expect(await totalBalance(page, 'MAT-001')).toBe(p1 - 20)
    // 流水：pick -20，变动后余额 = P₁a-20
    const mv = await apiGet(page, '/api/v1/inventory/movements', {
      source_type: 'pick',
      source_no: plNo,
    })
    expect(mv.total).toBe(1)
    expect(mv.items[0].direction).toBe(-1)
    expect(Number(mv.items[0].quantity)).toBe(20)
    expect(Number(mv.items[0].balance_after)).toBe(p1a - 20)
    // 流水页筛「领料出库」：-20 流水、单号 PL、变动后余额
    await page.goto('/inventory/movements')
    await page.locator('.filter-bar').getByText('单据类型', { exact: true }).click()
    await pickOption(page, '领料出库')
    await page.getByRole('button', { name: /查\s*询/ }).click()
    const mvRow = page.locator('.el-table__row', { hasText: '测试铝材' }).first()
    await expect(mvRow).toContainText('-')
    await expect(mvRow).toContainText('20')
    await expect(mvRow).toContainText(String(p1a - 20))
    await expect(mvRow).toContainText(plNo)
    // 发料：confirm → 全部发料
    await page.goto('/production/picks')
    const plRow2 = page.locator('.el-table__row', { hasText: plNo })
    await expect(plRow2).toContainText('已审核')
    await plRow2.getByRole('button', { name: /发\s*料/ }).click()
    await page
      .locator('.el-message-box')
      .getByRole('button', { name: /确\s*定/ })
      .click()
    await expect(page.locator('.el-message--success').last()).toContainText('发料成功')
    await expect(page.locator('.el-table__row', { hasText: plNo })).toContainText('全部发料')
    // 工单详情物料 tab：已领 20 剩余 0（需求回写）
    const moDetail = await apiGet(page, `/api/v1/production/orders/${mo1Id}`)
    expect(Number(moDetail.materials[0].issued_qty)).toBe(20)
    expect(Number(moDetail.materials[0].remaining_qty)).toBe(0)
  })

  test('TC-PRD-04 工序报工与自动流转', async ({ page }) => {
    await loginByAPI(page, 'admin', 'admin123')
    await page.goto('/production/orders')
    const row = page.locator('.el-table__row', { hasText: mo1No })
    await row.getByRole('button', { name: /报\s*工/ }).click()
    await expect(page).toHaveURL(/\/production\/reports/)
    // 步骤条初始：下料=进行中
    const steps = page.locator('.el-step__description')
    await expect(steps).toHaveCount(3)
    await expect(steps.nth(0)).toHaveText('进行中')
    await expect(steps.nth(1)).toHaveText('待开工')
    await expect(steps.nth(2)).toHaveText('待开工')
    const inputs = page.locator('.report-card .el-input-number input')
    const submitBtn = page.getByRole('button', { name: /提\s*交报工/ })
    // 下料报工：合格 10 不良 0 工时 2.5 → 下料完成、组装自动进行中
    await inputs.nth(0).fill('10')
    await inputs.nth(2).fill('2.5')
    await submitBtn.click()
    await expect(page.locator('.el-message--success').last()).toContainText('报工成功')
    await expect(steps.nth(0)).toHaveText('已完成')
    await expect(steps.nth(1)).toHaveText('进行中')
    // 组装报 4（累计 4<计划 10）→ 仍进行中
    // 注意：上一次报工保存结束前按钮处于 loading，EP 按钮会吞掉点击 → 先等 loading 清除再继续
    await expect(submitBtn).not.toHaveClass(/is-loading/)
    await inputs.nth(0).fill('4')
    await inputs.nth(2).fill('1')
    await submitBtn.click()
    await expect(page.locator('.el-message--success').last()).toContainText('报工成功')
    await expect(steps.nth(1)).toHaveText('进行中')
    await expect(steps.nth(2)).toHaveText('待开工')
    // 组装再报 6（累计 10）→ 组装完成、质检进行中
    await expect(submitBtn).not.toHaveClass(/is-loading/)
    await inputs.nth(0).fill('6')
    await inputs.nth(2).fill('1')
    await submitBtn.click()
    await expect(page.locator('.el-message--success').last()).toContainText('报工成功')
    await expect(steps.nth(1)).toHaveText('已完成')
    await expect(steps.nth(2)).toHaveText('进行中')
    // 质检报 10 工时 0.5 → 全部完成
    await expect(submitBtn).not.toHaveClass(/is-loading/)
    await inputs.nth(0).fill('10')
    await inputs.nth(2).fill('0.5')
    await submitBtn.click()
    await expect(page.locator('.el-message--success').last()).toContainText('报工成功')
    await expect(page.locator('.el-empty')).toContainText('工序已全部完成')
    // 报工记录：下料工序 1 条（合格 10/不良 0/工时 2.5）
    const moDetail = await apiGet(page, `/api/v1/production/orders/${mo1Id}`)
    const op1 = moDetail.operations[0] as { id: number }
    const reports = await apiGet(page, `/api/v1/production/operations/${op1.id}/reports`)
    expect(reports.total).toBe(1)
    expect(Number(reports.items[0].qualified_qty)).toBe(10)
    expect(Number(reports.items[0].defective_qty)).toBe(0)
    expect(Number(reports.items[0].hours)).toBe(2.5)
  })

  test('TC-PRD-05 报工边界拦截', async ({ page }) => {
    await loginByAPI(page, 'admin', 'admin123')
    const mo1Detail = await apiGet(page, `/api/v1/production/orders/${mo1Id}`)
    const op3 = mo1Detail.operations[2] as { id: number } // 质检（已完成）
    // 已完成工序再报工 → 1509
    const done = await apiPost(page, `/api/v1/production/operations/${op3.id}/reports`, {
      qualified_qty: 1,
      defective_qty: 0,
      hours: 0.1,
    })
    expect(done.code).toBe(1509)
    expect(done.message).toBe('该工序当前不可报工')
    // 建 MO-002（FIN-002×5）→ 下达（MAT-001 充足 → warnings 空）→ 开工（下料进行中）
    const mo2 = await apiPost(page, '/api/v1/production/orders', {
      product_id: finId,
      quantity: 5,
      plan_date: todayStr(),
    })
    expect(mo2.code).toBe(0)
    mo2No = (mo2.data as { no: string }).no
    const mo2List = await apiGet(page, '/api/v1/production/orders', { keyword: mo2No })
    mo2Id = mo2List.items[0].id as number
    const rel = await apiPost(page, `/api/v1/production/orders/${mo2Id}/release`)
    expect(rel.code).toBe(0)
    expect((rel.data as { warnings: unknown[] }).warnings).toHaveLength(0)
    const start = await apiPost(page, `/api/v1/production/orders/${mo2Id}/start`)
    expect(start.code).toBe(0)
    const mo2Detail = await apiGet(page, `/api/v1/production/orders/${mo2Id}`)
    const op1 = mo2Detail.operations[0] as { id: number } // 下料（进行中）
    // 合格 11 > 计划 5 → 1510（虚报防呆）
    const over = await apiPost(page, `/api/v1/production/operations/${op1.id}/reports`, {
      qualified_qty: 11,
      defective_qty: 0,
      hours: 0.5,
    })
    expect(over.code).toBe(1510)
    expect(over.message).toBe('合格数不能超过工单计划数量')
    // 合格 4 + 不良 5（合计 9 > 计划 5）→ 1511（文档原例 8+5/13>10 按 MO-001 计划数，本用例工单计划 5 调整为 4+5/9>5）
    const mix = await apiPost(page, `/api/v1/production/operations/${op1.id}/reports`, {
      qualified_qty: 4,
      defective_qty: 5,
      hours: 0.5,
    })
    expect(mix.code).toBe(1511)
    expect(mix.message).toBe('合格数与不良数合计不能超过工单计划数量')
    // 工时 -1 → 1512（业务码；合格/不良负数走 422 值域，见 PHPUnit）
    const neg = await apiPost(page, `/api/v1/production/operations/${op1.id}/reports`, {
      qualified_qty: 1,
      defective_qty: 0,
      hours: -1,
    })
    expect(neg.code).toBe(1512)
    expect(neg.message).toBe('工时不能为负数')
    // 前端 on-blur 校验：合格数 11 超计划 → 1510 文案警告
    await page.goto(`/production/reports?order_id=${mo2Id}`)
    const qInput = page.locator('.report-card .el-input-number input').nth(0)
    await qInput.fill('11')
    await qInput.press('Tab')
    await expect(page.locator('.el-message--warning').last()).toContainText(
      '合格数不能超过工单计划数量',
    )
  })

  test('TC-PRD-06 委外发出与回收', async ({ page }) => {
    await loginByAPI(page, 'admin', 'admin123')
    const mo2Detail = await apiGet(page, `/api/v1/production/orders/${mo2Id}`)
    const op2 = mo2Detail.operations[1] as { id: number } // 组装（待开工）
    // 委外量 > 工单计划数 → 1520（草稿期拦截）
    const over = await apiPost(page, '/api/v1/production/outsourcings', {
      order_id: mo2Id,
      operation_id: op2.id,
      supplier_id: supId,
      warehouse_id: whId,
      location_id: a01Id,
      quantity: 6,
    })
    expect(over.code).toBe(1520)
    expect(over.message).toBe('委外数量超过工单计划数量')
    // UI 新建委外单：MO-002 / 委外工序=组装 / SUP-001 / 数量 5 / 主仓 B-01（FIN-002 种子库位，见文件头注）
    await page.goto('/production/orders')
    const row = page.locator('.el-table__row', { hasText: mo2No })
    await row.getByRole('button', { name: /委\s*外/ }).click()
    await expect(page).toHaveURL(/\/production\/outsourcings/)
    const dialog = page.locator('.el-dialog')
    await expect(dialog).toBeVisible()
    await dialog.locator('.el-select', { hasText: '选择工序' }).click()
    await pickOption(page, '2. 组装')
    await dialog.locator('.el-select', { hasText: '选择供应商' }).click()
    await pickOption(page, '测试供应商（SUP-001）')
    await dialog.locator('.el-input-number input').fill('5')
    await dialog.locator('.el-select', { hasText: '选择仓库' }).click()
    await pickOption(page, '主仓')
    await dialog.locator('.el-select', { hasText: '选择库位' }).click()
    await pickOption(page, 'B-01')
    await dialog.getByRole('button', { name: /保\s*存/ }).click()
    await expect(page.locator('.el-message--success')).toContainText('保存成功')
    const osRow = page.locator('.el-table__row', { hasText: 'OS' })
    await expect(osRow).toContainText('草稿')
    osNo = (await osRow.locator('td').first().textContent())?.trim() ?? ''
    expect(osNo).toMatch(/^OS\d{12}\d{3}$/)
    // 审核（发出）：confirm「库存将减少」→ 成功；FIN-002 = F₁-5（outsourcing_out 流水）
    await osRow.getByRole('button', { name: /审\s*核/ }).click()
    await expect(page.locator('.el-message-box')).toContainText('库存将减少')
    await page
      .locator('.el-message-box')
      .getByRole('button', { name: /确\s*定/ })
      .click()
    await expect(page.locator('.el-message--success').last()).toContainText('发出成功')
    await expect(page.locator('.el-table__row', { hasText: osNo })).toContainText('已审核')
    expect(await totalBalance(page, 'FIN-002')).toBe(f1 - 5)
    const mvOut = await apiGet(page, '/api/v1/inventory/movements', {
      source_type: 'outsourcing_out',
      source_no: osNo,
    })
    expect(mvOut.total).toBe(1)
    expect(mvOut.items[0].direction).toBe(-1)
    expect(Number(mvOut.items[0].quantity)).toBe(5)
    expect(Number(mvOut.items[0].balance_after)).toBe(f1b - 5)
    // 回收：弹窗填 5 → 入库仓库主仓/B-01 → 已回收；FIN-002 回补 +5（outsourcing_in 流水，单号 OSR..）
    // 注意：行内另有「回收记录」按钮，回 收 需 exact 匹配
    await osRow.getByRole('button', { name: '回 收', exact: true }).click()
    const rc = page.locator('.el-dialog', { hasText: '委外回收' })
    await expect(rc).toBeVisible()
    await expect(rc.locator('.remain-cell').first()).toContainText('5')
    await rc.locator('.el-select', { hasText: '选择仓库' }).click()
    await pickOption(page, '主仓')
    await rc.locator('.el-select', { hasText: '选择库位' }).click()
    await pickOption(page, 'B-01')
    await rc.getByRole('button', { name: /提\s*交回收/ }).click()
    await expect(page.locator('.el-message--success').last()).toContainText('回收成功')
    await expect(page.locator('.el-table__row', { hasText: osNo })).toContainText('已回收')
    expect(await totalBalance(page, 'FIN-002')).toBe(f1)
    const osList = await apiGet(page, '/api/v1/production/outsourcings', { keyword: osNo })
    const osId = osList.items[0].id as number
    const receipts = await apiGet(page, `/api/v1/production/outsourcings/${osId}/receipts`)
    osrNo = receipts.items[0].no as string
    expect(osrNo).toMatch(/^OSR\d{12}\d{3}$/)
    const mvIn = await apiGet(page, '/api/v1/inventory/movements', {
      source_type: 'outsourcing_in',
      source_no: osrNo,
    })
    expect(mvIn.total).toBe(1)
    expect(mvIn.items[0].direction).toBe(1)
    expect(Number(mvIn.items[0].quantity)).toBe(5)
    expect(Number(mvIn.items[0].balance_after)).toBe(f1b)
    // 超收：再回收 1 → 1524
    const overRecv = await apiPost(page, `/api/v1/production/outsourcings/${osId}/receipts`, {
      quantity: 1,
      warehouse_id: whId,
      location_id: b01Id,
    })
    expect(overRecv.code).toBe(1524)
    expect(overRecv.message).toBe('回收数量超过委外数量')
    // 幂等：已回收单重复审核 → 1523
    const reApprove = await apiPost(page, `/api/v1/production/outsourcings/${osId}/approve`)
    expect(reApprove.code).toBe(1523)
  })

  test('TC-PRD-07 成品入库与工单自动完成', async ({ page }) => {
    await loginByAPI(page, 'admin', 'admin123')
    await page.goto('/production/orders')
    const row = page.locator('.el-table__row', { hasText: mo1No })
    await row.getByRole('button', { name: /成品入库/ }).click()
    await expect(page).toHaveURL(/\/production\/finished-inbounds/)
    const dialog = page.locator('.el-dialog')
    await expect(dialog).toBeVisible()
    // 预填成品行 FIN-002 数量 10（= 剩余产量；成品列仅渲染名称「成品B」，无编码列）
    await expect(dialog.locator('.el-table__row', { hasText: '成品B' })).toBeVisible()
    const qtyInput = dialog.locator('.el-input-number input')
    await expect(qtyInput).toHaveValue('10.00')
    // 改 11（>剩余 10）→ 前端 on-blur 上限校验：:max 静默钳制回 10（1525 精确消息走后端 API）
    await qtyInput.fill('11')
    await qtyInput.press('Tab')
    await expect(qtyInput).toHaveValue('10.00')
    // 后端草稿期 1525
    const over = await apiPost(page, '/api/v1/production/finished-inbounds', {
      order_id: mo1Id,
      warehouse_id: whId,
      location_id: b01Id,
      items: [{ product_id: finId, quantity: 11 }],
    })
    expect(over.code).toBe(1525)
    expect(over.message).toBe('入库数量超过工单剩余产量')
    // 仓库主仓/B-01 → 保存 → FI 草稿
    await dialog.locator('.el-select', { hasText: '选择仓库' }).click()
    await pickOption(page, '主仓')
    await dialog.locator('.el-select', { hasText: '选择库位' }).click()
    await pickOption(page, 'B-01')
    await dialog.getByRole('button', { name: /保\s*存/ }).click()
    await expect(page.locator('.el-message--success')).toContainText('保存成功')
    const fiRow = page.locator('.el-table__row', { hasText: 'FI' })
    await expect(fiRow).toContainText('草稿')
    fiNo = (await fiRow.locator('td').first().textContent())?.trim() ?? ''
    expect(fiNo).toMatch(/^FI\d{12}\d{3}$/)
    // 审核：confirm「成品库存将增加」→ 成功；FIN-002 = F₁+10（finished_inbound 流水，变动后余额 = F₁b+10）
    await fiRow.getByRole('button', { name: /审\s*核/ }).click()
    await expect(page.locator('.el-message-box')).toContainText('成品库存将增加')
    await page
      .locator('.el-message-box')
      .getByRole('button', { name: /确\s*定/ })
      .click()
    await expect(page.locator('.el-message--success').last()).toContainText('审核成功')
    expect(await totalBalance(page, 'FIN-002')).toBe(f1 + 10)
    const mv = await apiGet(page, '/api/v1/inventory/movements', {
      source_type: 'finished_inbound',
      source_no: fiNo,
    })
    expect(mv.total).toBe(1)
    expect(mv.items[0].direction).toBe(1)
    expect(Number(mv.items[0].quantity)).toBe(10)
    expect(Number(mv.items[0].balance_after)).toBe(f1b + 10)
    // 满产 → 工单自动「已完成」+ 进度 100%（列表 progress 字段）
    await page.goto('/production/orders')
    await expect(page.locator('.el-table__row', { hasText: mo1No })).toContainText('已完成')
    const moList = await apiGet(page, '/api/v1/production/orders', { keyword: mo1No })
    expect(moList.items[0].status_label).toBe('已完成')
    expect(moList.items[0].progress).toBe(100)
    // 幂等：重复审核 → 1528
    const fiList = await apiGet(page, '/api/v1/production/finished-inbounds', { keyword: fiNo })
    const again = await apiPost(
      page,
      `/api/v1/production/finished-inbounds/${fiList.items[0].id}/approve`,
    )
    expect(again.code).toBe(1528)
    expect(again.message).toBe('该成品入库单已审核')
    // 复原 FIN-002 基线（盘点盘亏 10）：销售 TC-SAL-04 并发超卖依赖 FIN-002 余额 < 26（两笔 10 只成功一笔），
    // 成品入库 +10 不冲回会破坏该场景；盘亏走 CK 流水，不影响后跑采购/销售用例断言（见文档 §5 注）
    const ck = await apiPost(page, '/api/v1/checks', {
      warehouse_id: whId,
      items: [{ product_id: finId, location_id: b01Id, actual_qty: f1b }],
    })
    expect(ck.code).toBe(0)
    const ckNo = (ck.data as { no: string }).no
    const ckList = await apiGet(page, '/api/v1/checks', { keyword: ckNo })
    const ckAppr = await apiPost(page, `/api/v1/checks/${ckList.items[0].id}/approve`)
    expect(ckAppr.code).toBe(0)
    expect(await totalBalance(page, 'FIN-002')).toBe(f1)
  })

  test('TC-PRD-09 退料冲销', async ({ page }) => {
    await loginByAPI(page, 'admin', 'admin123')
    // 退料口径：生产中/已完成工单可退料（完工余料退回）；MO-001 属前序用例共享工单，不依赖其状态——
    // 自建「生产中」工单并领料（issued=20）后走退料全流程（编辑/校验/保存/审核仍走 UI，见文档 §5 注）
    const moR = await apiPost(page, '/api/v1/production/orders', {
      product_id: finId,
      quantity: 10,
      plan_date: todayStr(),
    })
    expect(moR.code).toBe(0)
    const moRId = (moR.data as { id: number }).id
    const rel = await apiPost(page, `/api/v1/production/orders/${moRId}/release`)
    expect(rel.code).toBe(0)
    const st = await apiPost(page, `/api/v1/production/orders/${moRId}/start`)
    expect(st.code).toBe(0)
    const pk = await apiPost(page, '/api/v1/production/picks', {
      order_id: moRId,
      warehouse_id: whId,
      location_id: a01Id,
      items: [{ product_id: matId, pick_qty: 20 }],
    })
    expect(pk.code).toBe(0)
    const pkList = await apiGet(page, '/api/v1/production/picks', {
      keyword: (pk.data as { no: string }).no,
    })
    const pkAppr = await apiPost(page, `/api/v1/production/picks/${pkList.items[0].id}/approve`)
    expect(pkAppr.code).toBe(0)
    // 草稿经 API 直建（新工单生产中，符合新口径）
    const rl = await apiPost(page, '/api/v1/production/returns', {
      order_id: moRId,
      warehouse_id: whId,
      location_id: a01Id,
      items: [{ product_id: matId, quantity: 2 }],
    })
    expect(rl.code).toBe(0)
    rlNo = (rl.data as { no: string }).no
    expect(rlNo).toMatch(/^RL\d{12}\d{3}$/)
    // 超已领（25 > 已领 20）→ 1517（草稿期后端拦截）
    const rlList = await apiGet(page, '/api/v1/production/returns', { keyword: rlNo })
    const rlId = rlList.items[0].id as number
    const over = await apiPut(page, `/api/v1/production/returns/${rlId}`, {
      order_id: moRId,
      warehouse_id: whId,
      location_id: a01Id,
      items: [{ product_id: matId, quantity: 25 }],
    })
    expect(over.code).toBe(1517)
    expect(over.message).toBe('退料数量超过已领数量')
    // UI 编辑：本次退回改 25 → 前端 :max 静默钳制回 20（1517 精确消息已走后端 API）；恢复 2 → 保存
    await page.goto('/production/returns')
    const rlRow = page.locator('.el-table__row', { hasText: rlNo })
    await expect(rlRow).toContainText('草稿')
    await rlRow.getByRole('button', { name: /编\s*辑/ }).click()
    const dialog = page.locator('.el-dialog')
    await expect(dialog.locator('.el-table__row', { hasText: 'MAT-001' })).toContainText('20')
    const qtyInput = dialog.locator('.el-input-number input')
    await expect(qtyInput).toHaveValue('2.00')
    await qtyInput.fill('25')
    await qtyInput.press('Tab')
    await expect(qtyInput).toHaveValue('20.00')
    await qtyInput.fill('2')
    await dialog.getByRole('button', { name: /保\s*存/ }).click()
    await expect(page.locator('.el-message--success')).toContainText('保存成功')
    // 审核：confirm「库存将增加并冲销已领」→ 成功；
    // MAT-001 = P₁-38（前序用例领 20 + 本用例自建链路领 20 − 退 2；TC-PRD-10 开头会全量清零，无跨用例基线依赖）
    await rlRow.getByRole('button', { name: /审\s*核/ }).click()
    await expect(page.locator('.el-message-box')).toContainText('审核后库存将增加并冲销已领')
    await page
      .locator('.el-message-box')
      .getByRole('button', { name: /确\s*定/ })
      .click()
    await expect(page.locator('.el-message--success').last()).toContainText('审核成功')
    expect(await totalBalance(page, 'MAT-001')).toBe(p1 - 38)
    const mv = await apiGet(page, '/api/v1/inventory/movements', {
      source_type: 'return',
      source_no: rlNo,
    })
    expect(mv.total).toBe(1)
    expect(mv.items[0].direction).toBe(1)
    expect(Number(mv.items[0].quantity)).toBe(2)
    expect(Number(mv.items[0].balance_after)).toBe(p1a - 38)
    // 工单物料已领 20→18（冲销）
    const moDetail = await apiGet(page, `/api/v1/production/orders/${moRId}`)
    expect(Number(moDetail.materials[0].issued_qty)).toBe(18)
    expect(Number(moDetail.materials[0].remaining_qty)).toBe(2)
    // 幂等：重复审核 → 1519
    const again = await apiPost(page, `/api/v1/production/returns/${rlId}/approve`)
    expect(again.code).toBe(1519)
    expect(again.message).toBe('该退料单已审核')
  })

  test('TC-PRD-08 完工校验与关闭', async ({ page }) => {
    await loginByAPI(page, 'admin', 'admin123')
    // 建 MO-003（FIN-002×5）→ 下达（MAT-001 充足 → warnings 空）→ 开工
    const mo3 = await apiPost(page, '/api/v1/production/orders', {
      product_id: finId,
      quantity: 5,
      plan_date: todayStr(),
    })
    expect(mo3.code).toBe(0)
    mo3No = (mo3.data as { no: string }).no
    const mo3List = await apiGet(page, '/api/v1/production/orders', { keyword: mo3No })
    mo3Id = mo3List.items[0].id as number
    const rel = await apiPost(page, `/api/v1/production/orders/${mo3Id}/release`)
    expect(rel.code).toBe(0)
    expect((rel.data as { warnings: unknown[] }).warnings).toHaveLength(0)
    const st = await apiPost(page, `/api/v1/production/orders/${mo3Id}/start`)
    expect(st.code).toBe(0)
    // 工序未完成直接完工 → 1507
    const c1 = await apiPost(page, `/api/v1/production/orders/${mo3Id}/complete`)
    expect(c1.code).toBe(1507)
    expect(c1.message).toBe('存在未完成工序，无法完工')
    // 全部工序报工完成 → 无成品入库直接完工 → 1508
    const mo3Detail = await apiGet(page, `/api/v1/production/orders/${mo3Id}`)
    for (const op of mo3Detail.operations as { id: number }[]) {
      const rp = await apiPost(page, `/api/v1/production/operations/${op.id}/reports`, {
        qualified_qty: 5,
        defective_qty: 0,
        hours: 0.5,
      })
      expect(rp.code).toBe(0)
    }
    const c2 = await apiPost(page, `/api/v1/production/orders/${mo3Id}/complete`)
    expect(c2.code).toBe(1508)
    expect(c2.message).toBe('无成品入库，无法完工')
    // MO-001（已完成）→ UI 关闭 → 关闭
    await page.goto('/production/orders')
    const row = page.locator('.el-table__row', { hasText: mo1No })
    await row.getByRole('button', { name: /关\s*闭/ }).click()
    await expect(page.locator('.el-message-box')).toContainText('确认关闭工单')
    await page
      .locator('.el-message-box')
      .getByRole('button', { name: /确\s*定/ })
      .click()
    await expect(page.locator('.el-message--success').last()).toContainText('关闭成功')
    await expect(page.locator('.el-table__row', { hasText: mo1No })).toContainText('关闭')
    // 已关闭工单 release/start → 1505/1506（关闭后无操作入口）
    const relClosed = await apiPost(page, `/api/v1/production/orders/${mo1Id}/release`)
    expect(relClosed.code).toBe(1505)
    const startClosed = await apiPost(page, `/api/v1/production/orders/${mo1Id}/start`)
    expect(startClosed.code).toBe(1506)
  })

  test('TC-PRD-10 领料库存不足拦截', async ({ page }) => {
    await loginByAPI(page, 'admin', 'admin123')
    // 用库存盘点（MAT-001 为原料、销售禁售，走盘点而非销售出库）把 MAT-001 逐库位行实盘清 0 → 盘亏流水清空
    const rows = (await apiGet(page, '/api/v1/inventory/balances', { keyword: 'MAT-001' }))
      .items as {
      product_id: number
      warehouse_id: number
      location_id: number
      quantity: number
    }[]
    for (const r of rows) {
      if (Number(r.quantity) <= 0) continue
      const ck = await apiPost(page, '/api/v1/checks', {
        warehouse_id: r.warehouse_id,
        items: [{ product_id: r.product_id, location_id: r.location_id, actual_qty: 0 }],
      })
      expect(ck.code).toBe(0)
      const ckNo = (ck.data as { no: string }).no
      const ckList = await apiGet(page, '/api/v1/checks', { keyword: ckNo })
      const ckAppr = await apiPost(page, `/api/v1/checks/${ckList.items[0].id}/approve`)
      expect(ckAppr.code).toBe(0)
    }
    expect(await totalBalance(page, 'MAT-001')).toBe(0)
    // 领料单草稿（MO-003 需求 MAT-001×10）可建
    const pk = await apiPost(page, '/api/v1/production/picks', {
      order_id: mo3Id,
      warehouse_id: whId,
      location_id: a01Id,
      items: [{ product_id: matId, pick_qty: 10 }],
    })
    expect(pk.code).toBe(0)
    const pk10No = (pk.data as { no: string }).no
    const pk10List = await apiGet(page, '/api/v1/production/picks', { keyword: pk10No })
    const pk10Id = pk10List.items[0].id as number
    // 审核 → 1515 精确消息（商品编码）；整体回滚：余额不变、无流水
    const failAppr = await apiPost(page, `/api/v1/production/picks/${pk10Id}/approve`)
    expect(failAppr.code).toBe(1515)
    expect(failAppr.message).toBe('商品[MAT-001]库存不足')
    expect(await totalBalance(page, 'MAT-001')).toBe(0)
    const mv = await apiGet(page, '/api/v1/inventory/movements', { source_no: pk10No })
    expect(mv.total).toBe(0)
    // 补库存（盘点盘盈 +30，走 CK 而非采购入库——已审核 PI 行会破坏后跑 purchase.spec 的单 PI 行假设）
    // → 重新审核 → 0
    const ck2 = await apiPost(page, '/api/v1/checks', {
      warehouse_id: whId,
      items: [{ product_id: matId, location_id: a01Id, actual_qty: 30 }],
    })
    expect(ck2.code).toBe(0)
    const ck2No = (ck2.data as { no: string }).no
    const ck2List = await apiGet(page, '/api/v1/checks', { keyword: ck2No })
    const ck2Appr = await apiPost(page, `/api/v1/checks/${ck2List.items[0].id}/approve`)
    expect(ck2Appr.code).toBe(0)
    expect(await totalBalance(page, 'MAT-001')).toBe(30)
    const okAppr = await apiPost(page, `/api/v1/production/picks/${pk10Id}/approve`)
    expect(okAppr.code).toBe(0)
    // 领料 10 落库：MAT-001 = 20；MO-003 已领 10 剩余 0
    expect(await totalBalance(page, 'MAT-001')).toBe(20)
    const mo3Detail = await apiGet(page, `/api/v1/production/orders/${mo3Id}`)
    expect(Number(mo3Detail.materials[0].issued_qty)).toBe(10)
    expect(Number(mo3Detail.materials[0].remaining_qty)).toBe(0)
  })

  test('TC-MST-1113 补测：工序被生产工单引用不可删', async ({ page }) => {
    await loginByAPI(page, 'admin', 'admin123')
    // 基础资料工序页：删除「组装」（MO-001/002/003 工序序列均引用）→ 1113 拒绝
    await page.goto('/master/processes')
    const procRow = page.locator('.el-table__row', { hasText: '组装' }).first()
    await procRow.getByRole('button', { name: /删\s*除/ }).click()
    await page
      .locator('.el-message-box')
      .getByRole('button', { name: /确\s*定/ })
      .click()
    await expect(page.locator('.el-message--error')).toContainText('工序已被生产工单使用，不可删除')
    // 工序仍在列表中
    await expect(page.locator('.el-table__row', { hasText: '组装' }).first()).toBeVisible()
  })
})
