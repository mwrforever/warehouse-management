// Playwright E2E 配置：Chromium + 自动拉起后端（SQLite 种子库）与前端 dev server
// 后端启动前先 migrate:fresh --seed（含 admin/admin123 超管账号），E2E 数据每次全量重建
import { existsSync } from 'node:fs'
import { defineConfig, devices } from '@playwright/test'

// 后端：先重建种子库再常驻 serve（7000 端口，与 vite 代理一致）
const backendCommand =
  'php artisan migrate:fresh --seed --force && php artisan serve --host=127.0.0.1 --port=7000'

export default defineConfig({
  testDir: './e2e',
  fullyParallel: true,
  // 库存（inventory）与采购（purchase）spec 共享同一 sqlite 库存数据且互相增量断言，
  // 文件级并发会互相污染余额 → 单 worker 串行执行保证确定性
  workers: 1,
  retries: process.env.CI ? 2 : 0,
  reporter: process.env.CI ? 'github' : 'list',
  use: {
    // 前端 dev server 地址（vite 代理 /api → :7000）
    baseURL: 'http://127.0.0.1:4000',
    // 浏览器时区锁定 UTC：与后端 Laravel（config/app.php timezone=UTC）及 CI（GitHub Actions 默认 UTC）对齐——
    // 机器本地为东八区时，凌晨 0-8 点浏览器日期与后端 UTC 日期相差一天，
    // 日期联动断言（今日流水/工单计划日期）会跨日漂移失败
    timezoneId: 'UTC',
    trace: 'on-first-retry',
  },
  // 三段式 project：setup 先行登录 admin 保存 storageState，业务 project 注入复用，
  // 登录流程 project 保持干净浏览器态（workers:1 下 setup 依赖保证最先执行，不引入并行度变化）
  projects: [
    // setup：admin API 登录一次并保存登录态到 .auth/admin.json（仅本 project 匹配该文件，执行一次）
    {
      name: 'setup',
      testMatch: /[/\\]auth-setup\.spec\.ts$/,
      use: { ...devices['Desktop Chrome'] },
    },
    // 业务用例：注入 admin 登录态免去逐用例 API 登录（约 90 处调用各省一次登录+一次导航）；
    // 排除登录流程与 setup 文件（后者不得在业务 project 重复执行）
    {
      name: 'chromium',
      testIgnore: ['auth.spec.ts', 'auth-setup.spec.ts'],
      dependencies: ['setup'],
      // fixture 形式延迟求值：每个用例 context 创建时执行，setup 写出 .auth/admin.json 后（同一轮全量内）注入；
      // 单文件调试未先跑 setup 时文件缺失 → 不注入，loginByAPI 自动回退原 API 登录路径
      use: {
        ...devices['Desktop Chrome'],
        storageState:
          // Playwright fixture 覆盖签名强制首个参数为空解构占位（官方约定），豁免 no-empty-pattern
          // eslint-disable-next-line no-empty-pattern
          async ({}, use) =>
            use(existsSync('.auth/admin.json') ? { path: '.auth/admin.json' } : undefined),
      },
    },
    // 登录流程（UI 登录/登出/错误密码）：不注入 storageState，
    // 已登录态会被路由守卫重定向回仪表盘，导致登录表单不可达、登出语义失真
    {
      name: 'auth',
      testMatch: /[/\\]auth\.spec\.ts$/,
      use: { ...devices['Desktop Chrome'] },
    },
  ],
  webServer: [
    {
      // 后端：E2E 专用 SQLite 文件库（相对 server/ 目录），migrate 后常驻
      command: backendCommand,
      cwd: '../server',
      url: 'http://127.0.0.1:7000',
      reuseExistingServer: !process.env.CI,
      timeout: 120_000,
      env: {
        DB_CONNECTION: 'sqlite',
        DB_DATABASE: 'database/e2e.sqlite',
      },
    },
    {
      // vite 默认绑定 IPv6 ::1，Playwright 探测 127.0.0.1 会超时：显式绑定 IPv4；端口由 vite.config.ts 的 port:4000 决定
      command: 'npm run dev -- --host 127.0.0.1',
      url: 'http://127.0.0.1:4000',
      reuseExistingServer: !process.env.CI,
      timeout: 60_000,
    },
  ],
})
