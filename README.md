# Nexus Factory（仓库管理系统）

进销存 + 生产管理系统：`server/`（Laravel 13 API）+ `web/`（Vue 3 + TypeScript 前端）。

## CI 质量门禁

```
git push / PR
   ↓
┌─ 后端 ────────────────┐  ┌─ 前端 ────────────────────┐  ┌─ E2E ────────────────┐
│ Pint（PHP-CS-Fixer）  │  │ vue-tsc（TypeScript）     │  │ Playwright           │
│ PHPCS（PSR-12）       │  │ ESLint（含 Vue/TS/Import）│  │ 登录/系统管理/基础资料│
│ PHPStan（静态分析）   │  │ Prettier（统一格式）      │  │ 越权防护 业务流程     │
│ 全量测试（PHPUnit）   │  │ Stylelint（CSS 规范）     │  │                      │
│                       │  │ Vitest（单元测试）        │  │                      │
└───────────────────────┘  └───────────────────────────┘  └──────────────────────┘
   ↓ 全部通过
「ci」汇总检查（GitHub Actions，main 分支保护强制）
   ↓
Merge ✅
```

- **云端 CI**：`.github/workflows/ci.yml`，push 到 main / PR 到 main 触发；main 分支保护要求「ci」检查通过才可合并
- **本地门禁**：Husky + lint-staged（提交前快速检查）+ pre-push（推送前全量检查）

## 本地开发

### 环境要求

- PHP 8.3+、Composer、Node.js 24+
- 后端数据库默认 MySQL（`docker-compose.yml`），测试用 SQLite 内存库

### 安装

```bash
# 后端
cd server
composer install
cp .env.example .env && php artisan key:generate
# 前端
cd ../web
npm install
npm run build
```

### 启用本地门禁（Husky）

```bash
cd web && npm install          # 安装 husky
cd .. && web/node_modules/.bin/husky   # 设置 core.hooksPath
```

> ⚠️ Git Bash 不继承 Windows 用户级 PATH：运行 php/composer/node 前先执行
> `export PATH="$PATH:/d/code/envs/php/<版本>:/d/code/envs/composer/<版本>"`（node 同理），否则 pre-push 会被拦截并提示。

### 常用命令

| 命令 | 说明 |
| --- | --- |
| `web: npm run lint` / `lint:css` / `format:check` / `type-check` | 前端规范检查 |
| `web: npm run test:unit` | 前端单元测试（Vitest） |
| `web: npm run test:e2e` | E2E 测试（Playwright 自动起后端种子库 + 前端） |
| `server: vendor/bin/pint` / `phpcs` / `phpstan analyse` | 后端规范与静态分析 |
| `server: php artisan test` | 后端全量测试 |

## 目录结构

- `server/` — Laravel API（routes/api.php 为全部接口）
- `web/` — Vue 3 前端（src/views：system 系统管理、master 基础资料）
- `.github/workflows/ci.yml` — 云端 CI
- `.husky/` — 本地 git hooks（pre-commit / pre-push）
- `docs/` — 设计文档与会话进度
