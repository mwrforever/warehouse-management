// 仪表盘模块 E2E：TC-DSH-01~08（KPI 核对/待审核跳转/工单进度/预警/联动刷新/空态/权限过滤/接口容错）
// 数据依赖：上游 7 个业务模块 spec 遗留的真实数据（生产模块完成态工单、今日流水等），
// 本 spec 自建数据（草稿采购单/预警商品/limited01）全部按需清理——期望值一律 API 交叉核对，零硬编码
// 文件命名锁定 zz-dashboard.spec.ts：字典序 system < zz-dashboard（必须跑在全部业务 spec 之后）——
// 自然命名 dashboard.spec.ts 会排在 inventory.spec 之前（d < i），届时仅种子数据、自建流水/余额
// 将破坏 inventory.spec 硬编码基线断言（MAT-001=100/FIN-002=20/盘点弹窗 toHaveCount(3)），
// 且已下达工单/已审核单据不可删——本文件为最后一篇，残留不影响任何后续 spec
import { expect, test, type Page } from '@playwright/test'
import { loginByAPI } from './helpers'

// 已登录页面的认证请求辅助：token 取自 localStorage（与 stats-report.spec 同构）
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
async function apiDelete(page: Page, url: string) {
  const token = await page.evaluate(() => localStorage.getItem('token'))
  const res = await page.request.delete(url, { headers: { Authorization: `Bearer ${token}` } })
  return (await res.json()) as { code: number; message?: string }
}

