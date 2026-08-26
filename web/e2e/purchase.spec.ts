// 采购管理模块 E2E：TC-PUR-01~10（串行，库存随审核变化）+ 基础资料 1109 供应商删除保护补测
// 基线库存（InventorySeeder）：MAT-001=100@A-01、SEMI-001=30@A-01、FIN-002=20@B-01（主仓）
// 供应商无种子 → TC-PUR-01 用 API 自建「测试供应商 SUP-001」（已存在则复用）
// 库存末态随库存模块测试变化 → 一律记录「当时余额 B₀」按增量断言
// 定位方式：el-select 占位符无 placeholder 属性且 filterable 的 input 拦截点击 → 点 .el-select 外壳；
// 下拉项用 getByRole('option')，并先等唯一匹配（旧下拉淡出期间 aria-hidden 未翻转会产生重复匹配）
import { expect, test, type Page } from '@playwright/test'
import { loginByAPI } from './helpers'

// 已登录页面的认证请求辅助：token 取自 localStorage
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
// PUT 请求辅助：修改类接口（订单/入库单更新）走 PUT 方法
async function apiPut(page: Page, url: string, body?: unknown) {
  const token = await page.evaluate(() => localStorage.getItem('token'))
  const res = await page.request.put(url, {
    headers: { Authorization: `Bearer ${token}` },
    data: body,
  })
  return (await res.json()) as { code: number; message?: string; data?: unknown }
}

// 下拉项选择：等待唯一可见 option 后点击（隐藏的旧 popper 不参与 getByRole 匹配，杜绝 .first() 命中不可见项）
async function pickOption(page: Page, name: string) {
  const opt = page.getByRole('option', { name })
  await expect(opt).toHaveCount(1)
  await opt.click()
}

