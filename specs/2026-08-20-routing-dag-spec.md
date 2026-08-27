# 工艺路线 DAG + BOM 绑定工序 设计文档

- 日期：2026-08-20
- 状态：细化设计（对应主 spec：`2026-08-12-inventory-production-system-design.md` §5.2/7、`2026-08-12-master-data-spec.md` §4.6/5.7，本次重构 BOM/工序耦合关系）
- 覆盖需求：⑤不同商品制作过程不同 → 工序在 BOM（工艺路线）中自由绑定，支持**并行工序 DAG**；是委外重构（spec 5）的前置
- 调研依据：SAP PP 工艺路线（Routing：工序网络、一个起点一个终点、可版本化）+ 工序关系（Operation Relations）模型；MES 工序派工网络

## 1. 模块职责与范围

现状缺陷：BOM 明细无工序、BOM 头无工艺路线，工单展开的工序序列 = **全部启用工序快照**（所有商品共用一套）。本次重构为：

1. 新增**工艺路线**（Routing）概念：挂成品下、可版本化（与 BOM 同维度），内容为**工序 DAG 网络**。
2. 每个工序节点定义：工序、**输入材料**（原料或前驱产出半成品）、**输出产品**（半成品或成品）、是否委外工序。
3. 工单展开同时读 BOM（物料需求）与工艺路线（工序 DAG）；报工按 **DAG 推进**（前驱全部完成 → 后继可开工），支持并行分支。
4. 前端 **Vue Flow 画布**编辑器 + 工单工序网络展示。

**范围**：新增工艺路线 CRUD + 工单展开/报工流转改造。委外派单重构在 spec 5 落地（本 spec 只把 `is_outsourced` 标记带到工序节点，不实现委外发收料）。

## 2. 前置依赖

| 依赖项 | 说明 |
|---|---|
| 基础资料 | 商品（原料/半成品/成品主数据）、工序（`processes`）、BOM（沿用现有） |
| 生产模块 | 工单创建展开（`ProductionOrderService::expandBom`）、报工流转（`OperationReportController`） |
| 前端 | 新增依赖 **`@vue-flow/core`**（唯一新增 npm 依赖，版本锁定 ^1.x） |

## 3. 数据模型

### 3.1 新增表

```
routing_headers   id, code(唯一), product_id(FK 成品), version(默认 v1),
                  quantity(基准产出, 默认 1), status(0停用/1启用, 同成品启用唯一), remark
routing_nodes     id, routing_id(FK), node_no(如 OP10, 同 routing 唯一), process_id(FK 工序),
                  name(工序名快照), output_product_id(FK 商品: 半成品或成品),
                  output_qty(相对基准产出的产出数量, 默认 1), is_outsourced(0/1), remark
routing_node_materials id, node_id(FK), material_id(FK 商品: 原料或半成品),
                  qty_per_unit(单位产出的投入用量), unit_id,  唯一(node_id, material_id)
routing_edges     id, routing_id(FK), from_node_id(FK), to_node_id(FK), 唯一(routing_id, from, to)
```

### 3.2 DAG 约束（保存时后端强制校验）

1. **有向无环**：环路 → `{code:1701, message:"工艺路线存在工序环路"}`。
2. **至少一个起点（入度 0）与至少一个终点（出度 0）**；允许并行分支与汇合。
3. **结构闭合（账本对上）**：每个节点的输入材料必须是「原料商品」或「某前驱节点的输出半成品」；每个中间节点的输出半成品必须被至少一个后继节点作为输入消耗（或该节点为终点且输出=成品）。不满足 → `{code:1702, message:"工序[{name}]的输入/输出未闭合：材料[{material}]无产出来源"}` / `{code:1703, message:"半成品[{name}]未被任何后继工序消耗"}`。
4. **数量闭合**：半成品产出量（上游 output_qty×基准）与下游消耗量（下游 qty_per_unit×基准）折算不一致时 → `{code:1704, message:"工序[{name}]投入产出数量对不上账"}` 阻断（防止损耗被凭空抹平）。

### 3.3 既有表扩展（迁移增列，不回写存量）

```
bom_headers          不改（工艺路线独立成表，与 BOM 各自版本化，工单展开时按成品同时取启用 BOM + 启用工艺路线）
production_order_materials  + node_no(varchar, 物料归属工序节点, 可空=仅按总量领料不归节点)
work_order_operations       + node_no(varchar), output_product_id(FK), is_outsourced(0/1)
                            + 唯一约束调整：order_id + node_no 唯一（沿用 order_id+seq 展示序）
work_order_operation_edges(新) id, order_id, from_operation_id, to_operation_id, 唯一(order_id, from, to)
```

