# 前端通用交互基础设施 设计文档

- 日期：2026-08-20
- 状态：细化设计（对应主 spec：`2026-08-12-inventory-production-system-design.md`，为全模块前端交互的地基层）
- 覆盖需求：①列表筛选防抖/节流+实时搜索+查询/重置/刷新 ②用户选择器（下拉/分页搜索两种形态） ⑥扫码录入独立表单（逐件扫描/自动累加开关） ⑦报工→领料闪白修复 + 路由转场动画

## 1. 模块职责与范围

抽取全系统复用的前端交互能力，消除现有重复实现（探索结论）：

| 痛点现状 | 本次落地 |
|---|---|
| 全项目无防抖/节流工具，6+ 列表页手写 toolbar+表格+分页 | 统一工具 + `ListFilterBar` + `useListQuery` |
| 搜索触发方式混乱（按钮触发/即选即查并存） | 统一「实时搜索防抖 + 查询/重置/刷新」 |
| 报工「操作人」自由文本输入且不预填当前用户 | `UserSelect` 选择器（下拉/分页搜索自动切换） |
| `scanAdd()` 在 5 个页面重复实现且行为不一致 | 独立扫码表单 `ScanInboundForm` + `useScanInbound` |
| App.vue / MainLayout RouterView 无过渡，报工→领料闪白 | 路由转场动画 + 全带参跳转页排查 |

本 spec **纯前端、无后端/库表变更**（用户选择复用现有 `GET /api/v1/users`）。

## 2. 前置依赖

| 依赖项 | 说明 |
|---|---|
| 前端基础 | Vue 3.5 + TS + Element Plus + vue-router 5 + Pinia（现有依赖，不新增） |
| 后端既有接口 | `GET /api/v1/users`（UserSelect 数据源）、各业务列表接口（筛选组件接入） |
| 不新增 npm 依赖 | 防抖/节流手写工具，约 30 行，不引 lodash/vueuse |

## 3. 新增文件清单

```
web/src/utils/async.ts              # debounce / throttle / useDebouncedRef
web/src/composables/useListQuery.ts # 列表查询状态 + 防抖加载 + 重置分页
web/src/components/ListFilterBar.vue# 统一筛选栏（插槽放筛选项 + 内置关键字/查询/重置/刷新）
web/src/components/UserSelect.vue   # 用户选择器（下拉 / 分页搜索弹窗自动切换）
web/src/components/ScanInboundForm.vue # 扫码录入独立弹窗（逐件扫描/自动累加开关）
web/src/composables/useScanInbound.ts  # 扫码逻辑（合并/报错/数量累计）
```

## 4. 组件与工具设计

### 4.1 防抖/节流工具（`utils/async.ts`）

```ts
// 类型约束：禁止 any（AGENTS.md §3.2.3），泛型 + unknown 窄化
export function debounce<T extends (...args: unknown[]) => unknown>(fn: T, ms = 300, immediate = false): T & { cancel(): void }
export function throttle<T extends (...args: unknown[]) => unknown>(fn: T, ms = 300): T
export function useDebouncedRef<T>(initial: T, ms = 300): Ref<T>  // v-model 实时搜索用
```

- `debounce`：尾调用防抖，支持立即执行开关；`cancel()` 供组件卸载清理。
- `throttle`：首调用节流（leading），用于「查询」按钮 1s 内仅执行一次、滚动加载等。
- `useDebouncedRef`：底层 `watch` + debounce，供 `ListFilterBar` 关键字实时搜索与 `UserSelect` 搜索框复用。

### 4.2 列表筛选栏（`ListFilterBar.vue` + `useListQuery.ts`）

**行为约定**（全列表页统一）：
- 关键字输入框：`@input` → `useDebouncedRef` 300ms 防抖 → 自动查询（page 重置为 1）。
- 「查 询」按钮：`throttle` 1s 防重复点击，立即查询（page 重置为 1）。
- 「重 置」按钮：清空全部筛选（含关键字/日期范围）→ 恢复默认值 → 查询（page 重置为 1）。
- 「刷 新」按钮：按当前筛选重载当前页（不重置 page）。
- 日期范围选择器（如有）：`@change` 防抖查询，同关键字口径。

