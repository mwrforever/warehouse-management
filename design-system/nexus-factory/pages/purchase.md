# 采购管理页设计规范（nexus-factory / purchase）

> 依据：`design-system/nexus-factory/MASTER.md`（Swiss Modernism 2.0）+ ui-ux-pro-max 表单 UX 查询
> （on-blur 校验/错误提示醒目/提交加载反馈/焦点可见）。
> 覆盖采购 2 页：订单 `/purchase/orders`、入库单 `/purchase/inbounds`。
> 前置规范（页面骨架/按钮/弹窗/表格）见 MASTER.md 与 `pages/master-data.md`，本节只定义采购特有样式。

## 1. 页面骨架

- 2 页统一 `.page-card` 容器 + `.page-title` + `.toolbar`（左标题右操作）
- 金额列统一 Fira Code + 右对齐 + `¥` 前缀（`formatYuan` 千分位 2 位小数）
- 单号列 Fira Code（`class-name="font-code"`），`cursor: pointer` 点击看详情
- 弹窗宽 `900px` + `:close-on-click-modal="false"`（明细表格宽，禁止误触关闭丢数据）

## 2. 采购订单页（orders）

- 状态标签五态（同态不同色防混淆）：
  - 草稿 `el-tag type="info"`（灰 #6B7280）
  - 已审核 `el-tag type="success"`（绿 #059669）
  - 部分入库 `el-tag type="primary"`（蓝 #3B82F6）
  - 已完成 `el-tag type="success"`（深绿 #047857，自定义 class `tag-done`）
  - 关闭 `el-tag type="danger"`（红 #DC2626）
- 操作列按状态分流：草稿 →「编 辑」「删 除」「审 核」；已审核/部分入库 →「查 看」「关 闭」；已完成/关闭 →「查 看」
- 新建/编辑弹窗（900px）：
  - 抬头区：供应商*（el-select 可搜索，`supplierApi.list({status:1})` 仅启用）、下单日期*（el-date-picker 默认今天）、预计到货（date）、备注
  - 明细表格（`size="small"` `max-height="360"`）：商品*（el-select 可搜索/扫码框回车匹配）、数量*（el-input-number min=1 precision=2）、含税单价*（el-input-number min=0 precision=2 单位元，提交 ×100 转分）、行金额（只读 `formatYuan`）
  - 重复商品行：添加时即时校验（`ElMessage.warning`「明细存在重复商品」+ 不添加）
  - 底部合计条：右侧「合计：`¥1,000.00`」（Fira Code 加粗，实时 `formatYuan(Σ数量×单价×100)`）
  - 按钮：弹窗 footer「取 消」「保 存」（`:loading="saving"` 提交反馈）
- 审核：`ElMessageBox.confirm` 文案「确认审核订单 {no}？审核后不可修改」→ 成功 `ElMessage.success`「审核成功」
- 关闭：confirm「确认关闭订单 {no}？关闭后不可再入库」
- 详情弹窗（900px）：头信息描述列表 + 明细表格 + el-tabs「入库记录」（`orderInbounds`，空态「暂无入库记录」）

## 3. 采购入库单页（inbounds）

- 状态标签：草稿 `el-tag type="info"`、已审核 `el-tag type="success"`
- 新建弹窗两种入口（顶部 el-radio 切换）：
  1. **「从订单生成」**：先 el-select 选订单（`availableOrders` 下拉，仅已审核/部分入库且有剩余）→ 自动请求 `fromOrder` 预填供应商+明细行（列含「剩余量」灰色提示）→ 数量可改（≤ 剩余，超量 `ElMessage.warning` 拦截）→ 选仓库*/库位*
  2. **「新 建」独立入库**：手工选供应商 + 明细（扫码添加）+ 仓库*/库位*
- 明细商品选择框支持扫码：条码输入框自动聚焦，扫枪回车 → `productApi.byBarcode` → 命中追加行（默认数量 1、单价 0 待填）；未命中 `ElMessage.error`（1117 文案）输入保留
- 审核：confirm「确认审核入库单 {no}？审核后库存将增加且不可修改」→ 成功 `ElMessage.success`「入库成功，库存已更新」
- 详情弹窗：头信息（含来源订单单号链接跳订单详情）+ 明细表格；`route.params.id` 直达（流水页单号跳转）
- 列表「来源订单」列：有值显示单号（Fira Code），无值显示「—」（独立入库）

## 4. 交互细节（ui-ux-pro-max 表单 UX 落地）

- 所有输入 on-blur/即时校验（数量/价格/重复商品），错误提示 `ElMessage` 就近可见，不等到提交
- 提交按钮带 loading（`saving` 状态），成功后 `ElMessage.success` 反馈（不静默）
- 可点击元素一律 `cursor: pointer`，hover 过渡 150-300ms
- 弹窗关闭二次确认：明细已编辑时（dirty 标记）关闭弹窗 `ElMessageBox.confirm`「明细未保存，确认关闭？」
