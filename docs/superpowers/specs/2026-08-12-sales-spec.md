# 销售管理模块 细化设计文档

- 日期：2026-08-12
- 状态：细化设计（对应主 spec：`2026-08-12-inventory-production-system-design.md` 第 5.4、6、7.1、8 节）
- 对应端到端测试文档：`docs/test/2026-08-12-销售管理模块端到端测试.md`

## 1. 模块职责与范围

覆盖销售全链路：**销售订单**（计划）→ **销售出库单**（执行）。销售出库单审核时调用 `InventoryService` 写 `sales_outbound` 流水（方向 -1）并扣减库存。核心差异于采购：**库存只减不加，必须防超卖**（余额不足事务内拒绝）。两级状态：制单 → 审核，**审核后库存才变动**。

## 2. 前置依赖

| 依赖项 | 说明 |
|---|---|
| 系统管理模块 | 登录 + RBAC（`sales.*`） |
| 基础资料模块 | 客户（订单抬头）、商品（明细，仅成品/半成品可售，原料不参与销售）、仓库/库位（出库源） |
| 库存管理模块 | 审核调用 `InventoryService`；出库前校验余额充足 |

**本模块被依赖方**：统计报表（销售汇总）、仪表盘（待审核单据数）、库存流水页（单号跳转）。

## 3. 数据模型

```
sales_orders        id, no(单号 SO20260812-001), customer_id, order_date, expected_date,
                    status(草稿/已审核/部分出库/已完成/关闭),
                    total_amount, remark, created_by, approved_at, closed_at
sales_order_items   id, order_id, product_id, quantity, price, shipped_qty(已出库累计), amount
sales_outbounds     id, no(单号 SOUT20260812-001), customer_id, warehouse_id, location_id,
                    order_id(可空), status(草稿/已审核), total_amount, outbound_at, operator, remark
sales_outbound_items id, outbound_id, product_id, quantity, price, amount, order_item_id(可空)
```

金额单位同采购模块：分存储，前端两位小数展示。

## 4. API 接口清单

统一前缀 `/api/v1`，响应 `{code, message, data}`。

### 4.1 销售订单（权限：`sales.order.*`）

| 接口 | 方法 | 说明 |
|---|---|---|
| `/api/v1/sales/orders` | GET | 分页列表。Query：`page, per_page, keyword, customer_id, status, date_from, date_to` |
| `/api/v1/sales/orders` | POST | 新建草稿。请求体 `{customer_id, order_date, expected_date, remark, items:[{product_id, quantity, price}]}`；明细非空：`{code:1401}` |
| `/api/v1/sales/orders/{id}` | GET | 详情（含 items 及 shipped_qty） |
| `/api/v1/sales/orders/{id}` | PUT / DELETE | 更新/删除草稿（已审核：`{code:1402, message:"已审核订单不可修改"}` / `{code:1403, message:"已审核订单不可删除"}`） |
| `/api/v1/sales/orders/{id}/approve` | POST | 审核。重复审核：`{code:1404, message:"该订单已审核"}` |
| `/api/v1/sales/orders/{id}/close` | POST | 关闭（已完成不可关：`{code:1405}`） |
| `/api/v1/sales/orders/{id}/outbounds` | GET | 该订单的出库单列表 |

### 4.2 销售出库单（权限：`sales.outbound.*`）