> 存量已下达/进行中工单不受影响（已快照数据不回写）；新工单按新逻辑展开。

## 4. API 接口清单

统一前缀 `/api/v1`，权限 `routing.*`。

| 接口 | 方法 | 说明 |
|---|---|---|
| `/api/v1/routings` | GET | 分页列表。Query：`product_id, keyword, status`；items 含 `{id, code, product_name, version, quantity, status}` |
| `/api/v1/routings` | POST | 单头+DAG 一次提交。请求体 `{product_id, version, quantity, remark, nodes:[{node_no, process_id, name, output_product_id, output_qty, is_outsourced, materials:[{material_id, qty_per_unit, unit_id}]}], edges:[{from_node_id|from_node_no, to_node_id|to_node_no}]}`。校验见 §3.2 |
| `/api/v1/routings/{id}` | PUT | 更新（DAG 全量替换）；被工单引用仅可停用不可改结构 `{code:1705, message:"工艺路线已被生产工单使用，仅可启用/停用"}` |
| `/api/v1/routings/{id}` | DELETE | 删除；被工单引用不可删 `{code:1706, message:"工艺路线已被生产工单使用，不可删除"}` |
| `/api/v1/routings/{id}/toggle` | PUT | 启用/停用（启用时自动停用同成品其他版本） |
| `/api/v1/routings/{id}/graph` | GET | 返回节点+边完整 DAG（Vue Flow 渲染用）：`{nodes:[{id, node_no, process_name, output_product_name, is_outsourced, materials:[...]}], edges:[...]}` |

### 4.1 工单相关调整

| 接口 | 说明 |
|---|---|
| `POST /production/orders` | 展开逻辑：**无启用工艺路线时沿用旧逻辑（全量启用工序快照）并 warn**；有则按工艺路线生成 DAG 工序序列 + 物料按 `node_no` 归属 |
| `GET /production/orders/{id}` | 详情 operations 增加 `node_no, output_product_name, is_outsourced, predecessors`；新增 `graph` 字段供 Vue Flow 渲染工序网络 |
| `POST /production/operations/{id}/reports` | 流转规则改 DAG（见 §6） |

## 5. 页面与交互设计

### 5.1 工艺路线管理页（`/master/routings`，权限 `routing.*`）

- 列表：编码、成品、版本、基准数量、状态标签、操作（「画布编辑」「详情」「删除」「启用/停用」）。
- **新建/编辑弹窗 = Vue Flow 画布**（对话框宽 ~1200px，高 600px）：
  - 左侧工具栏：工序库（从 `processes` 下拉选择/拖拽）。
  - 画布：节点卡片显示 `OP10 · 工序名` + 输入材料列表 + 输出半成品；连边表示依赖。
  - 节点配置面板（点选节点弹出）：工序、输出产品（el-select 仅半成品/成品）、产出数量、是否委外 switch、输入材料动态行（材料 el-select 仅原料/半成品 + 用量 + 单位自动带出）、备注。
  - 校验按钮「校验 DAG」：前端做环路预检（拓扑排序）+ 调用后端 §3.2 校验，错误以画布高亮 + message 展示。
  - 保存：一次性提交节点+边+DAG 校验。
- 半成品商品需先在主数据维护（type=semi_finished）供工序节点选择产出/输入。

### 5.2 工单详情「工序网络」tab（`/production/orders` 详情）

- 用 **Vue Flow 只读渲染**工单工序 DAG：节点状态着色（待开工灰/进行中蓝/已完成绿）、委外节点琥珀边框标记。
- 点击节点查看：累计合格/不良/工时、报工记录、输入材料已领/需求。

### 5.3 工序管理页（`/master/processes`）

- 无结构变化；工序编码/名称/排序继续用于工艺路线节点选择。

## 6. 业务流转说明

```
成品工艺路线维护：选成品 → 画布拖节点/连线 → 每节点配输入材料+输出半成品+委外标记
  → 校验 DAG（环/闭合/数量闭合）→ 保存（启用版本唯一同成品）

工单创建：选成品+BOM → 后端同时取启用工艺路线：
  ├─ 无 → 旧逻辑全量工序快照（warn 兼容）
  └─ 有 → 展开 DAG：节点→work_order_operations(+node_no/output_product/is_outsourced)，
          边→work_order_operation_edges，物料按 node_no 归属写入 production_order_materials

报工 DAG 推进：
  开工 → 所有入度 0 节点「进行中」
  报工(合格+不良+工时) → 合格累计 ≥ 计划数(工单数量) → 本节点「已完成」
       → 检查其后继：所有前驱已完成的后继 →「进行中」（并行分支可同时进行中）
  委外节点(is_outsourced=1)：不可报工，只能经委外单回收完成（spec 5）
  末节点全部完成 + ≥1 次成品入库 → 工单「已完成」
```

