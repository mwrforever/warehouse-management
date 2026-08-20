// 库存管理模块 E2E：TC-INV-01~12（串行，余额随盘点变化）+ 基础资料 1106 删除保护补测
// 基线库存（InventorySeeder）：MAT-001=100@A-01、SEMI-001=30@A-01、FIN-002=20@B-01（主仓）
import { readFile } from 'node:fs/promises'
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
// PUT 请求辅助：修改类接口（如盘点单更新）走 PUT 方法
async function apiPut(page: Page, url: string, body?: unknown) {
  const token = await page.evaluate(() => localStorage.getItem('token'))
  const res = await page.request.put(url, {
    headers: { Authorization: `Bearer ${token}` },
    data: body,
  })
  return (await res.json()) as { code: number; message?: string; data?: unknown }
}

test.describe('库存管理模块', () => {
  // 用例间余额相互依赖（盘点变更库存），串行执行
  test.describe.configure({ mode: 'serial' })

  test('TC-INV-01 余额列表与筛选', async ({ page }) => {
    await loginByAPI(page, 'admin', 'admin123')
    await page.goto('/inventory/balances')
    await expect(page.locator('.el-table__row', { hasText: 'MAT-001' })).toContainText('100')
    await expect(page.locator('.el-table__row', { hasText: 'SEMI-001' })).toContainText('30')
    await expect(page.locator('.el-table__row', { hasText: 'FIN-002' })).toContainText('20')
    // 仓库筛选「主仓」+ 关键字 MAT（el-select 占位符为 div 文本，非 input placeholder）
    await page.locator('.filter-bar').getByText('仓库', { exact: true }).click()
    await page.locator('.el-select-dropdown__item', { hasText: '主仓' }).click()
    await page.getByPlaceholder('商品编码/名称/条码').fill('MAT')
    await page.getByRole('button', { name: /查\s*询/ }).click()
    await expect(page.locator('.el-table__row')).toHaveCount(1)
    await expect(page.locator('.el-table__row')).toContainText('MAT-001')
    // 类型筛选取「原料」
    await page.locator('.filter-bar').getByText('类型', { exact: true }).click()
    await page.locator('.el-select-dropdown__item', { hasText: '原料' }).click()
    await page.getByRole('button', { name: /查\s*询/ }).click()
    await expect(page.locator('.el-table__row')).toHaveCount(1)
  })

  test('TC-INV-02 余额导出 CSV（BOM/表头/行数）', async ({ page }) => {
    await loginByAPI(page, 'admin', 'admin123')
    await page.goto('/inventory/balances')
    const downloadPromise = page.waitForEvent('download')
    await page.getByRole('button', { name: /导\s*出/ }).click()
    const download = await downloadPromise
    const path = await download.path()
    expect(path).toBeTruthy()
    const csv = await readFile(path!, 'utf-8')
    // UTF-8 BOM 开头（中文无乱码）
    expect(csv.charCodeAt(0)).toBe(0xfeff)
    const lines = csv.trim().split('\n')
    expect(lines[0]).toBe('商品编码,商品名称,仓库,库位,数量,下限,上限,状态')
    expect(lines).toHaveLength(4) // 表头 + 3 行基线库存
    expect(lines[1]).toContain('MAT-001')
  })

  test('TC-INV-03 流水列表与筛选', async ({ page }) => {
    await loginByAPI(page, 'admin', 'admin123')
    await page.goto('/inventory/movements')
    // 基线采购入库流水：测试铝材(MAT-001) +100（商品列仅渲染名称；种子同秒插入、列表按 id 倒序）
    const matRow = page.locator('.el-table__row', { hasText: '测试铝材' }).first()
    await expect(matRow).toContainText('采购入库')
    await expect(matRow).toContainText('100')
    // 方向选「出库 -」→ 基线无出库流水 → 空态（el-table 内建空文本）
    await page.locator('.filter-bar').getByText('方向', { exact: true }).click()
    await page.locator('.el-select-dropdown__item', { hasText: '出库 -' }).click()
    await page.getByRole('button', { name: /查\s*询/ }).click()
    await expect(page.locator('.el-table__empty-text')).toContainText('暂无数据')
    // 重新进入页面重置筛选后：日期快捷「近 30 天」再查询
    await page.goto('/inventory/movements')
    await page.locator('.el-range-editor').click()
    await page.locator('.el-picker-panel__shortcut', { hasText: '近 30 天' }).click()
    await page.getByRole('button', { name: /查\s*询/ }).click()
    await expect(page.locator('.el-table__row').first()).toContainText('采购入库')
    // 单据类型选「盘盈」→ 当前无盘盈流水 → 空态
    await page.locator('.filter-bar').getByText('单据类型', { exact: true }).click()
    await page.locator('.el-select-dropdown__item', { hasText: '盘盈' }).click()
    await page.getByRole('button', { name: /查\s*询/ }).click()
    await expect(page.locator('.el-table__empty-text')).toContainText('暂无数据')
    // 重新进入重置筛选后：单号点击 → 采购入库来源跳采购入库单页（模块已实施；
    // 种子流水 source_id=0 无真实单据 → 落到入库单列表页，不再提示「随对应模块实施后开放」）
    await page.goto('/inventory/movements')
    await page.locator('.source-no').first().click()
    await expect(page).toHaveURL(/\/purchase\/inbounds/)
  })

  test('TC-INV-04 余额=流水恒等式核对', async ({ page }) => {
    await loginByAPI(page, 'admin', 'admin123')
    // 三个基线商品逐一核对：Σ(direction*quantity) == 当前余额
    for (const [keyword, expected] of [
      ['MAT-001', 100],
      ['SEMI-001', 30],
      ['FIN-002', 20],
    ] as const) {
      const balances = await apiGet(page, '/api/v1/inventory/balances', { keyword, per_page: 100 })
      expect(balances.items).toHaveLength(1)
      const balanceQty = Number(balances.items[0].quantity)
      const productId = balances.items[0].product_id
      const movements = await apiGet(page, '/api/v1/inventory/movements', {
        product_id: productId,
        per_page: 100,
      })
      const sum = movements.items.reduce(
        (acc: number, m: { direction: number; quantity: number }) =>
          acc + m.direction * Number(m.quantity),
        0,
      )
      expect(Number(sum.toFixed(2))).toBe(Number(Number(balanceQty).toFixed(2)))
      expect(Number(balanceQty)).toBe(expected)
    }
  })

  test('TC-INV-05 新建盘点单（加载账面数）', async ({ page }) => {
    await loginByAPI(page, 'admin', 'admin123')
    await page.goto('/inventory/checks')
    await page.getByRole('button', { name: /新\s*建/ }).click()
    const dialog = page.locator('.el-dialog')
    await dialog.locator('.check-toolbar').getByText('盘点仓库', { exact: true }).click()
    await page.locator('.el-select-dropdown__item', { hasText: '主仓' }).click()
    await dialog.getByRole('button', { name: /加\s*载账面数/ }).click()
    // 3 行明细：账面 100/30/20，实盘默认=账面
    await expect(dialog.locator('.el-table__row')).toHaveCount(3)
    await expect(dialog.locator('.el-table__row', { hasText: 'MAT-001' })).toContainText('100')
    await expect(dialog.locator('.el-table__row', { hasText: 'SEMI-001' })).toContainText('30')
    await expect(dialog.locator('.el-table__row', { hasText: 'FIN-002' })).toContainText('20')
    await dialog.getByRole('button', { name: /保\s*存/ }).click()
    await expect(page.locator('.el-message--success').last()).toContainText('保存成功')
    // 列表出现草稿单号 CK{date}-001
    await expect(page.locator('.el-table__row', { hasText: '草稿' })).toContainText('CK')
  })

  test('TC-INV-06 盘点单编辑与删除（已审核不可改删）', async ({ page }) => {
    await loginByAPI(page, 'admin', 'admin123')
    await page.goto('/inventory/checks')
    // 步骤 1：编辑草稿，MAT-001 实盘改 105
    const draftRow = page.locator('.el-table__row', { hasText: '草稿' }).first()
    await draftRow.getByRole('button', { name: /编\s*辑/ }).click()
    const dialog = page.locator('.el-dialog')
    const matRow = dialog.locator('.el-table__row', { hasText: 'MAT-001' })
    await matRow.locator('.el-input-number input').fill('105')
    await dialog.getByRole('button', { name: /保\s*存/ }).click()
    await expect(page.locator('.el-message--success').last()).toContainText('保存成功')
    // 步骤 2：再建一张全量单并审核（diff=0）
    await page.getByRole('button', { name: /新\s*建/ }).click()
    const dialog2 = page.locator('.el-dialog').last()
    await dialog2.locator('.check-toolbar').getByText('盘点仓库', { exact: true }).click()
    await page.locator('.el-select-dropdown__item', { hasText: '主仓' }).click()
    await dialog2.getByRole('button', { name: /加\s*载账面数/ }).click()
    // 等待账面数异步加载完成再保存：items 未就绪时 save() 会以「请先加载账面数」警告拦截且弹窗不关闭，
    // 而下一条「保存成功」断言会误匹配上一步编辑保存的残留消息（TC-INV-05 同款等待，此处补齐）
    await expect(dialog2.locator('.el-table__row')).toHaveCount(3)
    await dialog2.getByRole('button', { name: /保\s*存/ }).click()
    await expect(page.locator('.el-message--success').last()).toContainText('保存成功')
    const approvedRow = page.locator('.el-table__row', { hasText: '草稿' }).first()
    await approvedRow.getByRole('button', { name: /审\s*核/ }).click()
    await page
      .locator('.el-message-box')
      .last()
      .getByRole('button', { name: /确\s*定/ })
      .click()
    await page
      .locator('.el-message-box')
      .last()
      .getByRole('button', { name: /确\s*定/ })
      .click() // 结果弹窗
    await expect(page.locator('.el-table__row', { hasText: '已审核' })).toHaveCount(1)
    // 步骤 3：已审核单无编辑/删除入口；API 直改 → 1202
    await expect(
      page
        .locator('.el-table__row', { hasText: '已审核' })
        .getByRole('button', { name: /编\s*辑/ }),
    ).toHaveCount(0)
    const approvedNo = await page
      .locator('.el-table__row', { hasText: '已审核' })
      .locator('td')
      .first()
      .textContent()
    const list = await apiGet(page, '/api/v1/checks', { keyword: approvedNo?.trim() })
    const approved = list.items[0] as { id: number }
    // 明细用有余额的商品（MAT-001 真实 id）绕过 1205 无余额校验，验证 1202 已审核状态守卫
    const matBal = await apiGet(page, '/api/v1/inventory/balances', { keyword: 'MAT-001' })
    const put = await apiPut(page, `/api/v1/checks/${approved.id}`, {
      warehouse_id: 1,
      items: [{ product_id: matBal.items[0].product_id as number, location_id: 1, actual_qty: 1 }],
    })
    expect(put.code).toBe(1202)
    // 步骤 4：删除第一张草稿（MAT-001 105 那张）
    const draft = page.locator('.el-table__row', { hasText: '草稿' }).first()
    await draft.getByRole('button', { name: /删\s*除/ }).click()
    await page
      .locator('.el-message-box')
      .getByRole('button', { name: /确\s*定/ })
      .click()
    await expect(page.locator('.el-message--success').last()).toContainText('删除成功')
  })

  test('TC-INV-07 扫码盘点添加行', async ({ page }) => {
    await loginByAPI(page, 'admin', 'admin123')
    await page.goto('/inventory/checks')
    await page.getByRole('button', { name: /新\s*建/ }).click()
    const dialog = page.locator('.el-dialog')
    await dialog.locator('.check-toolbar').getByText('盘点仓库', { exact: true }).click()
    await page.locator('.el-select-dropdown__item', { hasText: '主仓' }).click()
    // 不点「加载账面数」，直接扫码：FIN-002 条码 888888
    await dialog.getByPlaceholder('扫描条码回车添加商品').fill('888888')
    await dialog.getByPlaceholder('扫描条码回车添加商品').press('Enter')
    const finRow = dialog.locator('.el-table__row', { hasText: 'FIN-002' })
    await expect(finRow).toBeVisible()
    await expect(finRow).toContainText('20')
    // 未匹配条码 000000 → 红色错误提示，不添加行
    await dialog.getByPlaceholder('扫描条码回车添加商品').fill('000000')
    await dialog.getByPlaceholder('扫描条码回车添加商品').press('Enter')
    await expect(page.locator('.el-message--error')).toContainText('条码未匹配')
    await expect(dialog.locator('.el-table__row')).toHaveCount(1)
  })

  test('TC-INV-08 盘点审核-盘盈（+5）', async ({ page }) => {
    await loginByAPI(page, 'admin', 'admin123')
    await page.goto('/inventory/checks')
    await page.getByRole('button', { name: /新\s*建/ }).click()
    const dialog = page.locator('.el-dialog')
    await dialog.locator('.check-toolbar').getByText('盘点仓库', { exact: true }).click()
    await page.locator('.el-select-dropdown__item', { hasText: '主仓' }).click()
    await dialog.getByRole('button', { name: /加\s*载账面数/ }).click()
    const matRow = dialog.locator('.el-table__row', { hasText: 'MAT-001' })
    await matRow.locator('.el-input-number input').fill('105')
    await dialog.getByRole('button', { name: /保\s*存/ }).click()
    await expect(page.locator('.el-message--success').last()).toContainText('保存成功')
    // 审核：确认框 → 结果弹窗「盘盈 1 项 +5、盘亏 0 项」
    const row = page.locator('.el-table__row', { hasText: '草稿' }).first()
    await row.getByRole('button', { name: /审\s*核/ }).click()
    await expect(page.locator('.el-message-box')).toContainText(
      '确认审核？差异将生成盘盈/盘亏流水并更新库存',
    )
    await page
      .locator('.el-message-box')
      .last()
      .getByRole('button', { name: /确\s*定/ })
      .click()
    await expect(page.locator('.el-message-box').last()).toContainText('盘盈 1 项 +5')
    await expect(page.locator('.el-message-box').last()).toContainText('盘亏 0 项')
    await page
      .locator('.el-message-box')
      .last()
      .getByRole('button', { name: /确\s*定/ })
      .click()
    // 余额页：MAT-001 = 105
    await page.goto('/inventory/balances')
    await expect(page.locator('.el-table__row', { hasText: 'MAT-001' })).toContainText('105')
    // 流水页筛「盘盈」：MAT-001 +5，来源单号 CK
    await page.goto('/inventory/movements')
    await page.locator('.filter-bar').getByText('单据类型', { exact: true }).click()
    await page.locator('.el-select-dropdown__item', { hasText: '盘盈' }).click()
    await page.getByRole('button', { name: /查\s*询/ }).click()
    const gainRow = page.locator('.el-table__row', { hasText: '测试铝材' })
    await expect(gainRow).toContainText('+')
    await expect(gainRow).toContainText('5')
    await expect(gainRow).toContainText('105')
    await expect(gainRow).toContainText('CK')
    // 单号点击：盘盈来源 → 跳盘点详情
    await gainRow.locator('.source-no').click()
    await expect(page.locator('.el-dialog')).toContainText('盘点单详情')
    await page.locator('.el-dialog').getByRole('button', { name: '关 闭', exact: true }).click()
  })

  test('TC-INV-09 盘点审核-盘亏（-2）', async ({ page }) => {
    await loginByAPI(page, 'admin', 'admin123')
    await page.goto('/inventory/checks')
    await page.getByRole('button', { name: /新\s*建/ }).click()
    const dialog = page.locator('.el-dialog')
    await dialog.locator('.check-toolbar').getByText('盘点仓库', { exact: true }).click()
    await page.locator('.el-select-dropdown__item', { hasText: '主仓' }).click()
    await dialog.getByRole('button', { name: /加\s*载账面数/ }).click()
    const semiRow = dialog.locator('.el-table__row', { hasText: 'SEMI-001' })
    await semiRow.locator('.el-input-number input').fill('28')
    await dialog.getByRole('button', { name: /保\s*存/ }).click()
    await expect(page.locator('.el-message--success').last()).toContainText('保存成功')
    const row = page.locator('.el-table__row', { hasText: '草稿' }).first()
    await row.getByRole('button', { name: /审\s*核/ }).click()
    await page
      .locator('.el-message-box')
      .last()
      .getByRole('button', { name: /确\s*定/ })
      .click()
    await expect(page.locator('.el-message-box').last()).toContainText('盘亏 1 项 -2')
    await page
      .locator('.el-message-box')
      .last()
      .getByRole('button', { name: /确\s*定/ })
      .click()
    // 余额页：SEMI-001 = 28
    await page.goto('/inventory/balances')
    await expect(page.locator('.el-table__row', { hasText: 'SEMI-001' })).toContainText('28')
    // 流水页筛「盘亏」：SEMI-001 -2
    await page.goto('/inventory/movements')
    await page.locator('.filter-bar').getByText('单据类型', { exact: true }).click()
    await page.locator('.el-select-dropdown__item', { hasText: '盘亏' }).click()
    await page.getByRole('button', { name: /查\s*询/ }).click()
    const lossRow = page.locator('.el-table__row', { hasText: '半成品A' })
    await expect(lossRow).toContainText('-')
    await expect(lossRow).toContainText('2')
    await expect(lossRow).toContainText('28')
    // 详情查看：diff 列 -2
    await page.goto('/inventory/checks')
    const approvedRow = page.locator('.el-table__row', { hasText: '已审核' }).first()
    await approvedRow.getByRole('button', { name: /查\s*看/ }).click()
    await expect(
      page.locator('.el-dialog').locator('.el-table__row', { hasText: 'SEMI-001' }),
    ).toContainText('-2')
    await page.locator('.el-dialog').getByRole('button', { name: '关 闭', exact: true }).click()
  })

  test('TC-INV-10 审核幂等与并发防重', async ({ page }) => {
    await loginByAPI(page, 'admin', 'admin123')
    // 幂等：对已审核单再次 approve → 1204，余额不变
    const list = await apiGet(page, '/api/v1/checks', { status: 1, per_page: 100 })
    const approved = list.items[0] as { id: number }
    const again = await apiPost(page, `/api/v1/checks/${approved.id}/approve`)
    expect(again.code).toBe(1204)
    expect(again.message).toBe('该盘点单已审核')
    const semiBalance = await apiGet(page, '/api/v1/inventory/balances', { keyword: 'SEMI-001' })
    expect(Number(semiBalance.items[0].quantity)).toBe(28)
    // 并发：同一草稿单双 approve，仅一个成功（SEMI-001 账面 28 → 实盘 30 盘盈 2）
    const semi = await apiGet(page, '/api/v1/inventory/balances', { keyword: 'SEMI-001' })
    const fresh = await apiPost(page, '/api/v1/checks', {
      warehouse_id: 1,
      items: [
        {
          product_id: semi.items[0].product_id,
          location_id: semi.items[0].location_id,
          actual_qty: 30,
        },
      ],
    })
    expect(fresh.code).toBe(0)
    const freshList = await apiGet(page, '/api/v1/checks', {
      keyword: (fresh.data as { no: string }).no,
    })
    const freshId = freshList.items[0].id as number
    const [r1, r2] = await Promise.all([
      apiPost(page, `/api/v1/checks/${freshId}/approve`),
      apiPost(page, `/api/v1/checks/${freshId}/approve`),
    ])
    const codes = [r1.code, r2.code].sort()
    expect(codes).toEqual([0, 1204])
    const after = await apiGet(page, '/api/v1/inventory/balances', { keyword: 'SEMI-001' })
    expect(Number(after.items[0].quantity)).toBe(30) // 仅变动一次
  })

  test('TC-INV-11 预警联动（低于下限出现与消除）', async ({ page }) => {
    await loginByAPI(page, 'admin', 'admin123')
    // 构造：MAT-001 盘点至 40（低于下限 50）并审核
    const mat = await apiGet(page, '/api/v1/inventory/balances', { keyword: 'MAT-001' })
    const matId = mat.items[0].product_id as number
    const locId = mat.items[0].location_id as number
    const low = await apiPost(page, '/api/v1/checks', {
      warehouse_id: 1,
      items: [{ product_id: matId, location_id: locId, actual_qty: 40 }],
    })
    expect(low.code).toBe(0)
    const lowList = await apiGet(page, '/api/v1/checks', {
      keyword: (low.data as { no: string }).no,
    })
    const lowApprove = await apiPost(page, `/api/v1/checks/${lowList.items[0].id}/approve`)
    expect(lowApprove.code).toBe(0)
    // 余额页：低库存红标签
    await page.goto('/inventory/balances')
    const matRow = page.locator('.el-table__row', { hasText: 'MAT-001' })
    await expect(matRow).toContainText('40')
    await expect(matRow.locator('.el-tag', { hasText: '低库存' })).toHaveClass(/danger/)
    // 预警页：汇总「低于下限 1 项」+ 红色卡片
    await page.goto('/inventory/alerts')
    await expect(page.locator('.summary-bar')).toContainText('低于下限 1 项')
    const card = page.locator('.alert-card', { hasText: 'MAT-001' })
    await expect(card).toContainText('40')
    await expect(card).toContainText('50')
    await expect(card).toHaveClass(/card-low/)
    // 恢复：盘点 MAT-001 至 60 → 预警消除
    const restore = await apiPost(page, '/api/v1/checks', {
      warehouse_id: 1,
      items: [{ product_id: matId, location_id: locId, actual_qty: 60 }],
    })
    expect(restore.code).toBe(0)
    const restoreList = await apiGet(page, '/api/v1/checks', {
      keyword: (restore.data as { no: string }).no,
    })
    const restoreApprove = await apiPost(page, `/api/v1/checks/${restoreList.items[0].id}/approve`)
    expect(restoreApprove.code).toBe(0)
    await page.goto('/inventory/balances')
    await expect(page.locator('.el-table__row', { hasText: 'MAT-001' })).toContainText('60')
    await page.goto('/inventory/alerts')
    await expect(page.locator('.summary-bar')).toContainText('低于下限 0 项')
    await expect(page.locator('.alert-card', { hasText: 'MAT-001' })).toHaveCount(0)
  })

  test('TC-INV-12 边界：负数与零差异', async ({ page }) => {
    await loginByAPI(page, 'admin', 'admin123')
    // 前端拦截：实盘输入负数被钳制（el-input-number min=0）
    await page.goto('/inventory/checks')
    await page.getByRole('button', { name: /新\s*建/ }).click()
    const dialog = page.locator('.el-dialog')
    await dialog.locator('.check-toolbar').getByText('盘点仓库', { exact: true }).click()
    await page.locator('.el-select-dropdown__item', { hasText: '主仓' }).click()
    await dialog.getByRole('button', { name: /加\s*载账面数/ }).click()
    const input = dialog
      .locator('.el-table__row', { hasText: 'MAT-001' })
      .locator('.el-input-number input')
    await input.fill('-5')
    await input.blur()
    expect(Number(await input.inputValue())).toBeGreaterThanOrEqual(0)
    // 后端拦截：API 直发负数 → 1201
    const mat = await apiGet(page, '/api/v1/inventory/balances', { keyword: 'MAT-001' })
    const neg = await apiPost(page, '/api/v1/checks', {
      warehouse_id: 1,
      items: [
        {
          product_id: mat.items[0].product_id,
          location_id: mat.items[0].location_id,
          actual_qty: -5,
        },
      ],
    })
    expect(neg.code).toBe(1201)
    expect(neg.message).toBe('实盘数量不能为负数')
    // 零差异：实盘=账面审核 → 无流水
    const zero = await apiPost(page, '/api/v1/checks', {
      warehouse_id: 1,
      items: [
        {
          product_id: mat.items[0].product_id,
          location_id: mat.items[0].location_id,
          actual_qty: 60,
        },
      ],
    })
    expect(zero.code).toBe(0)
    const zeroList = await apiGet(page, '/api/v1/checks', {
      keyword: (zero.data as { no: string }).no,
    })
    const zeroApprove = await apiPost(page, `/api/v1/checks/${zeroList.items[0].id}/approve`)
    expect(zeroApprove.code).toBe(0)
    expect((zeroApprove.data as { changed_items: number }).changed_items).toBe(0)
    // 无余额商品不可录盘 → 1205（RAW-001 铝材无库存）
    const raw = await apiGet(page, '/api/v1/products', { keyword: 'RAW-001', per_page: 100 })
    const rawId = raw.items[0].id as number
    const noBalance = await apiPost(page, '/api/v1/checks', {
      warehouse_id: 1,
      items: [{ product_id: rawId, location_id: 1, actual_qty: 1 }],
    })
    expect(noBalance.code).toBe(1205)
  })

  test('TC-MST-03 补测：仓库有库存不可删（1106）', async ({ page }) => {
    await loginByAPI(page, 'admin', 'admin123')
    // 基础资料仓库页：删除主仓（有基线库存）→ 1106 拒绝
    await page.goto('/master/warehouses')
    const whRow = page.locator('.el-table__row', { hasText: '主仓' }).first()
    await whRow.getByRole('button', { name: /删\s*除/ }).click()
    await page
      .locator('.el-message-box')
      .getByRole('button', { name: /确\s*定/ })
      .click()
    await expect(page.locator('.el-message--error')).toContainText('仓库存在库存，不可删除')
    // 仓库仍在列表中
    await expect(page.locator('.el-table__row', { hasText: '主仓' }).first()).toBeVisible()
  })
})
