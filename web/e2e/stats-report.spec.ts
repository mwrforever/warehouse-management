// 统计报表模块 E2E：TC-RPT-01~06（库存维度/流水聚合/生产指标/采购销售金额/日期边界空态/权限）
// 数据源 = 上游 spec（inventory/purchase/production/sales）留下的真实业务数据，
// 期望值一律用 API 交叉核对计算（不硬编码）——报表纯读零数据变更，不破坏跨 spec 数据假设
// 文件命名锁定 stats-report.spec.ts：字典序 sales < stats-report < system（TC-RPT-04 依赖 sales 数据）
import { expect, test, type Page } from '@playwright/test'
import { loginByAPI, loginByUI, sessionHeaders } from './helpers'

// 已登录页面的会话认证请求辅助：page.request 与浏览器上下文共享会话 cookie（与 production.spec 同构）
async function apiGet(page: Page, url: string, params: Record<string, string | number> = {}) {
  const res = await page.request.get(url, { headers: await sessionHeaders(page), params })
  expect(res.ok()).toBeTruthy()
  return (await res.json()).data
}
async function apiPost(page: Page, url: string, body?: unknown) {
  const res = await page.request.post(url, {
    headers: await sessionHeaders(page),
    data: body,
  })
  return (await res.json()) as { code: number; message?: string; data?: unknown }
}

// 本地日期拼接（上游 spec 同款，toISOString 为 UTC 会偏移一天）
function todayStr(): string {
  const d = new Date()
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}
// 距今 n 天前的本地日期（跨月边界用 Date 运算，禁用 getDate()-n 拼接——月初会得到非法日期）
function daysAgoStr(n: number): string {
  const d = new Date(Date.now() - n * 86400000)
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}
// 千分位（与前端 formatThousand 的 toLocaleString('zh-CN') 输出一致，KPI 文本断言用）
function thousand(s: string): string {
  return s.replace(/\B(?=(\d{3})+(?!\d))/g, ',')
}
// 分类树节点（categories 接口返回树形数组：顶层节点 + 可选 children）
type CatNode = { id: number; name: string; children?: CatNode[] }
// 分类树扁平化为 id→name 映射（主数据可建子分类，递归展开保证全部层级参与映射）
function flattenCats(nodes: CatNode[], acc: Map<number, string>): Map<number, string> {
  for (const n of nodes) {
    acc.set(n.id, n.name)
    if (n.children) flattenCats(n.children, acc)
  }
  return acc
}
// el-radio-button 点击：原生 radio input 被内层 span 拦截 pointer 事件 → 点可见按钮文本层（用户实际点击目标）
async function clickRadio(page: Page, name: string) {
  await page.locator('.el-radio-button__inner', { hasText: name }).click()
}

