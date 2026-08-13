// Playwright E2E 配置：Chromium + 自动拉起后端（SQLite 种子库）与前端 dev server
// 后端启动前先 migrate:fresh --seed（含 admin/admin123 超管账号），E2E 数据每次全量重建
import { defineConfig, devices } from '@playwright/test'

// 后端：先重建种子库再常驻 serve（8000 端口，与 vite 代理一致）
const backendCommand =
  'php artisan migrate:fresh --seed --force && php artisan serve --host=127.0.0.1 --port=8000'

export default defineConfig({
  testDir: './e2e',
  fullyParallel: true,
  // 库存（inventory）与采购（purchase）spec 共享同一 sqlite 库存数据且互相增量断言，
  // 文件级并发会互相污染余额 → 单 worker 串行执行保证确定性
  workers: 1,
  retries: process.env.CI ? 2 : 0,
  reporter: process.env.CI ? 'github' : 'list',
  use: {
    // 前端 dev server 地址（vite 代理 /api → :8000）
    baseURL: 'http://127.0.0.1:5173',
    // 浏览器时区锁定 UTC：与后端 Laravel（config/app.php timezone=UTC）及 CI（GitHub Actions 默认 UTC）对齐——
    // 机器本地为东八区时，凌晨 0-8 点浏览器日期与后端 UTC 日期相差一天，
    // 日期联动断言（今日流水/工单计划日期）会跨日漂移失败
    timezoneId: 'UTC',
    trace: 'on-first-retry',
  },
  projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
  webServer: [
    {
      // 后端：E2E 专用 SQLite 文件库（相对 server/ 目录），migrate 后常驻
      command: backendCommand,
      cwd: '../server',
      url: 'http://127.0.0.1:8000',
      reuseExistingServer: !process.env.CI,
      timeout: 120_000,
      env: {
        DB_CONNECTION: 'sqlite',
        DB_DATABASE: 'database/e2e.sqlite',
      },
    },
    {
      // vite 默认绑定 IPv6 ::1，Playwright 探测 127.0.0.1 会超时：显式绑定 IPv4
      command: 'npm run dev -- --host 127.0.0.1',
      url: 'http://127.0.0.1:5173',
      reuseExistingServer: !process.env.CI,
      timeout: 60_000,
    },
  ],
})
