# warehouse 进销存 + 生产管理系统

面向中小制造企业的**进销存 + 生产一体化管理系统**：覆盖采购、销售、库存、生产（工单/报工/领退料/委外/成品入库）、统计报表与仪表盘，以**统一的库存引擎**保证账实一致。

- 后端：`server/`（Laravel 13 API，`/api/v1/*`，Sanctum 认证 + RBAC 权限）
- 前端：`web/`（Vue 3 + TypeScript + Element Plus + ECharts）
- 设计契约：`specs/`（8 个模块功能规格 + 系统设计总纲）

## 功能模块

| 模块 | 核心能力 |
| --- | --- |
| 基础资料 | 商品（原料/半成品/成品）/ 分类 / 单位 / 仓库 / 库位 / 供应商 / 客户 / BOM（多版本启用唯一）/ 工序 / 字典；删除保护（被引用单据不可删） |
| 库存管理 | 盘点（盘盈盘亏自动入账）、余额与流水台账（只增不改不删）、低/高库存预警、CSV 流式导出 |
| 采购管理 | 采购订单 → 入库单，审核联动库存（余额行锁 + 精确校验） |
| 销售管理 | 销售订单 → 出库单，**防超卖**（并发下余额行锁 + 业务码拒绝） |
| 生产管理 | 工单（BOM 展开快照 / 下达缺料警告 / 开工 / 完工 / 关闭）、工序报工（累计防虚报、自动流转）、领料 / 退料（防超领 / 超退）、委外加工（发出 / 分批回收）、成品入库（防超产、满产自动完工） |
| 统计报表 | 4 类实时聚合（库存汇总 / 出入库汇总 / 生产统计 / 购销汇总），KPI + ECharts 图表，纯读零迁移 |
| 仪表盘 | 4 KPI 卡 + 待审核单据（按审核权限过滤）+ 工单进度 + 库存预警，分区并行容错 |
| 系统管理 | 用户 / 角色 / 权限（RBAC，`permission:{资源}.{动作}`），内置 admin 保留码保护 |

## 技术栈

| 层 | 技术 |
| --- | --- |
| 后端 | PHP 8.5 · Laravel 13 · Sanctum · bcmath（金额/数量精确运算） |
| 前端 | Vue 3.5 · TypeScript · Vite · Element Plus 2.14 · Pinia · ECharts 6 |
| 数据库 | MySQL 8.4（开发）/ SQLite（测试，外键开启） |
| 测试 | PHPUnit（后端 449 用例）/ Vitest（前端）/ Playwright（E2E 68 条，跨 spec 数据隔离） |
| 工程 | Pint + PHPCS(PSR-12) + PHPStan(level 5) · ESLint + Prettier + Stylelint + vue-tsc · Husky + lint-staged · GitHub Actions 四门禁 |

## 核心设计

- **库存引擎不变式**：一切库存变动（采购入库 / 销售出库 / 盘点 / 领退料 / 委外收发 / 成品入库）必须经 `InventoryService::apply` 事务双写（流水 + 余额）；余额=流水求和恒等式；余额允许 0 不允许负
- **并发安全**：单据审核统一「锁单幂等 → 锁关联行复核 → 锁余额行校验 → 同事务写库存 → 回写累计」模式；全系统锁序约定（op→order）从根上消除 ABBA 死锁
- **精确计算**：数量/金额/比率一律 bcmath 字符串运算（4 位中间精度），禁止浮点累计
- **单号规则**：`{前缀}{yyyyMMdd}-{3位}` 持久序列，并发原子取号、删除不回退

## 快速开始

> 💡 **一键启动**（需 Docker Desktop）：运行 `./start-dev.sh`（Windows 可双击 `start-dev.bat`），自动完成 MySQL 启动、数据库初始化、前后端启动并打开浏览器；Ctrl+C 一键停止。

要求：PHP 8.3+ / Composer / Node.js 24+ / Docker（可选，MySQL）

```bash
# 1. 启动 MySQL（可选，开发库）
docker compose up -d

# 2. 后端
cd server
composer install
cp .env.example .env && php artisan key:generate
php artisan migrate --seed && php artisan serve   # http://localhost:8000

# 3. 前端
cd ../web
npm install
npm run dev                                       # http://localhost:5173

# 默认管理员账号 admin / admin123（生产环境务必经 ADMIN_PASSWORD 环境变量覆盖）
```

## 测试与质量门禁

```bash
# 后端：静态分析 + 规范 + 全量测试
cd server
vendor/bin/phpstan analyse --no-progress --memory-limit=1G
vendor/bin/phpcs -q
vendor/bin/pint --test
php artisan test

# 前端：类型检查 + lint + 单元测试
cd ../web
npm run type-check && npm run lint && npm run lint:css && npm run format:check
npm run test:unit

# E2E（自动起种子库 + 前端；UTC 时区对齐后端）
TZ=UTC npx playwright test
```

云端 CI（`.github/workflows/ci.yml`）在 push / PR 时跑后端、前端、E2E 三道流水线并汇总；main 分支保护要求汇总检查通过才可合并。本地 Husky 钩子提供提交前 lint-staged 快速检查。

## 目录结构

```
server/                  Laravel API（controllers / services / models / routes/api.php）
web/                     Vue 3 前端（src/views：system/master/inventory/purchase/sales/production/reports/dashboard）
specs/                   系统设计宪法（8 模块功能规格 + 系统设计总纲）
.github/workflows/       云端 CI 流水线
docker-compose.yml       MySQL 开发环境编排
```
