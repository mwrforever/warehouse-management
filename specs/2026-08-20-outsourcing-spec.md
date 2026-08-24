# 工序委外重构 设计文档

- 日期：2026-08-20
- 状态：细化设计（对应主 spec：`2026-08-12-production-spec.md` §4.5/5.5/6，本次按 SAP 工序委外机制重构；前置依赖 `2026-08-20-routing-dag-spec.md`）
- 覆盖需求：⑧委外派单——业务边界划分、材料出库/回收入库/余料退回、数量管控、经生产工单统一管理、工序网络（Vue Flow）展示
- 调研依据：SAP 工序委外（Processing，控制码 PP02 标记外协工序 → 工单自动触发委外需求 → 发料 541 移转供应商 → 回收 543 倒冲消耗组件 → 余料退回 542；物权不变，成本归集到生产订单）

## 1. 模块职责与范围

现状缺陷（探索结论）：委外单只绑「工单工序 + 成品数量」，无材料/产出/半成品概念、无并行、无物料出入库边界。本次重构：

1. 委外**绑定到工序节点**（工单展开自工艺路线 DAG，节点自带输入材料 + 输出半成品）。
2. 委外单承载完整物料闭环：**发料组件**（该节点输入材料清单，按委外量折算应发/实发）、**回收品**（该节点输出半成品/成品）、**余料退回**。
3. 库存模型（已确认）**简化版**：发料扣自有库存（`outsourcing_out` 流水）、回收加半成品库存（`outsourcing_in`）、余料退回加库存（新增 `outsourcing_return`）。
4. 经**生产工单统一管理**：工单详情「工序网络」标注委外节点，跳转委外单；委外量 ≤ 该节点计划量；委外回收满量 → 委外工序节点「已完成」→ DAG 继续推进。

## 2. 前置依赖

| 依赖项 | 说明 |
|---|---|
| 工艺路线 DAG（spec 4） | 工单工序节点含 `node_no/output_product_id/is_outsourced/输入材料快照` |
| 生产模块 | 工单、报工 DAG 推进（spec 4 §6）、成品入库联动（沿用） |
| 库存引擎 | `InventoryService`（唯一写入口，锁序「单据→工序→工单→库存余额」） |
| 基础资料 | 供应商、仓库/库位、商品（半成品主数据） |

## 3. 数据模型

### 3.1 既有表扩展（`outsourcing_orders`）

```
+ output_product_id (FK 回收品 = 该节点输出半成品/成品)
+ received_qty      (累计回收量, decimal 12,2, 默认 0)
+ status 语义调整：0草稿 → 1已发出(发料扣库存) → 2已回收(回收入库) → 3已关闭(余料退回完成, 可选)
```

### 3.2 新增表

```
outsourcing_order_items  id, outsourcing_id(FK), material_id(FK 发料组件),
                         required_qty(应发 = 委外量×节点单位用量), issued_qty(已发, 默认0),
                         returned_qty(已退回, 默认0), unit_id,  唯一(outsourcing_id, material_id)
outsourcing_returns      id, no(唯一, 类型 osrt, 前缀 ORT), outsourcing_id, item_id(FK, 可空=整体退回),
                         material_id, quantity, warehouse_id, location_id,
                         status(恒1=创建即审核), returned_at, operator, remark
```

### 3.3 库存流水 source_type 扩展

`InventoryService` 现有 9 种 source_type 基础上新增 `outsourcing_return`（+，余料退回）。发料/回收沿用 `outsourcing_out` / `outsourcing_in`。

## 4. 规则定义

| 环节 | 规则 |
|---|---|
| 可委外对象 | 仅工单中 `is_outsourced=1` 的工序节点（工艺路线里标记），且节点状态非「已完成」 |
| 委外量 | 1 ≤ 委外量 ≤ 该节点计划量（工单数量）− 已委外量；bcmath 比较；`{code:1520}` 沿用 |
| 发料组件 | 节点输入材料清单 × (委外量÷基准产出折算)；可逐行调整实发 ≤ 应发 |
| 发出（草稿→已发出） | 逐行校验组件库存充足（不足 `{code:1522, message:"商品[{name}]库存不足"}` 整体回滚）→ 扣库存写 `outsourcing_out` 流水 → `issued_qty` 回写 → 状态已发出。重复发出 `{code:1523}` |
| 回收（分批） | 回收量 ≤ 委外量 − 累计回收量（`{code:1524, message:"回收数量超过委外数量"}`）→ 回收品入半成品库存写 `outsourcing_in` → `received_qty` 累计 → 累计=委外量 → 委外单「已回收」+ 委外工序节点置「已完成」→ DAG 推进后继 |
| 余料退回 | 退回量 ≤ 该组件 `issued_qty − returned_qty` → 创建 `outsourcing_returns`（创建即审核）→ 库存+写 `outsourcing_return` → 回写 `returned_qty` → 全部组件退回完毕 → 委外单「已关闭」 |

