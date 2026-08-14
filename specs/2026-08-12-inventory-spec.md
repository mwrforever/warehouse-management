# 库存管理模块 细化设计文档

- 日期：2026-08-12
- 状态：细化设计（对应主 spec：`2026-08-12-inventory-production-system-design.md` 第 5.5、6「核心不变式」、7.1、7.3、8 节）
- 对应端到端测试文档：`docs/test/2026-08-12-库存管理模块端到端测试.md`

## 1. 模块职责与范围

库存管理是**全系统核心不变式的载体**：库存余额（`inventory_balances`）、出入库流水（`inventory_movements`）、库存盘点（`inventory_checks`）、库存预警（安全上下限）。

- **引擎角色**：`InventoryService` 为采购入库/销售出库/领退料/成品入库/委外收发/盘点提供统一的「写流水 + 更新余额」能力，其他模块**禁止绕过**（主 spec §6 核心不变式）
- **本模块自身单据**：盘点单（盘盈/盘亏也走统一引擎）
- 余额计算以流水为唯一事实来源，禁止旁路修改；所有变动在数据库事务内完成

## 2. 前置依赖

| 依赖项 | 说明 |
|---|---|
| 系统管理模块 | 登录与 RBAC（`inventory.*`/`check.*` 权限） |
| 基础资料模块 | 商品（type/上下限）、仓库/库位（余额按 商品×仓库×库位 维度） |
| 采购模块（数据源） | 采购入库审核产生 + 方向流水（测试数据准备用，非本模块实现依赖） |

**本模块被依赖方**：采购/销售/生产模块审核单据时调用 `InventoryService`；报表与仪表盘读取余额/流水聚合；预警列表被仪表盘引用（主 spec §8 仪表盘）。

## 3. 数据模型

```
inventory_balances  id, product_id, warehouse_id, location_id, quantity(当前余额),
                    safety_min(冗余自商品, 查询用), safety_max, updated_at
                    (product_id, warehouse_id, location_id 联合唯一)
inventory_movements id, product_id, warehouse_id, location_id, direction(+1/-1),
                    quantity(变动数量, 恒正), balance_after(变动后余额, 快照),
                    source_type(枚举: purchase_inbound/sales_outbound/pick/return/
                                finished_inbound/outsourcing_out/outsourcing_in/check_in/check_out),
                    source_id(来源单据id), source_no(来源单号), remark,
                    operator_id, created_at
inventory_checks   id, no(单号 CK20260812-001), warehouse_id, status(草稿/已审核),
                   checker(审核人), check_time, remark, created_at
inventory_check_items id, check_id, product_id, location_id,
                   book_qty(账面数), actual_qty(实盘数), diff_qty(差异=actual-book, 审核时计算)
```

**核心不变式（测试必须验证）**：
1. 每笔业务变动事务内同时写 `inventory_movements` + 更新 `inventory_balances.quantity`
2. 任一商品×仓库×库位的余额恒等于其全部流水 `direction*quantity` 之和
3. 盘点审核后差异≠0 的项生成 `check_in`(+)/`check_out`(-) 流水
4. 同一单据审核幂等：重复审核被拒绝

## 4. API 接口清单

统一前缀 `/api/v1`，响应 `{code, message, data}`。

### 4.1 库存余额（权限：`inventory.list`）

| 接口 | 方法 | 说明 |
|---|---|---|
| `/api/v1/inventory/balances` | GET | 分页列表。Query：`page, per_page, keyword(商品编码/名称/条码), warehouse_id, type, alert(1=仅预警)`。items 含 `{id, product_id, product_name, product_code, type, warehouse_name, location_name, quantity, safety_min, safety_max, alert_level(0正常/1低于下限/2高于上限)}` |
| `/api/v1/inventory/balances/export` | GET | 导出 CSV（表头：商品编码/名称/仓库/库位/数量/下限/上限/状态） |

### 4.2 库存流水（权限：`inventory.list`）