test.describe('统计报表模块 E2E（TC-RPT-01~06）', () => {
  // 报表纯读，用例间仅共享登录态与计算中间量；串行执行保证确定性
  test.describe.configure({ mode: 'serial' })

  test('TC-RPT-01 库存报表维度一致性', async ({ page }) => {
    await loginByAPI(page, 'admin', 'admin123')
    // —— 期望值计算：余额接口按「商品×仓库×库位」分行，维度聚合 = 分组求和 ——
    const balances = await apiGet(page, '/api/v1/inventory/balances', { per_page: 100 })
    const rows = balances.items as {
      quantity: number
      product_id: number
      warehouse_id: number
      type: string
    }[]
    const sumBy = (key: (r: (typeof rows)[0]) => string) =>
      rows.reduce(
        (acc, r) => {
          const k = key(r)
          acc[k] = (acc[k] ?? 0) + Number(r.quantity)
          return acc
        },
        {} as Record<string, number>,
      )

    // 1. 页面默认「按分类」：报表分类行数量 = 余额页同分类求和
    await page.goto('/reports/inventory')
    await expect(page.getByRole('heading', { name: '库存报表' })).toBeVisible()
    const catData = await apiGet(page, '/api/v1/reports/inventory-summary', {
      group_by: 'category',
    })
    // 交叉核对：每个分类行 quantity_total = 该分类余额和（调 products 接口取 category_id 分桶）
    const products = await apiGet(page, '/api/v1/products', { per_page: 100 })
    const catOf = new Map(
      (products.items as { id: number; category_id: number }[]).map((p) => [p.id, p.category_id]),
    )
    const expectedByCat: Record<string, number> = {}
    for (const r of rows) {
      const cid = catOf.get(r.product_id) ?? 0
      expectedByCat[cid] = (expectedByCat[cid] ?? 0) + Number(r.quantity)
    }
    // 分类 id→name：categories 接口返回树形数组（无 items 包装），递归扁平化后按组名反查 id
    const catTree = (await apiGet(page, '/api/v1/categories')) as CatNode[]
    const catName = flattenCats(catTree, new Map<number, string>())
    for (const item of catData.items as { group_name: string; quantity_total: string }[]) {
      const expected =
        expectedByCat[[...catName.entries()].find(([, n]) => n === item.group_name)?.[0] ?? -1] ?? 0
      expect(Number(item.quantity_total)).toBeCloseTo(expected, 2)
    }
    // KPI 库存总量 = 全部余额行求和（与余额页加总一致）
    const allSum = rows.reduce((s, r) => s + Number(r.quantity), 0)
    expect(Number(catData.total.quantity_total)).toBeCloseTo(allSum, 2)

    // 2. 切换「按类型」：radio 触发请求，「原料」行数量 = type=raw_material 筛选总数
    await clickRadio(page, '按类型')
    const typeData = await apiGet(page, '/api/v1/reports/inventory-summary', { group_by: 'type' })
    const rawSum = rows
      .filter((r) => r.type === 'raw_material')
      .reduce((s, r) => s + Number(r.quantity), 0)
    const rawRow = (typeData.items as { group_name: string; quantity_total: string }[]).find(
      (i) => i.group_name === '原料',
    )
    expect(rawRow).toBeTruthy()
    expect(Number(rawRow!.quantity_total)).toBeCloseTo(rawSum, 2)

    // 3. 切换「按仓库」：主仓行数量 = 该仓库所有余额行合计
    await clickRadio(page, '按仓库')
    const whData = await apiGet(page, '/api/v1/reports/inventory-summary', {
      group_by: 'warehouse',
    })
    const whSum = sumBy((r) => String(r.warehouse_id))
    const whs = await apiGet(page, '/api/v1/warehouses', { per_page: 100 })
    const whName = new Map((whs.items as { id: number; name: string }[]).map((w) => [w.id, w.name]))
    for (const item of whData.items as { group_name: string; quantity_total: string }[]) {
      const wid = [...whName.entries()].find(([, n]) => n === item.group_name)?.[0] ?? -1
      expect(Number(item.quantity_total)).toBeCloseTo(whSum[wid] ?? 0, 2)
    }
  })

  test('TC-RPT-02 出入库汇总与流水求和', async ({ page }) => {
    await loginByAPI(page, 'admin', 'admin123')
    const from = todayStr()
    const from30 = daysAgoStr(29)
    // 期望值：流水接口全量分页拉取（30 天窗口行数可能超 per_page=100 → 循环翻页收全），按 created_at 分日求和
    const expectedByDay: Record<string, { in: number; out: number }> = {}
    let pageNo = 1
    for (;;) {
      const movements = await apiGet(page, '/api/v1/inventory/movements', {
        date_from: from30,
        date_to: from,
        page: pageNo,
        per_page: 100,
      })
      const rows = movements.items as { created_at: string; direction: number; quantity: number }[]
      for (const m of rows) {
        const day = m.created_at.slice(0, 10)
        expectedByDay[day] ??= { in: 0, out: 0 }
        if (m.direction === 1) expectedByDay[day].in += Number(m.quantity)
        else expectedByDay[day].out += Number(m.quantity)
      }
      if (rows.length < 100) break
      pageNo++
    }

    // 1. 页面默认近 30 天 + 粒度「按日」：报表行与分日求和一致
    await page.goto('/reports/movements')
    await expect(page.getByRole('heading', { name: '出入库汇总' })).toBeVisible()
    const rep = await apiGet(page, '/api/v1/reports/movements-summary', {
      date_from: from30,
      date_to: from,
      granularity: 'day',
    })
    for (const item of rep.items as {
      period: string
      inbound_qty: string
      outbound_qty: string
    }[]) {
      expect(Number(item.inbound_qty)).toBeCloseTo(expectedByDay[item.period]?.in ?? 0, 2)
      expect(Number(item.outbound_qty)).toBeCloseTo(expectedByDay[item.period]?.out ?? 0, 2)
    }
    // KPI：总入库=Σin、总出库=Σout
    const sumIn = Object.values(expectedByDay).reduce((s, v) => s + v.in, 0)
    const sumOut = Object.values(expectedByDay).reduce((s, v) => s + v.out, 0)
    expect(Number(rep.totals.inbound_qty)).toBeCloseTo(sumIn, 2)
    expect(Number(rep.totals.outbound_qty)).toBeCloseTo(sumOut, 2)
    // 净变动 KPI = 入-出（页面文本断言，千分位格式与 formatThousand 一致）
    expect(page.locator('.kpi-card').nth(2)).toContainText(thousand((sumIn - sumOut).toFixed(2)))

    // 2. 粒度切「按月」：totals 不变（与日粒度完全一致）
    await clickRadio(page, '按月')
    const repMonth = await apiGet(page, '/api/v1/reports/movements-summary', {
      date_from: from30,
      date_to: from,
      granularity: 'month',
    })
    expect(repMonth.totals.inbound_qty).toBe(rep.totals.inbound_qty)
    expect(repMonth.totals.outbound_qty).toBe(rep.totals.outbound_qty)

    // 3. 切回「按日」，点击某有数据行（如今天）→ 下钻流水页，URL 预填该日闭区间
    await clickRadio(page, '按日')
    const todayRow = page.locator('.el-table__row', { hasText: from }).first()
    await todayRow.click()
    await expect(page).toHaveURL(
      new RegExp(`/inventory/movements\\?date_from=${from}&date_to=${from}`),
    )
    // 列表为当日流水：断言流水页日期筛选已预填（daterange 两个输入框值 = 该日）且出现当日流水行
    await expect(page.locator('.el-date-editor input').first()).toHaveValue(from)
    await expect(page.locator('.el-table__row', { hasText: from }).first()).toBeVisible()
  })

  test('TC-RPT-03 生产统计-达成率与良率', async ({ page }) => {
    await loginByAPI(page, 'admin', 'admin123')
    // —— 期望值：找生产 spec 留下的「计划=完工>0」工单（MO20260812-001 语义，单号以实际为准）——
    const from30 = daysAgoStr(29)
    const orders = await apiGet(page, '/api/v1/production/orders', { per_page: 100 })
    const done = (
      orders.items as {
        id: number
        no: string
        quantity: number
        completed_qty: number
        plan_date: string
      }[]
    ).find((o) => Number(o.completed_qty) > 0 && o.plan_date >= from30)
    expect(done).toBeTruthy() // 前置：生产 spec 已完成工单（数据未清理）

    // 1. 页面选日期范围含该工单计划日期 → 表格出现该行：达成率 100.00%（绿色 tag-done）、良率 100.00%（无不良）
    await page.goto('/reports/production')
    const rep = await apiGet(page, '/api/v1/reports/production', {
      date_from: from30,
      date_to: todayStr(),
    })
    const row = (
      rep.items as {
        order_no: string
        achievement_rate: string
        yield_rate: string
        total_hours: string
        qualified_qty: string
        defective_qty: string
      }[]
    ).find((i) => i.order_no === done.no)
    expect(row).toBeTruthy()
    expect(row!.achievement_rate).toBe('100.00')
    expect(row!.yield_rate).toBe('100.00')
    // 工时 = 生产 spec 报工累计（API 交叉核对：调工单详情工序求和）
    const detail = await apiGet(page, `/api/v1/production/orders/${done.id}`)
    const hoursSum = (detail.operations as { hours: number }[]).reduce(
      (s, o) => s + Number(o.hours),
      0,
    )
    expect(Number(row!.total_hours)).toBeCloseTo(hoursSum, 2)

    // 2. 页面行断言：找到该行，达成率标签为深绿分级（tag-done 类）
    const rowEl = page.locator('.el-table__row', { hasText: done.no }).first()
    await expect(rowEl).toBeVisible()
    await expect(rowEl.locator('.tag-done').first()).toBeVisible()
    await expect(rowEl).toContainText('100.00%')

    // 3. 展开「物料耗用」：明细 used_qty = Σ领料流水-Σ退料流水（API 交叉核对）
    // 流水核对：调领/退料接口按工单过滤已审核单（status=1），明细求和
    const picks = await apiGet(page, '/api/v1/production/picks', { per_page: 100 })
    const rets = await apiGet(page, '/api/v1/production/returns', { per_page: 100 })
    const orderPicks = (
      picks.items as { id: number; order_id: number; order_no: string; status: number }[]
    ).filter((p) => p.order_id === done.id && p.status === 1)
    let pickQty = 0
    for (const p of orderPicks) {
      const pDetail = await apiGet(page, `/api/v1/production/picks/${p.id}`)
      pickQty += (pDetail.items as { pick_qty: number }[]).reduce(
        (s, i) => s + Number(i.pick_qty),
        0,
      )
    }
    const orderRets = (
      rets.items as { id: number; order_id: number; order_no: string; status: number }[]
    ).filter((r) => r.order_id === done.id && r.status === 1)
    let retQty = 0
    for (const r of orderRets) {
      const rDetail = await apiGet(page, `/api/v1/production/returns/${r.id}`)
      retQty += (rDetail.items as { quantity: number }[]).reduce(
        (s, i) => s + Number(i.quantity),
        0,
      )
    }
    const expectedUsed = pickQty - retQty
    await rowEl.locator('.el-table__expand-icon').click()
    const expandEl = page.locator('.el-table__expanded-cell').last()
    await expect(expandEl).toBeVisible()
    const matRow = expandEl.locator('tr', { hasText: 'MAT-001' })
    await expect(matRow).toBeVisible()
    // 耗用数字核对（千分位文本容差，与 formatThousand 输出一致）
    const matText = await matRow.textContent()
    expect(matText).toContain(thousand(expectedUsed.toFixed(2)))

    // 4. 颜色分级：若存在达成率 <80 的工单（如未完工 MO-003）→ 该行红色标签
    const lowRow = (rep.items as { order_no: string; achievement_rate: string }[]).find(
      (i) => Number(i.achievement_rate) < 80,
    )
    if (lowRow) {
      const lowEl = page.locator('.el-table__row', { hasText: lowRow.order_no }).first()
      await expect(lowEl.locator('.tag-danger').first()).toBeVisible()
    }
  })

  test('TC-RPT-04 采购销售汇总', async ({ page }) => {
    await loginByAPI(page, 'admin', 'admin123')
    // —— 期望值：已审核单据合计（列表接口累加 total_amount，仅 status=1）——
    const inbounds = await apiGet(page, '/api/v1/purchase/inbounds', { per_page: 100 })
    const pa = (inbounds.items as { status: number; total_amount: number }[])
      .filter((i) => i.status === 1)
      .reduce((s, i) => s + Number(i.total_amount), 0)
    const outbounds = await apiGet(page, '/api/v1/sales/outbounds', { per_page: 100 })
    const sa = (outbounds.items as { status: number; total_amount: number }[])
      .filter((i) => i.status === 1)
      .reduce((s, i) => s + Number(i.total_amount), 0)
    expect(sa).toBeGreaterThan(0) // 前置：sales spec 已留下已审核出库单

    const today = todayStr()
    const firstOfMonth = `${today.slice(0, 7)}-01`
    // 1. 页面选本月、粒度「按月」：报表 totals 金额为整数分（R2 契约），与列表累加分值精确相等
    await page.goto('/reports/purchase-sales')
    await clickRadio(page, '按月')
    const rep = await apiGet(page, '/api/v1/reports/purchase-sales', {
      date_from: firstOfMonth,
      date_to: today,
      granularity: 'month',
    })
    // 本月行可能因审核时间跨月不存在 → 用 totals 断言（整数分无舍入，可直接全等）
    expect(rep.totals.purchase_amount).toBe(pa)
    expect(rep.totals.sales_amount).toBe(sa)
    // 2. 差额 KPI = 销售-采购（分→元两位小数展示，千分位；可为负红色）
    const diffText = ((sa - pa) / 100).toFixed(2)
    expect(page.locator('.kpi-card').nth(2)).toContainText(thousand(diffText))
    // 3. 切粒度「按日」：totals 不变（月度粒度聚合不改变全区间合计）
    const repDay = await apiGet(page, '/api/v1/reports/purchase-sales', {
      date_from: firstOfMonth,
      date_to: today,
      granularity: 'day',
    })
    expect(repDay.totals.purchase_amount).toBe(rep.totals.purchase_amount)
    expect(repDay.totals.sales_amount).toBe(rep.totals.sales_amount)
  })

  test('TC-RPT-05 日期边界与空态', async ({ page }) => {
    await loginByAPI(page, 'admin', 'admin123')
    // 1. 出入库汇总选未来日期：响应 items=[]、totals 全 0 → 页面空态三要素（KPI 0 + el-empty + 表格空文案）
    const fut = await apiGet(page, '/api/v1/reports/movements-summary', {
      date_from: '2099-01-01',
      date_to: '2099-01-31',
      granularity: 'day',
    })
    expect(fut.items).toEqual([])
    expect(Number(fut.totals.inbound_qty)).toBe(0)
    expect(Number(fut.totals.outbound_qty)).toBe(0)
    // 页面构造空态：导航到出入库汇总页，daterange 双输入框直接 fill + Enter 确认未来区间
    // （keyboard.type 焦点会落在面板内部输入框、确认不稳定；fill 走触发输入框的 parse 路径，实测可靠）
    await page.goto('/reports/movements')
    const startInput = page.locator('.el-date-editor input').first()
    const endInput = page.locator('.el-date-editor input').nth(1)
    await startInput.click()
    await startInput.fill('2099-01-01')
    await startInput.press('Enter')
    await endInput.fill('2099-01-31')
    await endInput.press('Enter')
    // 空态三要素：KPI 0 + el-empty「暂无数据」+ 表格空文案
    await expect(page.locator('.kpi-card').nth(0)).toContainText('0.00')
    await expect(page.locator('.el-empty, .el-table__empty-text').first()).toBeVisible()
    // 2. 生产统计选未来日期：空态正常（API 断言 + 页面空态）
    const futProd = await apiGet(page, '/api/v1/reports/production', {
      date_from: '2099-01-01',
      date_to: '2099-01-31',
    })
    expect(futProd.items).toEqual([])
    // 3. 倒置日期（API 直调）：业务码 1601 + 精确消息
    const inv = await page.request.get('/api/v1/reports/movements-summary', {
      headers: await sessionHeaders(page),
      params: { date_from: '2099-01-31', date_to: '2099-01-01', granularity: 'day' },
    })
    const invBody = (await inv.json()) as { code: number; message: string }
    expect(invBody.code).toBe(1601)
    expect(invBody.message).toBe('开始日期不能晚于结束日期')
    // 4. 区间跨度上限（P2-2）：日粒度 >366 天、月粒度 >36 个月 → 业务码 1601「日期区间过长」
    // （前端快捷项最大近 30 天不可触发，API 直调断言；防区间无上限导致流水全量遍历）
    const overDay = await page.request.get('/api/v1/reports/movements-summary', {
      headers: await sessionHeaders(page),
      params: { date_from: '2025-08-01', date_to: '2026-08-14', granularity: 'day' },
    })
    const overDayBody = (await overDay.json()) as { code: number; message: string }
    expect(overDayBody.code).toBe(1601)
    expect(overDayBody.message).toBe('日期区间过长')
    const overMonth = await page.request.get('/api/v1/reports/movements-summary', {
      headers: await sessionHeaders(page),
      params: { date_from: '2025-01-01', date_to: '2028-02-01', granularity: 'month' },
    })
    const overMonthBody = (await overMonth.json()) as { code: number; message: string }
    expect(overMonthBody.code).toBe(1601)
    expect(overMonthBody.message).toBe('日期区间过长')
  })

  test('TC-RPT-06 权限控制', async ({ page }) => {
    await loginByAPI(page, 'admin', 'admin123')
    // 1. admin 创建 operator 用户（API 直建：挂「操作员」角色——仅持有 %.list；status 必填）
    const roles = await apiGet(page, '/api/v1/roles', { per_page: 100 })
    const opRole = (roles.items as { id: number; code: string }[]).find(
      (r) => r.code === 'operator',
    )
    expect(opRole).toBeTruthy()
    const opName = `rpt_op_${Date.now()}`
    const created = await apiPost(page, '/api/v1/users', {
      name: '报表只读用户',
      username: opName,
      password: 'Operator123',
      status: 1,
      role_ids: [opRole!.id],
    })
    expect(created.code).toBe(0)
    // 2. 登出 admin，operator 登录：侧边栏 4 个报表菜单项隐藏
    // （菜单组标题「统计报表」为静态分组头无条件渲染，仅组内条目按权限门控——断言以组内条目为准）
    await page.locator('.user-name').click()
    await page.getByText('退出登录').click()
    await loginByUI(page, opName, 'Operator123')
    await expect(page.locator('.sidebar')).not.toContainText('库存报表')
    await expect(page.locator('.sidebar')).not.toContainText('出入库汇总')
    await expect(page.locator('.sidebar')).not.toContainText('生产统计')
    await expect(page.locator('.sidebar')).not.toContainText('采购销售汇总')
    // 3. 越权 API：operator 会话直接调用报表接口 → 403（后端拦截）
    for (const url of [
      '/api/v1/reports/inventory-summary',
      '/api/v1/reports/movements-summary?date_from=2026-08-01&date_to=2026-08-31&granularity=day',
      '/api/v1/reports/production?date_from=2026-08-01&date_to=2026-08-31',
      '/api/v1/reports/purchase-sales?date_from=2026-08-01&date_to=2026-08-31&granularity=month',
    ]) {
      const res = await page.request.get(url, { headers: await sessionHeaders(page) })
      expect(res.status()).toBe(403)
      const body = (await res.json()) as { code: number }
      expect(body.code).toBe(403)
    }
  })
})