**`useListQuery` 接口**：

```ts
function useListQuery<T extends Record<string, unknown>>(opts: {
  defaultQuery: T
  fetch: (q: T & { page: number; per_page: number }) => Promise<{ list: unknown[]; total: number }>
  debounceMs?: number
}) {
  query, page, per_page, list, total, loading,
  load(keepPage = false),            // 防抖加载
  search(),                          // 重置 page=1 后 load
  reset(),                           // 恢复 defaultQuery + page=1 + load
  refresh(),                         // 保持 page 的 load
  cancel(),                          // 卸载清理
}
```

**接入范围**（全部列表页）：采购（订单/入库）、销售（订单/出库）、生产（工单/领料/退料/委外/成品入库）、基础资料（商品/BOM/供应商/客户/仓库/单位/工序/分类）、库存（余额/流水/盘点）、系统（用户/角色/字典）。每页改造：删手写 query/load 样板，改为 `useListQuery` + `<ListFilterBar>`（筛选项通过默认插槽传入）。

### 4.3 用户选择器（`UserSelect.vue`）

- 封装 `userApi.list`，props：`modelValue`、`clearable`、`disabled`、`placeholder`。
- **阈值自动切换**：首次加载拉用户列表；`total ≤ 50` 用 `el-select`（直接选择）；`> 50` 切换为「点击输入框 → 弹出分页搜索弹窗」：搜索框（`useDebouncedRef` 300ms 实时搜索 + 「查 询」按钮）+ 分页表格 + 选中回填。
- 数据缓存：组件卸载前缓存已拉取选项（避免每次挂载重复请求），角色变更/失败自动刷新。
- 空态/失败：展示占位「无可用用户」，失败提示重试。

**应用点**：报工「操作人」（**默认预填当前登录用户**，可改选）；后续新增选人场景统一复用。

### 4.4 扫码录入表单（`ScanInboundForm.vue` + `useScanInbound.ts`）

独立弹窗 UI，**不混入新增表单**。Props：`open`、`title`、`excludeProductIds`（已存在行）；Emits：`update:open`、`add-items`（返回合并后的明细行）。

**交互流程**：
1. 弹窗打开自动聚焦扫码框（`nextTick` + ref）。
2. 扫枪输入条码回车 → `productApi.byBarcode` 命中商品。
3. 按开关状态处理（见下），处理后清空扫码框并保持聚焦，支持连续扫码。
4. 关闭弹窗把明细带回宿主页明细表（按行合并）。

**两个开关（弹窗顶部）**：

| 开关 | 默认 | 开 | 关 |
|---|---|---|---|
| 「逐件扫描」 | 关 | 扫一次 → 数量直接 +1，同款继续扫继续 +1（散件场景） | 扫一次 → 聚焦数量框 → 填本次数量 → 确定加入 |
| 「自动累加」 | 开 | 同条码再扫/再填 → **合并到同一行、数量相加** | 每扫一条新行；**若扫码商品已在列表中 → 报错提醒**「该商品已在列表中」，不合并也不加行 |

**组合语义**：逐件 = 「扫完是否先填数量」；自动累加 = 「同条码合并还是报错」，两者正交，四个开关状态均按上述规则生效。数量框校验：`> 0`、`≤ 工单/订单剩余量`（由宿主页传入上限）。

**替换范围**：采购订单/采购入库/销售订单/销售出库/库存盘点的扫码追加逻辑统一替换为 `ScanInboundForm`；商品页扫码校验回显保留。

### 4.5 路由转场动画（修复报工→领料闪白）

- **根因**：App.vue 与 MainLayout 的 `<RouterView>` 均无 `<transition>`，组件挂载前无过渡占位，跳转瞬间出现短暂空白。
- **改造**：
  - App.vue `<RouterView v-slot="{ Component }"><transition name="page" mode="out-in"><component :is="Component" /></transition></RouterView>`；
  - MainLayout 内容区 `<RouterView>` 同样包裹（避免布局内跳转闪白）。
  - `page` 过渡：fade + 上移 8px，200ms `cubic-bezier(0.22, 1, 0.36, 1)`；`transform: translateY(0)` 结束态，避免残留。