test.describe('采购管理模块', () => {
  // 用例间库存/订单状态相互依赖，串行执行（UI 步骤多，放宽单用例超时）
  test.describe.configure({ mode: 'serial', timeout: 60_000 })

  // 用例共享：供应商 id、MAT-001 余额基线 B₀、PO 单号
  let supplierId = 0
  let b0 = 0
  let semiBase = 0
  let poNo = ''
  let poId = 0
  let poNo3 = '' // TC-PUR-11 自建的 0 行测试订单（TC-PUR-12 复用）

  test('TC-PUR-01 采购订单创建（金额计算）', async ({ page }) => {
    await loginByAPI(page, 'admin', 'admin123')
    // 数据准备：供应商（无种子，API 自建幂等）
    const supList = await apiGet(page, '/api/v1/suppliers', { keyword: 'SUP-001', per_page: 100 })
    if (supList.total > 0) {
      supplierId = supList.items[0].id as number
    } else {
      const created = await apiPost(page, '/api/v1/suppliers', {
        name: '测试供应商',
        code: 'SUP-001',
        status: 1,
      })
      expect(created.code).toBe(0)
      const after = await apiGet(page, '/api/v1/suppliers', { keyword: 'SUP-001', per_page: 100 })
      supplierId = after.items[0].id as number
    }
    // 记录 MAT-001 / SEMI-001 当时余额（后续增量断言基线）
    const mat = await apiGet(page, '/api/v1/inventory/balances', { keyword: 'MAT-001' })
    b0 = Number(mat.items[0].quantity)
    const semi = await apiGet(page, '/api/v1/inventory/balances', { keyword: 'SEMI-001' })
    semiBase = Number(semi.items[0].quantity)

    // 新建订单：2 行 MAT-001×100@5.00、SEMI-001×50@10.00 → 合计 ¥1,000.00
    await page.goto('/purchase/orders')
    await page.getByRole('button', { name: /新\s*建/ }).click()
    const dialog = page.locator('.el-dialog')
    await dialog.locator('.el-select', { hasText: '选择供应商' }).click()
    await pickOption(page, '测试供应商')
    const rows = dialog.locator('.el-table__row')
    // 行 1：MAT-001×100
    await rows.nth(0).locator('.el-select', { hasText: '选择商品' }).click()
    await pickOption(page, 'MAT-001')
    await rows.nth(0).locator('.el-input-number input').first().fill('100')
    await rows.nth(0).locator('.el-input-number input').nth(1).fill('5')
    // 行 2：SEMI-001×50
    await dialog.getByRole('button', { name: /添加明细行/ }).click()
    const rows2 = dialog.locator('.el-table__row')
    await rows2.nth(1).locator('.el-select', { hasText: '选择商品' }).click()
    await pickOption(page, 'SEMI-001')
    await rows2.nth(1).locator('.el-input-number input').first().fill('50')
    await rows2.nth(1).locator('.el-input-number input').nth(1).fill('10')
    // 行金额与合计实时（分→元展示）
    await expect(dialog.locator('.el-table__row').nth(0)).toContainText('¥500.00')
    await expect(dialog.locator('.total-amount')).toContainText('¥1,000.00')
    // 重复商品行被拦截
    await dialog.getByRole('button', { name: /添加明细行/ }).click()
    const rows3 = dialog.locator('.el-table__row')
    await rows3.nth(2).locator('.el-select', { hasText: '选择商品' }).click()
    await pickOption(page, 'MAT-001')
    await expect(page.locator('.el-message--warning')).toContainText('明细存在重复商品')
    // 删掉第三行（清除重复行；第 3 行的「删 除」按钮在 DOM 中为第 3 个）
    await dialog
      .getByRole('button', { name: /删\s*除/ })
      .nth(2)
      .click()
    // 保存 → 列表出现 PO 草稿（取首行：列表按 id 倒序，最新在建单在最上），金额列 1,000.00
    await dialog.getByRole('button', { name: /保\s*存/ }).click()
    await expect(page.locator('.el-message--success')).toContainText('保存成功')
    const row = page.locator('.el-table__row').first()
    await expect(row).toContainText('草稿')
    await expect(row).toContainText('¥1,000.00')
    poNo = (await row.locator('td').first().textContent())?.trim() ?? ''
    expect(poNo).toMatch(/^PO\d{12}\d{3}$/)
    // 编辑：MAT-001 数量改 120 → 合计 ¥1,100.00
    await row.getByRole('button', { name: /编\s*辑/ }).click()
    const ed = page.locator('.el-dialog')
    await ed.locator('.el-table__row').nth(0).locator('.el-input-number input').first().fill('120')
    await ed.getByRole('button', { name: /保\s*存/ }).click()
    // 上一条「保存成功」可能未消失（3s 自动关闭），取最后一条避免 strict 冲突
    await expect(page.locator('.el-message--success').last()).toContainText('保存成功')
    await expect(page.locator('.el-table__row').first()).toContainText('¥1,100.00')
  })

  test('TC-PUR-02 订单审核与修改保护', async ({ page }) => {
    await loginByAPI(page, 'admin', 'admin123')
    await page.goto('/purchase/orders')
    const row = page.locator('.el-table__row', { hasText: poNo })
    // 审核：confirm → 状态绿「已审核」，编辑/删除按钮消失
    await row.getByRole('button', { name: /审\s*核/ }).click()
    await expect(page.locator('.el-message-box')).toContainText('确认审核订单')
    await page
      .locator('.el-message-box')
      .getByRole('button', { name: /确\s*定/ })
      .click()
    await expect(page.locator('.el-message--success')).toContainText('审核成功')
    const approvedRow = page.locator('.el-table__row', { hasText: poNo })
    await expect(approvedRow).toContainText('已审核')
    await expect(approvedRow.getByRole('button', { name: /编\s*辑/ })).toHaveCount(0)
    // 幂等：API 重复审核 → 1305
    const list = await apiGet(page, '/api/v1/purchase/orders', { keyword: poNo })
    poId = list.items[0].id as number
    const again = await apiPost(page, `/api/v1/purchase/orders/${poId}/approve`)
    expect(again.code).toBe(1305)
    expect(again.message).toBe('该订单已审核')
    // 后端拦截改/删
    const put = await apiPut(page, `/api/v1/purchase/orders/${poId}`, {
      supplier_id: supplierId,
      order_date: '2026-08-12',
      items: [{ product_id: 1, quantity: 1, price: 1 }],
    })
    expect(put.code).toBe(1303)
    const del = await page.request.delete(`/api/v1/purchase/orders/${poId}`, {
      headers: {
        Authorization: `Bearer ${await page.evaluate(() => localStorage.getItem('token'))}`,
      },
    })
    expect(((await del.json()) as { code: number }).code).toBe(1304)
  })

  test('TC-PUR-03 从订单生成入库单（部分入库）', async ({ page }) => {
    await loginByAPI(page, 'admin', 'admin123')
    await page.goto('/purchase/inbounds')
    await page.getByRole('button', { name: /从订单生成/ }).click()
    const dialog = page.locator('.el-dialog')
    // 选订单 → 自动预填 2 行（MAT-001 剩余 120、SEMI-001 剩余 50）
    await dialog.locator('.el-select', { hasText: '选择已审核/部分入库订单' }).click()
    await pickOption(page, poNo)
    await expect(dialog.locator('.el-table__row')).toHaveCount(2)
    const matRow = dialog.locator('.el-table__row', { hasText: 'MAT-001' })
    // 预填数量在输入框 value 中（剩余 120.00，el-input-number precision=2）
    await expect(matRow.locator('.el-input-number input').first()).toHaveValue('120.00')
    // MAT-001 数量改 60（≤剩余），选仓库/库位，保存 → 草稿 PI
    await matRow.locator('.el-input-number input').first().fill('60')
    await dialog.locator('.el-select', { hasText: '选择仓库' }).click()
    await pickOption(page, '主仓')
    await dialog.locator('.el-select', { hasText: '选择库位' }).click()
    await pickOption(page, 'A-01')
    await dialog.getByRole('button', { name: /保\s*存/ }).click()
    await expect(page.locator('.el-message--success')).toContainText('保存成功')
    // 列表按 id 倒序 → .first() 即刚保存的草稿（前置 spec（outsourcing/routing 库存注入）会留
    // 已审核 PI 行，全量串行下不可假设 PI 行唯一；与 TC-PUR-04 的 .first() 口径一致）
    const piRow = page.locator('.el-table__row', { hasText: 'PI' }).first()
    await expect(piRow).toContainText('草稿')
    // 超量拦截：MAT-001 数量改 200（>剩余 120）→ 前端/后端拒绝
    await piRow.getByRole('button', { name: /编\s*辑/ }).click()
    const ed = page.locator('.el-dialog')
    await ed
      .locator('.el-table__row', { hasText: 'MAT-001' })
      .locator('.el-input-number input')
      .first()
      .fill('200')
    await ed.getByRole('button', { name: /保\s*存/ }).click()
    await expect(page.locator('.el-message--error')).toContainText('入库数量超过订单剩余数量')
  })

  test('TC-PUR-04 入库单审核-库存增加', async ({ page }) => {
    await loginByAPI(page, 'admin', 'admin123')
    await page.goto('/purchase/inbounds')
    const piRow = page.locator('.el-table__row', { hasText: 'PI' }).first()
    const piNo = (await piRow.locator('td').first().textContent())?.trim() ?? ''
    // 审核：confirm「库存将增加」→ 成功消息「入库成功，库存已更新」
    await piRow.getByRole('button', { name: /审\s*核/ }).click()
    await expect(page.locator('.el-message-box')).toContainText('确认审核入库单')
    await expect(page.locator('.el-message-box')).toContainText('审核后库存将增加且不可修改')
    await page
      .locator('.el-message-box')
      .getByRole('button', { name: /确\s*定/ })
      .click()
    await expect(page.locator('.el-message--success')).toContainText('入库成功，库存已更新')
    // 余额页：MAT-001 = B₀+60
    await page.goto('/inventory/balances')
    await expect(page.locator('.el-table__row', { hasText: 'MAT-001' })).toContainText(
      String(b0 + 60),
    )
    // 流水页筛「采购入库」：+60 流水、单号 PI、变动后余额 B₀+60（商品列仅渲染名称 → 按「测试铝材」定位）
    await page.goto('/inventory/movements')
    await page.locator('.filter-bar').getByText('单据类型', { exact: true }).click()
    await pickOption(page, '采购入库')
    await page.getByRole('button', { name: /查\s*询/ }).click()
    const mvRow = page.locator('.el-table__row', { hasText: '测试铝材' }).first()
    await expect(mvRow).toContainText('+')
    await expect(mvRow).toContainText('60')
    await expect(mvRow).toContainText(String(b0 + 60))
    await expect(mvRow).toContainText(piNo)
    // 单号点击 → 跳采购入库单详情弹窗（exact 匹配 footer「关 闭」，避开头部关闭图标按钮）
    await mvRow.locator('.source-no').click()
    await expect(page.locator('.el-dialog')).toContainText('入库单详情')
    await page.locator('.el-dialog').getByRole('button', { name: '关 闭', exact: true }).click()
    // 订单详情「入库记录」tab
    await page.goto('/purchase/orders')
    await page
      .locator('.el-table__row', { hasText: poNo })
      .getByRole('button', { name: /查\s*看/ })
      .click()
    await page.locator('.el-dialog').getByRole('tab', { name: '入库记录' }).click()
    await expect(page.locator('.el-dialog')).toContainText(piNo)
    await page.locator('.el-dialog').getByRole('button', { name: '关 闭', exact: true }).click()
  })

  test('TC-PUR-05 订单状态联动（部分入库 → 已完成）', async ({ page }) => {
    await loginByAPI(page, 'admin', 'admin123')
    // 订单状态「部分入库」（蓝）
    await page.goto('/purchase/orders')
    const orderRow = page.locator('.el-table__row', { hasText: poNo })
    await expect(orderRow).toContainText('部分入库')
    // 再入剩余：TC-PUR-03 的入库单已含 SEMI-001×50（预填 2 行仅改 MAT-001 数量）→ 剩余仅 MAT-001×60
    await page.goto('/purchase/inbounds')
    await page.getByRole('button', { name: /从订单生成/ }).click()
    const dialog = page.locator('.el-dialog')
    await dialog.locator('.el-select', { hasText: '选择已审核/部分入库订单' }).click()
    await pickOption(page, poNo)
    await expect(dialog.locator('.el-table__row')).toHaveCount(1)
    const matRow5 = dialog.locator('.el-table__row', { hasText: 'MAT-001' })
    await expect(matRow5.locator('.el-input-number input').first()).toHaveValue('60.00')
    await dialog.locator('.el-select', { hasText: '选择仓库' }).click()
    await pickOption(page, '主仓')
    await dialog.locator('.el-select', { hasText: '选择库位' }).click()
    await pickOption(page, 'A-01')
    await dialog.getByRole('button', { name: /保\s*存/ }).click()
    await expect(page.locator('.el-message--success')).toContainText('保存成功')
    const row2 = page.locator('.el-table__row', { hasText: 'PI' }).first()
    await row2.getByRole('button', { name: /审\s*核/ }).click()
    await page
      .locator('.el-message-box')
      .getByRole('button', { name: /确\s*定/ })
      .click()
    await expect(page.locator('.el-message--success').last()).toContainText('入库成功')
    // 库存：MAT-001 = B₀+120、SEMI-001 = 基线+50（SEMI 在 TC-PUR-03 入库单审核时已 +50）
    const mat = await apiGet(page, '/api/v1/inventory/balances', { keyword: 'MAT-001' })
    expect(Number(mat.items[0].quantity)).toBe(b0 + 120)
    const semi = await apiGet(page, '/api/v1/inventory/balances', { keyword: 'SEMI-001' })
    expect(Number(semi.items[0].quantity)).toBe(semiBase + 50)
    // 订单状态「已完成」（绿）；从订单生成下拉不再出现该订单
    await page.goto('/purchase/orders')
    await expect(page.locator('.el-table__row', { hasText: poNo })).toContainText('已完成')
    await page.goto('/purchase/inbounds')
    await page.getByRole('button', { name: /从订单生成/ }).click()
    const dialog2 = page.locator('.el-dialog')
    await dialog2.locator('.el-select', { hasText: '选择已审核/部分入库订单' }).click()
    await expect(page.getByRole('option', { name: poNo })).toHaveCount(0)
    await page.keyboard.press('Escape')
  })

  test('TC-PUR-06 订单关闭', async ({ page }) => {
    await loginByAPI(page, 'admin', 'admin123')
    // 新订单 PO-002：MAT-001×10 → 审核 → 关闭
    await page.goto('/purchase/orders')
    await page.getByRole('button', { name: /新\s*建/ }).click()
    const dialog = page.locator('.el-dialog')
    await dialog.locator('.el-select', { hasText: '选择供应商' }).click()
    await pickOption(page, '测试供应商')
    const row = dialog.locator('.el-table__row').nth(0)
    await row.locator('.el-select', { hasText: '选择商品' }).click()
    await pickOption(page, 'MAT-001')
    await row.locator('.el-input-number input').first().fill('10')
    await dialog.getByRole('button', { name: /保\s*存/ }).click()
    await expect(page.locator('.el-message--success')).toContainText('保存成功')
    // 等待列表刷新完成（刷新前旧列表首行是上一单，避免 po2No 取到旧列表首行）
    await expect(page.locator('.el-table__row').first()).not.toContainText(poNo)
    const po2Row = page.locator('.el-table__row').first()
    const po2No = (await po2Row.locator('td').first().textContent())?.trim() ?? ''
    await po2Row.getByRole('button', { name: /审\s*核/ }).click()
    await page
      .locator('.el-message-box')
      .getByRole('button', { name: /确\s*定/ })
      .click()
    await expect(page.locator('.el-message--success').last()).toContainText('审核成功')
    const approved2 = page.locator('.el-table__row', { hasText: po2No })
    await approved2.getByRole('button', { name: /关\s*闭/ }).click()
    await page
      .locator('.el-message-box')
      .getByRole('button', { name: /确\s*定/ })
      .click()
    await expect(page.locator('.el-message--success').last()).toContainText('关闭成功')
    await expect(page.locator('.el-table__row', { hasText: po2No })).toContainText('关闭')
    // 关闭后不可再入库（下拉不出现）
    await page.goto('/purchase/inbounds')
    await page.getByRole('button', { name: /从订单生成/ }).click()
    const dialog2 = page.locator('.el-dialog')
    await dialog2.locator('.el-select', { hasText: '选择已审核/部分入库订单' }).click()
    await expect(page.getByRole('option', { name: po2No })).toHaveCount(0)
    await page.keyboard.press('Escape')
    // 已完成订单不可关闭（API → 1306）
    const list = await apiGet(page, '/api/v1/purchase/orders', { keyword: poNo })
    const doneId = list.items[0].id as number
    const close = await apiPost(page, `/api/v1/purchase/orders/${doneId}/close`)
    expect(close.code).toBe(1306)
  })

  test('TC-PUR-07 入库单审核幂等', async ({ page }) => {
    await loginByAPI(page, 'admin', 'admin123')
    // 对已审核入库单重复 approve → 1310，余额不变
    const list = await apiGet(page, '/api/v1/purchase/inbounds', { status: 1, per_page: 100 })
    const approved = list.items[0] as { id: number }
    const again = await apiPost(page, `/api/v1/purchase/inbounds/${approved.id}/approve`)
    expect(again.code).toBe(1310)
    expect(again.message).toBe('该入库单已审核')
    const mat = await apiGet(page, '/api/v1/inventory/balances', { keyword: 'MAT-001' })
    expect(Number(mat.items[0].quantity)).toBe(b0 + 120)
    // 已审核入库单改/删 → 1309
    const put = await apiPut(page, `/api/v1/purchase/inbounds/${approved.id}`, {
      supplier_id: supplierId,
      warehouse_id: 1,
      location_id: 1,
      items: [{ product_id: 1, quantity: 1, price: 1 }],
    })
    expect(put.code).toBe(1309)
  })

  test('TC-PUR-08 独立入库（无订单来源）', async ({ page }) => {
    await loginByAPI(page, 'admin', 'admin123')
    // 「新 建」独立入库：MAT-001×5@1.00 → 审核 → 余额 +5，流水无订单关联
    const mat = await apiGet(page, '/api/v1/inventory/balances', { keyword: 'MAT-001' })
    const before = Number(mat.items[0].quantity)
    await page.goto('/purchase/inbounds')
    await page.getByRole('button', { name: /新\s*建/ }).click()
    const dialog = page.locator('.el-dialog')
    await dialog.locator('.el-select', { hasText: '选择供应商' }).click()
    await pickOption(page, '测试供应商')
    const row = dialog.locator('.el-table__row').nth(0)
    await row.locator('.el-select', { hasText: '选择商品' }).click()
    await pickOption(page, 'MAT-001')
    await row.locator('.el-input-number input').first().fill('5')
    await row.locator('.el-input-number input').nth(1).fill('1')
    await dialog.locator('.el-select', { hasText: '选择仓库' }).click()
    await pickOption(page, '主仓')
    await dialog.locator('.el-select', { hasText: '选择库位' }).click()
    await pickOption(page, 'A-01')
    await dialog.getByRole('button', { name: /保\s*存/ }).click()
    await expect(page.locator('.el-message--success')).toContainText('保存成功')
    const row2 = page.locator('.el-table__row', { hasText: 'PI' }).first()
    await row2.getByRole('button', { name: /审\s*核/ }).click()
    await page
      .locator('.el-message-box')
      .getByRole('button', { name: /确\s*定/ })
      .click()
    await expect(page.locator('.el-message--success').last()).toContainText('入库成功')
    const after = await apiGet(page, '/api/v1/inventory/balances', { keyword: 'MAT-001' })
    expect(Number(after.items[0].quantity)).toBe(before + 5)
    // 列表来源订单列为「—」
    await expect(page.locator('.el-table__row', { hasText: 'PI' }).first()).toContainText('—')
  })

  test('TC-PUR-09 扫码录明细（ScanInboundForm 独立弹窗）', async ({ page }) => {
    await loginByAPI(page, 'admin', 'admin123')
    // 进入采购入库页（沿用前序用例创建的供应商）
    await page.goto('/purchase/inbounds')
    await page.getByRole('button', { name: /新\s*建/ }).click()
    const dialog = page.locator('.el-dialog').last()
    await dialog.getByRole('button', { name: /扫码添加/ }).click()
    const scanDialog = page.locator('.el-dialog').last()
    // 默认逐件关：扫码后填数量再确定
    await scanDialog.getByPlaceholder('扫描条码回车添加商品').fill('888888')
    await scanDialog.getByPlaceholder('扫描条码回车添加商品').press('Enter')
    await expect(scanDialog.locator('.pending-row')).toBeVisible()
    await scanDialog.getByPlaceholder('数量').fill('5')
    await scanDialog.getByRole('button', { name: /确\s*定/ }).click()
    await expect(scanDialog.locator('.preview-table')).toContainText('FIN-002')
    // 关闭弹窗回带明细
    await scanDialog.getByRole('button', { name: /关\s*闭/ }).click()
    await expect(dialog.locator('.el-table__row')).toContainText('FIN-002')
    await expect(dialog.locator('.el-table__row')).toContainText('5')
  })

  test('TC-PUR-10 金额与价格边界', async ({ page }) => {
    await loginByAPI(page, 'admin', 'admin123')
    // 负价前端拦截（el-input-number min=0 钳制）
    await page.goto('/purchase/orders')
    await page.getByRole('button', { name: /新\s*建/ }).click()
    const dialog = page.locator('.el-dialog')
    const row = dialog.locator('.el-table__row').nth(0)
    const priceInput = row.locator('.el-input-number input').nth(1)
    await priceInput.fill('-1')
    await priceInput.blur()
    expect(Number(await priceInput.inputValue())).toBeGreaterThanOrEqual(0)
    // 后端拦截负价 → 1311
    const neg = await apiPost(page, '/api/v1/purchase/orders', {
      supplier_id: supplierId,
      order_date: '2026-08-12',
      items: [{ product_id: 1, quantity: 1, price: -1 }],
    })
    expect(neg.code).toBe(1311)
    expect(neg.message).toBe('价格不能为负数')
    // 小数价格无误差：0.10×3 → ¥0.30（price=10 分，total_amount 为整数分 → 30）
    const gift = await apiPost(page, '/api/v1/purchase/orders', {
      supplier_id: supplierId,
      order_date: '2026-08-12',
      items: [{ product_id: 1, quantity: 3, price: 10 }],
    })
    expect(gift.code).toBe(0)
    const giftList = await apiGet(page, '/api/v1/purchase/orders', {
      keyword: (gift.data as { no: string }).no,
    })
    expect(giftList.items[0].total_amount).toBe(30)
    // 空明细双端拦截 → 1301
    const empty = await apiPost(page, '/api/v1/purchase/orders', {
      supplier_id: supplierId,
      order_date: '2026-08-12',
      items: [],
    })
    expect(empty.code).toBe(1301)
  })

  test('TC-PUR-11 从订单生成 0 数量行（本次不收货跳过）', async ({ page }) => {
    await loginByAPI(page, 'admin', 'admin123')
    // 数据准备：自建 2 行订单 PO3（MAT-001×20、SEMI-001×10）并审核；商品 id 经列表接口反查
    const mat = await apiGet(page, '/api/v1/products', { keyword: 'MAT-001' })
    const semi = await apiGet(page, '/api/v1/products', { keyword: 'SEMI-001' })
    const matId = mat.items[0].id as number
    const semiId = semi.items[0].id as number
    const created = await apiPost(page, '/api/v1/purchase/orders', {
      supplier_id: supplierId,
      order_date: '2026-08-22',
      items: [
        { product_id: matId, quantity: 20, price: 500 },
        { product_id: semiId, quantity: 10, price: 1000 },
      ],
    })
    expect(created.code).toBe(0)
    poNo3 = (created.data as { no: string }).no
    const po3List = await apiGet(page, '/api/v1/purchase/orders', { keyword: poNo3 })
    const po3Id = po3List.items[0].id as number
    const approved = await apiPost(page, `/api/v1/purchase/orders/${po3Id}/approve`)
    expect(approved.code).toBe(0)
    const semiBefore = Number(
      (await apiGet(page, '/api/v1/inventory/balances', { keyword: 'SEMI-001' })).items[0].quantity,
    )

    // UI：从订单生成 → MAT 行数量改 0（灰字「本次不收货」）→ 保存 → 草稿仅含 SEMI 行
    await page.goto('/purchase/inbounds')
    await page.getByRole('button', { name: /从订单生成/ }).click()
    const dialog = page.locator('.el-dialog')
    await dialog.locator('.el-select', { hasText: '选择已审核/部分入库订单' }).click()
    await pickOption(page, poNo3)
    await expect(dialog.locator('.el-table__row')).toHaveCount(2)
    const matRow = dialog.locator('.el-table__row', { hasText: 'MAT-001' })
    await matRow.locator('.el-input-number input').first().fill('0')
    await matRow.locator('.el-input-number input').first().blur()
    await expect(matRow).toContainText('本次不收货')
    await dialog.locator('.el-select', { hasText: '选择仓库' }).click()
    await pickOption(page, '主仓')
    await dialog.locator('.el-select', { hasText: '选择库位' }).click()
    await pickOption(page, 'A-01')
    await dialog.getByRole('button', { name: /保\s*存/ }).click()
    await expect(page.locator('.el-message--success')).toContainText('保存成功')
    const piRow = page.locator('.el-table__row', { hasText: 'PI' }).first()
    await expect(piRow).toContainText('草稿')
    // 编辑弹窗复核：明细仅 SEMI 一行（MAT 0 行被剔除，未落库）
    await piRow.getByRole('button', { name: /编\s*辑/ }).click()
    const ed = page.locator('.el-dialog')
    await expect(ed.locator('.el-table__row')).toHaveCount(1)
    await expect(ed.locator('.el-table__row')).toContainText('SEMI-001')
    await ed.getByRole('button', { name: /取\s*消/ }).click()

    // 审核 → SEMI-001 余额 +10；订单变为部分入库
    await piRow.getByRole('button', { name: /审\s*核/ }).click()
    await page
      .locator('.el-message-box')
      .getByRole('button', { name: /确\s*定/ })
      .click()
    await expect(page.locator('.el-message--success').last()).toContainText('入库成功')
    const semiAfter = Number(
      (await apiGet(page, '/api/v1/inventory/balances', { keyword: 'SEMI-001' })).items[0].quantity,
    )
    expect(semiAfter).toBe(semiBefore + 10)
    // 再次从订单生成 PO3：仅剩 MAT 行、剩余 20（0 行商品留在订单上可再收）
    await page.getByRole('button', { name: /从订单生成/ }).click()
    const dialog2 = page.locator('.el-dialog')
    await dialog2.locator('.el-select', { hasText: '选择已审核/部分入库订单' }).click()
    await pickOption(page, poNo3)
    await expect(dialog2.locator('.el-table__row')).toHaveCount(1)
    await expect(dialog2.locator('.el-table__row')).toContainText('MAT-001')
    await expect(
      dialog2.locator('.el-table__row').locator('.el-input-number input').first(),
    ).toHaveValue('20.00')
    await page.keyboard.press('Escape')
  })

  test('TC-PUR-12 全部行填 0 保存被拦截', async ({ page }) => {
    await loginByAPI(page, 'admin', 'admin123')
    // 延续 TC-PUR-11 的 PO3（此时仅剩 MAT-001×20 未收）：唯一行填 0 → 保存被前端拦截
    await page.goto('/purchase/inbounds')
    await page.getByRole('button', { name: /从订单生成/ }).click()
    const dialog = page.locator('.el-dialog')
    await dialog.locator('.el-select', { hasText: '选择已审核/部分入库订单' }).click()
    await pickOption(page, poNo3)
    await expect(dialog.locator('.el-table__row')).toHaveCount(1)
    const matRow = dialog.locator('.el-table__row', { hasText: 'MAT-001' })
    await matRow.locator('.el-input-number input').first().fill('0')
    await matRow.locator('.el-input-number input').first().blur()
    await expect(matRow).toContainText('本次不收货')
    await dialog.locator('.el-select', { hasText: '选择仓库' }).click()
    await pickOption(page, '主仓')
    await dialog.locator('.el-select', { hasText: '选择库位' }).click()
    await pickOption(page, 'A-01')
    await dialog.getByRole('button', { name: /保\s*存/ }).click()
    await expect(page.locator('.el-message--warning')).toContainText(
      '请至少录入一个收货数量大于 0 的商品',
    )
    await page.keyboard.press('Escape')
  })

  test('TC-PUR-13 手动新增数量 0 仍被拦截', async ({ page }) => {
    await loginByAPI(page, 'admin', 'admin123')
    // UI：手动新增录入 0 → blur 被 el-input-number min=1 钳制（无法提交 0 数量）
    await page.goto('/purchase/inbounds')
    await page.getByRole('button', { name: /新\s*建/ }).click()
    const dialog = page.locator('.el-dialog')
    const row = dialog.locator('.el-table__row').nth(0)
    const qtyInput = row.locator('.el-input-number input').first()
    await qtyInput.fill('0')
    await qtyInput.blur()
    expect(Number(await qtyInput.inputValue())).toBeGreaterThanOrEqual(1)
    await dialog.getByRole('button', { name: /取\s*消/ }).click()
    // 后端兜底：手动新增（无订单）0 数量 → 1302「数量必须大于 0」
    const mat = await apiGet(page, '/api/v1/products', { keyword: 'MAT-001' })
    const manual = await apiPost(page, '/api/v1/purchase/inbounds', {
      supplier_id: supplierId,
      warehouse_id: 1,
      location_id: 1,
      items: [{ product_id: mat.items[0].id as number, quantity: 0, price: 500 }],
    })
    expect(manual.code).toBe(1302)
    expect(manual.message).toBe('数量必须大于 0')
  })

  test('TC-MST-04 补测：供应商被采购单据引用不可删（1109）', async ({ page }) => {
    await loginByAPI(page, 'admin', 'admin123')
    // 基础资料供应商页：删除「测试供应商」（被 PO 引用）→ 1109 拒绝
    await page.goto('/master/suppliers')
    const supRow = page.locator('.el-table__row', { hasText: 'SUP-001' }).first()
    await supRow.getByRole('button', { name: /删\s*除/ }).click()
    await page
      .locator('.el-message-box')
      .getByRole('button', { name: /确\s*定/ })
      .click()
    await expect(page.locator('.el-message--error')).toContainText(
      '供应商已被采购单据使用，不可删除',
    )
    // 供应商仍在列表中
    await expect(page.locator('.el-table__row', { hasText: 'SUP-001' }).first()).toBeVisible()
  })
})
