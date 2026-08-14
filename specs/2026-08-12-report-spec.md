# 统计报表模块 细化设计文档

- 日期：2026-08-12
- 状态：细化设计（对应主 spec：`2026-08-12-inventory-production-system-design.md` 第 5.7、8 节）
- 对应端到端测试文档：`docs/test/2026-08-12-统计报表模块端到端测试.md`

## 1. 模块职责与范围

提供 4 类**只读聚合**报表：库存报表、出入库汇总、生产统计（工单达成率/良率/工时/物料耗用）、采购销售汇总。所有数据实时聚合（不落快照表），来源为余额/流水/工单/订单等业务表；报表口径与库存模块一致（余额=流水求和，恒等式在报表层同样成立）。

## 2. 前置依赖

| 依赖项 | 说明 |
|---|---|
| 系统管理模块 | 登录 + RBAC（`report.*` 只读权限） |
| 全部业务模块（数据源） | 库存（余额/流水）、采购（订单/入库）、销售（订单/出库）、生产（工单/报工/领退料）——**报表测试必须在其余模块测试通过后执行** |

**本模块被依赖方**：仪表盘部分卡片直接复用本模块聚合接口。

## 3. API 接口清单

统一前缀 `/api/v1`，响应 `{code, message, data}`，全部 GET + 权限 `report.*`。

### 3.1 库存报表

| 接口 | 参数 | 说明 |
|---|---|---|
| `/api/v1/reports/inventory-summary` | `group_by(category/warehouse/type), date_to` | 按维度汇总库存：`data:{items:[{group_name, quantity_total, product_count, amount_total(按成本价估算, 无成本时仅数量)}], total:{...}}` |

### 3.2 出入库汇总

| 接口 | 参数 | 说明 |
|---|---|---|
| `/api/v1/reports/movements-summary` | `date_from, date_to, granularity(day/month), source_type(可空)` | 出入库趋势：`data:{items:[{period(如 2026-08-12), inbound_qty, outbound_qty, inbound_count, outbound_count}], totals:{inbound_qty, outbound_qty}}` |

- 日期区间跨度上限：日粒度 ≤ 366 天、月粒度 ≤ 36 个月，超出返回业务码 1601「日期区间过长」（防区间无上限全量遍历；前端快捷项最大近 30 天不可触发）。

### 3.3 生产统计

| 接口 | 参数 | 说明 |
|---|---|---|
| `/api/v1/reports/production` | `date_from, date_to, product_id(可空)` | 工单汇总：`data:{items:[{order_no, product_name, quantity, completed_qty, achievement_rate(达成率=completed/quantity), qualified_qty, defective_qty, yield_rate(良率=合格/(合格+不良)), total_hours, material_used(物料耗用: [{material_name, used_qty}])}]}` |

### 3.4 采购销售汇总

| 接口 | 参数 | 说明 |
|---|---|---|
| `/api/v1/reports/purchase-sales` | `date_from, date_to, granularity(day/month)` | 采购/销售金额与数量对比：`data:{items:[{period, purchase_amount, sales_amount, purchase_qty, sales_qty}], totals:{purchase_amount, sales_amount}}` |

**口径说明**：金额=已审核单据金额合计（分单位，输出元）；出入库数量=已审核流水方向求和；生产达成率/良率保留 2 位小数百分比。

## 4. 页面与交互设计

侧边栏「统计报表」组：库存报表、出入库汇总、生产统计、采购销售汇总。统一模式：筛选区（日期范围 el-date-picker 快捷项 + 维度下拉）+ 汇总 KPI 卡片（2-4 张）+ 表格 + 简易图表（`echarts` 柱状/折线，无需下载导出）。

### 4.1 库存报表页（`/reports/inventory`）

- 维度下拉：按分类/按仓库/按类型（radio 切换，切换即重新请求）
- KPI 卡：商品种类数、库存总量、预警商品数
- 表格：维度名、商品种类、总数量、数量占比（横向条形）

### 4.2 出入库汇总页（`/reports/movements`）

- 筛选：日期范围（默认近 30 天）、粒度（日/月）
- KPI 卡：总入库量、总出库量、净变动（入-出）
- 折线图：入库线（绿）与出库线（红）双系列；表格同数据
- 点击表格某行可下钻流水页（携带 period 参数跳转库存流水）

### 4.3 生产统计页（`/reports/production`）

- 筛选：日期范围、成品下拉
- KPI 卡：工单数、总计划数、平均达成率、平均良率
- 表格：工单号、成品、计划、完工、达成率（绿≥100%/琥珀≥80%/红<80%）、合格、不良、良率、总工时、物料耗用（展开行显示明细）
- 物料耗用列点击展开：`[{material_name, used_qty, unit}]`

### 4.4 采购销售汇总页（`/reports/purchase-sales`）

- 筛选：日期范围、粒度
- KPI 卡：采购金额、销售金额、差额（销售-采购）
- 柱状图：双系列（采购蓝/销售绿）；表格同数据

## 5. 业务流转说明

```
报表数据流（全部实时聚合，无缓存快照）：
余额/流水表 → 库存报表、出入库汇总（口径=库存模块恒等式）
工单+报工+领退料 → 生产统计（达成率=completed/quantity；良率=Σ合格/(Σ合格+Σ不良)；
  工时=Σhours；物料耗用=Σ领料-Σ退料 按物料）
订单+入库/出库单 → 采购销售汇总（金额=已审核单据合计）

日期筛选范围：[date_from, date_to] 闭区间；粒度 day 输出 YYYY-MM-DD，month 输出 YYYY-MM
```

## 6. 功能清单与通过条件

| 编号 | 功能 | 通过条件 |
|---|---|---|
| RPT-01 | 库存报表 | 按分类/仓库/类型维度汇总数字与余额页一致；切换维度刷新 |
| RPT-02 | 出入库汇总 | 日/月粒度数量 = 流水按方向求和；净变动=入-出；下钻跳转正确 |
| RPT-03 | 生产统计-达成率 | 达成率=完工/计划精确到 2 位小数；颜色分级正确 |
| RPT-04 | 生产统计-良率 | 良率=合格/(合格+不良)；无不良时 100% |
| RPT-05 | 生产统计-物料耗用 | 耗用=Σ领料-Σ退料，与库存模块流水核对一致 |
| RPT-06 | 采购销售汇总 | 金额=已审核单据合计（分转元精确）；差额=销售-采购 |
| RPT-07 | 日期筛选 | 闭区间生效；跨月边界正确（8/1-8/31 不含 9/1） |
| RPT-08 | 空数据 | 无数据区间显示空态（KPI 0、图表空、表格空态文案），不报错 |

## 7. 边界与异常场景

- 日期非法（date_from > date_to）：`{code:1601, message:"开始日期不能晚于结束日期"}`
- 无数据区间：返回 `items:[]`、totals 全 0，前端空态展示
- 分转元换算统一在后端完成（PHP 整数运算），前端仅格式化千分位
- 报表接口不做分页（聚合结果行数受维度限制），超大维度（>500 行）时返回前 500 + `truncated:true` 标记
