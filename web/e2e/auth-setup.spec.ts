// setup project 专用：admin 会话登录一次（SPA 握手 + 凭证提交）并将登录态保存为 storageState（.auth/admin.json），
// 供 chromium project（业务用例）通过 use.storageState 自动注入复用，
// 免去每个用例重复登录（全量约 90 处调用各省一次登录请求+一次导航）。
// 会话模式（R4-3）：storageState 归档的是会话 cookie（laravel_session/XSRF-TOKEN）而非 localStorage token，
// Playwright storageState 原生支持 cookies 注入，业务用例 context 建立时自动携带。
// 注意：本文件仅被 playwright.config.ts 中 setup project 的 testMatch 命中执行一次，
// 其余 project 已用 testIgnore 排除，禁止在普通业务用例中 import 本文件内容。
import { expect, test } from '@playwright/test'
import { sessionHeaders } from './helpers'

test('准备 admin 登录态（storageState 会话 cookie）', async ({ page }) => {
  // SPA 握手：先取 XSRF-TOKEN 与 laravel_session cookie（Sanctum 会话必经；page.request 与上下文共享 cookie 存储）
  const csrf = await page.request.get('/sanctum/csrf-cookie')
  expect(csrf.ok()).toBeTruthy()
  // 进入公开页建立同源后读取 XSRF cookie（未登录会被守卫送回 /login，无碍），再以会话头提交凭证
  await page.goto('/')
  const headers = await sessionHeaders(page)
  expect(headers['X-XSRF-TOKEN']).toBeTruthy()
  const res = await page.request.post('/api/v1/auth/login', {
    data: { username: 'admin', password: 'admin123' },
    headers,
  })
  expect(res.ok()).toBeTruthy()
  // 会话 cookie 随浏览器上下文归档（storageState 含 cookies），业务用例注入后自动携带鉴权
  await page.context().storageState({ path: '.auth/admin.json' })
})
