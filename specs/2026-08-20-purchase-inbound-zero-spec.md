# 采购入库支持 0 数量行 设计文档

- 日期：2026-08-20
- 状态：细化设计（对应主 spec：`2026-08-12-purchase-spec.md` 采购入库章节）
- 覆盖需求：④采购入库「从订单生成」时数量可填 0 —— 0 = 本次不收货该商品，跳过该行

## 1. 模块职责与范围

采购订单一行可能跨多次收货（部分收货是现实业务）。现状 `store/update/approve` 均强制 `quantity > 0`，导致无法跳过本次不收的商品行。本次放开「从订单生成」场景：**数量可填 0，0 即不入本入库单**，其余行正常收货。

## 2. 前置依赖

| 依赖项 | 说明 |
|---|---|
| 后端 `PurchaseInboundController`（fromOrder/store/update/approve） | 数量校验与过滤逻辑改造 |
| 前端 `views/purchase/InboundsView.vue` | 从订单生成弹窗允许 0 数量，提交时过滤 |
| 既有流水/审核机制 | 不变（仅约束与过滤，不影响 InventoryService） |

## 3. 规则定义

| 场景 | 规则 |
|---|---|
| 从订单生成（`fromOrder` 预填 + 新建） | 数量默认 = 剩余量；**允许填 0**；0 数量行**不入本入库单**（前端过滤 + 后端兜底过滤） |
| 手动新增入库单（不选订单） | 仍要求 `quantity > 0`（现有 1302 校验保留） |
| 审核 `approve` | 保持 `0 < quantity ≤ 剩余量` 复核；入库单内不会存在 0 行（生成时已过滤），无需改审核校验 |

**校验口径**：`0 ≤ quantity ≤ 剩余量`（bcmath 比较，2 位小数）；错误码：`quantity < 0` → `{code:1302, message:"数量不能小于 0"}`；`quantity > 剩余量` → 沿用 1308；过滤后无明细（全部行为 0）→ 沿用 `{code:1301, message:"请至少添加一条明细"}`。

## 4. 后端改造（server/app/Http/Controllers/Api/PurchaseInboundController.php）

1. `fromOrder`：返回的 `remaining_qty` 不变（前端据此默认填值）。
2. `store`：
   - 入参校验放宽：`quantity >= 0`（原 `> 0`），错误码 1302 文案改为「数量不能小于 0」。
   - **入库前过滤**：`quantity == 0` 的行直接从明细中剔除（不落库）。若全部行为 0 → 报 `{code:1301, message:"请至少添加一条明细"}`（沿用现有空明细错误码）。
   - 汇总金额/数量统计按过滤后的行计算。
3. `update`：同口径（`>= 0` + 过滤 0 行）。
4. `approve`：不变（入库单内已无非 0 行，复核 `bccomp` 保持）。

## 5. 前端改造（web/src/views/purchase/InboundsView.vue）

- 从订单生成的明细行数量输入允许 0，blur 校验范围 `0 ≤ qty ≤ 剩余量`，超上限回弹提示（沿用现有提示文案）。
- 行尾提示：数量为 0 的行显示灰字「本次不收货」。
- 提交前 `filter(qty > 0)`；若过滤后为空 → 提示「请至少录入一个收货数量大于 0 的商品」。
- 提交后刷新列表并 `ElMessage.success`。

## 6. 业务流转说明

```
从订单生成 → 逐行填本次收货量（默认剩余量，可改 0 跳过）→ 保存
  → 0 数量行被剔除 → 生成仅含 >0 行的入库单（草稿）→ 审核扣/加库存流水
  → 订单 received_qty 仅按实际收货行回写；0 行商品留在订单上待下次收货
```

## 7. 边界与异常场景

- 全部行填 0 → 拒绝并提示（后端 1301 + 前端拦截双保险）。
- 混合行（部分 0 部分 >0）→ 只生成 >0 行，订单状态按实际收货行 `syncStatus` 重算。
- 编辑草稿时把已有行改为 0 → 该行从入库单剔除（更新语义与新建一致）。
- 不影响手动新增（无订单）的 `> 0` 约束，防止空数量单据。

## 8. 单元/接口测试（PHPUnit）

| 用例 | 覆盖点 |
|---|---|
| `test_from_order_allows_zero_qty_skip_row` | 从订单生成含 0 数量行 → 入库单不含该行 |
| `test_store_all_rows_zero_rejected` | 全部 0 行报 1301 且不入库 |
| `test_store_rejects_negative_qty` | 负数量报 1302「数量不能小于 0」 |
| `test_manual_create_still_requires_positive` | 手动新增 0 数量仍被拒（1302 原约束保留） |
| `test_update_zero_removes_row` | 编辑草稿行改 0 → 行被剔除 |
| `test_approve_still_checks_remaining` | 审核超剩余量仍报 1308，库存不重复变动 |
| `test_received_qty_only_updates_non_zero_rows` | 0 行商品 received_qty 不变，订单可再次收货 |

## 9. E2E（Playwright，`web/e2e`，用例带 TC 编号）

| 用例 | 场景 |
|---|---|
| TC-PUR-01 | 从订单生成：某行填 0 → 入库单不含该行，订单该商品显示剩余可再收货 |
| TC-PUR-02 | 全部行填 0 → 保存被拦截提示 |
| TC-PUR-03 | 手动新增入库单数量填 0 → 仍被拦截 |

## 10. 测试、CI 与合入流程（本 Spec 交付门禁）

### 10.1 门禁命令（本地全量 + CI 双跑）
```
server: vendor/bin/pint → vendor/bin/phpcs → vendor/bin/phpstan → composer test
web:    npm run type-check / lint / lint:css / format:check / test:unit / test:e2e
```
CI 由 `.github/workflows/ci.yml` 三 job 执行，汇总 job「ci」全绿为合并条件；本地提交走 husky lint-staged，禁止 `--no-verify`。

### 10.2 核心功能界定
涉及库存/收货数据一致性（核心路径），本次改动相关的采购入库单测**100%**；既有入库相关测试全量回归。失效旧测试（断言"0 数量被拒"的用例）同次提交删除。

### 10.3 PR 与代码评审
- 建分支 `feat/purchase-inbound-zero` → PR 到 `main`（受保护），门禁全绿。
- 调用 **`/code-review` 外部插件**（`~/.config/opencode/command/code-review.md`，Claude Code 式 PR 评审：资格检查 + 5 路并行 Sonnet 审核 + 置信度评分 ≥80 过滤）审核 PR，产出问题清单。
- **逐条验证问题真实性**：真实存在 → 修复并补测试后复评；误报 → 说明理由不修。
- 复评通过 → squash merge 到 `main`。
- 接口契约变更（`api/purchase.ts` 与调用处）前后端同次提交。
