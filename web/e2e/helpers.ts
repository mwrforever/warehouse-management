// E2E 公共工具：UI 登录与 API 登录两种方式
// UI 登录走真实登录页表单（登录用例专用）；API 登录直接换 token 注入 localStorage（其余用例提速）
import { expect, type Page } from '@playwright/test'

/** UI 登录：填写登录页表单并等待跳转仪表盘 */
export async function loginByUI(page: Page, username: string, password: string): Promise<void> {
  await page.goto('/login')
  await page.getByPlaceholder('请输入用户名').fill(username)
  await page.getByPlaceholder('请输入密码').fill(password)
  await page.getByRole('button', { name: /登\s*录/ }).click()
  await expect(page).toHaveURL(/\/dashboard/)
}

/** API 登录：调用 /auth/login 换取 token 写入 localStorage 后进入仪表盘（无持久注入副作用） */
export async function loginByAPI(page: Page, username: string, password: string): Promise<void> {
  const res = await page.request.post('/api/v1/auth/login', { data: { username, password } })
  expect(res.ok()).toBeTruthy()
  const body = (await res.json()) as { data: { token: string } }
  // 先进公开登录页建立同源，写入 token 后导航；路由守卫读取 localStorage 自动拉取用户
  await page.goto('/login')
  await page.evaluate((token) => localStorage.setItem('token', token), body.data.token)
  await page.goto('/dashboard')
  await expect(page).toHaveURL(/\/dashboard/)
}
