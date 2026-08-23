// 前端交互基础设施 E2E：TC-FR-01~06（串行；数据自建自清）
// 依赖种子：FIN-002 条码 888888、admin 用户（姓名「管理员」）
// 种子无 PO/MO 单据 → 用例经 API 自建并清理：
//   TC-FR-01/02 自建供应商+11 张草稿采购单（凑 2 页分页）后删除；
//   TC-FR-03/06 自建 FIN-002 启用 BOM + 生产中工单，已开工工单不可经 API 删除（destroy 仅草稿 1504）
//   → 直连 e2e.sqlite（artisan tinker）级联清理，保证不污染后跑的 production/purchase spec 单行匹配假设
import { expect, test, type Page } from '@playwright/test'
import { execSync } from 'node:child_process'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import { loginByAPI } from './helpers'

const __dirname = path.dirname(fileURLToPath(import.meta.url))

// 已登录页面的认证请求辅助：token 取自 localStorage（与 purchase/production spec 同构）
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

// 认证删除辅助（清理用）：断言业务 code=0——后端统一 HTTP 200 由 code 承载结果，
// 仅看 HTTP 层（res.ok）会漏掉业务失败（如校验拒绝）导致的静默残留
async function apiDelete(page: Page, url: string) {
  const token = await page.evaluate(() => localStorage.getItem('token'))
  const res = await page.request.delete(url, { headers: { Authorization: `Bearer ${token}` } })
  expect((await res.json()).code).toBe(0)
}

