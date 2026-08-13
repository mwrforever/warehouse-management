// 销售管理模块 E2E：TC-SAL-01~08（串行，库存随审核变化）+ 基础资料 TC-MST-05 客户删除保护补测
// 基线库存（InventorySeeder）：MAT-001=100@A-01、SEMI-001=30@A-01、FIN-002=20@B-01（主仓）
// 客户无种子 → TC-SAL-01 用 API 自建「测试客户 CUS-001」（已存在则复用）
// 库存末态随库存/采购模块测试变化 → 一律记录「当时余额 S₁/S₂」按增量断言
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
// PUT 请求辅助：修改类接口（订单/出库单更新）走 PUT 方法
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

test.describe('销售管理模块', () => {
  // 用例间库存/订单状态相互依赖，串行执行（UI 步骤多，放宽单用例超时）
  test.describe.configure({ mode: 'serial', timeout: 60_000 })

  // 用例共享：客户 id、FIN-002 余额基线 S₁、SEMI-001 基线 S₂、SEMI-001@B-01 准备量 S₂b、SO 单号
  let customerId = 0
  let s1 = 0
  let s2 = 0
  let s2b = 0
  let soNo = ''
  let so2No = ''

  test('TC-SAL-01 销售订单创建与审核（原料禁售）', async ({ page }) => {
    await loginByAPI(page, 'admin', 'admin123')
    // 数据准备：客户（无种子，API 自建幂等）
    const cusList = await apiGet(page, '/api/v1/customers', { keyword: 'CUS-001', per_page: 100 })
    if (cusList.total > 0) {
      customerId = cusList.items[0].id as number
    } else {
      const created = await apiPost(page, '/api/v1/customers', {
        name: '测试客户',
        code: 'CUS-001',
        status: 1,
      })
      expect(created.code).toBe(0)
      const after = await apiGet(page, '/api/v1/customers', { keyword: 'CUS-001', per_page: 100 })
      customerId = after.items[0].id as number
    }
    // 记录 FIN-002 / SEMI-001 当时余额（后续增量断言基线）
    const fin = await apiGet(page, '/api/v1/inventory/balances', { keyword: 'FIN-002' })
    s1 = Number(fin.items[0].quantity)
    const semi = await apiGet(page, '/api/v1/inventory/balances', { keyword: 'SEMI-001' })
    s2 = Number(semi.items[0].quantity)

    // 库存准备（库位错配修正）：种子 SEMI-001 仅 @A-01，但 TC-SAL-02 的订单出库单整单落 B-01
    // （from-order 弹窗无删除明细行能力，出库单必然同时含 FIN-002 与 SEMI-001 两行）
    // → 经采购入库 API 给 SEMI-001@B-01 补 50 库存（供应商 SUP-001 幂等解析），TC-SAL-02/05 的扣减发生在 B-01
    const supList = await apiGet(page, '/api/v1/suppliers', { keyword: 'SUP-001', per_page: 100 })
    // 供应商 id 先声明、分支内赋值（无初始值：初始 0 不会被读取，规避 eslint no-useless-assignment）
    let supId: number
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
    const whs0 = await apiGet(page, '/api/v1/warehouses', { keyword: '主仓' })
    const whId0 = whs0.items[0].id as number
    const locs0 = await apiGet(page, `/api/v1/warehouses/${whId0}/locations`)
    const b01Prep = locs0.items.find((l: { code: string }) => l.code === 'B-01') as { id: number }
    const semiProds = await apiGet(page, '/api/v1/products', { keyword: 'SEMI-001' })
    const semiIdPrep = semiProds.items[0].id as number
    const prepPi = await apiPost(page, '/api/v1/purchase/inbounds', {
      supplier_id: supId,
      warehouse_id: whId0,
      location_id: b01Prep.id,
      items: [{ product_id: semiIdPrep, quantity: 50, price: 100 }],
    })
    expect(prepPi.code).toBe(0)
    const prepPiNo = (prepPi.data as { no: string }).no
    const prepPiList = await apiGet(page, '/api/v1/purchase/inbounds', { keyword: prepPiNo })
    const prepApprove = await apiPost(
      page,
      `/api/v1/purchase/inbounds/${prepPiList.items[0].id}/approve`,
    )
    expect(prepApprove.code).toBe(0)
    // B-01 上 SEMI-001 的准备量（TC-SAL-05 断言基线；余额接口无 location_id 过滤参数，客户端按行筛选）
    const semiB01 = await apiGet(page, '/api/v1/inventory/balances', {
      keyword: 'SEMI-001',
      warehouse_id: whId0,
    })
    const b01Row = semiB01.items.find(
      (r: { location_id: number }) => r.location_id === b01Prep.id,
    ) as { quantity: number }
    s2b = Number(b01Row.quantity)
    expect(s2b).toBe(50)
    // 注意：S₂（A-01 余额）此后不再被 TC-SAL-02/05 触碰（扣减都在 B-01），TC-SAL-08 在 A-01 独立出库

    // 新建订单：客户 CUS-001；行1 FIN-002×10@100.00、行2 SEMI-001×5@20.00 → 合计 ¥1,100.00
    await page.goto('/sales/orders')
    await page.getByRole('button', { name: /新\s*建/ }).click()
    const dialog = page.locator('.el-dialog')
    await dialog.locator('.el-select', { hasText: '选择客户' }).click()
    await pickOption(page, '测试客户')
    // 原料禁售：商品下拉不含 MAT-001（仅成品/半成品）
    const rows0 = dialog.locator('.el-table__row')
    await rows0.nth(0).locator('.el-select', { hasText: '选择商品' }).click()
    await expect(page.getByRole('option', { name: /MAT-001/ })).toHaveCount(0)
    await pickOption(page, 'FIN-002')
    const rows = dialog.locator('.el-table__row')
    await rows.nth(0).locator('.el-input-number input').first().fill('10')
    await rows.nth(0).locator('.el-input-number input').nth(1).fill('100')
    await dialog.getByRole('button', { name: /添加明细行/ }).click()
    const rows2 = dialog.locator('.el-table__row')
    await rows2.nth(1).locator('.el-select', { hasText: '选择商品' }).click()
    await pickOption(page, 'SEMI-001')
    await rows2.nth(1).locator('.el-input-number input').first().fill('5')
    await rows2.nth(1).locator('.el-input-number input').nth(1).fill('20')
    // 行金额与合计实时（分→元展示）
    await expect(dialog.locator('.el-table__row').nth(0)).toContainText('¥1,000.00')
    await expect(dialog.locator('.total-amount')).toContainText('¥1,100.00')
    // 保存 → 列表出现 SO 草稿
    await dialog.getByRole('button', { name: /保\s*存/ }).click()
    await expect(page.locator('.el-message--success')).toContainText('保存成功')
    const row = page.locator('.el-table__row', { hasText: 'SO' })
    await expect(row).toContainText('草稿')
    await expect(row).toContainText('¥1,100.00')
    soNo = (await row.locator('td').first().textContent())?.trim() ?? ''
    expect(soNo).toMatch(/^SO\d{8}-\d{3}$/)
    // 审核：confirm → 状态绿「已审核」（.last()：上一条「保存成功」可能未消失，取最后一条避免 strict 冲突）
    await row.getByRole('button', { name: /审\s*核/ }).click()
    await expect(page.locator('.el-message-box')).toContainText('确认审核订单')
    await page
      .locator('.el-message-box')
      .getByRole('button', { name: /确\s*定/ })
      .click()
    await expect(page.locator('.el-message--success').last()).toContainText('审核成功')
    const approvedRow = page.locator('.el-table__row', { hasText: soNo })
    await expect(approvedRow).toContainText('已审核')
    // 幂等：API 重复审核 → 1404
    const list = await apiGet(page, '/api/v1/sales/orders', { keyword: soNo })
    const soId = list.items[0].id as number
    const again = await apiPost(page, `/api/v1/sales/orders/${soId}/approve`)
    expect(again.code).toBe(1404)
    expect(again.message).toBe('该订单已审核')
  })

  test('TC-SAL-02 从订单生成出库单并审核（部分出库）', async ({ page }) => {
    await loginByAPI(page, 'admin', 'admin123')
    await page.goto('/sales/outbounds')
    await page.getByRole('button', { name: /从订单生成/ }).click()
    const dialog = page.locator('.el-dialog')
    // 选订单 → 自动预填 2 行（FIN-002 剩余 10、SEMI-001 剩余 5）
    await dialog.locator('.el-select', { hasText: '选择已审核/部分出库订单' }).click()
    await pickOption(page, soNo)
    await expect(dialog.locator('.el-table__row')).toHaveCount(2)
    const finRow = dialog.locator('.el-table__row', { hasText: 'FIN-002' })
    await expect(finRow.locator('.el-input-number input').first()).toHaveValue('10.00')
    // FIN-002 数量改 6（≤剩余），选仓库主仓/B-01，保存 → 草稿 SOUT
    await finRow.locator('.el-input-number input').first().fill('6')
    await dialog.locator('.el-select', { hasText: '选择仓库' }).click()
    await pickOption(page, '主仓')
    await dialog.locator('.el-select', { hasText: '选择库位' }).click()
    await pickOption(page, 'B-01')
    await dialog.getByRole('button', { name: /保\s*存/ }).click()
    await expect(page.locator('.el-message--success')).toContainText('保存成功')
    const soutRow = page.locator('.el-table__row', { hasText: 'SOUT' })
    await expect(soutRow).toContainText('草稿')
    const soutNo = (await soutRow.locator('td').first().textContent())?.trim() ?? ''
    expect(soutNo).toMatch(/^SOUT\d{8}-\d{3}$/)
    // 审核：confirm「审核后库存将减少且不可修改」→ 成功消息「出库成功，库存已更新」（.last()：同上，防上一条成功消息未消失）
    await soutRow.getByRole('button', { name: /审\s*核/ }).click()
    await expect(page.locator('.el-message-box')).toContainText('确认审核出库单')
    await expect(page.locator('.el-message-box')).toContainText('审核后库存将减少且不可修改')
    await page
      .locator('.el-message-box')
      .getByRole('button', { name: /确\s*定/ })
      .click()
    await expect(page.locator('.el-message--success').last()).toContainText('出库成功，库存已更新')
    // 余额页：FIN-002 = S₁-6
    await page.goto('/inventory/balances')
    await expect(page.locator('.el-table__row', { hasText: 'FIN-002' })).toContainText(
      String(s1 - 6),
    )
    // 流水页筛「销售出库」：-6 流水、单号 SOUT、变动后余额 S₁-6（商品列仅渲染名称 → 按「成品B」定位）
    await page.goto('/inventory/movements')
    await page.locator('.toolbar').getByText('单据类型', { exact: true }).click()
    await pickOption(page, '销售出库')
    await page.getByRole('button', { name: /查\s*询/ }).click()
    const mvRow = page.locator('.el-table__row', { hasText: '成品B' }).first()
    await expect(mvRow).toContainText('-')
    await expect(mvRow).toContainText('6')
    await expect(mvRow).toContainText(String(s1 - 6))
    await expect(mvRow).toContainText(soutNo)
    // 单号点击 → 跳销售出库单详情弹窗（exact 匹配 footer「关 闭」，避开头部关闭图标按钮）
    await mvRow.locator('.source-no').click()
    await expect(page.locator('.el-dialog')).toContainText('出库单详情')
    await page.locator('.el-dialog').getByRole('button', { name: '关 闭', exact: true }).click()
    // 订单详情「出库记录」tab → 状态「部分出库」
    await page.goto('/sales/orders')
    const orderRow = page.locator('.el-table__row', { hasText: soNo })
    await expect(orderRow).toContainText('部分出库')
    await orderRow.getByRole('button', { name: /查\s*看/ }).click()
    await page.locator('.el-dialog').getByRole('tab', { name: '出库记录' }).click()
    await expect(page.locator('.el-dialog')).toContainText(soutNo)
    await page.locator('.el-dialog').getByRole('button', { name: '关 闭', exact: true }).click()
  })

  test('TC-SAL-03 超卖拦截（核心）', async ({ page }) => {
    await loginByAPI(page, 'admin', 'admin123')
    // 独立出库 FIN-002×9999 草稿可建（保存不校验余额）
    await page.goto('/sales/outbounds')
    await page.getByRole('button', { name: /新\s*建/ }).click()
    const dialog = page.locator('.el-dialog')
    await dialog.locator('.el-select', { hasText: '选择客户' }).click()
    await pickOption(page, '测试客户')
    const row = dialog.locator('.el-table__row').nth(0)
    await row.locator('.el-select', { hasText: '选择商品' }).click()
    await pickOption(page, 'FIN-002')
    await row.locator('.el-input-number input').first().fill('9999')
    await dialog.locator('.el-select', { hasText: '选择仓库' }).click()
    await pickOption(page, '主仓')
    await dialog.locator('.el-select', { hasText: '选择库位' }).click()
    await pickOption(page, 'B-01')
    await dialog.getByRole('button', { name: /保\s*存/ }).click()
    await expect(page.locator('.el-message--success')).toContainText('保存成功')
    // 等待列表刷新出草稿行（旧列表仍含 SOUT-001，避免单号取到旧行），再取单号
    const row2 = page.locator('.el-table__row', { hasText: 'SOUT' }).first()
    await expect(row2).toContainText('草稿')
    const oversellNo = (await row2.locator('td').first().textContent())?.trim() ?? ''
    await row2.getByRole('button', { name: /审\s*核/ }).click()
    await page
      .locator('.el-message-box')
      .getByRole('button', { name: /确\s*定/ })
      .click()
    await expect(page.locator('.el-message--error')).toContainText('库存不足')
    await expect(page.locator('.el-message--error')).toContainText('成品B')
    await expect(page.locator('.el-message--error')).toContainText(String(s1 - 6))
    await expect(page.locator('.el-table__row', { hasText: oversellNo })).toContainText('草稿')
    // 整体回滚：余额不变，无流水
    const fin = await apiGet(page, '/api/v1/inventory/balances', { keyword: 'FIN-002' })
    expect(Number(fin.items[0].quantity)).toBe(s1 - 6)
    const mv = await apiGet(page, '/api/v1/inventory/movements', { source_no: oversellNo })
    expect(mv.total).toBe(0)
    // 删除该草稿出库单
    const list = await apiGet(page, '/api/v1/sales/outbounds', { keyword: oversellNo })
    const del = await page.request.delete(`/api/v1/sales/outbounds/${list.items[0].id}`, {
      headers: {
        Authorization: `Bearer ${await page.evaluate(() => localStorage.getItem('token'))}`,
      },
    })
    expect(((await del.json()) as { code: number }).code).toBe(0)
  })

  test('TC-SAL-04 并发防超卖', async ({ page }) => {
    await loginByAPI(page, 'admin', 'admin123')
    // 前置：FIN-002 当前余额 = S₁-6；两张独立出库单各出 10（总 20 > 余额）
    const base = s1 - 6
    // 商品/仓库/库位 id 动态化（种子库主仓=1 稳定，但商品 id 随种子顺序漂移；B-01 库位按编码查）
    const prods = await apiGet(page, '/api/v1/products', { keyword: 'FIN-002' })
    const finId = prods.items[0].id as number
    const whs = await apiGet(page, '/api/v1/warehouses', { keyword: '主仓' })
    const whId = whs.items[0].id as number
    const locs = await apiGet(page, `/api/v1/warehouses/${whId}/locations`)
    const b01 = locs.items.find((l: { code: string }) => l.code === 'B-01') as { id: number }
    const payload = {
      customer_id: customerId,
      warehouse_id: whId,
      location_id: b01.id,
      items: [{ product_id: finId, quantity: 10, price: 10000 }],
    }
    const a = await apiPost(page, '/api/v1/sales/outbounds', payload)
    expect(a.code).toBe(0)
    const b = await apiPost(page, '/api/v1/sales/outbounds', payload)
    expect(b.code).toBe(0)
    const aNo = (a.data as { no: string }).no
    const bNo = (b.data as { no: string }).no
    // 并发审核：Promise.all 两张 approve → 恰好一张成功一张 1409
    const [ra, rb] = await Promise.all([
      apiPost(page, `/api/v1/sales/outbounds/${await outboundId(page, aNo)}/approve`),
      apiPost(page, `/api/v1/sales/outbounds/${await outboundId(page, bNo)}/approve`),
    ])
    const codes = [ra.code, rb.code].sort()
    expect(codes).toEqual([0, 1409])
    // 余额只扣一次：S₁-6-10
    const fin = await apiGet(page, '/api/v1/inventory/balances', { keyword: 'FIN-002' })
    expect(Number(fin.items[0].quantity)).toBe(base - 10)
    // 清理失败单（已审核单删除被拒则跳过）
    const failed = ra.code === 1409 ? aNo : bNo
    const failedList = await apiGet(page, '/api/v1/sales/outbounds', { keyword: failed })
    await page.request.delete(`/api/v1/sales/outbounds/${failedList.items[0].id}`, {
      headers: {
        Authorization: `Bearer ${await page.evaluate(() => localStorage.getItem('token'))}`,
      },
    })
  })

  test('TC-SAL-05 订单完成流转', async ({ page }) => {
    await loginByAPI(page, 'admin', 'admin123')
    // 对 SO-001 从订单生成出库单：预填仅 FIN-002 剩余 4 一行（SEMI-001 已在 TC-SAL-02 随单出完）
    await page.goto('/sales/outbounds')
    await page.getByRole('button', { name: /从订单生成/ }).click()
    const dialog = page.locator('.el-dialog')
    await dialog.locator('.el-select', { hasText: '选择已审核/部分出库订单' }).click()
    await pickOption(page, soNo)
    await expect(dialog.locator('.el-table__row')).toHaveCount(1)
    const finRow5 = dialog.locator('.el-table__row', { hasText: 'FIN-002' })
    await expect(finRow5.locator('.el-input-number input').first()).toHaveValue('4.00')
    await dialog.locator('.el-select', { hasText: '选择仓库' }).click()
    await pickOption(page, '主仓')
    await dialog.locator('.el-select', { hasText: '选择库位' }).click()
    await pickOption(page, 'B-01')
    await dialog.getByRole('button', { name: /保\s*存/ }).click()
    await expect(page.locator('.el-message--success')).toContainText('保存成功')
    // 等待列表刷新出草稿行（旧列表仍含 SOUT-001，避免审核点错行），再审核
    const row2 = page.locator('.el-table__row', { hasText: 'SOUT' }).first()
    await expect(row2).toContainText('草稿')
    await row2.getByRole('button', { name: /审\s*核/ }).click()
    await page
      .locator('.el-message-box')
      .getByRole('button', { name: /确\s*定/ })
      .click()
    await expect(page.locator('.el-message--success').last()).toContainText('出库成功')
    // 库存：FIN-002@B-01 = S₁-20（S₁-6-10-4）；SEMI-001@B-01 = s2b-5（TC-SAL-02/05 的扣减都在 B-01）
    const fin = await apiGet(page, '/api/v1/inventory/balances', { keyword: 'FIN-002' })
    expect(Number(fin.items[0].quantity)).toBe(s1 - 20)
    const whs5 = await apiGet(page, '/api/v1/warehouses', { keyword: '主仓' })
    const whId5 = whs5.items[0].id as number
    const locs5 = await apiGet(page, `/api/v1/warehouses/${whId5}/locations`)
    const b015 = locs5.items.find((l: { code: string }) => l.code === 'B-01') as { id: number }
    const semi5 = await apiGet(page, '/api/v1/inventory/balances', {
      keyword: 'SEMI-001',
      warehouse_id: whId5,
    })
    const semiB015 = semi5.items.find(
      (r: { location_id: number }) => r.location_id === b015.id,
    ) as { quantity: number }
    expect(Number(semiB015.quantity)).toBe(s2b - 5)
    // 订单状态「已完成」（绿）；从订单生成下拉不再出现该订单
    await page.goto('/sales/orders')
    await expect(page.locator('.el-table__row', { hasText: soNo })).toContainText('已完成')
    await page.goto('/sales/outbounds')
    await page.getByRole('button', { name: /从订单生成/ }).click()
    const dialog2 = page.locator('.el-dialog')
    await dialog2.locator('.el-select', { hasText: '选择已审核/部分出库订单' }).click()
    await expect(page.getByRole('option', { name: soNo })).toHaveCount(0)
    await page.keyboard.press('Escape')
  })

  test('TC-SAL-06 订单关闭', async ({ page }) => {
    await loginByAPI(page, 'admin', 'admin123')
    // 新订单 SO-002：SEMI-001×2 → 审核 → 关闭
    await page.goto('/sales/orders')
    await page.getByRole('button', { name: /新\s*建/ }).click()
    const dialog = page.locator('.el-dialog')
    await dialog.locator('.el-select', { hasText: '选择客户' }).click()
    await pickOption(page, '测试客户')
    const row = dialog.locator('.el-table__row').nth(0)
    await row.locator('.el-select', { hasText: '选择商品' }).click()
    await pickOption(page, 'SEMI-001')
    await row.locator('.el-input-number input').first().fill('2')
    await dialog.getByRole('button', { name: /保\s*存/ }).click()
    await expect(page.locator('.el-message--success')).toContainText('保存成功')
    // 等待列表刷新完成（旧列表含 SO-001 一行，避免 so2No 取到旧列表首行）
    await expect(page.locator('.el-table__row', { hasText: 'SO' })).toHaveCount(2)
    const so2Row = page.locator('.el-table__row', { hasText: 'SO' }).first()
    so2No = (await so2Row.locator('td').first().textContent())?.trim() ?? ''
    await so2Row.getByRole('button', { name: /审\s*核/ }).click()
    await page
      .locator('.el-message-box')
      .getByRole('button', { name: /确\s*定/ })
      .click()
    await expect(page.locator('.el-message--success').last()).toContainText('审核成功')
    const approved2 = page.locator('.el-table__row', { hasText: so2No })
    await approved2.getByRole('button', { name: /关\s*闭/ }).click()
    await page
      .locator('.el-message-box')
      .getByRole('button', { name: /确\s*定/ })
      .click()
    await expect(page.locator('.el-message--success').last()).toContainText('关闭成功')
    await expect(page.locator('.el-table__row', { hasText: so2No })).toContainText('关闭')
    // 关闭后不可再出库（下拉不出现）
    await page.goto('/sales/outbounds')
    await page.getByRole('button', { name: /从订单生成/ }).click()
    const dialog2 = page.locator('.el-dialog')
    await dialog2.locator('.el-select', { hasText: '选择已审核/部分出库订单' }).click()
    await expect(page.getByRole('option', { name: so2No })).toHaveCount(0)
    await page.keyboard.press('Escape')
    // 已完成订单不可关闭（API → 1405）
    const list = await apiGet(page, '/api/v1/sales/orders', { keyword: soNo })
    const doneId = list.items[0].id as number
    const close = await apiPost(page, `/api/v1/sales/orders/${doneId}/close`)
    expect(close.code).toBe(1405)
  })

  test('TC-SAL-07 审核幂等与改删保护', async ({ page }) => {
    await loginByAPI(page, 'admin', 'admin123')
    // 对已审核出库单重复 approve → 1410，余额不变
    const list = await apiGet(page, '/api/v1/sales/outbounds', { status: 1, per_page: 100 })
    const approved = list.items[0] as { id: number; no: string }
    const again = await apiPost(page, `/api/v1/sales/outbounds/${approved.id}/approve`)
    expect(again.code).toBe(1410)
    expect(again.message).toBe('该出库单已审核')
    // 已审核出库单改/删 → 1408
    const put = await apiPut(page, `/api/v1/sales/outbounds/${approved.id}`, {
      customer_id: customerId,
      warehouse_id: 1,
      location_id: 1,
      items: [{ product_id: 3, quantity: 1, price: 1 }],
    })
    expect(put.code).toBe(1408)
    // 余额复核：FIN-002 仍为 S₁-20
    const fin = await apiGet(page, '/api/v1/inventory/balances', { keyword: 'FIN-002' })
    expect(Number(fin.items[0].quantity)).toBe(s1 - 20)
  })

  test('TC-SAL-08 独立出库与边界（余额 0/重复商品）', async ({ page }) => {
    await loginByAPI(page, 'admin', 'admin123')
    // 独立出库 SEMI-001×1@20.00 → 审核 → SEMI-001 = S₂-6
    await page.goto('/sales/outbounds')
    await page.getByRole('button', { name: /新\s*建/ }).click()
    const dialog = page.locator('.el-dialog')
    await dialog.locator('.el-select', { hasText: '选择客户' }).click()
    await pickOption(page, '测试客户')
    const row = dialog.locator('.el-table__row').nth(0)
    await row.locator('.el-select', { hasText: '选择商品' }).click()
    await pickOption(page, 'SEMI-001')
    await row.locator('.el-input-number input').first().fill('1')
    await row.locator('.el-input-number input').nth(1).fill('20')
    await dialog.locator('.el-select', { hasText: '选择仓库' }).click()
    await pickOption(page, '主仓')
    await dialog.locator('.el-select', { hasText: '选择库位' }).click()
    await pickOption(page, 'A-01')
    await dialog.getByRole('button', { name: /保\s*存/ }).click()
    await expect(page.locator('.el-message--success')).toContainText('保存成功')
    // 等待列表刷新出草稿行（旧列表仍含 SOUT-001，避免审核点错行），再审核
    const row2 = page.locator('.el-table__row', { hasText: 'SOUT' }).first()
    await expect(row2).toContainText('草稿')
    await row2.getByRole('button', { name: /审\s*核/ }).click()
    await page
      .locator('.el-message-box')
      .getByRole('button', { name: /确\s*定/ })
      .click()
    await expect(page.locator('.el-message--success').last()).toContainText('出库成功')
    const semi = await apiGet(page, '/api/v1/inventory/balances', { keyword: 'SEMI-001' })
    // SEMI-001 现有多行余额（A-01 种子 + B-01 准备）→ 显式筛 A-01 行（本用例独立出库走 A-01）
    const whs8 = await apiGet(page, '/api/v1/warehouses', { keyword: '主仓' })
    const whId8 = whs8.items[0].id as number
    const locs8 = await apiGet(page, `/api/v1/warehouses/${whId8}/locations`)
    const a018 = locs8.items.find((l: { code: string }) => l.code === 'A-01') as { id: number }
    const semiA01 = semi.items.find((r: { location_id: number }) => r.location_id === a018.id) as {
      quantity: number
      product_id: number
      warehouse_id: number
      location_id: number
    }
    const semiNow = Number(semiA01.quantity)
    // A-01 的 SEMI-001 未被 TC-SAL-02/05 触碰（扣减在 B-01）→ 独立出库 1 后 = S₂-1
    expect(semiNow).toBe(s2 - 1)
    // 出库量=当前余额（把 SEMI-001 出到 0）→ 允许，余额 0（仓库/库位/商品 id 从余额行动态取，防种子顺序漂移）
    const semiId = semiA01.product_id as number
    const semiWhId = semiA01.warehouse_id as number
    const semiLocId = semiA01.location_id as number
    const drain = await apiPost(page, '/api/v1/sales/outbounds', {
      customer_id: customerId,
      warehouse_id: semiWhId,
      location_id: semiLocId,
      items: [{ product_id: semiId, quantity: semiNow, price: 2000 }],
    })
    expect(drain.code).toBe(0)
    const drainNo = (drain.data as { no: string }).no
    const drainList = await apiGet(page, '/api/v1/sales/outbounds', { keyword: drainNo })
    await apiPost(page, `/api/v1/sales/outbounds/${drainList.items[0].id}/approve`)
    const semiAfter = await apiGet(page, '/api/v1/inventory/balances', { keyword: 'SEMI-001' })
    expect(Number(semiAfter.items[0].quantity)).toBe(0)
    // 余额 0 再出 1 → 1409 精确提示「当前库存 0」
    const zero = await apiPost(page, '/api/v1/sales/outbounds', {
      customer_id: customerId,
      warehouse_id: semiWhId,
      location_id: semiLocId,
      items: [{ product_id: semiId, quantity: 1, price: 2000 }],
    })
    expect(zero.code).toBe(0)
    const zeroNo = (zero.data as { no: string }).no
    const zeroList = await apiGet(page, '/api/v1/sales/outbounds', { keyword: zeroNo })
    const zeroRes = await apiPost(page, `/api/v1/sales/outbounds/${zeroList.items[0].id}/approve`)
    expect(zeroRes.code).toBe(1409)
    expect(zeroRes.message).toBe('商品[半成品A]库存不足，当前库存 0')
    // 出库单明细重复商品 → 1412
    const dup = await apiPost(page, '/api/v1/sales/outbounds', {
      customer_id: customerId,
      warehouse_id: semiWhId,
      location_id: semiLocId,
      items: [
        { product_id: semiId, quantity: 1, price: 2000 },
        { product_id: semiId, quantity: 2, price: 2000 },
      ],
    })
    expect(dup.code).toBe(1412)
    // 清理零单草稿
    await page.request.delete(`/api/v1/sales/outbounds/${zeroList.items[0].id}`, {
      headers: {
        Authorization: `Bearer ${await page.evaluate(() => localStorage.getItem('token'))}`,
      },
    })
  })

  test('TC-MST-05 补测：客户被销售单据引用不可删（1111）', async ({ page }) => {
    await loginByAPI(page, 'admin', 'admin123')
    // 基础资料客户页：删除「测试客户」（被 SO/SOUT 引用）→ 1111 拒绝
    await page.goto('/master/customers')
    const cusRow = page.locator('.el-table__row', { hasText: 'CUS-001' }).first()
    await cusRow.getByRole('button', { name: /删\s*除/ }).click()
    await page
      .locator('.el-message-box')
      .getByRole('button', { name: /确\s*定/ })
      .click()
    await expect(page.locator('.el-message--error')).toContainText('客户已被销售单据使用，不可删除')
    // 客户仍在列表中
    await expect(page.locator('.el-table__row', { hasText: 'CUS-001' }).first()).toBeVisible()
  })
})

// 按单号查出库单 id（TC-SAL-04 并发场景复用；函数声明提升，可被上方 describe 内用例调用）
async function outboundId(page: Page, no: string): Promise<number> {
  const list = await apiGet(page, '/api/v1/sales/outbounds', { keyword: no })
  return list.items[0].id as number
}