**回收品一致性**：回收必须入 `output_product_id` 对应的半成品/成品库存，禁止回收品与节点输出不一致（`{code:1529, message:"回收商品与委外工序产出不一致"}`）。

## 5. API 接口清单

统一前缀 `/api/v1`，权限 `production.outsource.*`。既有的 `outsourcings` 列表/详情/更新/删除沿用，字段扩展。

| 接口 | 方法 | 说明 |
|---|---|---|
| `/production/outsourcings/from-operation/{operationId}` | GET | 预填：节点输入材料清单（应发组件=委外量×单位用量）、回收品、计划量、已委外量 |
| `/production/outsourcings` | POST | 新建。请求体 `{order_id, operation_id, supplier_id, warehouse_id, location_id, quantity, items:[{material_id, required_qty}], remark}`；`items` 由预填带出可调整 |
| `/production/outsourcings/{id}/approve` | POST | **发出**（校验组件库存→扣库存→写流水→已发出） |
| `/production/outsourcings/{id}/receipts` | POST | **回收**（批量/分批）：`{quantity, warehouse_id, location_id, remark}` → 半成品入库 → 累计 → 满量节点完成 |
| `/production/outsourcings/{id}/returns` | POST | **余料退回**：`{items:[{item_id, quantity}], warehouse_id, location_id, remark}` → 创建即审核 → 库存+ |
| `/production/outsourcings/{id}/returns` | GET | 退回记录列表 |

## 6. 页面与交互设计

### 6.1 委外页（`/production/outsourcings`，重构）

- 列表：单号、工单、**委外工序（含节点号 OPxx）**、供应商、**回收品**、数量、已回收、状态标签（草稿/已发出/已回收/已关闭）、操作。
- 新建（弹窗较大 ~900px）：
  1. 选工单 → 选**委外工序节点**（仅 `is_outsourced=1` 且未完成，下拉显示 `OP10 · 工序名（产出：半成品名）`）。
  2. 委外数量（≤ 节点剩余计划量）+ 供应商 + 仓库/库位。
  3. **发料组件表格**：自动带出节点输入材料清单（应发=委外量×单位用量），行内可改实发 ≤ 应发；显示各组件当前库存。
  4. 回收品信息（只读：该节点输出半成品）。
- 发出：confirm「确认发出委外物料？组件库存将减少」→ approve。
- 回收：弹窗填回收量（≤ 剩余）+ 入库仓库/库位 → 提交创建即审核 → 列表「已回收/已发货」进度更新；**满量后委外工序节点自动完成**。
- 余料退回：已发出后可见「退回余料」按钮 → 弹窗列出未退回组件（已发−已退）→ 填退回量 + 仓库/库位 → 提交。
- 回收记录/退回记录：只读弹窗列表。

### 6.2 工单详情「工序网络」tab（spec 4 §5.2 扩展）

- 委外节点：琥珀色边框 + 标记「委外」，点击显示委外单列表（单号/供应商/数量/状态），可跳转委外页。

### 6.3 工艺路线画布（spec 4 §5.1 扩展）

- 节点配置面板的「是否委外」switch 打开时，追加供应商提示文案「委外工序将在工单下达后生成委外需求（spec 5）」。

## 7. 业务流转说明

```
工艺路线标记委外工序 → 工单按 DAG 展开（委外节点 is_outsourced=1，不可报工）
→ 委外页从工单选该节点 → 自动带出发料组件清单 + 回收品 → 建委外单（草稿）
→ 发出（审核）：组件出库 outsourcing_out，状态已发出
→ 供应商加工 → 分批回收：回收品入半成品库存 outsourcing_in → 满量 → 节点已完成 → DAG 推进后继
→ 剩余组件退回：outsourcing_return 库存+ → 全部退回 → 委外单已关闭
→ 末节点完成 + 成品入库 → 工单已完成
```

