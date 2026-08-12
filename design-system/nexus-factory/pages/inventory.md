# 库存管理页设计规范（nexus-factory / inventory）

> 依据：`design-system/nexus-factory/MASTER.md`（Swiss Modernism 2.0）；覆盖库存 4 页：
> 余额 `/inventory/balances`、流水 `/inventory/movements`、盘点 `/inventory/checks`、预警 `/inventory/alerts`。
> 前置规范（页面骨架/按钮/弹窗/表格）见 MASTER.md 与 `pages/master-data.md`，本节只定义库存特有样式。

## 1. 页面骨架

- 4 页统一 `.page-card` 容器 + `.page-title` + `.toolbar`（左标题右操作）
- 筛选区 `.toolbar` 内 el-select/el-input/el-switch 保持 260px 内宽，间距 `--space-md`
- 数量/单号列统一 `class-name="font-code"`（Fira Code），数字右对齐

## 2. 余额页（balances）

- 数量列：Fira Code + `font-weight: 700` + `color: var(--color-foreground)`（数据强调）
- 状态列（只对预警显示标签）：
  - level=1 低于下限：`el-tag type="danger"`（红 `#DC2626`）文案「低库存」
  - level=2 高于上限：`el-tag type="warning"`（琥珀 `#D97706`）文案「超上限」
  - level=0：不渲染标签（正常无标签，视觉安静）
- 行点击（展开流水）：整行 `cursor: pointer`，hover 背景 `#F1F5F9`，点击跳流水页并预填筛选
- 工具栏：「仅看预警」switch（active-text 空）+「导 出」btn-secondary 按钮

## 3. 流水页（movements）

- 方向列：`+` 绿 `#059669` / `-` 红 `#DC2626`，前缀符号 + Fira Code，粗体
- 单号列：Fira Code 链接样式（`color: var(--color-primary)` + 下划线 hover），点击行为：
  - check_in/check_out → 跳盘点详情 `/inventory/checks/{id}`
  - 其他来源 → `ElMessage.info` 提示「{类型}单据页随对应模块实施后开放」
- 单据类型列：el-tag（默认灰底 `#F1F5F9` 文字 `#334155`）
- 日期筛选：el-date-picker `type="daterange"` + 快捷项（今天/近 7 天/近 30 天）

## 4. 盘点页（checks）

- 状态标签：草稿 `el-tag type="info"`（灰）、已审核 `el-tag type="success"`（绿）
- 操作列：草稿 →「编 辑」「删 除」「审 核」；已审核 →「查 看」（仅查看，无编辑/删除入口）
- 新建弹窗：`width="900px"`；顶部仓库下拉 +「加 载账面数」按钮
- 明细表格列：商品（含编码）、库位、账面数（只读灰字）、实盘数（el-input-number min=0，前端拦截负数）、差异（仅详情展示）
- 扫码交互：明细区条码输入框自动聚焦（`v-focus` 或 onMounted focus），扫枪回车 → `byBarcode` 校验 → 命中自动追加行并回填账面数；未命中 `ElMessage.error` 展示后端 1117 文案，输入保留便于重扫
- 审核确认：`ElMessageBox.confirm` 文案「确认审核？差异将生成盘盈/盘亏流水并更新库存」；确认按钮主色绿
- 审核结果弹窗：`ElMessageBox.alert` 文案「盘盈 {increased} 项 +{增加数}、盘亏 {decreased} 项 -{减少数}」；changed_items=0 时提示「本次无差异，未生成流水」

## 5. 预警页（alerts）

- 顶部汇总条：`.summary-bar`（浅灰底 `#F8FAFC` 圆角 8px）文案「低于下限 N 项 / 高于上限 M 项」
- 卡片网格：`grid-template-columns: repeat(auto-fill, minmax(280px, 1fr))`，间距 `--space-lg`
- 卡片样式：白底、8px 圆角、`--shadow-card` 阴影；左侧 4px 色条（level=1 红 `#DC2626`、level=2 琥珀 `#D97706`）
- 卡片内容：商品名（加粗）+ 编码（Fira Code 灰）+ 仓库/库位 + 当前量（Fira Code 大号 20px）+ 下限或上限 + 超额幅度（如「低于下限 10」红字 /「高于上限 5」琥珀字）