| 接口 | 方法 | 说明 |
|---|---|---|
| `/api/v1/inventory/movements` | GET | 分页列表。Query：`page, per_page, product_id, warehouse_id, source_type, direction, date_from, date_to`。items 含 `{id, product_name, product_code, warehouse_name, location_name, direction(+1/-1), quantity, balance_after, source_type, source_type_label, source_no, operator_name, created_at}` |

### 4.3 盘点单（权限：`check.*`）

| 接口 | 方法 | 说明 |
|---|---|---|
| `/api/v1/checks` | GET | 分页列表。Query：`page, per_page, keyword(单号), status, warehouse_id`。items 含 `{id, no, warehouse_name, status, checker, check_time, remark, created_at}` |
| `/api/v1/checks` | POST | 新建草稿。请求体 `{warehouse_id, remark, items:[{product_id, location_id, actual_qty}]}`；响应返回单号。校验：账面数自动带出，`actual_qty` ≥ 0（负数：`{code:1201, message:"实盘数量不能为负数"}`） |
| `/api/v1/checks/{id}` | GET | 详情（含 items：book_qty/actual_qty/diff_qty） |
| `/api/v1/checks/{id}` | PUT | 更新草稿（仅 status=草稿 可改；已审核：`{code:1202, message:"已审核单据不可修改"}`） |
| `/api/v1/checks/{id}` | DELETE | 删除草稿（已审核不可删 `{code:1203}`） |
| `/api/v1/checks/{id}/approve` | POST | 审核：事务内逐项生成 check_in/check_out 流水并更新余额；响应 `{"code":0,"data":{"changed_items":2,"increased":5,"decreased":3}}`。重复审核：`{code:1204, message:"该盘点单已审核"}` |

### 4.4 库存预警（权限：`inventory.list`）

| 接口 | 方法 | 说明 |
|---|---|---|
| `/api/v1/inventory/alerts` | GET | 预警列表：`data.items:[{product_name, product_code, warehouse_name, quantity, safety_min, safety_max, level}]`，level=1 低于下限、2 高于上限 |

## 5. 页面与交互设计

侧边栏菜单组「库存管理」，4 个子菜单：余额、流水、盘点、预警。

### 5.1 库存余额页（`/inventory/balances`）

- 工具栏：关键字、仓库下拉、类型下拉、「仅看预警」switch、「导 出」按钮（btn-secondary）
- 表格列：商品编码、名称、类型、仓库、库位、**数量**（加粗，Fira Code 字体）、下限、上限、状态标签（正常无标签/低于下限红色「低库存」/高于上限琥珀「超上限」）
- 点击行可展开查看该商品×仓库的流水（跳转流水页并预填筛选）

### 5.2 库存流水页（`/inventory/movements`）

- 筛选区：商品（el-select 可搜索）、仓库、单据类型下拉（采购入库/销售出库/领料/退料/成品入库/委外发出/委外回收/盘盈/盘亏）、方向（全部/入库+/出库-）、日期范围（el-date-picker 快捷：今天/近7天/近30天）
- 表格列：时间、单号（Fira Code 链接，点击跳对应单据）、商品、仓库/库位、方向（+绿/-红 前缀符号）、数量、变动后余额、类型标签、操作人
- 导出按钮复用 balances 的 CSV 模式

### 5.3 库存盘点页（`/inventory/checks`）

- 列表：单号、仓库、状态标签（草稿灰/已审核绿）、审核人、审核时间、操作
- 操作列：草稿 →「编辑」「删除」「审 核」；已审核 →「查 看」
- 新建盘点单（`el-dialog` 宽 900px）：
  1. 选仓库 → 「加载账面数」按钮 → `GET /api/v1/checks/auto-books?warehouse_id=x`（预填接口：返回该仓库全部有库存的商品+账面数，供盘点行使用）
  2. 明细表格：商品（el-select 可搜索/扫码输入条码回车自动匹配并添加行）、库位、账面数（只读）、实盘数（number 输入，默认=账面数）
  3. 「保 存」→ POST 草稿