## 8. 功能清单与通过条件

| 编号 | 功能 | 通过条件 |
|---|---|---|
| OUT-01 | 委外单绑工序节点+组件+回收品 | 新建自动带出节点输入材料与输出半成品；委外量 ≤ 节点剩余计划量 |
| OUT-02 | 发出扣组件库存 | 组件出库 outsourcing_out 流水、issued_qty 回写；库存不足整体回滚 1522 |
| OUT-03 | 分批回收入库 | 回收品入半成品库存 outsourcing_in、received_qty 累计、超量拦截 1524 |
| OUT-04 | 满量节点完成 | 累计=委外量 → 委外单已回收 + 委外节点已完成 + DAG 后继推进 |
| OUT-05 | 余料退回 | outsourcing_return 库存+、returned_qty 回写、超退拦截、全退后委外单已关闭 |
| OUT-06 | 委外节点不可报工 | 对 is_outsourced=1 节点报工被拒（spec 4 RTG-07 承接） |
| OUT-07 | 工单统一管理 | 工单工序网络标注委外节点并可跳转委外单 |
| OUT-08 | 幂等与锁序 | 重复发出/重复回收全拦截；锁序「单据→工序→工单→库存余额」全局一致 |

## 9. 边界与异常场景

- 委外节点不可报工，只能回收完成；回收量不足时节点保持「进行中/待开工」不推进。
- 组件库存不足时允许先建草稿（发出时拦截），与工单下达缺料警告口径一致。
- 回收允许分批；最后一批才触发节点完成，避免提前推进 DAG。
- 余料退回仅限已发出状态；已关闭委外单禁止任何操作。
- 委外中途供应商变更：V1 不支持（新建新委外单承接剩余量即可），评审说明。
- 发料组件含「前驱工序产出的半成品」：该半成品必须已通过前驱节点回收/入库有库存才能发出（否则 1522），保证账本连续。

## 10. 单元/接口测试（PHPUnit）

| 用例 | 覆盖点 |
|---|---|
| `test_from_operation_prefills_components` | 预填组件=节点输入×委外量、回收品=节点输出 |
| `test_approve_deducts_components_and_writes_movements` | 发出后组件库存-、outsourcing_out 流水、issued_qty 回写 |
| `test_approve_rejects_insufficient_stock` | 库存不足 1522 整体回滚 |
| `test_receipt_partial_and_full_advances_node` | 分批回收、满量后节点已完成 + DAG 后继推进 |
| `test_receipt_rejects_over_quantity` | 超量 1524、回收品不一致 1529 |
| `test_return_restores_stock` | 余料退回库存+、outsourcing_return 流水、全退后委外单已关闭 |
| `test_idempotency` | 重复发出 1523、已关闭禁操作 |
| `test_lock_order_consistency` | 锁序「单据→工序→工单→库存余额」不违反（沿用现有 ABBA 注释口径） |

## 11. E2E（Playwright，`web/e2e`，用例带 TC 编号）

| 用例 | 场景 |
|---|---|
| TC-OS-01 | 工单委外全链路：建委外单→发出（组件出库）→分批回收（半成品入库）→满量节点完成 |
| TC-OS-02 | 余料退回：退回组件→库存恢复→委外单已关闭 |
| TC-OS-03 | 委外节点不可报工被拦截 |
| TC-OS-04 | 工单工序网络标记委外节点并可跳转委外单 |

## 12. 测试、CI 与合入流程（本 Spec 交付门禁）

### 12.1 门禁命令（本地全量 + CI 双跑）
```
server: vendor/bin/pint → vendor/bin/phpcs → vendor/bin/phpstan → composer test
web:    npm run type-check / lint / lint:css / format:check / test:unit / test:e2e
```
CI 由 `.github/workflows/ci.yml` 三 job（backend/frontend/e2e）执行，汇总 job「ci」全绿为合并条件；本地提交走 husky lint-staged，禁止 `--no-verify`。E2E 纪律：跑 Playwright 前清理残留 7000/4000 进程，数据自建自清（AGENTS.md §9.5）。

### 12.2 核心功能界定
委外涉及资金/库存/数据一致性 + 对外单据（核心），本 spec 相关后端单测**100%**；前端页面非核心 ≥ 80%。失效旧测试（断言委外=成品数量、无组件/回收品逻辑的用例）同次提交删除或改造。