// 当日日期字符串（单据下单/计划日期默认今天，按本地时区拼装）
function todayStr(): string {
  const d = new Date()
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

// 供应商 SUP-FR（幂等自建；与采购 spec 的 SUP-001 隔离，用例结束随订单一并删除）
async function ensureSupplier(page: Page): Promise<number> {
  const list = await apiGet(page, '/api/v1/suppliers', { keyword: 'SUP-FR', per_page: 100 })
  if (list.total > 0) return (list.items as { id: number }[])[0].id
  const created = await apiPost(page, '/api/v1/suppliers', {
    name: '前端基础设施测试供应商',
    code: 'SUP-FR',
    status: 1,
  })
  expect(created.code).toBe(0)
  const after = await apiGet(page, '/api/v1/suppliers', { keyword: 'SUP-FR', per_page: 100 })
  return (after.items as { id: number }[])[0].id
}

// 批量创建草稿采购单（凑分页；创建响应无 id，按供应商回查 id 供清理）
async function createPODrafts(page: Page, supplierId: number, count: number): Promise<number[]> {
  const prods = await apiGet(page, '/api/v1/products', { keyword: 'MAT-001', per_page: 100 })
  const productId = (prods.items as { id: number }[])[0].id
  for (let i = 0; i < count; i++) {
    const res = await apiPost(page, '/api/v1/purchase/orders', {
      supplier_id: supplierId,
      order_date: todayStr(),
      items: [{ product_id: productId, quantity: 1, price: 100 }],
    })
    expect(res.code).toBe(0)
  }
  const list = await apiGet(page, '/api/v1/purchase/orders', {
    per_page: 100,
    supplier_id: supplierId,
  })
  const ids = (list.items as { id: number }[]).map((i) => i.id)
  expect(ids).toHaveLength(count)
  return ids
}

// FIN-002 启用 BOM 幂等自建（生产工单创建前置；生产 spec 同款幂等复用，BOM 保留供后续 spec 复用）
async function ensureBomForFin002(page: Page): Promise<void> {
  const fin = await apiGet(page, '/api/v1/products', { keyword: 'FIN-002', per_page: 100 })
  const finId = (fin.items as { id: number }[])[0].id
  const boms = await apiGet(page, '/api/v1/boms', { product_id: finId, per_page: 100 })
  if ((boms.items as { status: number }[]).some((b) => b.status === 1)) return
  const mat = await apiGet(page, '/api/v1/products', { keyword: 'MAT-001', per_page: 100 })
  const units = await apiGet(page, '/api/v1/units', { per_page: 100 })
  const pcId = (units.items as { code: string; id: number }[]).find((u) => u.code === 'pc')?.id
  const res = await apiPost(page, '/api/v1/boms', {
    product_id: finId,
    version: 'V1',
    quantity: 1,
    status: 1,
    items: [{ material_id: (mat.items as { id: number }[])[0].id, quantity: 2, unit_id: pcId }],
  })
  expect(res.code).toBe(0)
}

// 自建生产中工单（创建→下达→开工，status=2 报工/领料入口可点）
async function createProducingOrder(page: Page): Promise<{ id: number; no: string }> {
  const fin = await apiGet(page, '/api/v1/products', { keyword: 'FIN-002', per_page: 100 })
  const finId = (fin.items as { id: number }[])[0].id
  const created = await apiPost(page, '/api/v1/production/orders', {
    product_id: finId,
    quantity: 5,
    plan_date: todayStr(),
  })
  expect(created.code).toBe(0)
  const { id, no } = created.data as { id: number; no: string }
  const rel = await apiPost(page, `/api/v1/production/orders/${id}/release`)
  expect(rel.code).toBe(0)
  const start = await apiPost(page, `/api/v1/production/orders/${id}/start`)
  expect(start.code).toBe(0)
  return { id, no }
}

// 直连库清理已开工工单：destroy 仅草稿（1504），经 artisan tinker 连 e2e.sqlite 级联删除（操作/物料/报工随 FK 级联）
// 删除行数经 stdout 返回并断言非 0：清理失败立即报错，避免遗留生产中工单污染后跑 spec 的单行匹配假设
function deleteStartedOrderByNo(no: string) {
  const serverDir = path.resolve(__dirname, '..', '..', 'server')
  const cmd = `php artisan tinker --execute="echo App\\Models\\ProductionOrder::where('no', '${no}')->delete();"`
  const stdout = execSync(cmd, {
    cwd: serverDir,
    env: { ...process.env, DB_CONNECTION: 'sqlite', DB_DATABASE: 'database/e2e.sqlite' },
    stdio: 'pipe',
    encoding: 'utf8',
  })
  expect(parseInt(stdout.trim(), 10)).toBeGreaterThan(0)
}

test.describe('前端交互基础设施', () => {
  test.describe.configure({ mode: 'serial', timeout: 60_000 })

  // 用例共享（describe 顶层声明）：各用例自建数据，随用例结束清理（自建自清，互不依赖）
  let createdSupplierId = 0
  let createdPoIds: number[] = []
  let createdMoNo = ''

  // 每条用例结束后清理自建数据：草稿采购单/供应商可经 API 删，生产中工单走直连库删除
  test.afterEach(async ({ page }) => {
    for (const id of createdPoIds) {
      // 删除结果必须校验：失败即报错中止，避免 11 张草稿单静默残留污染后续 spec 的 .first() 匹配假设
      await apiDelete(page, `/api/v1/purchase/orders/${id}`)
    }
    createdPoIds = []
    if (createdSupplierId) {
      await apiDelete(page, `/api/v1/suppliers/${createdSupplierId}`)
      createdSupplierId = 0
    }
    if (createdMoNo) {
      const no = createdMoNo
      createdMoNo = ''
      deleteStartedOrderByNo(no)
    }
  })

  test('TC-FR-01 列表关键字实时搜索：输入停 300ms 自动查询且页码重置', async ({ page }) => {
    await loginByAPI(page, 'admin', 'admin123')
    // 数据准备：供应商 + 11 张草稿采购单（种子无 PO 数据，自建凑 2 页分页）
    createdSupplierId = await ensureSupplier(page)
    createdPoIds = await createPODrafts(page, createdSupplierId, 11)
    await page.goto('/purchase/orders')
    const pagination = page.locator('.el-pagination')
    // 等初始列表加载（11 条 → 分页出现 2 页），再翻到第 2 页并等其响应结算（防后续 keyword 拦截误捕 page=2 响应）
    await expect(pagination.locator('.el-pager li.number')).toHaveCount(2)
    const [p2] = await Promise.all([
      page.waitForResponse(
        (r) => r.url().includes('/api/v1/purchase/orders') && r.request().method() === 'GET',
      ),
      pagination.getByText('2', { exact: true }).click(),
    ])
    expect(new URL(p2.url()).searchParams.get('page')).toBe('2')
    await expect(page.locator('.el-pagination .is-active')).toContainText('2')
    // 输入关键字：拦截下一次 orders 请求，断言自动回首页（page=1）且带 keyword
    const [resp] = await Promise.all([
      page.waitForResponse(
        (r) => r.url().includes('/api/v1/purchase/orders') && r.request().method() === 'GET',
      ),
      page.locator('.filter-bar input').first().fill('PO'),
    ])
    expect(new URL(resp.url()).searchParams.get('page')).toBe('1')
    expect(new URL(resp.url()).searchParams.get('keyword')).toBe('PO')
  })

  test('TC-FR-02 查询/重置/刷新：重置清空筛选回默认、刷新保持当前页', async ({ page }) => {
    await loginByAPI(page, 'admin', 'admin123')
    createdSupplierId = await ensureSupplier(page)
    createdPoIds = await createPODrafts(page, createdSupplierId, 11)
    await page.goto('/purchase/orders')
    const pagination = page.locator('.el-pagination')
    await expect(pagination.locator('.el-pager li.number')).toHaveCount(2)
    // 输入关键字并等防抖查询（回首页 + 带 keyword，全部草稿单号以 PO 开头命中）
    await Promise.all([
      page.waitForResponse((r) => r.url().includes('/api/v1/purchase/orders')),
      page.locator('.filter-bar input').first().fill('PO'),
    ])
    // 翻到第 2 页并等其响应结算
    const [p2] = await Promise.all([
      page.waitForResponse((r) => r.url().includes('/api/v1/purchase/orders')),
      pagination.getByText('2', { exact: true }).click(),
    ])
    expect(new URL(p2.url()).searchParams.get('page')).toBe('2')
    await expect(page.locator('.el-pagination .is-active')).toContainText('2')
    // 刷新：保持当前页（拦截请求断言 page=2；限定筛选栏内按钮，避开顶栏「刷新当前页」图标按钮）
    const [refResp] = await Promise.all([
      page.waitForResponse((r) => r.url().includes('/api/v1/purchase/orders')),
      page
        .locator('.filter-bar')
        .getByRole('button', { name: /刷\s*新/ })
        .click(),
    ])
    expect(new URL(refResp.url()).searchParams.get('page')).toBe('2')
    // 重置：关键字清空 + 回第 1 页
    await page
      .locator('.filter-bar')
      .getByRole('button', { name: /重\s*置/ })
      .click()
    await expect(page.locator('.filter-bar input').first()).toHaveValue('')
    await expect(page.locator('.el-pagination .is-active')).toContainText('1')
  })

  test('TC-FR-03 报工操作人默认预填当前登录用户且可改选', async ({ page }) => {
    await loginByAPI(page, 'admin', 'admin123')
    // 数据准备：FIN-002 启用 BOM + 生产中工单（操作人 UserSelect 仅在进行中工序卡片内渲染，需先选中工单）
    await ensureBomForFin002(page)
    const mo = await createProducingOrder(page)
    createdMoNo = mo.no
    await page.goto(`/production/reports?order_id=${mo.id}`)
    // 操作人表单项预填当前登录用户姓名「管理员」（选中值以 span 渲染，不落入 input value）
    const opItem = page.locator('.report-card .el-form-item').filter({ hasText: '操作人' })
    await expect(opItem).toContainText('管理员')
    // 可改选：点击打开用户下拉，当前登录用户仍在可选项（具体选人逻辑由单测覆盖）
    await page.locator('.report-card .el-select').click()
    await expect(page.getByRole('option', { name: '管理员' })).toBeVisible()
  })

  test('TC-FR-04 扫码弹窗逐件扫描关：扫码→填数量→确定→行合并数量相加', async ({ page }) => {
    await loginByAPI(page, 'admin', 'admin123')
    await page.goto('/purchase/inbounds')
    await page.getByRole('button', { name: /新\s*建/ }).click()
    const dialog = page.locator('.el-dialog').last()
    await dialog.getByRole('button', { name: /扫码添加/ }).click()
    const scan = page.locator('.el-dialog').last()
    // 默认逐件关、累加开：扫码 → 填数量 → 确定
    await scan.getByPlaceholder('扫描条码回车添加商品').fill('888888')
    await scan.getByPlaceholder('扫描条码回车添加商品').press('Enter')
    await expect(scan.locator('.pending-row')).toBeVisible()
    await scan.getByPlaceholder('数量').fill('5')
    await scan.getByRole('button', { name: /确\s*定/ }).click()
    await expect(scan.locator('.preview-table')).toContainText('FIN-002')
    // 再次扫码同商品 → 合并数量相加（累加开）
    await scan.getByPlaceholder('扫描条码回车添加商品').fill('888888')
    await scan.getByPlaceholder('扫描条码回车添加商品').press('Enter')
    await scan.getByPlaceholder('数量').fill('3')
    await scan.getByRole('button', { name: /确\s*定/ }).click()
    await expect(scan.locator('.preview-table')).toContainText('8')
    // 关闭回带：等扫码弹窗完全隐藏后，在「可见的新建弹窗」内断言回带行合并数量（
    // 避免 .last() 懒解析命中已关闭但仍留在 DOM 的扫码预览表——carry-over 强化点）
    await scan.getByRole('button', { name: /关\s*闭/ }).click()
    await expect(scan).toBeHidden()
    const createDialog = page.locator('.el-dialog').filter({ visible: true })
    await expect(createDialog.locator('.el-table__row')).toHaveCount(2)
    // 回带行数量 8 落在 el-input-number 输入框（非行文本）→ 断言其值
    const finRow = createDialog.locator('.el-table__row', { hasText: 'FIN-002' })
    await expect(finRow.locator('.el-input-number input').first()).toHaveValue('8.00')
  })

  test('TC-FR-05 扫码弹窗自动累加关：同条码再次扫码报错「该商品已在列表中」', async ({ page }) => {
    await loginByAPI(page, 'admin', 'admin123')
    await page.goto('/purchase/inbounds')
    await page.getByRole('button', { name: /新\s*建/ }).click()
    const dialog = page.locator('.el-dialog').last()
    await dialog.getByRole('button', { name: /扫码添加/ }).click()
    const scan = page.locator('.el-dialog').last()
    // 关闭自动累加（第 2 个开关）
    await scan.locator('.el-switch').nth(1).click()
    await scan.getByPlaceholder('扫描条码回车添加商品').fill('888888')
    await scan.getByPlaceholder('扫描条码回车添加商品').press('Enter')
    await scan.getByPlaceholder('数量').fill('2')
    await scan.getByRole('button', { name: /确\s*定/ }).click()
    // 再次扫码同商品 → 报错「该商品已在列表中」（累加关判重拦截，行不合并）
    await scan.getByPlaceholder('扫描条码回车添加商品').fill('888888')
    await scan.getByPlaceholder('扫描条码回车添加商品').press('Enter')
    await scan.getByPlaceholder('数量').fill('2')
    await scan.getByRole('button', { name: /确\s*定/ }).click()
    await expect(page.locator('.el-message--error')).toContainText('该商品已在列表中')
  })

  test('TC-FR-06 生产工单「报 工」→「领 料」跳转无闪白，页面正常渲染', async ({ page }) => {
    await loginByAPI(page, 'admin', 'admin123')
    // 数据准备：自建生产中工单（种子无 MO 数据），经 API 下达/开工
    await ensureBomForFin002(page)
    const mo = await createProducingOrder(page)
    createdMoNo = mo.no
    await page.goto('/production/orders')
    // 找一张生产中工单点击「报 工」
    const prodRow = page.locator('.el-table__row', { hasText: '生产中' }).first()
    await prodRow.getByRole('button', { name: /报\s*工/ }).click()
    await expect(page).toHaveURL(/\/production\/reports/)
    // 报工页正常渲染（页面标题，限定 .page-title 避开侧边栏同名菜单项）
    await expect(page.locator('.page-title', { hasText: '工序报工' })).toBeVisible()
    // 返回工单列表，点「领 料」跳转：页面正常渲染
    await page.goto('/production/orders')
    const prodRow2 = page.locator('.el-table__row', { hasText: '生产中' }).first()
    await prodRow2.getByRole('button', { name: /领\s*料/ }).click()
    await expect(page).toHaveURL(/\/production\/picks/)
    await expect(page.locator('.page-title', { hasText: '领料单' })).toBeVisible()
  })
})
