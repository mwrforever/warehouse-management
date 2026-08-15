# 生产管理模块 细化设计文档

- 日期：2026-08-12
- 状态：细化设计（对应主 spec：`2026-08-12-inventory-production-system-design.md` 第 5.6、6、7.1、7.2、8 节）
- 对应端到端测试文档：`docs/test/2026-08-12-生产管理模块端到端测试.md`

## 1. 模块职责与范围

生产管理是**完整版生产业务**：生产工单（计划）、BOM 展开物料需求、领料/退料、多工序流转与报工（合格/不良/工时）、委外加工与回收、成品入库、工单关闭。所有库存变动（领料 -、退料 +、委外发出 -、委外回收 +、成品入库 +）统一经 `InventoryService`，在事务内完成。不良数 V1 仅记录与统计，返修/报废处置流程预留扩展（主 spec §7.2）。

## 2. 前置依赖

| 依赖项 | 说明 |
|---|---|
| 系统管理模块 | 登录 + RBAC（`production.*`） |
| 基础资料模块 | 商品（成品）、**BOM**（物料展开）、工序（工单工序序列）、仓库/库位（发料/入库目标） |
| 库存管理模块 | 领料/退料/委外/成品入库审核调 `InventoryService` |

**本模块被依赖方**：统计报表（生产统计：达成率/良率/工时/物料耗用）、仪表盘（工单进度）、库存流水页（单号跳转）。

## 3. 数据模型

```
production_orders       id, no(MO20260812-001), product_id(成品), quantity(计划数),
                        plan_date, bom_id, status(草稿/已下达/生产中/已完成/关闭),
                        completed_qty(累计完工), created_by, released_at, closed_at, remark
work_order_operations   id, order_id, process_id, seq(工序顺序), status(待开工/进行中/已完成),
                        qualified_qty(合格累计), defective_qty(不良累计), hours(工时累计)
operation_reports       id, operation_id, order_id, operator, qualified_qty, defective_qty,
                        hours, report_time, remark
pick_lists              id, no(PL20260812-001), order_id, status(草稿/已审核), issue_status(未发料/部分发料/全部发料),
                        warehouse_id, location_id, remark
pick_list_items         id, pick_id, product_id(原料), required_qty(需求), issued_qty(已发), pick_qty(本次发料)
return_lists            id, no(RL20260812-001), order_id, pick_id(可空: 冲销来源), status(草稿/已审核),
                        warehouse_id, location_id, remark
return_list_items       id, return_id, product_id, quantity
outsourcing_orders      id, no(OS20260812-001), order_id, operation_id(委外工序), supplier_id,
                        status(草稿/已审核/已回收), warehouse_id, location_id, quantity, remark
outsourcing_receipts    id, no(OSR20260812-001), outsourcing_id, quantity(回收量), status(草稿/已审核), remark
finished_inbounds       id, no(FI20260812-001), order_id, status(草稿/已审核), warehouse_id, location_id, remark
finished_inbound_items  id, finished_inbound_id, product_id, quantity
```

## 4. API 接口清单

统一前缀 `/api/v1`，响应 `{code, message, data}`。

### 4.1 生产工单（权限：`production.order.*`）

| 接口 | 方法 | 说明 |
|---|---|---|
| `/api/v1/production/orders` | GET | 分页列表。Query：`page, per_page, keyword, product_id, status, date_from, date_to`。items 含 `{id,no,product_name,quantity,completed_qty,plan_date,status,status_label,progress(完成率%)}` |
| `/api/v1/production/orders` | POST | 新建草稿。请求体 `{product_id, quantity, plan_date, bom_id, remark}`。**BOM 展开**：提交时后端按 bom_id 展开物料需求并生成工单工序序列（按工序 sort 排序，全部「待开工」）。校验：成品必须有启用 BOM `{code:1501, message:"该成品没有启用版本的 BOM"}`、quantity>0 `{code:1502}` |
| `/api/v1/production/orders/{id}` | GET | 详情：抬头 + 物料需求（BOM 展开结果：`materials:[{material_id,material_name,required_qty,issued_qty}]`）+ 工序列表（`operations:[{id,seq,process_name,status,qualified_qty,defective_qty,hours}]`） |
| `/api/v1/production/orders/{id}` | PUT / DELETE | 更新/删除草稿（已下达：`{code:1503, message:"已下达工单不可修改"}` / `{code:1504}`） |
| `/api/v1/production/orders/{id}/release` | POST | **下达**（草稿→已下达）。校验：物料库存不足时仅 warn 提示 `data.warnings:[{material_name, required, stock}]`（允许下达，缺料由领料环节控制）。重复下达：`{code:1505, message:"工单已下达"}` |
| `/api/v1/production/orders/{id}/start` | POST | **开工**（已下达→生产中）：首工序状态→进行中。重复开工：`{code:1506}` |
| `/api/v1/production/orders/{id}/complete` | POST | **完工**（生产中→已完成）：所有工序必须已完成 `{code:1507, message:"存在未完成工序，无法完工"}`；且至少一次成品入库 `{code:1508}` |
| `/api/v1/production/orders/{id}/close` | POST | 关闭（已完成→关闭） |
| `/api/v1/production/orders/{id}/materials` | GET | BOM 展开物料需求（领料单生成时复用） |

