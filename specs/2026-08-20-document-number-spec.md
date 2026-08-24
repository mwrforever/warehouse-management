# 单据编号自动生成 设计文档

- 日期：2026-08-20
- 状态：细化设计（对应主 spec：`2026-08-12-inventory-production-system-design.md` §4.4.1）
- 覆盖需求：③编码/条码/单号后端自动生成，业务意义 + 同类业务固定长度；格式 `{固定大写业务前缀}{年月日时分}{按配置补零的递增序列}`，无连字符，序列长度用户可配置

## 1. 模块职责与范围

将单据编号从「写死格式」升级为**配置驱动 + 固定长度 + 业务含义**，并覆盖商品编码/条码的自动生成：

| 现状 | 本次落地 |
|---|---|
| 单号 `{prefix}{Ymd}-{seq:03d}`（如 `MO20260812-001`），含连字符、长度随日期波动、seq 位数写死 3 | 统一 `{prefix}{date_format}{seq 补零}` 无连字符，**seq 长度按配置表** |
| 商品编码/条码手动录入 | 新建商品自动生成编码与条码（条码=编码，可手动覆盖） |
| 12 类单据类型常量写死在 `DocumentSequence.php` | 编号规则收敛到配置表 `document_number_configs`，前缀/日期格式/序列长度可配 |

**范围**：业务单据号（check/bom/po/pi/so/sout/mo/pl/rl/os/osr/fi，共 12 类）+ 商品编码 + 商品条码。**不含**字典/权限等系统内编号。

## 2. 前置依赖

| 依赖项 | 说明 |
|---|---|
| 既有 `DocumentSequenceService` | 保留其并发机制（行锁 + 冲突重试 + legacyMax），仅格式化输出与配置源改造 |
| 系统管理模块 | 编号规则配置入口放「系统管理」下（复用 RBAC `system.setting.*`，见 §5） |
| 迁移工具链 | 新表 + 种子走迁移（§10 门禁含 pint/phpcs/phpstan/phpunit） |

## 3. 数据模型

```
document_number_configs  id, type(唯一, 如 po/mo/prd), prefix(大写, 如 PO/MO/PRD),
                         date_format(如 YmdHi, 允许空=无日期段), seq_length(默认 3),
                         enabled(0/1), remark
document_sequences       现有表（type+date 唯一, seq 原子自增）保持不变
```

**格式规则**（对每个 type 配置生效）：

```
单号 = {prefix}{date_format 格式化当前时间}{seq 按 seq_length 补零}
例：PO + YmdHi + seq_length=4 → PO2026082015300001
商品编码 = {prefix}{seq 按 seq_length 补零}（date_format 为空）
例：PRD + seq_length=6 → PRD000001；条码默认 = 商品编码
```

**约束**：
- 同一 type 的编号**长度固定**（prefix 固定 + date_format 固定 + seq_length 固定），禁止运行期改动长度导致撞号（配置变更需评估存量数据，见 §7）。
- `seq_length` 为配置项（用户可调，默认 3；生产环境变更需与存量单号位宽对齐评审）。
- 序列键粒度与 `date_format` 对齐（`YmdHi` → 键为分钟），保证同键内序号自增不撞；`legacyMax` 扫描同日内既有单号段取最大序号续接。

## 4. 后端改造（server）

### 4.1 `DocumentSequenceService`

- 新增 `nextNoByConfig(string $type, ?array $overrides = null)`：从 `document_number_configs` 读 type 规则（`prefix/date_format/seq_length`），组合出最终单号。
- 保留：`lockForUpdate()` 行锁、persist 闭包内撞号重试（最多 3 次）、删除单据不回退号段。
- 向后兼容：既有历史单号保持原样，仅新生成单号走新格式；`document_sequences` 存量行按新键粒度重新 firstOrCreate 续接。
- 商品编码走同一服务：type=`prd`，`date_format=''`。

### 4.2 商品编码/条码自动生成

- `ProductController::store`：当请求未提供 `code`（或提供空）→ 自动 `nextNoByConfig('prd')`；未提供 `barcode` → 默认 = code。
- 手动录入仍支持：提供 `code`/`barcode` 时沿用现有唯一校验（1114/1115）。

### 4.3 配置种子（`database/seeders`）

为 12 类单据 + prd 生成默认配置行：

| type | prefix | date_format | seq_length |
|---|---|---|---|
| check | CK | YmdHi | 3 |
| bom | BOM | YmdHi | 3 |
| po / pi | PO / PI | YmdHi | 3 |
| so / sout | SO / ST | YmdHi | 3 |
| mo | MO | YmdHi | 3 |
| pl / rl | PL / RL | YmdHi | 3 |
| os / osr | OS / OSR | YmdHi | 3 |
| fi | FI | YmdHi | 3 |
| prd | PRD | （空） | 6 |