- **排查范围**：所有带参跳转页（生产工单→领料/退料/报工/委外/成品入库、采购入库、销售出库、库存盘点）均验证跳转无闪白。

## 5. 单元测试（Vitest，`web/src/tests/`）

| 用例 | 覆盖点 |
|---|---|
| `async.test.ts` | debounce 合并调用次数、immediate 语义、throttle 首调用、cancel 清理、useDebouncedRef 值延迟更新 |
| `list-filter.test.ts` | search 重置 page=1、reset 恢复默认、refresh 保持 page、防抖期内连续输入只发一次请求、卸载 cancel 不触发请求 |
| `user-select.test.ts` | ≤50 走下拉 / >50 走分页弹窗、搜索防抖、选中回填、预填当前用户 |
| `scan-inbound.test.ts` | 逐件开/关 × 累加开/关 四态行为、同条码报错不合并不加行、数量上限校验、重复条码 |
| 各接入列表页回归 | 改造后筛选/翻页行为与改造前一致（沿用 `production-filter-pagination` 模式） |

## 6. E2E（Playwright，`web/e2e`，用例带 TC 编号）

| 用例 | 场景 |
|---|---|
| TC-FR-01 | 列表页关键字实时搜索：输入停 300ms 后自动查询、页码重置 |
| TC-FR-02 | 查询/重置/刷新三按钮：重置清空筛选回默认、刷新保持当前页 |
| TC-FR-03 | 报工页操作人默认预填当前登录用户且可改选 |
| TC-FR-04 | 扫码弹窗逐件扫描关：扫码→填数量→确定→行合并数量相加 |
| TC-FR-05 | 扫码弹窗自动累加关：同条码再次扫码报错「该商品已在列表中」 |
| TC-FR-06 | 生产工单「报 工」→「领 料」跳转无闪白，页面正常渲染 |

## 7. 边界与异常场景

- 防抖加载与手动点击「查询」并发：以最后一次触发为准，loading 态互斥，禁止重复请求（用 `cancel` 清理）。
- 用户接口返回失败：UserSelect 降级为仅显示占位，不阻塞页面。
- 扫码未命中条码：提示「条码未匹配到商品」，保留输入便于重扫（沿用采购入库口径）。
- 扫码弹窗关闭时进行中的防抖/请求必须 `cancel()`，防止卸载后 setState。
- 转场动画仅影响视觉，禁止改变路由行为；`mode="out-in"` 下旧组件先退场，避免双渲染争抢焦点。

## 8. 测试、CI 与合入流程（本 Spec 交付门禁）

### 8.1 单元测试
核心（筛选组件/扫码逻辑/用户选择器）单测 100%，其余接入改造 ≥ 80%；因本改动失效的旧测试（如针对旧按钮触发方式的用例）**同次提交删除**。

### 8.2 CI 门禁（`.github/workflows/ci.yml`，push main / PR 到 main 触发）
前端 job：`npm run type-check` → `npm run lint` → `npm run lint:css` → `npm run format:check` → `npm run test:unit`；E2E job：`npx playwright test`；汇总 job「ci」全绿。本地提交走 husky lint-staged（lint+format 修复），禁止 `--no-verify`。

### 8.3 PR 与代码评审
- 建分支 `feat/frontend-infra` → PR 到 `main`（受保护），PR 门禁必须全绿。
- 门禁通过后调用 **`/code-review` 外部插件**（`~/.config/opencode/command/code-review.md`，Claude Code 式 PR 评审：资格检查 + 5 路并行 Sonnet 审核 + 置信度评分 ≥80 过滤）对 PR 审核，产出问题清单。
- **问题清单逐条验证真实性**：真实存在 → 修复并补/改测试后复评；不存在（误报）→ 说明理由不修。
- 复评通过 → squash merge 到 `main`。