| 接口 | 方法 | 说明 |
|---|---|---|
| `/api/v1/sales/outbounds` | GET | 分页列表（Query 同入库单） |
| `/api/v1/sales/outbounds` | POST | 新建草稿。请求体 `{customer_id, warehouse_id, location_id, order_id(可空), remark, items:[{product_id, quantity, price, order_item_id(可空)}]}`。**校验**：库存/库位必填 `{code:1406}`；关联订单行数量 ≤ 剩余 `{code:1407, message:"出库数量超过订单剩余数量"}` |
| `/api/v1/sales/outbounds/{id}` | GET | 详情 |
| `/api/v1/sales/outbounds/{id}` | PUT / DELETE | 更新/删除草稿（已审核：`{code:1408}`） |
| `/api/v1/sales/outbounds/{id}/approve` | POST | **审核（核心）**：事务内逐行校验**余额充足**（不足：`{code:1409, message:"商品[{product_name}]库存不足，当前库存 {qty}"}`，整体回滚）→ 写 `sales_outbound` 流水(-1) → 扣减余额 → 回写订单 `shipped_qty` → 重算订单状态。响应 `{"code":0,"data":{"no":"SOUT20260812-001"}}`。重复审核：`{code:1410, message:"该出库单已审核"}` |
| `/api/v1/sales/outbounds/from-order/{orderId}` | GET | 从订单生成预填：订单头+未出库明细（含 remaining_qty） |

## 5. 页面与交互设计

侧边栏「销售管理」组：订单、出库单。页面结构与采购模块对称（列表/弹窗/明细行编辑/审核 confirm），差异点：

- 出库单审核 confirm 文案：「确认审核出库单 SOUT…？审核后库存将减少且不可修改」
- 审核失败（库存不足）时：红色 `ElMessage.error` 显示后端 message（含商品名与当前库存），单据保持草稿
- 商品选择下拉仅含 **成品/半成品**（原料过滤）
- 出库单列表增加「出库数量」汇总行（当日累计，轻量统计）
- 客户下拉仅启用客户

## 6. 业务流转说明

```
主链路：建销售订单(草稿) → 审核 → 从订单生成出库单(预填未出库明细)
→ 确认数量/仓库/库位 → 保存草稿 → 审核出库单
→ 事务内: 逐行校验余额 ≥ 出库量(否则 1409 整体回滚)
→ 写 sales_outbound 流水(-qty) → 余额-qty → 回写订单行 shipped_qty
→ 全部出库 → 订单「已完成」；部分 → 「部分出库」
→ 完成/关闭后不可再生成出库单

防超卖链：余额校验在事务内 + 行锁(SELECT FOR UPDATE)保证并发下不超卖
→ 多张出库单并发审核同一商品时，后审核的因余额不足被拒
```

## 7. 功能清单与通过条件

| 编号 | 功能 | 通过条件 |
|---|---|---|
| SAL-01 | 订单 CRUD | 草稿增删改通；金额合计正确 |
| SAL-02 | 订单审核 | 审核后不可改删；重复审核被拒 |
| SAL-03 | 从订单生成出库单 | 预填明细+剩余量正确 |
| SAL-04 | 出库单审核扣库存 | 余额精确减少、流水生成、balance_after 正确 |
| SAL-05 | 订单状态联动 | 全部出库→已完成；部分→部分出库；超量出库被拒(1407) |
| SAL-06 | 超卖拦截 | 出库量>余额：审核失败 1409，整体回滚，库存不变 |
| SAL-07 | 并发防超卖 | 两张出库单并发审核同一商品，最多一张成功 |
| SAL-08 | 订单关闭 | 关闭后不可再出库；已完成不可关闭 |
| SAL-09 | 审核幂等 | 重复审核出库单返回 1410，库存不重复扣减 |
| SAL-10 | 原料禁售 | 出库明细不可选原料商品 |
| SAL-11 | 独立出库 | 无订单直接出库全链路 |

## 8. 边界与异常场景

- 余额刚好等于出库量：允许出库，扣后余额为 0（不禁止，仅禁止负数）
- 出库数量超过余额的差值信息必须精确（后端返回当前库存快照）
- 已审核出库单对应订单行 shipped_qty 回写失败 → 整体回滚（事务原子性）
- 客户停用后：历史订单正常展示，新订单不可选该客户
- 同出库单内同一商品+同一订单行只允许一行（`{code:1412, message:"明细存在重复商品"}`）