> 委外退料单类型 `osrt`（前缀 ORT）由委外重构 spec（`2026-08-20-outsourcing-spec.md`）追加配置行。

## 5. 页面与交互设计

- 新增「系统管理 → 编号规则」页（权限 `system.setting.*`）：
  - 表格列：类型、前缀、日期格式、序列长度、状态、操作。
  - 编辑弹窗：前缀（大写字母，2~4 位）、日期格式（下拉：无/Ymd/YmdHi/YmdHis）、序列长度（number，1~10）、备注。
  - 编辑后展示「规则预览」：按当前值生成一次示例单号供核对。
  - **修改 seq_length/date_format 变更提示**：若存量单号位宽与新规则不一致，弹确认框说明「仅影响新生成单号，请评审位宽一致性」。
- 商品新建弹窗：编码/条码字段留空时显示灰字提示「留空则自动生成」，保存后回填。

## 6. 业务流转说明

```
新建单据/商品 → 提交时未指定编号 → 按 type 读配置表 →
  prefix + date_format(当前时间) + seq(行锁自增+补零) → 写 document_sequences → 单号入库
商品条码默认=编码 → 扫码直接命中商品
```

## 7. 边界与异常场景

- 同秒/同分钟并发取号：行锁串行化 + 撞号重试兜底，不重复不跳号。
- 配置被删/停用：回退到默认规则（前缀=type 大写、YmdHi、seq 3 位），不抛业务异常。
- seq 达到位数上限：按 `seq_length` 宽度自然溢出（`999`→`1000` 时若仍 3 位则继续增长不截断，长度以实际为准并 warn 日志提示调整配置）。
- 商品编码长度固定是「同一配置下」的保证；变更配置前必须评审，避免长度不一致（§5 弹窗提示）。
- 历史数据不回写：只影响新生成编号，存量单据号保持原样（只增不改原则）。

## 8. 单元/接口测试（PHPUnit）

| 用例 | 覆盖点 |
|---|---|
| `test_next_no_follows_config_format` | 前缀+日期+补零正确拼装；date_format 为空时无日期段 |
| `test_seq_length_configurable` | seq_length=4/6 分别补零正确 |
| `test_config_missing_falls_back_default` | 无配置/停用回退默认规则 |
| `test_concurrent_sequences_no_collision` | 并发取号不重复（行锁+重试路径） |
| `test_product_code_auto_generate` | 商品留空 code/barcode 自动生成且条码=编码；手填走唯一校验 |
| `test_legacy_sequence_continue` | 新格式在同日既有单号段上续接 |
| 全量回归 | 12 类单据创建均返回新格式单号，唯一索引不冲突 |

## 9. E2E（Playwright，`web/e2e`，用例带 TC 编号）

| 用例 | 场景 |
|---|---|
| TC-NUM-01 | 新建商品留空编码/条码 → 保存后自动生成且条码=编码、长度一致 |
| TC-NUM-02 | 新建采购订单/工单 → 单号格式 `{前缀}{年月日时分}{4位序号}` 且同类型长度一致 |
| TC-NUM-03 | 编号规则页编辑 seq_length → 预览示例变化 → 保存后新单号按新位宽生成 |

## 10. 测试、CI 与合入流程（本 Spec 交付门禁）

### 10.1 门禁命令（本地全量 + CI 双跑）
```
server: vendor/bin/pint → vendor/bin/phpcs → vendor/bin/phpstan → composer test
web:    npm run type-check / lint / lint:css / format:check / test:unit / test:e2e
```
CI 由 `.github/workflows/ci.yml` 三 job（backend/frontend/e2e）执行，汇总 job「ci」全绿为合并条件；本地提交走 husky lint-staged，禁止 `--no-verify`。

### 10.2 核心功能界定
单据编号生成涉及并发/唯一性（数据一致性关键路径），按核心功能要求**单测 100%**；页面/配置 CRUD 非核心 ≥ 80%。失效旧测试（断言旧格式单号的用例）同次提交删除。

### 10.3 PR 与代码评审
- 建分支 `feat/document-number` → PR 到 `main`（受保护），门禁全绿。
- 调用 **`/code-review` 外部插件**（`~/.config/opencode/command/code-review.md`，Claude Code 式 PR 评审：资格检查 + 5 路并行 Sonnet 审核 + 置信度评分 ≥80 过滤）审核 PR，产出问题清单。
- **逐条验证问题真实性**：真实存在 → 修复并补测试后复评；误报 → 说明理由不修。
- 复评通过 → squash merge 到 `main`。
- 接口契约变更（如有）前后端 `api/` 模块与调用处同次提交。
