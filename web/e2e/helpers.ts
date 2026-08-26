// E2E 公共工具：UI 登录与 API 登录两种方式（会话模式 R4-3：登录态由会话 cookie 决定）
// UI 登录走真实登录页表单（登录用例专用）；API 登录优先复用 setup project 预注入的 admin 会话登录态
import { expect, type Page } from '@playwright/test'

// 会话模式 API 请求头：page.request 与浏览器上下文共享 cookie 存储（laravel_session 自动携带），
// 但独立于页面导航，不会自动产生 Origin/Referer；Sanctum 按 Referer/Origin 匹配前端源做 stateful 判定
// （EnsureFrontendRequestsAreStateful::fromFrontend），须显式附加 Origin。
// 写请求另需 X-XSRF-TOKEN 校验头（与 axios 同款约定：XSRF-TOKEN cookie 值经 URL 编码，需解码后携带）
export async function sessionHeaders(page: Page): Promise<Record<string, string>> {
  const xsrf = await page.evaluate(() => {
    const m = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/)
    return m ? decodeURIComponent(m[1]) : ''
  })
  return { Origin: 'http://127.0.0.1:4000', ...(xsrf ? { 'X-XSRF-TOKEN': xsrf } : {}) }
}

/** UI 登录：先经 API 兜底登出旧会话（storageState 注入的既有会话会让 /login 被守卫重定向回仪表盘，
 * 换号场景必须作废旧会话，登录表单才可达；无会话时登出请求返回 401 属预期，忽略即可），
 * 再填写登录页表单并等待跳转仪表盘 */
export async function loginByUI(page: Page, username: string, password: string): Promise<void> {
  await page.goto('/')
  await page.request.post('/api/v1/auth/logout', { headers: await sessionHeaders(page) })
  await page.goto('/login')
  await page.getByPlaceholder('请输入用户名').fill(username)
  await page.getByPlaceholder('请输入密码').fill(password)
  await page.getByRole('button', { name: /登\s*录/ }).click()
  await expect(page).toHaveURL(/\/dashboard/)
}

/** API 登录：会话模式——优先复用当前上下文既有会话（storageState 注入或本用例已登录），
 * 经 /me 确认会话归属用户与目标一致即直接进入仪表盘；不一致（换号）或会话缺失/过期时，
 * 走 SPA 握手（GET /sanctum/csrf-cookie）+ 凭证登录，新会话直接覆盖旧会话 */
export async function loginByAPI(page: Page, username: string, password: string): Promise<void> {
  await page.goto('/dashboard')
  const res = await page.request
    .get('/api/v1/auth/me', { headers: await sessionHeaders(page) })
    .catch(() => null)
  const body =
    res && res.ok() ? ((await res.json()) as { code: number; data?: { username: string } }) : null
  if (body?.code === 0 && body.data?.username === username) {
    await expect(page).toHaveURL(/\/dashboard/)
    return
  }
  // 会话缺失/过期/归属不符：先握手取 XSRF-TOKEN cookie，再提交凭证建立新会话
  await page.request.get('/sanctum/csrf-cookie')
  const loginRes = await page.request.post('/api/v1/auth/login', {
    data: { username, password },
    headers: await sessionHeaders(page),
  })
  expect(loginRes.ok()).toBeTruthy()
  await page.goto('/dashboard')
  await expect(page).toHaveURL(/\/dashboard/)
}
