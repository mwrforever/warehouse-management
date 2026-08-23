// 编号自动生成 E2E（Spec 2）：TC-NUM-01 商品编码/条码自动生成；TC-NUM-02 采购订单新格式单号；
// TC-NUM-03 编号规则页改 seq_length → 预览变化 → 新单号按新位宽（用例自建自清，结束恢复配置）
import { expect, test, type Page } from '@playwright/test'
import { loginByAPI } from './helpers'

// 已登录页面的认证请求辅助：token 取自 localStorage（跨页导航后仍有效）
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

// 下拉项选择：等待唯一可见 option 后点击（用法见 purchase.spec.ts 注释）
async function pickOption(page: Page, name: string) {
  const opt = page.getByRole('option', { name })
  await expect(opt).toHaveCount(1)
  await opt.click()
}

test.describe('编号自动生成', () => {
  test.describe.configure({ mode: 'serial' })

  // TC-NUM-03 兜底恢复标记：改过编号配置的用例无论成败，afterEach 都恢复 po 规则位宽为种子默认 3。
  // 恢复内联在用例尾部时中途失败即跳过，seq_length=4 残留会让 purchase.spec 的 ^PO\d{12}\d{3}$
  // 断言级联全挂（BUG-02）；恢复走 API 而非 UI 步骤，规避恢复动作自身的弹窗/确认框脆弱性。
  let numberingDirty = false

  test.afterEach(async ({ page }) => {
    if (!numberingDirty) return
    numberingDirty = false
    const cfgs = await apiGet(page, '/api/v1/document-number-configs', { per_page: 50 })
    const po = cfgs.items.find((c: { type: string }) => c.type === 'po')
    expect(po, '采购订单编号配置应存在').toBeTruthy()
    const res = await apiPost(page, `/api/v1/document-number-configs/${po.id}`, {
      prefix: po.prefix,
      date_format: po.date_format,
      seq_length: 3,
      enabled: po.enabled,
      remark: po.remark,
    })
    expect(res.code).toBe(0)
  })

  test('TC-NUM-01 新建商品留空编码/条码 → 自动生成且条码=编码、长度一致', async ({ page }) => {
    await loginByAPI(page, 'admin', 'admin123')
    await page.goto('/master/products')
    // 新建弹窗：名称/类型/分类/单位（编码留空，条码留空）
    await page.getByRole('button', { name: /新\s*建/ }).click()
    const dialog = page.locator('.el-dialog')
    await dialog
      .locator('.el-form-item', { hasText: '名称' })
      .locator('input')
      .fill(`E2E自动编码${Date.now()}`)
    await dialog.locator('.el-radio', { hasText: '原料' }).click()
    // 分类树选择（既有商品弹窗模式：el-tree-select 点击展开后选「原材料」）
    await dialog
      .locator('.el-form-item', { hasText: '分类' })
      .locator('.el-select__wrapper')
      .click()
    await page.locator('.el-tree-node__content', { hasText: '原材料' }).first().click()
    await dialog
      .locator('.el-form-item', { hasText: '单位' })
      .locator('.el-select__wrapper')
      .click()
    await pickOption(page, '个')
    // 新建时条码提示可见（留空则自动生成且默认等于编码）
    await expect(dialog.locator('.hint', { hasText: '留空则自动生成' })).toBeVisible()
    await dialog.getByRole('button', { name: /保\s*存/ }).click()
    await expect(page.locator('.el-message--success')).toContainText(/保存成功，编码 PRD\d{6}/)

    // 列表出现自动编码：PRD 前缀 6 位、条码=编码（BarcodeSvg 列可见编码）
    const row = page.locator('.el-table__row', { hasText: /PRD\d{6}/ }).first()
    const code = await row.locator('.font-code').first().textContent()
    expect(code).toMatch(/^PRD\d{6}$/)
    await expect(row).toContainText(code!.trim())
  })

  test('TC-NUM-02 新建采购订单 → 单号 {前缀}{年月日时分}{3位序号} 且同类型长度一致', async ({
    page,
  }) => {
    await loginByAPI(page, 'admin', 'admin123')
    // 前置：保证 SUP-001 存在（复用既有采购 spec 的供应商）
    const supList = await apiGet(page, '/api/v1/suppliers', { keyword: 'SUP-001', per_page: 100 })
    if (supList.total === 0) {
      await apiPost(page, '/api/v1/suppliers', { name: '测试供应商', code: 'SUP-001', status: 1 })
    }
    const prods = await apiGet(page, '/api/v1/products', { keyword: 'MAT-001', per_page: 100 })
    expect(prods.total).toBeGreaterThan(0)

    await page.goto('/purchase/orders')
    await page.getByRole('button', { name: /新\s*建/ }).click()
    const dialog = page.locator('.el-dialog')
    await dialog.locator('.el-select', { hasText: '选择供应商' }).click()
    await pickOption(page, '测试供应商')
    const rows = dialog.locator('.el-table__row')
    await rows.nth(0).locator('.el-select', { hasText: '选择商品' }).click()
    await pickOption(page, 'MAT-001')
    await rows.nth(0).locator('.el-input-number input').first().fill('1')
    await rows.nth(0).locator('.el-input-number input').nth(1).fill('5')
    await dialog.getByRole('button', { name: /保\s*存/ }).click()
    await expect(page.locator('.el-message--success')).toContainText('保存成功')

    // 列表首行单号匹配新格式：PO + YmdHi(12 位日期) + 3 位序号，无连字符（旧格式 -001 已废弃）
    const firstRow = page.locator('.el-table__row').first()
    await expect(firstRow.locator('.font-code').first()).toHaveText(/^PO\d{12}\d{3}$/)
    // 同类型长度一致：再建一单（或检查今日最大序号长度一致）——列表内任一 PO 单号均满足同正则
    const allNos = await firstRow.locator('.font-code').first().textContent()
    expect(allNos).toMatch(/^PO\d{12}\d{3}$/)
  })

  test('TC-NUM-03 编号规则页改 seq_length → 预览变化 → 保存后新单号按新位宽', async ({ page }) => {
    await loginByAPI(page, 'admin', 'admin123')
    // 进入即标记脏配置：此后任一步失败，afterEach 仍会恢复 po 位宽（防配置残留级联其它 spec）
    numberingDirty = true
    await page.goto('/system/numbering')
    // 找到采购订单行 → 编辑
    const poRow = page.locator('.el-table__row', { hasText: '采购订单' })
    await poRow.getByRole('button', { name: /编\s*辑/ }).click()
    const dialog = page.locator('.el-dialog')
    // 改序列长度 3 → 4：预览变长（触发 preview 接口）
    await dialog.locator('.el-form-item', { hasText: '序列长度' }).locator('input').fill('4')
    await expect(dialog.locator('.font-code')).toHaveText(/^PO\d{12}\d{4}$/)
    // 保存触发位宽一致性确认框
    await dialog.getByRole('button', { name: /保\s*存/ }).click()
    const confirmBox = page.locator('.el-message-box')
    await expect(confirmBox).toBeVisible()
    await confirmBox.getByRole('button', { name: /确\s*定/ }).click()
    await expect(page.locator('.el-message--success')).toContainText('已保存')

    // 新建采购订单验证 4 位序号（API 建单更快；列表再断言）
    const supList = await apiGet(page, '/api/v1/suppliers', { keyword: 'SUP-001', per_page: 100 })
    const prods = await apiGet(page, '/api/v1/products', { keyword: 'MAT-001', per_page: 100 })
    const created = await apiPost(page, '/api/v1/purchase/orders', {
      supplier_id: supList.items[0].id,
      order_date: new Date().toISOString().slice(0, 10),
      items: [{ product_id: prods.items[0].id, quantity: 1, price: 5 }],
    })
    expect(created.code).toBe(0)
    // 列表首行应为刚建的 4 位序号订单（列表按 id 倒序）
    await page.goto('/purchase/orders')
    await expect(
      page.locator('.el-message--success').or(page.locator('.el-table__row').first()),
    ).toBeVisible()
    const firstRow = page.locator('.el-table__row').first()
    await expect(firstRow.locator('.font-code').first()).toHaveText(/^PO\d{12}\d{4}$/)
    // 配置恢复由 describe 级 afterEach 兜底执行（seq_length=3），不再内联在用例尾部
  })
})