### 4.2 工序报工（权限：`production.report.*`）

| 接口 | 方法 | 说明 |
|---|---|---|
| `/api/v1/production/operations/{id}/reports` | POST | 报工。请求体 `{qualified_qty, defective_qty, hours, operator, remark}`。**规则**：报工仅当工序状态=进行中（首工序开工后/前工序完成后自动进行中）`{code:1509, message:"该工序当前不可报工"}`；合格数 ≤ 工单计划数 `{code:1510, message:"合格数不能超过工单计划数量"}`；合格+不良 ≤ 计划数 `{code:1511}`；hours ≥ 0 `{code:1512}`。**流转**：报工累计合格数 ≥ 计划数 → 本工序自动「已完成」，下一工序自动「进行中」；末工序完成后工单可完工 |
| `/api/v1/production/operations/{id}/reports` | GET | 该工序报工记录列表 |

### 4.3 领料单（权限：`production.pick.*`）

| 接口 | 方法 | 说明 |
|---|---|---|
| `/api/v1/production/picks` | GET / POST | 列表 / 新建。POST 请求体 `{order_id, warehouse_id, location_id, remark, items:[{product_id, pick_qty}]}`；`pick_qty` ≤ 需求剩余量：`{code:1513, message:"领料数量超过需求数量"}`；库存不足审核时拦截（见 approve） |
| `/api/v1/production/picks/from-order/{orderId}` | GET | 从工单生成预填：物料需求（required_qty、已领 issued_qty、剩余） |
| `/api/v1/production/picks/{id}` | GET / PUT / DELETE | 详情 / 更新草稿 / 删除草稿（已审核：`{code:1514}`） |
| `/api/v1/production/picks/{id}/approve` | POST | **审核（核心）**：逐行校验库存充足（不足：`{code:1515, message:"商品[{name}]库存不足"}` 整体回滚）→ 写 `pick` 流水(-1) → 扣余额 → 回写物料 issued_qty。重复审核：`{code:1516, message:"该领料单已审核"}` |
| `/api/v1/production/picks/{id}/issue` | POST | **发料**（已审核→部分/全部发料）：`issue_status` 更新。响应 `{"code":0,"data":{"issue_status":"全部发料"}}` |

### 4.4 退料单（权限：`production.return.*`）

| 接口 | 方法 | 说明 |
|---|---|---|
| `/api/v1/production/returns` | GET / POST | 列表 / 新建。POST `{order_id, pick_id(可空), warehouse_id, location_id, remark, items:[{product_id, quantity}]}`；数量 ≤ 该商品已领总量：`{code:1517, message:"退料数量超过已领数量"}`；仅生产中/已完成工单可退料（草稿/已下达/关闭拒绝 `{code:1517, message:"工单当前状态不可退料"}`） |
| `/api/v1/production/returns/{id}` | GET / PUT / DELETE | 详情 / 更新草稿 / 删除草稿（已审核：`{code:1518}`） |
| `/api/v1/production/returns/{id}/approve` | POST | 审核：写 `return` 流水(+1) → 余额+ → 冲销领料 issued_qty。重复审核：`{code:1519}` |

### 4.5 委外加工（权限：`production.outsource.*`）

