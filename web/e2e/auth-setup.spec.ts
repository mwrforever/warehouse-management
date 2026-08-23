// setup project 专用：admin API 登录一次并将登录态保存为 storageState（.auth/admin.json），
// 供 chromium project（业务用例）通过 use.storageState 自动注入复用，
// 免去每个用例重复 API 登录（全量约 90 处调用各省一次登录请求+一次导航）。
// 注意：本文件仅被 playwright.config.ts 中 setup project 的 testMatch 命中执行一次，
// 其余 project 已用 testIgnore 排除，禁止在普通业务用例中 import 本文件内容。
import { expect, test } from '@playwright/test'

test('准备 admin 登录态（storageState）', async ({ page }) => {
  // 与 helpers.loginByAPI 同源换 token：走 vite 代理到后端 Sanctum 换取 admin token
  const res = await page.request.post('/api/v1/auth/login', {
    data: { username: 'admin', password: 'admin123' },
  })
  expect(res.ok()).toBeTruthy()
  const body = (await res.json()) as { data: { token: string } }

  // 先进入公开登录页建立前端同源（storageState 按 origin 归档 localStorage），
  // 写入 token 后保存整个浏览器上下文状态，形成 origins[].localStorage 注入结构
  await page.goto('/login')
  await page.evaluate((token) => localStorage.setItem('token', token), body.data.token)
  await page.context().storageState({ path: '.auth/admin.json' })
})
