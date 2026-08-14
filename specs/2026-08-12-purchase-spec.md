# 采购管理模块 细化设计文档

- 日期：2026-08-12
- 状态：细化设计（对应主 spec：`2026-08-12-inventory-production-system-design.md` 第 5.3、6、7.1、8 节）
- 对应端到端测试文档：`docs/test/2026-08-12-采购管理模块端到端测试.md`

## 1. 模块职责与范围

覆盖采购全链路：**采购订单**（计划）→ **采购入库单**（执行）。采购入库单审核时调用 `InventoryService` 写 `purchase_inbound` 流水并增加库存余额（方向 +1）。单据两级状态：制单 → 审核，**审核后库存才变动**（主 spec §3.4）。

## 2. 前置依赖

| 依赖项 | 说明 |
|---|---|
| 系统管理模块 | 登录 + RBAC（`purchase.*`） |
| 基础资料模块 | 供应商（订单抬头）、商品（明细行，仅原料/半成品/成品均可采购）、仓库/库位（入库目标） |
| 库存管理模块 | 审核调用 `InventoryService`（余额/流水引擎） |

**本模块被依赖方**：统计报表（采购汇总）、仪表盘（待审核单据数）、库存流水页（单号跳转至本模块单据详情）。

## 3. 数据模型

```
purchase_orders       id, no(单号 PO20260812-001), supplier_id, order_date, expected_date,
                      status(草稿 draft/已审核 approved/部分入库 partial/已完成 completed/关闭 closed),
                      total_amount(明细金额合计), remark, created_by, approved_at, closed_at
purchase_order_items  id, order_id, product_id, quantity(订购数), price(含税单价, 分),
                      received_qty(已入库累计), amount(=quantity*price)
purchase_inbounds     id, no(单号 PI20260812-001), supplier_id, warehouse_id, location_id,
                      order_id(可空: 来源订单), status(草稿/已审核), total_amount,
                      inbound_at, operator, remark
purchase_inbound_items id, inbound_id, product_id, quantity, price, amount,
                       order_item_id(可空, 关联订单行)
```

**金额单位**：价格与金额一律以「分」存储（主 spec 金额约定），前端展示除以 100 保留 2 位小数。

## 4. API 接口清单

统一前缀 `/api/v1`，响应 `{code, message, data}`。

### 4.1 采购订单（权限：`purchase.order.*`）

| 接口 | 方法 | 说明 |
|---|---|---|
| `/api/v1/purchase/orders` | GET | 分页列表。Query：`page, per_page, keyword(单号), supplier_id, status, date_from, date_to`。items 含 `{id,no,supplier_name,order_date,total_amount,status,status_label,created_by,approved_at}` |
| `/api/v1/purchase/orders` | POST | 新建草稿。请求体 `{supplier_id, order_date, expected_date, remark, items:[{product_id, quantity, price}]}`；校验：明细非空 `{code:1301, message:"请至少添加一条明细"}`、quantity>0 `{code:1302}` |
| `/api/v1/purchase/orders/{id}` | GET | 详情（含 items：`[{id,product_id,product_name,quantity,received_qty,price,amount}]`） |
| `/api/v1/purchase/orders/{id}` | PUT | 更新（仅草稿；已审核：`{code:1303, message:"已审核订单不可修改"}`） |
| `/api/v1/purchase/orders/{id}` | DELETE | 删除（仅草稿；已审核：`{code:1304, message:"已审核订单不可删除"}`） |
| `/api/v1/purchase/orders/{id}/approve` | POST | 审核。响应 `{"code":0,"data":{"no":"PO20260812-001"}}`；重复审核：`{code:1305, message:"该订单已审核"}` |
| `/api/v1/purchase/orders/{id}/close` | POST | 关闭（仅已审核/部分入库；已完成不可关：`{code:1306}`）。关闭后不可再建入库单 |
| `/api/v1/purchase/orders/{id}/inbounds` | GET | 该订单的入库单列表（订单详情页「入库记录」tab 使用） |

### 4.2 采购入库单（权限：`purchase.inbound.*`）

| 接口 | 方法 | 说明 |
|---|---|---|
| `/api/v1/purchase/inbounds` | GET | 分页列表。Query：`page, per_page, keyword, warehouse_id, status, date_from, date_to` |
| `/api/v1/purchase/inbounds` | POST | 新建草稿。请求体 `{supplier_id, warehouse_id, location_id, order_id(可空), remark, items:[{product_id, quantity, price, order_item_id(可空)}]}`。**校验**：库存/库位必填 `{code:1307}`；数量>0；若关联订单行，`quantity ≤ 该行剩余可入库量`，超量：`{code:1308, message:"入库数量超过订单剩余数量"}` |
| `/api/v1/purchase/inbounds/{id}` | GET | 详情（含 items） |
| `/api/v1/purchase/inbounds/{id}` | PUT / DELETE | 更新/删除草稿（已审核不可：`{code:1309, message:"已审核单据不可修改/删除"}`） |
| `/api/v1/purchase/inbounds/{id}/approve` | POST | **审核（核心）**：事务内逐行调 `InventoryService` 写 `purchase_inbound` 流水(+1)、更新余额、回写订单行 `received_qty` 并重算订单状态（全入→已完成）。响应 `{"code":0,"data":{"no":"PI20260812-001"}}`。重复审核：`{code:1310, message:"该入库单已审核"}` |
| `/api/v1/purchase/inbounds/from-order/{orderId}` | GET | 「从订单生成」预填数据：返回订单头+未入库完的明细行（`[{product_id,product_name,quantity,remaining_qty,price}]`） |