| 接口 | 方法 | 说明 |
|---|---|---|
| `/api/v1/production/outsourcings` | GET / POST | 列表 / 新建。POST `{order_id, operation_id(委外工序), supplier_id, warehouse_id, location_id, quantity, remark}`；委外量 ≤ 工单计划数 `{code:1520}` |
| `/api/v1/production/outsourcings/{id}` | GET / PUT / DELETE | 详情 / 更新草稿 / 删除草稿（已审核：`{code:1521}`） |
| `/api/v1/production/outsourcings/{id}/approve` | POST | **发出**（草稿→已审核）：写 `outsourcing_out` 流水(-quantity，来源单号 OS..)，校验余额 `{code:1522}`。重复：`{code:1523}` |
| `/api/v1/production/outsourcings/{id}/receipts` | POST | **回收**：请求体 `{quantity, warehouse_id, location_id, remark}`，创建并审核回收单：写 `outsourcing_in` 流水(+quantity) → 余额+ → 委外单状态→已回收。超收：`{code:1524, message:"回收数量超过委外数量"}` |
| `/api/v1/production/outsourcings/{id}/receipts` | GET | 回收记录列表 |

### 4.6 成品入库（权限：`production.finished.*`）

| 接口 | 方法 | 说明 |
|---|---|---|
| `/api/v1/production/finished-inbounds` | GET / POST | 列表 / 新建。POST `{order_id, warehouse_id, location_id, remark, items:[{product_id, quantity}]}`；`quantity` ≤ 工单剩余完工量（计划数-completed_qty）：`{code:1525, message:"入库数量超过工单剩余产量"}`；成品必须与工单产品一致 `{code:1526}` |
| `/api/v1/production/finished-inbounds/{id}` | GET / PUT / DELETE | 详情 / 更新草稿 / 删除草稿（已审核：`{code:1527}`） |
| `/api/v1/production/finished-inbounds/{id}/approve` | POST | **审核（核心）**：写 `finished_inbound` 流水(+qty) → 余额+ → 工单 `completed_qty` 累计；若末工序已完成且 completed_qty ≥ 计划数 → 工单自动「已完成」。重复审核：`{code:1528, message:"该成品入库单已审核"}` |

## 5. 页面与交互设计

侧边栏「生产管理」组：工单、领料、退料、工序报工、委外。

### 5.1 生产工单页（`/production/orders`）

- 列表：单号、成品、计划数、完工数、进度（el-progress，完成率 %）、计划日期、状态标签（草稿灰/已下达蓝/生产中琥珀/已完成绿/关闭红）、操作
- 操作：草稿→「编辑/删除/下 达」；已下达→「开 工/详情」；生产中→「领料/退料/报工/委外/成品入库/详情」；已完成→「退料/关 闭/详情」（完工后已领余料可退库，与生产中同口径）
- 新建弹窗：成品*（el-select 仅成品，选中后自动校验存在启用 BOM，无则提示 1501）、数量*、计划日期*、BOM 版本（默认启用版）、备注
- 保存后弹「展开确认」：显示 BOM 展开的物料需求与工序序列（只读确认）
- 下达：confirm「确认下达工单 MO…？」→ release → 若有缺料 warnings 用 amber 提示条展示（不阻断）
- 详情页 tab：①物料需求（需求/已领/剩余）②工序流转（含状态与累计合格/不良/工时）③报工记录

### 5.2 工序报工页（`/production/reports`）

- 顶部：选择工单（el-select）→ 展示工序步骤条（el-steps：待开工/进行中/已完成）
- 当前进行中工序卡片：合格数*（number）、不良数*、工时*（number 小数，小时）、操作人、备注
- 「提 交报工」按钮 → POST reports → 成功 ElMessage「报工成功」→ 步骤条自动推进（本工序完成、下一工序进行中）
- 不良数输入框旁注释「不良数仅记录与统计，返修/报废流程后续版本提供」

### 5.3 领料单页（`/production/picks`）

- 列表：单号、工单、仓库、状态、发料状态标签（未发料/部分/全部）、操作
- 新建「从工单生成」：选工单 → 预填物料需求（剩余量）→ 行内填本次领用量（≤剩余）→ 选仓库/库位 → 保存
- 审核：confirm「确认审核领料单 PL…？审核后库存将减少」→ approve
- 发料：审核后点「发 料」→ confirm → issue → 发料状态标签更新

### 5.4 退料单页（`/production/returns`）

- 新建：选工单（可关联领料单）→ 物料行（数量 ≤ 已领）→ 审核（库存+，冲销领料）

### 5.5 委外页（`/production/outsourcings`）