- 审核：点击「审 核」→ `ElMessageBox.confirm`（"确认审核？差异将生成盘盈/盘亏流水并更新库存"）→ 确认后 POST approve → 结果弹窗显示「盘盈 X 项 +N、盘亏 Y 项 -M」→ 刷新
- 详情查看：只读表格含 diff 列（红色负值/绿色正值）

### 5.4 库存预警页（`/inventory/alerts`）

- 卡片式列表（KPI 风格）：每项显示商品/仓库/当前量/下限或上限/超额幅度，level=1 红色卡片、level=2 琥珀卡片
- 顶部汇总：「低于下限 N 项 / 高于上限 M 项」

## 6. 业务流转说明

```
盘点流转：
新建盘点单(选仓库) → 加载账面数 → 逐行录入实盘数(支持扫码) → 保存草稿
→ 点击「审 核」→ 二次确认 → POST /checks/{id}/approve
→ 事务内：逐行 diff = actual - book
    diff>0 → 生成 check_in 流水(+diff, 来源=盘点单号) → 余额+diff
    diff<0 → 生成 check_out 流水(|diff|) → 余额-|diff|
→ 返回 {changed_items, increased, decreased} → 页面弹窗汇总 → 状态变「已审核」

预警流转：
商品设置上下限(基础资料) → 每次余额变动后 InventoryService 同步重算该商品×仓库的
alert_level（quantity < min → 1；quantity > max → 2；否则 0）
→ 余额页显示标签 + 预警页卡片 + 仪表盘列表（三级联动，同一数据源）
```

## 7. 功能清单与通过条件

| 编号 | 功能 | 通过条件 |
|---|---|---|
| INV-01 | 余额列表 | 按 商品×仓库×库位 展示正确数量；筛选（关键字/仓库/类型/预警）即时生效 |
| INV-02 | 余额导出 | 导出 CSV 表头正确、行数与当前筛选一致、编码无乱码（UTF-8 BOM） |
| INV-03 | 流水列表 | 按时间倒序；筛选（类型/方向/日期/商品）正确；单号可点击跳转 |
| INV-04 | 余额=流水恒等式 | 任取商品：余额 = Σ(方向×数量)，页面数据与接口二次核对一致 |
| INV-05 | 盘点单 CRUD | 草稿增删改通；已审核不可改/删 |
| INV-06 | 加载账面数 | 按仓库自动带出有库存商品与账面数 |
| INV-07 | 扫码盘点 | 条码输入添加行并匹配商品 |
| INV-08 | 盘点审核-盘盈 | 实盘>账面 生成 check_in 流水，余额增加，数量吻合 |
| INV-09 | 盘点审核-盘亏 | 实盘<账面 生成 check_out 流水，余额减少，数量吻合 |
| INV-10 | 审核幂等 | 重复审核返回「该盘点单已审核」，余额不重复变动 |
| INV-11 | 预警状态 | 库存低于下限 → 余额页红标签 + 预警页红色卡片；高于上限 → 琥珀 |
| INV-12 | 负数/零边界 | 实盘数负数被拒；实盘=账面（diff=0）不生成流水 |

## 8. 边界与异常场景

- 盘点商品仅限该仓库**存在余额**的商品（无余额商品不可录盘：`{code:1205, message:"商品在该仓库无库存，无需盘点"}`）
- 审核时若该商品已被并发审核（同商品并发两张盘点单）：后审者余额校验失败整体回滚 `{code:1206, message:"库存已变动，请重新盘点"}`——事务+行锁（`SELECT ... FOR UPDATE`）保证
- 流水只增不改不删（审计要求）；更正走红冲：V1 不做红冲，改单走「删除重录」流程（仅限未审核单据）
- 余额 quantity 允许为 0 但不允许为负（出库超卖被业务层拒绝，见采购/销售/生产模块 spec）
- 预警为查询时计算（不落库），上下限修改后立即生效，无需重算任务
