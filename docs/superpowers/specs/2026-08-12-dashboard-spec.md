# 仪表盘模块 细化设计文档

- 日期：2026-08-12
- 状态：细化设计（对应主 spec：`2026-08-12-inventory-production-system-design.md` 第 5.8、8、9「Page Pattern」节）
- 对应端到端测试文档：`docs/test/2026-08-12-仪表盘模块端到端测试.md`

## 1. 模块职责与范围

登录后的**默认落地页**（`/dashboard`），一屏聚合运营关键信息：库存总值、今日出入库、待审核单据、工单进度、库存预警。全部为只读聚合，数据实时来自各模块（余额、流水、单据状态、工单进度、预警）。不含任何写操作。

## 2. 前置依赖

| 依赖项 | 说明 |
|---|---|
| 系统管理模块 | 登录后跳转目标；RBAC（`dashboard.view`） |
| 全部业务模块（数据源） | 库存余额/流水、各单据状态、工单、预警——**仪表盘测试必须在全部业务模块测试通过后执行** |
| 统计报表模块 | 部分卡片复用其聚合口径 |

**被依赖方**：无（终端展示层）。

## 3. API 接口清单

统一前缀 `/api/v1`，响应 `{code, message, data}`，全部 GET + 权限 `dashboard.view`。

| 接口 | 说明 |
|---|---|
| `/api/v1/dashboard/summary` | KPI 卡数据：`data:{inventory_total_qty(库存总量), inventory_value(库存总值, 无成本时返回 qty 并 value=null), today_inbound_qty, today_outbound_qty, pending_approvals(待审核单据总数), work_order_running(生产中工单数), alert_count(预警数)}` |
| `/api/v1/dashboard/pending-approvals` | 待审核单据列表：`data:{items:[{module(采购/销售/库存/生产), type(订单/入库单/...), no, created_at, url(前端路由路径)}]}`（按创建时间倒序，最多 20 条；仅列出当前用户有 `*.approve` 权限的模块，无权限模块不显示） |
| `/api/v1/dashboard/work-order-progress` | 工单进度：`data:{items:[{no, product_name, quantity, completed_qty, progress, status}]}`（生产中与已完成工单，最多 10 条，按更新时间倒序） |
| `/api/v1/dashboard/alerts` | 库存预警：复用库存模块 `/api/v1/inventory/alerts` 口径，仅取 `level=1`（低库存）前 10 条：`data:{items:[{product_name, product_code, warehouse_name, quantity, safety_min}]}` |

## 4. 页面与交互设计

登录成功后默认路由 `/dashboard`（主 spec §9 导航结构首位）。Swiss Modernism 2.0 风格 KPI 卡片式布局（主 spec §9 Page Pattern 2「Key metrics」区）：

- **KPI 卡区**（一行 4 张，grid 12 列布局）：
  1. 库存总量（值 Fira Code 加粗大字 + 单位说明，次级文案「库存总值 ¥xx / 未启用成本核算」当 value=null）
  2. 今日入库（绿 + 前缀，次级「出库 Σ」）
  3. 今日出库（红 + 前缀）
  4. 待审核单据（琥珀数字，点击跳转待审核列表区）
- **中部双栏**（左 2/3 右 1/3）：
  - 左：**待审核单据**列表（分组标签：采购/销售/生产，每行：类型标签 + 单号 Fira Code + 时间 + 右箭头）；点击行跳转对应单据列表页（`url` 字段）
  - 右：**工单进度**列表（每行：单号 + 商品 + el-progress 进度条 + 状态标签）
- **底部**：**库存预警**（低库存红色卡片列表：商品/仓库/当前量/下限，点击跳转预警页）
- 空态：无待审核单据显示「全部单据已审核 ✓」（绿勾图标）；无预警显示「库存状态正常」
- 刷新：页面挂载请求全部 4 个接口（并行 Promise.all）；无手动刷新按钮（V1）

## 5. 业务流转说明

```
登录成功 → 跳转 /dashboard → 并行请求 summary/pending-approvals/work-order-progress/alerts
→ 渲染 4 KPI + 3 列表 → 点击各元素跳转对应模块页面（路由 url 由后端下发，前端按白名单放行）
→ 单据审核后（任意模块）→ 刷新仪表盘 → pending_approvals 与 KPI 数字即时更新

口径约定：今日 = created_at 当天 00:00~23:59；待审核 = status=草稿 且当前用户有
对应 approve 权限；工单进度 = 生产中(进行中) 与 已完成 工单
```

## 6. 功能清单与通过条件

| 编号 | 功能 | 通过条件 |
|---|---|---|
| DSH-01 | KPI 卡片 | 4 卡数字与源模块核对一致：库存总量=余额 Σ、今日出入库=今日流水方向 Σ、待审核=各模块草稿单 Σ |
| DSH-02 | 待审核列表 | 仅显示当前用户有审核权限的模块单据；点击跳转正确路由；最多 20 条倒序 |
| DSH-03 | 工单进度 | 进度条=completed/quantity 百分比；生产中与已完成工单可见 |
| DSH-04 | 预警列表 | 与库存预警页一致（低库存前 10）；点击跳转预警页 |
| DSH-05 | 联动刷新 | 任意模块审核一张草稿单后刷新仪表盘：待审核数 -1、KPI 同步 |
| DSH-06 | 空态 | 全部审核后显示「全部单据已审核 ✓」；无预警显示「库存状态正常」 |
| DSH-07 | 权限过滤 | limited01（仅 *.list）登录：不显示待审核区（无 approve 权限），KPI 区正常显示 |

## 7. 边界与异常场景

- 无库存数据时：库存总值 value=null，卡片显示「未启用成本核算」文案，不显示 ¥0（避免误导）
- 单接口失败：该卡片区域显示骨架屏/重试提示，**不影响其他卡片**（并行加载容错）
- 待审核列表按权限过滤后可能为空 → 显示空态，不报错
- 预警仅取低库存（level=1），高库存不占仪表盘（预警页完整展示两级）