## 7. 功能清单与通过条件

| 编号 | 功能 | 通过条件 |
|---|---|---|
| RTG-01 | 工艺路线 CRUD | 画布保存成功；启用版本唯一同成品 |
| RTG-02 | DAG 环路拦截 | 建环保存报 1701 |
| RTG-03 | 结构闭合校验 | 材料无产出来源 1702；半成品未被消耗 1703 |
| RTG-04 | 数量闭合校验 | 投入产出数量不一致报 1704 |
| RTG-05 | 工单按 DAG 展开 | 并行分支节点序列正确、边快照正确、物料按 node_no 归属 |
| RTG-06 | 无工艺路线兼容 | 老商品工单仍按全量工序展开并 warn |
| RTG-07 | DAG 报工推进 | 前驱全完成才后继进行中；并行分支独立推进；委外节点不可报工 |
| RTG-08 | 工单网络展示 | 详情工序网络 tab 用 Vue Flow 呈现节点状态与委外标记 |
| RTG-09 | 引用保护 | 被工单引用不可删(1706)、仅可启停(1705) |

## 8. 边界与异常场景

- 工艺路线启用版本唯一：新启用自动停用同成品旧版本（沿用 BOM 口径）。
- 工单下达后工艺路线被停用/改版：不影响已下达工单（工序序列已快照）。
- 工单计划数变化（草稿期编辑）：按最新计划数重算各节点计划量，DAG 结构不变。
- 并行分支某条完成、其余未完成：工单不提前完成，仅该分支节点标记完成。
- 数量闭合是「结构+基准折算」级校验；实际损耗通过报工不良数体现，不做损耗率计算（V1）。
- Vue Flow 依赖仅前端；后端纯数据校验不感知画布。

## 9. 单元/接口测试（PHPUnit + Vitest）

### 9.1 后端（核心，100%）
| 用例 | 覆盖点 |
|---|---|
| `test_routing_store_saves_dag` | 节点+边+DAG 结构正确落库 |
| `test_routing_rejects_cycle_1701` | 环路保存被拒 |
| `test_routing_rejects_open_chain_1702_1703` | 材料无来源/半成品未消耗被拒 |
| `test_routing_rejects_qty_mismatch_1704` | 数量闭合校验 |
| `test_order_expands_routing_dag` | 工单展开节点/边/物料归属正确 |
| `test_order_without_routing_falls_back` | 无工艺路线沿用旧快照 + warn |
| `test_report_advances_dag_parallel` | 并行分支报工推进正确 |
| `test_report_rejects_outsourced_node` | 委外节点不可报工 |
| `test_routing_deletion_guard` | 被引用不可删 1706、仅启停 1705 |

### 9.2 前端
- `routing-view.test.ts`：画布节点/边增删、环路预检、保存载荷结构。
- `order-detail-graph.test.ts`：工序网络节点状态渲染。

## 10. E2E（Playwright，`web/e2e`，用例带 TC 编号）

| 用例 | 场景 |
|---|---|
| TC-RTG-01 | 建含并行分支的工艺路线（A→B/C/D→E）→ 校验通过保存 |
| TC-RTG-02 | 建环路 → 保存被拦截提示 1701 |
| TC-RTG-03 | 按新工艺路线建工单 → 工序网络展示并行节点、状态正确 |
| TC-RTG-04 | 并行报工：B/C 完成后 D 仍待开工、E 才进行中 |
| TC-RTG-05 | 老商品（无工艺路线）建工单仍可用（兼容回退） |

## 11. 测试、CI 与合入流程（本 Spec 交付门禁）

### 11.1 门禁命令（本地全量 + CI 双跑）
```
server: vendor/bin/pint → vendor/bin/phpcs → vendor/bin/phpstan → composer test
web:    npm run type-check / lint / lint:css / format:check / test:unit / test:e2e
```
CI 由 `.github/workflows/ci.yml` 三 job（backend/frontend/e2e）执行，汇总 job「ci」全绿为合并条件；本地提交走 husky lint-staged，禁止 `--no-verify`。`package-lock.json` 随 `@vue-flow/core` 依赖变更同次提交。

### 11.2 核心功能界定
DAG 校验、工单展开、报工流转均属数据一致性/状态机关键路径（核心），后端本 spec 相关单测**100%**；前端画布编辑器非核心 ≥ 80%。失效旧测试（断言线性全量工序展开的用例）同次提交删除或改造。

