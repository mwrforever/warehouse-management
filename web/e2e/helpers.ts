// E2E 公共工具：UI 登录与 API 登录两种方式
// UI 登录走真实登录页表单（登录用例专用）；API 登录优先复用 setup project 预注入的 admin 登录态
import { readFileSync } from 'node:fs'
import { expect, type Page } from '@playwright/test'

// setup project（auth-setup.spec.ts）保存的 admin storageState 文件，
// 路径相对 web/ 运行目录，与 playwright.config.ts 的 use.storageState 配置一致
const ADMIN_STATE_FILE = '.auth/admin.json'

/** 读取 setup 保存的 admin token；文件缺失或损坏（如单文件调试未先跑 setup）返回 null，调用方回退原 API 登录路径 */
function readAdminStateToken(): string | null {
  try {
    const state = JSON.parse(readFileSync(ADMIN_STATE_FILE, 'utf8')) as {
      origins?: { localStorage?: { name: string; value: string }[] }[]
    }
    const entry = state.origins?.[0]?.localStorage?.find((item) => item.name === 'token')
    return entry?.value ?? null
  } catch {
    return null
  }
}

/** UI 登录：填写登录页表单并等待跳转仪表盘 */
export async function loginByUI(page: Page, username: string, password: string): Promise<void> {
  // storageState 已注入 admin token 时，路由守卫会把 /login 重定向回仪表盘导致登录表单不可达
  // （换号场景如 permission/stats-report 的 opName UI 登录）：先建立同源并清除旧 token 再进登录页
  await page.goto('/')
  await page.evaluate(() => localStorage.removeItem('token'))
  await page.goto('/login')
  await page.getByPlaceholder('请输入用户名').fill(username)
  await page.getByPlaceholder('请输入密码').fill(password)
  await page.getByRole('button', { name: /登\s*录/ }).click()
  await expect(page).toHaveURL(/\/dashboard/)
}

/** API 登录：admin 优先复用 setup project 注入的 storageState 登录态（直接进入仪表盘，省一次登录请求+一次导航）；
 * 非 admin（如 zz-dashboard 的 limited01 换号）或 admin token 已被中途换号覆盖时，回退原 API 登录路径 */
export async function loginByAPI(page: Page, username: string, password: string): Promise<void> {
  const stateToken = username === 'admin' ? readAdminStateToken() : null
  if (stateToken) {
    // storageState 随 context 自动注入 token，首次导航即视为已登录；导航同时建立同源以便读取当前 token
    await page.goto('/dashboard')
    // 与 setup 保存值比对：不一致说明 token 已被换号覆盖（limited01 场景后切回 admin）→ 回退重新登录
    if ((await page.evaluate(() => localStorage.getItem('token'))) === stateToken) {
      await expect(page).toHaveURL(/\/dashboard/)
      return
    }
  }
  // 回退路径对齐 loginByUI：chromium project 已注入 admin storageState，路由守卫会把已登录的
  // /login 访问重定向回仪表盘（"先进公开登录页建立同源"并不成立），MainLayout 会以 admin 身份
  // 闪现一次；先建立同源并清除注入的旧 token，后续导航才以新身份落地
  await page.goto('/')
  await page.evaluate(() => localStorage.removeItem('token'))
  const res = await page.request.post('/api/v1/auth/login', { data: { username, password } })
  expect(res.ok()).toBeTruthy()
  const body = (await res.json()) as { data: { token: string } }
  // 同源已建立：直接写入新 token 再导航（相同 URL 强制 reload），路由守卫读取 localStorage 自动拉取用户
  await page.evaluate((token) => localStorage.setItem('token', token), body.data.token)
  await page.goto('/dashboard')
  await expect(page).toHaveURL(/\/dashboard/)
}