// 本地日期 YYYY-MM-DD（toISOString 为 UTC 会偏移一天，上游 spec 同款辅助）
function todayStr(): string {
  const d = new Date()
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

// 千分位（与前端 formatThousand 的 toLocaleString('zh-CN') 输出一致，KPI 文本断言用）
function thousand(s: string): string {
  return s.replace(/\B(?=(\d{3})+(?!\d))/g, ',')
}

// 待审核草稿总数：9 类单据列表接口 status=0 累加（与后端 pendingData 9 类同口径；
// 列表客户端过滤 status 而非传参——各模块 status 参数行为差异的防御）
const DRAFT_ENDPOINTS = [
  '/api/v1/purchase/orders',
  '/api/v1/purchase/inbounds',
  '/api/v1/sales/orders',
  '/api/v1/sales/outbounds',
  '/api/v1/checks',
  '/api/v1/production/picks',
  '/api/v1/production/returns',
  '/api/v1/production/outsourcings',
  '/api/v1/production/finished-inbounds',
]
async function countDrafts(page: Page): Promise<number> {
  let n = 0
  for (const url of DRAFT_ENDPOINTS) {
    const data = await apiGet(page, url, { per_page: 100 })
    n += (data.items as { status?: number }[]).filter((i) => i.status === 0).length
  }
  return n
}

// 供应商确保存在（种子无供应商；不存在则自建 DSH 供应商）
async function ensureSupplier(page: Page): Promise<{ id: number }> {
  const list = await apiGet(page, '/api/v1/suppliers', { per_page: 100 })
  const items = list.items as { id: number }[]
  if (items.length > 0) return { id: items[0]!.id }
  const res = await apiPost(page, '/api/v1/suppliers', {
    name: 'DSH 供应商',
    code: 'SUP-DSH',
    status: 1,
  })
  expect(res.code).toBe(0)
  return { id: (res.data as { id: number }).id }
}

// 新建采购订单草稿（payload 与 PurchaseOrderController validatePayload 对齐：supplier_id/order_date/items）
// store 响应仅含单号 no（现行实现不含 id）→ id 经列表接口按单号反查（TC-PUR-02 同款模式，删除/审核用例用）
async function createDraftPo(
  page: Page,
  supplierId: number,
  productId: number,
): Promise<{ id: number; no: string }> {
  const res = await apiPost(page, '/api/v1/purchase/orders', {
    supplier_id: supplierId,
    order_date: todayStr(),
    items: [{ product_id: productId, quantity: 1, price: 100 }],
  })
  expect(res.code).toBe(0)
  const no = (res.data as { no: string }).no
  const list = await apiGet(page, '/api/v1/purchase/orders', { keyword: no, per_page: 100 })
  const row = (list.items as { id: number; no: string }[]).find((i) => i.no === no)
  expect(row).toBeTruthy()
  return { id: row!.id, no }
}

test.describe('仪表盘模块 E2E（TC-DSH-01~08）', () => {
  // 用例间共享登录态与自建数据；串行执行保证确定性（文件本身字典序最后一篇）
  test.describe.configure({ mode: 'serial' })

  test('TC-DSH-01 KPI 卡片数字核对', async ({ page }) => {
    await loginByAPI(page, 'admin', 'admin123')

    // 挂载并行 4 请求收集（E2E 文档 step 1：页面挂载即发 4 个 dashboard 请求）
    const seen = new Set<string>()
    page.on('request', (r) => {
      const path = new URL(r.url()).pathname
      if (path.startsWith('/api/v1/dashboard/')) seen.add(path)
    })
    await page.goto('/dashboard')
    await expect(page.getByText('库存总量')).toBeVisible()
    await expect.poll(() => seen.size).toBe(4)

    // —— 期望值计算：全部运行时 API 交叉核对（零硬编码） ——
    // 库存总量 = 余额页全部行求和
    const balances = await apiGet(page, '/api/v1/inventory/balances', { per_page: 100 })
    const totalQty = (balances.items as { quantity: number }[]).reduce(
      (s, r) => s + Number(r.quantity),
      0,
    )
    // 今日出入库 = 流水页今日方向 Σ（date_from/date_to 闭区间）
    const movs = await apiGet(page, '/api/v1/inventory/movements', {
      date_from: todayStr(),
      date_to: todayStr(),
      per_page: 100,
    })
    let inQty = 0
    let outQty = 0
    for (const m of movs.items as { direction: number; quantity: number }[]) {
      if (m.direction === 1) inQty += Number(m.quantity)
      else outQty += Number(m.quantity)
    }
    // 待审核 = 9 类草稿总数
    const drafts = await countDrafts(page)

    // —— 与 summary 接口逐项比对 ——
    const summary = await apiGet(page, '/api/v1/dashboard/summary')
    expect(Number(summary.inventory_total_qty)).toBeCloseTo(totalQty, 2)
    expect(Number(summary.today_inbound_qty)).toBeCloseTo(inQty, 2)
    expect(Number(summary.today_outbound_qty)).toBeCloseTo(outQty, 2)
    expect(summary.pending_approvals).toBe(drafts)

    // —— UI 文本核对（千分位格式 + 方向色前缀） ——
    await expect(
      page.locator('.kpi-card', { hasText: '库存总量' }).locator('.kpi-value'),
    ).toHaveText(thousand(totalQty.toFixed(2)))
    await expect(
      page.locator('.kpi-card', { hasText: '今日入库' }).locator('.kpi-value'),
    ).toHaveText(`+${thousand(inQty.toFixed(2))}`)
    await expect(
      page.locator('.kpi-card', { hasText: '今日出库' }).locator('.kpi-value'),
    ).toHaveText(`-${thousand(outQty.toFixed(2))}`)
    await expect(
      page.locator('.kpi-card', { hasText: '待审核单据' }).locator('.kpi-value'),
    ).toHaveText(String(drafts))
  })

  test('TC-DSH-02 待审核列表与跳转', async ({ page }) => {
    await loginByAPI(page, 'admin', 'admin123')

    // 前置：自建 1 张草稿采购订单（列表/点击断言对象）
    const sup = await ensureSupplier(page)
    const products = await apiGet(page, '/api/v1/products', { per_page: 100 })
    const draft = await createDraftPo(page, sup.id, (products.items as { id: number }[])[0]!.id)

    await page.goto('/dashboard')
    const pending = await apiGet(page, '/api/v1/dashboard/pending-approvals')
    const rows = pending.items as {
      no: string
      module: string
      type: string
      url: string
      created_at: string
    }[]
    // 接口形状：≤20 条、含自建单、创建时间倒序（字符串字典序=时间序）
    expect(rows.length).toBeLessThanOrEqual(20)
    expect(rows.some((r) => r.no === draft.no)).toBeTruthy()
    for (let i = 1; i < rows.length; i++) {
      expect(rows[i - 1]!.created_at >= rows[i]!.created_at).toBeTruthy()
    }
    // UI：行内类型标签 + 单号（Fira Code）+ 时间
    const row = page.locator('.pending-row', { hasText: draft.no })
    await expect(row).toBeVisible()
    await expect(row.locator('.type-tag')).toHaveText('订单')
    await expect(row.locator('.pending-no')).toHaveText(draft.no)
    // 点击 → 跳转 url 字段路由（采购订单页）
    await row.click()
    await expect(page).toHaveURL(/\/purchase\/orders/)
    // 返回仪表盘：列表正常
    await page.goto('/dashboard')
    await expect(page.locator('.pending-row', { hasText: draft.no })).toBeVisible()

    // 清理：删除自建草稿（删除守卫仅拦已审核/被引用，草稿可删）
    const del = await apiDelete(page, `/api/v1/purchase/orders/${draft.id}`)
    expect(del.code).toBe(0)
  })

  test('TC-DSH-03 工单进度', async ({ page }) => {
    await loginByAPI(page, 'admin', 'admin123')

    // 前置数据：上游生产模块遗留的生产中/已完成工单（E2E 文档 §2 前置条件）
    const expected: {
      no: string
      product_name: string
      quantity: string
      completed_qty: string
      status: number
    }[] = []
    for (const status of [2, 3]) {
      const data = await apiGet(page, '/api/v1/production/orders', { status, per_page: 100 })
      for (const o of data.items as typeof expected) expected.push(o)
    }
    expect(expected.length).toBeGreaterThan(0) // 生产 spec 是既定前置依赖，若为空说明上游数据被破坏

    await page.goto('/dashboard')
    const data = await apiGet(page, '/api/v1/dashboard/work-order-progress')
    const items = data.items as {
      no: string
      product_name: string
      quantity: string
      completed_qty: string
      progress: string
      status: number
      status_label: string
    }[]
    expect(items.length).toBeLessThanOrEqual(10)
    // 交叉核对：仪表盘行 ⊆ 生产模块工单列表
    const expectedNos = new Set(expected.map((o) => o.no))
    for (const row of items) {
      expect(expectedNos.has(row.no)).toBeTruthy()
      const src = expected.find((o) => o.no === row.no)!
      expect(row.product_name).toBe(src.product_name)
      expect(row.quantity).toBe(String(src.quantity))
      expect(row.completed_qty).toBe(String(src.completed_qty))
      // 进度口径 = completed/quantity×100（容忍 0.01 浮点/舍入差）
      const want = (Number(src.completed_qty) / Number(src.quantity)) * 100
      expect(Number(row.progress)).toBeCloseTo(want, 2)
    }
    // UI：进度条 + 进度文本 + 状态标签
    const first = items[0]!
    const orderRow = page.locator('.order-row', { hasText: first.no })
    await expect(orderRow).toBeVisible()
    await expect(orderRow.locator('.progress-text')).toHaveText(`${first.progress}%`)
    await expect(orderRow.locator('.el-tag')).toHaveText(first.status_label)
    // 点击 → 跳转生产工单页（V1 无独立详情路由，列表页承载详情 tabs）
    await orderRow.click()
    await expect(page).toHaveURL(/\/production\/orders/)
  })

  test('TC-DSH-04 预警列表联动', async ({ page }) => {
    await loginByAPI(page, 'admin', 'admin123')

    // 自建低库存预警：新建预警商品（下限 10）→ 独立采购入库 5 件并审核 → 余额 5 < 10 → level=1
    // （预警接口以余额行 join 商品计算——无余额行的商品不产生预警行，必须走真实入库制造余额）
    const catTree = (await apiGet(page, '/api/v1/categories')) as { id: number }[]
    const units = await apiGet(page, '/api/v1/units', { per_page: 100 })
    const created = await apiPost(page, '/api/v1/products', {
      name: 'DSH 预警原料',
      code: 'DSH-MAT-001',
      type: 'raw_material',
      category_id: catTree[0]!.id,
      unit_id: (units.items as { id: number }[])[0]!.id,
      safety_min: 10,
      safety_max: 0,
      status: 1,
    })
    expect(created.code).toBe(0)
    const alertProductId = (created.data as { id: number }).id
    // 仓库/库位取主仓 A-01（上游 spec 同款取法）；独立入库（无订单来源）
    const sup = await ensureSupplier(page)
    const whs = await apiGet(page, '/api/v1/warehouses', { keyword: '主仓' })
    const whId = (whs.items as { id: number }[])[0]!.id
    const locs = await apiGet(page, `/api/v1/warehouses/${whId}/locations`)
    const locId = (locs.items as { id: number; code: string }[]).find((l) => l.code === 'A-01')!.id
    const inbound = await apiPost(page, '/api/v1/purchase/inbounds', {
      supplier_id: sup.id,
      warehouse_id: whId,
      location_id: locId,
      items: [{ product_id: alertProductId, quantity: 5, price: 100 }],
    })
    expect(inbound.code).toBe(0)
    const inboundNo = (inbound.data as { no: string }).no
    const inboundList = await apiGet(page, '/api/v1/purchase/inbounds', { keyword: inboundNo })
    const inboundId = (inboundList.items as { id: number }[])[0]!.id
    const approved = await apiPost(page, `/api/v1/purchase/inbounds/${inboundId}/approve`)
    expect(approved.code).toBe(0)

    await page.goto('/dashboard')
    // 交叉核对：仪表盘 alerts = 库存预警接口 level=1（低库存）过滤前 10（两处同为 product_id 升序）
    const src = await apiGet(page, '/api/v1/inventory/alerts')
    const lowOnly = (src.items as { level: number }[]).filter((a) => a.level === 1).slice(0, 10)
    const data = await apiGet(page, '/api/v1/dashboard/alerts')
    const items = data.items as { product_code: string }[]
    expect(items.length).toBe(lowOnly.length)
    for (let i = 0; i < items.length; i++) {
      expect(items[i]!.product_code).toBe((lowOnly[i] as { product_code: string }).product_code)
    }
    // 自建商品必在列表内（上游低库存预警少，前 10 必含新商品）
    expect(items.some((i) => i.product_code === 'DSH-MAT-001')).toBeTruthy()
    // UI：预警卡渲染
    await expect(page.locator('.alert-card', { hasText: 'DSH-MAT-001' })).toBeVisible()
    // 点击 → 跳转库存预警页
    await page.locator('.alert-card', { hasText: 'DSH-MAT-001' }).click()
    await expect(page).toHaveURL(/\/inventory\/alerts/)

    // 清理说明：已审核入库单/流水不可删（删除守卫），预警商品与 5 件余额为永久残留——
    // 本文件为字典序最后一篇，残留不影响任何后续 spec（E2E 设计原则允许已审核单据永久残留）
  })

  test('TC-DSH-05 联动刷新（构造草稿 → 审核 → 计数变化）', async ({ page }) => {
    await loginByAPI(page, 'admin', 'admin123')
    const before = await apiGet(page, '/api/v1/dashboard/summary')
    const n = before.pending_approvals as number

    const sup = await ensureSupplier(page)
    const products = await apiGet(page, '/api/v1/products', { per_page: 100 })
    const draft = await createDraftPo(page, sup.id, (products.items as { id: number }[])[0]!.id)

    // 刷新仪表盘：KPI 显示 N+1；列表顶部新增该单
    await page.goto('/dashboard')
    await expect(
      page.locator('.kpi-card', { hasText: '待审核单据' }).locator('.kpi-value'),
    ).toHaveText(String(n + 1))
    const pending = await apiGet(page, '/api/v1/dashboard/pending-approvals')
    expect((pending.items as { no: string }[])[0]!.no).toBe(draft.no)

    // 回采购订单页审核该单（UI 流程与 purchase.spec TC-PUR-02 同构：审 核 → 确认 → 成功提示）
    await page.goto('/purchase/orders')
    const target = page.locator('.el-table__row', { hasText: draft.no })
    await expect(target).toBeVisible()
    await target.getByRole('button', { name: /审\s*核/ }).click()
    await expect(page.locator('.el-message-box')).toContainText('确认审核订单')
    await page
      .locator('.el-message-box')
      .getByRole('button', { name: /确\s*定/ })
      .click()
    await expect(page.locator('.el-message--success')).toContainText('审核成功')

    // 刷新仪表盘：待审核数回到 N；该单从列表消失
    await page.goto('/dashboard')
    await expect(
      page.locator('.kpi-card', { hasText: '待审核单据' }).locator('.kpi-value'),
    ).toHaveText(String(n))
    await expect(page.locator('.pending-row', { hasText: draft.no })).toHaveCount(0)
  })

  test('TC-DSH-06 空态', async ({ page }) => {
    await loginByAPI(page, 'admin', 'admin123')

    // 前置说明：预警商品 DSH-MAT-001 已带已审核入库（TC-DSH-04 制造低库存余额），
    // 删除守卫拦截已引用商品 → 预警侧不再清理（永久残留，不影响任何后续 spec）
    // 前置：临时清空草稿数据（E2E 文档允许「审核或临时清空」；删除比审核更稳健——审核受库存校验约束）
    for (const url of DRAFT_ENDPOINTS) {
      const data = await apiGet(page, url, { per_page: 100 })
      const ids = (data.items as { id: number; status: number }[])
        .filter((i) => i.status === 0)
        .map((i) => i.id)
      for (const id of ids) {
        const del = await apiDelete(page, `${url}/${id}`)
        expect(del.code).toBe(0)
      }
    }

    // 空态断言：待审核「全部单据已审核 ✓」；KPI 待审核 = 0
    await page.goto('/dashboard')
    await expect(page.locator('.empty-ok', { hasText: '全部单据已审核' })).toBeVisible()
    await expect(
      page.locator('.kpi-card', { hasText: '待审核单据' }).locator('.kpi-value'),
    ).toHaveText('0')
    // 预警区：按上游实际数据交叉核对分支断言（零硬编码）——SEMI-001（种子下限 10，上游消耗至 0）
    // 与 DSH-MAT-001（TC-DSH-04 制造的低库存余额）常态构成低库存预警 → 无预警断言空态文案，有预警断言预警卡渲染
    const alerts = await apiGet(page, '/api/v1/inventory/alerts')
    const lowCount = (alerts.items as { level: number }[]).filter((a) => a.level === 1).length
    if (lowCount === 0) {
      await expect(page.locator('.empty-ok', { hasText: '库存状态正常' })).toBeVisible()
    } else {
      await expect(page.locator('.empty-ok', { hasText: '库存状态正常' })).toHaveCount(0)
      await expect(page.locator('.alert-card').first()).toBeVisible()
    }

    // 恢复现场：重建 1 张草稿采购订单（E2E 文档「重建草稿单等」；预警商品无需恢复——本文件无后续用例依赖）
    const sup = await ensureSupplier(page)
    const products = await apiGet(page, '/api/v1/products', { per_page: 100 })
    await createDraftPo(page, sup.id, (products.items as { id: number }[])[0]!.id)
  })

  test('TC-DSH-07 权限过滤（limited01 仅 *.list）', async ({ page }) => {
    await loginByAPI(page, 'admin', 'admin123')

    // 自建 limited01（挂 operator 角色——仅持有 %.list + dashboard.view 例外）
    const roles = await apiGet(page, '/api/v1/roles', { per_page: 100 })
    const opId = (roles.items as { id: number; code: string }[]).find(
      (r) => r.code === 'operator',
    )!.id
    const created = await apiPost(page, '/api/v1/users', {
      name: '只读用户',
      username: 'limited01',
      email: 'limited01@php-design.local',
      password: 'Test@12345',
      status: 1,
      role_ids: [opId],
    })
    expect(created.code).toBe(0)
    const limitedId = (created.data as { id: number }).id

    // 越权前置：确保存在一张草稿采购订单（06 已恢复一张；此处自建一张确定性对象）
    const sup = await ensureSupplier(page)
    const products = await apiGet(page, '/api/v1/products', { per_page: 100 })
    const draft = await createDraftPo(page, sup.id, (products.items as { id: number }[])[0]!.id)

    // limited01 登录：KPI 卡（库存总量/今日出入库）正常；待审核卡与区块隐藏
    await loginByAPI(page, 'limited01', 'Test@12345')
    await expect(page.locator('.kpi-card', { hasText: '库存总量' })).toBeVisible()
    await expect(page.locator('.kpi-card', { hasText: '今日入库' })).toBeVisible()
    await expect(page.locator('.kpi-card', { hasText: '今日出库' })).toBeVisible()
    await expect(page.locator('.kpi-card', { hasText: '待审核单据' })).toHaveCount(0)
    await expect(page.locator('#pending-panel')).toHaveCount(0)

    // 后端过滤：pending-approvals 返回空 items（无审核权限 → 看不到任何草稿）
    const pending = await apiGet(page, '/api/v1/dashboard/pending-approvals')
    expect(pending.items).toEqual([])

    // 越权：带 approve 动作的单据接口 → 403（审核复用 update 权限，operator 不持有）
    const attempt = await apiPost(page, `/api/v1/purchase/orders/${draft.id}/approve`)
    expect(attempt.code).toBe(403)

    // 清理：admin 删除 limited01 与自建草稿
    await loginByAPI(page, 'admin', 'admin123')
    const delUser = await apiDelete(page, `/api/v1/users/${limitedId}`)
    expect(delUser.code).toBe(0)
    const delDraft = await apiDelete(page, `/api/v1/purchase/orders/${draft.id}`)
    expect(delDraft.code).toBe(0)
  })

  test('TC-DSH-08 接口容错（单接口 500 不影响其余区域）', async ({ page }) => {
    await loginByAPI(page, 'admin', 'admin123')

    // 拦截工单进度接口 → 500（E2E 文档 route 方式；fulfill 统一响应体形状）
    await page.route('**/dashboard/work-order-progress', (route) =>
      route.fulfill({
        status: 500,
        contentType: 'application/json',
        body: JSON.stringify({ code: 500, message: '服务异常' }),
      }),
    )
    await page.goto('/dashboard')
    // 工单进度区：失败 → 重试按钮；其余区正常渲染
    await expect(
      page.locator('.panel', { hasText: '工单进度' }).getByRole('button', { name: '重 试' }),
    ).toBeVisible()
    await expect(
      page.locator('.kpi-card', { hasText: '库存总量' }).locator('.kpi-value'),
    ).toBeVisible()
    await expect(page.locator('.panel', { hasText: '库存预警' })).toBeVisible()

    // 恢复：解除拦截后重载正常
    await page.unroute('**/dashboard/work-order-progress')
    await page.goto('/dashboard')
    await expect(page.locator('.panel-title', { hasText: '工单进度' })).toBeVisible()
    await expect(page.getByRole('button', { name: '重 试' })).toHaveCount(0)
  })
})