- 列表：单号、工单、委外工序、供应商、数量、状态（草稿/已审核/已回收）、操作
- 新建：选工单 → 委外工序下拉（仅该工单工序）→ 供应商 → 数量 → 仓库/库位
- 发出：审核（库存-，confirm「确认发出委外物料？库存将减少」）
- 回收：点「回 收」→ 弹窗填回收量（≤委外量）+ 入库仓库/库位 → 提交 → 自动生成回收单并审核（库存+）→ 委外单状态「已回收」

### 5.6 成品入库页（`/production/finished-inbounds`）

- 列表：单号、工单、成品、数量、状态、操作
- 新建：选工单 → 自动带出成品行（数量默认=剩余产量，可改）→ 仓库/库位 → 保存 → 审核（confirm「确认审核成品入库单 FI…？审核后成品库存将增加且工单进度更新」）

## 6. 业务流转说明

```
工单生命周期：新建(草稿, 自动 BOM 展开+工序序列) → 下达(可带缺料警告)
→ 开工(首工序进行中) → [领料审核扣原料] → 工序逐级报工(合格+不良+工时,
  前工序累计合格≥计划 → 自动完成并推进下一工序) → 末工序完成
→ 成品入库审核(合格品+入成品库, completed_qty 累计) → 全部完工 → 工单自动已完成
→ 手动关闭

委外子链：对某工序建委外单 → 审核发出(原料/半成品库存-, outsourcing_out 流水)
→ 供应商加工 → 回收单审核(成品/半成品库存+, outsourcing_in 流水) → 委外单已回收
→ 委外工序标记完成（回收量≥委外量时）

领退料链：工单 BOM 需求 → 领料单(≤需求剩余) → 审核(库存-) → 发料(状态推进)
→ 多余/不良料 → 退料单(≤已领) → 审核(库存+ 冲销)

不良处理（V1）：报工记录 defective_qty → 生产统计良率 = 合格/(合格+不良)
```

## 7. 功能清单与通过条件

| 编号 | 功能 | 通过条件 |
|---|---|---|
| PRD-01 | 工单创建+BOM 展开 | 保存后自动生成物料需求与工序序列（按 sort）；无 BOM 被拒(1501) |
| PRD-02 | 工单下达/开工 | 状态流转正确；缺料仅警告不阻断；重复下达被拒(1505) |
| PRD-03 | 工序报工流转 | 逐工序报工后自动推进；累计合格≥计划→工序完成；末工序完成 |
| PRD-04 | 报工边界 | 合格>计划(1510)、合格+不良>计划(1511)、负数(1512) 全拦截 |
| PRD-05 | 领料审核扣原料 | 领料后原料余额-、pick 流水、需求/已领更新 |
| PRD-06 | 领料超领拦截 | 超需求(1513)、库存不足(1515) 全拦截并整体回滚 |
| PRD-07 | 发料状态 | 审核后 issue_status 未发料→部分/全部发料 |
| PRD-08 | 退料冲销 | 退料审核后原料余额+、return 流水、≤已领(1517) |
| PRD-09 | 委外发出 | 发出后库存-、outsourcing_out 流水 |
| PRD-10 | 委外回收 | 回收后库存+、outsourcing_in 流水、委外单已回收；超收(1524)拦截 |
| PRD-11 | 成品入库联动 | 入库后成品余额+、finished_inbound 流水、completed_qty 累计、满产自动已完成 |
| PRD-12 | 完工校验 | 工序未完成不可完工(1507)；无入库不可完工(1508) |
| PRD-13 | 审核幂等 | 领料/退料/委外/成品入库重复审核全被拒，库存不重复变动 |
| PRD-14 | 工单关闭 | 已完成→关闭；关闭后无任何操作 |

## 8. 边界与异常场景

- 工单下达后 BOM 版本被停用：不影响已下达工单（物料需求已快照在工单上）
- 领料/退料/委外/成品入库全部走 InventoryService 事务；任一步失败整体回滚（含流水与余额双写原子性）
- 工序报工允许不良数大于 0 但合格数必须 ≥ 0；合格+不良 > 计划数时拒绝（防止虚报）
- 委外回收允许分批（多次回收，累计 ≤ 委外量）；回收完成前工序仍可被其他报工（V1 不做互斥，统计口径以报工记录为准）
- 成品入库允许分批入库（多次 FI，累计 ≤ 剩余产量）；最后一批触发工单自动完成
- 并发领料同一原料：行锁保证不超领（与销售防超卖同机制）