## 5. 页面与交互设计

侧边栏「采购管理」组：订单、入库单。

### 5.1 采购订单页（`/purchase/orders`）

- 列表：单号（Fira Code）、供应商、下单日期、金额合计（右对齐，2 位小数）、状态标签（草稿灰/已审核绿/部分入库蓝/已完成绿/关闭红）、操作
- 操作列：草稿 →「编辑」「删除」「审 核」；已审核/部分入库 →「详情」「关闭」「入库」；已完成/关闭 →「详情」
- 新建/编辑弹窗（宽 900px）：
  - 抬头：供应商*（el-select 可搜索，仅启用供应商）、下单日期*（默认今天）、预计到货（date）、备注
  - 明细表格：商品*（el-select 可搜索 / 扫码输入）、数量*（number min=1）、含税单价*（number, 元，展示两位小数，提交转分）、金额（自动=数量×单价，只读）
  - 「+ 添加明细行」；行删除
  - 底部显示「合计：¥xx.xx」（实时计算）
- 审核：点「审 核」→ confirm「确认审核订单 PO…？审核后不可修改」→ POST approve → 状态变绿
- 详情页：头信息 + 明细表格 + 「入库记录」tab（`/orders/{id}/inbounds`）

### 5.2 采购入库单页（`/purchase/inbounds`）

- 列表：单号、供应商、仓库/库位、金额、状态、操作
- 新建弹窗两种入口：
  1. **「从订单生成」**：先选订单（下拉仅已审核/部分入库订单）→ 自动带出供应商+未入库明细行（剩余量）→ 可改数量（≤剩余）→ 选仓库/库位 → 保存
  2. 「新 建」独立入库：手工选供应商+商品行
- 审核：confirm「确认审核入库单 PI…？审核后库存将增加且不可修改」→ POST approve → 成功 ElMessage「入库成功，库存已更新」
- 扫码：明细商品选择框支持扫码输入条码回车匹配

## 6. 业务流转说明

```
主链路：建采购订单(草稿) → 审核 → 从订单生成入库单(预填未入库明细)
→ 确认数量/仓库/库位 → 保存草稿 → 审核入库单
→ InventoryService: 每行写 purchase_inbound 流水(+qty, 单号 PI..) → 余额+qty
→ 回写订单行 received_qty → 重算订单状态:
     全部订单行 received_qty == quantity → 已完成；否则 → 部分入库
→ 订单完成或手动关闭后，不可再生成入库单

数量约束链：入库量 ≤ 订单剩余量(1308) → 审核后 received 累计
→ 超量被事务拒绝，防超收；入库单审核幂等(1310)防重复加库存
```

## 7. 功能清单与通过条件

| 编号 | 功能 | 通过条件 |
|---|---|---|
| PUR-01 | 订单 CRUD | 草稿增删改通；金额实时合计正确（分/元换算无误差） |
| PUR-02 | 订单审核 | 审核后状态绿、不可改/删；重复审核被拒 |
| PUR-03 | 从订单生成入库单 | 预填供应商+明细（剩余量）；剩余量计算正确 |
| PUR-04 | 独立入库单 | 无订单也可直接入库（无订单号来源） |
| PUR-05 | 入库单审核加库存 | 审核后：余额+数量、purchase_inbound 流水生成、balance_after 正确 |
| PUR-06 | 订单状态联动 | 全部入库→已完成；部分→部分入库；入库单超量被拒(1308) |
| PUR-07 | 订单关闭 | 关闭后状态红、不可再入库；已完成不可关闭 |
| PUR-08 | 审核幂等 | 重复审核入库单返回 1310，库存不重复增加 |
| PUR-09 | 删除/修改保护 | 已审核订单/入库单不可改删（前端隐藏+后端 1303/1304/1309） |
| PUR-10 | 扫码录明细 | 条码回车匹配商品加入明细行 |
| PUR-11 | 金额一致性 | 明细金额=数量×单价；合计=Σ明细（分单位无浮点误差） |

## 8. 边界与异常场景

- 订单审核后商品停用（status=0）：生成入库单时该商品行不可选（前端过滤），但已生成明细不受影响
- 入库数量与订单行数量单位必须一致（同商品同单位，不允许跨单位换算）
- 负价格/零价格：价格允许 0（赠品场景），负数被拒 `{code:1311, message:"价格不能为负数"}`
- 同商品多行合并：同一入库单内同一商品+同一订单行只允许一行（重复：`{code:1312, message:"明细存在重复商品"}`）
- 金额精度：总计以分为单位累加，禁止浮点累加（后端用整数运算，`bcmath`）
- 关闭订单后历史入库记录仍可查（只读），不影响报表