### 12.3 PR 与代码评审
- 建分支 `feat/outsourcing-rework` → PR 到 `dev`（受保护 main 之外的目标分支，2026-08-23 起约定；spec 原文 main 表述已被用户指令覆盖），门禁全绿。
- 调用 **`/code-review` 外部插件**（`~/.config/opencode/command/code-review.md`，Claude Code 式 PR 评审：资格检查 + 5 路并行 Sonnet 审核 + 置信度评分 ≥80 过滤）审核 PR，产出问题清单。
- **逐条验证问题真实性**：真实存在 → 修复并补测试后复评；误报 → 说明理由不修。
- 复评通过 → squash merge 到 `dev`。
- 接口契约变更（`api/production.ts` 及调用处）前后端同次提交；`specs/2026-08-12-production-spec.md` 委外章节按本 spec 更新；`specs/` 与本设计不一致处同步。

## 13. 实现偏离记录（2026-08-24 合并 dev）

1. **实发=草稿期调整+发出全额**：spec §6.1「行内可改实发 ≤ 应发」落地为创建/编辑时调整应发（required_qty ≤ 单位用量×委外量，后端 bcmath 权威），发出（approve）时 `issued_qty = required_qty` 全额扣减——不提供发出时逐行改动口径（YAGNI）。
2. **余料退回允许已回收状态**：spec §9「余料退回仅限已发出状态」收紧为「草稿/已关闭外均可（含已回收）」——否则满回收单（材料未全退）无法闭环关闭；状态机为 草稿→已发出→已回收→已关闭（全退自动）。
3. **returns 多行提交仅头记首行**：`outsourcing_returns` 表无明细行（spec 如此），一次提交多组件行时退回单仅记录首个组件；行级账实以 `outsourcing_order_items.returned_qty` 与 `inventory_movements`（source_type=outsourcing_return）为准。
4. **1529 以可选 product_id 冒烟校验落地**：回收请求体可选 `product_id`，提供时须等于委外单 output_product_id 否则 1529「回收商品与委外工序产出不一致」；output_product_id 为 null（数据异常）同样 1529。正常前端不传该字段。
5. **approve 的 InventoryService::apply quantity float 为引擎既定签名**：余额预校验全部 bcmath 字符串，float 仅单值传输（历史契约，不改为铁律冲突）。
6. **事务边界（AGENTS §2.2.1 缓行）**：既有三个写方法（store/approve/storeReceipt）事务留在控制器既有骨架（历史模式），新逻辑（from-operation 组装/validateItems）经 `OutsourcingService`；returns 亦落控制器骨架。偏离记录备查。
7. **委外仅限 is_outsourced=1 节点**：旧线性工单（无工艺路线）不可再委外（store/update/fromOperation 均 422「该工单没有工艺路线，不可委外」）；旧「委外=工单成品」口径全部移除（approve 组件口径、回收品=节点输出、1522 消息=组件名）；E2E TC-PRD-06 删除由 TC-OS 系列取代。
8. **已关闭态禁回收/退回**：补充约束（spec 未明示）——状态机终态不可再操作（防「收 3→退全关闭→再收」路径）。
9. **1520 文案更新**：「委外数量超过节点剩余计划量」（原「工单计划数量」语义随剩余量口径演进）。
10. **前端提示文案**：工艺路线画布委外 switch 提示「委外工序将在工单下达后生成委外需求」（spec §6.3 原文「（spec 5）」为章节引用误入，去除）。
11. **`receivedQty` 归一化**：无回收单时返回 `'0.00'` 与列表口径一致（E2E 触发发现）。
12. **组件流水复用既有 source_type**：发料沿用 `outsourcing_out`、回收沿用 `outsourcing_in`（分量逐组件），仅新增 `outsourcing_return`——流水归属以 source_no（委外单号/退回单号）区分。
13. **`received_qty` 不作列维护（评审波删列）**：`outsourcing_orders.received_qty` 列为评审波删除——全仓无写入点（spec §4 回收环节「received_qty 累计」落地为实时 `SUM(outsourcing_receipts)` 派生），避免与回收单双写漂移；index/show 输出同口径（index `withSum` / show `receivedQty()`，bcmath 归一），API 字段名 `received_qty` 不变。