### 11.3 PR 与代码评审
- 建分支 `feat/routing-dag` → PR 到 `main`（受保护），门禁全绿。
- 调用 **`/code-review` 外部插件**（`~/.config/opencode/command/code-review.md`，Claude Code 式 PR 评审：资格检查 + 5 路并行 Sonnet 审核 + 置信度评分 ≥80 过滤）审核 PR，产出问题清单。
- **逐条验证问题真实性**：真实存在 → 修复并补测试后复评；误报 → 说明理由不修。
- 复评通过 → squash merge 到 `main`。
- 接口契约变更（`api/production.ts`、新增 `api/routing.ts` 及调用处）前后端同次提交；`specs/` 与本设计不一致处同步更新。

## 12. 实现偏离记录（2026-08-24 合并 dev）

以下偏离在实现中确认，已按「spec 与实现不符时以实现为准并标注」记录：

1. **错误码追加**：在 1701-1706 基础上追加 1707（同成品启用版本唯一，BOM 1120 同构）、1708（路线成品必须是成品）、1709（节点输出必须是半成品或终点工序的路线成品）、1710（节点输入必须是原料或半成品）。
2. **`production_orders.routing_id` 扩列（spec 未列）**：spec §3.3 未提，但工单必须锚定所用工艺路线快照（与 `bom_id` 同构），否则 Spec 5「从工序预填委外组件」无法追溯路线、变更后无法做引用保护（1705/1706 判定）。可空，存量工单不回写。
3. **数量闭合语义逐节点**：半成品数量闭合按「产出节点逐个校验」——每个产出半成品的节点，其 `output_qty×基准` 必须等于**全部直接后继对该半成品消耗合计**（`Σ qty_per_unit×基准`）。推论：**并行分支必须产出互异半成品**，同品种多产一耗无法通过闭合（多产一耗场景请拆为互异半成品表达）；一产多耗（分叉）合法。spec 原文公式据此解释落地。
4. **边载荷仅 `node_no`**：spec §4 写 `from_node_id|from_node_no`，实现只收 node_no（新建时节点无 id；更新亦全量替换），语义等价，YAGNI。
5. **TC-RTG-04 断言口径（spec TC 表文字有歧义）**：结构 A→B/C/D→E；实测断言为「A 完成后 B/C/D 进行中；B、C 完成后 D 仍进行中（独立分支不受影响）、E 仍待开工；D 完成后 E 才进行中」——即汇合点必须全部前驱完成才推进。
6. **每节点完成线 = 工单计划数**（spec §6 字面）：节点合格累计 ≥ 工单数量即完成，不按节点产出数量折算（V1，损耗经报工不良体现）。
7. **节点号自动生成只读**：画布节点 node_no 由前端按 OP10/OP20 步进自动分配（`nextNodeNo`），不可编辑（保证边引用唯一性，语义与手动输入等价）。
8. **画布位置不持久化**：spec 表无坐标列；编辑/回显按拓扑分层自动布局（`utils/dag.ts`），重开弹窗重新布局。
9. **委外节点完成须推进 DAG 后继**（spec §6 隐含、Spec 5 OUT-04 明确）：委外工序经回收满量置「已完成」时，若其直接后继的全部前驱已完成，则该后继置「进行中」——锁序改为 `outsourcing→全部工序(升序)→order`，与报工/完工同向。该项在 Spec 4 即落地（否则含委外分支的工单无法完工），Spec 5 承接细化。
10. **委外库存对象暂按工单成品（已知缺口）**：委外发出（approve）扣成品库存、回收（storeReceipt）加成品库存沿旧模型；DAG 允许委外节点输出半成品，分支委外场景出现「库存对象=成品、实际委外物=半成品」错位，`OutsourcingDagTest` 固化现状行为。Spec 5 重构为「回收必须入 `output_product_id` 对应库存（错误码 1529）」时承接修正。**已于 Spec 5（2026-08-24）修复**：发料组件/回收品均取节点口径，1529 一致性校验落地。
11. **画布位置不持久化的补充**：节点拖拽位置在结构编辑（增删节点/边）后重置为自动分层布局；节点配置编辑（材料/数量）不重置。V1 设计权衡，spec 无坐标列。
12. **`routing_warning` 响应字段**：store 在无启用工艺路线回退时返回 `data.routing_warning`（后端告警+测试断言），前端暂不消费（新建成功即关弹窗，前端判断回退语义靠详情 `routing_id === null`）。
