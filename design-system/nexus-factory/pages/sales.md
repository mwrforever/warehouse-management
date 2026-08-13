# 销售管理页设计规范（nexus-factory / sales）

> 依据：`design-system/nexus-factory/MASTER.md`（Swiss Modernism 2.0）+ ui-ux-pro-max 表单 UX 查询
> （on-blur 校验/错误提示醒目/提交加载反馈/焦点可见）。
> 覆盖销售 2 页：订单 `/sales/orders`、出库单 `/sales/outbounds`。
> 前置规范（页面骨架/按钮/弹窗/表格）见 MASTER.md 与 `pages/master-data.md`，本节只定义销售特有样式。

## 1. 页面骨架

- 2 页统一 `.page-card` 容器 + `.page-title` + `.toolbar`（左标题右操作）
- 金额列统一 Fira Code + 右对齐 + `¥` 前缀（`formatYuan` 千分位 2 位小数）
- 单号列 Fira Code（`class-name="font-code"`），`cursor: pointer` 点击看详情（未实施——与采购页镜像一致，待产品决策后统一落地）
- 弹窗宽 `900px` + `:close-on-click-modal="false"`（明细表格宽，禁止误触关闭丢数据）
- **商品下拉仅含成品/半成品**（原料禁售 SAL-10）：`productApi.list({type:'finished'})` 与 `{type:'semi_finished'}` 两次调用合并

## 2. 销售订单页（orders）

- 状态标签五态（同态不同色防混淆，镜像采购订单）：
  - 草稿 `el-tag type="info"`（灰 #6B7280）
  - 已审核 `el-tag type="success"`（绿 #059669）
  - 部分出库 `el-tag type="primary"`（蓝 #3B82F6）
  - 已完成 `el-tag type="success"`（深绿 #047857，自定义 class `tag-done`）
  - 关闭 `el-tag type="danger"`（红 #DC2626）
- 操作列按状态分流：草稿 →「编 辑」「删 除」「审 核」；已审核/部分出库 →「查 看」「关 闭」；已完成/关闭 →「查 看」
- 新建/编辑弹窗（900px）：抬头区客户*（el-select 可搜索，`customerApi.list({status:1})` 仅启用）、下单日期*（默认今天）、预计发货（date）、备注；明细表格（商品*可搜索/扫码、数量* min=1 precision=2、单价* min=0 单位元提交 ×100 转分、行金额只读）
- 重复商品行：添加时即时校验（`ElMessage.warning`「明细存在重复商品」+ 不添加）
- 底部合计条：右侧「合计：`¥1,100.00`」（Fira Code 加粗，实时）
- 审核：confirm「确认审核订单 {no}？审核后不可修改」→ `ElMessage.success`「审核成功」
- 关闭：confirm「确认关闭订单 {no}？关闭后不可再出库」
- 详情弹窗（900px）：头信息描述列表 + 明细表格（订购数/已出库列）+ el-tabs「出库记录」（空态「暂无出库记录」）

## 3. 销售出库单页（outbounds）

- 状态标签：草稿 `el-tag type="info"`、已审核 `el-tag type="success"`
- 新建弹窗两种入口：**「从订单生成」**（选可出库订单 → `fromOrder` 预填客户+明细剩余量 → 数量可改 ≤ 剩余 → 选仓库*/库位*）；**「新 建」独立出库**（手工选客户 + 明细 + 仓库*/库位*）
- 出库单客户下拉仅启用客户（status=1）；from-order 模式客户框禁用（后端客户一致性 1407 兜底）
- 审核：confirm「确认审核出库单 {no}？审核后库存将减少且不可修改」→ `ElMessage.success`「出库成功，库存已更新」
- **审核失败（库存不足 1409）**：红色 `ElMessage.error` 直接显示后端 message（含商品名与当前库存精确值），单据保持草稿——错误信息醒目且就近可见
- 列表页顶部「今日已出库」汇总行：`todaySummary` 按商品 chips 展示（`FIN-002 成品B ×12`，浅绿底 Fira Code），当日无出库隐藏
- 详情弹窗：头信息（含来源订单单号）+ 明细表格；`route.params.id` 直达（流水页单号跳转）
- 列表「来源订单」列：有值显示单号（Fira Code），无值显示「—」（独立出库）

## 4. 交互细节（ui-ux-pro-max 表单 UX 落地）

- 所有输入 on-blur/即时校验（数量/价格/重复商品/剩余量上限），错误提示 `ElMessage` 就近可见，不等到提交
- 提交按钮带 loading（`saving` 状态），成功后 `ElMessage.success` 反馈（不静默）
- 可点击元素一律 `cursor: pointer`，hover 过渡 150-300ms
- 弹窗关闭二次确认：明细已编辑时（dirty 标记）关闭弹窗 `ElMessageBox.confirm`「明细未保存，确认关闭？」（未实施——与采购页镜像一致，待产品决策后统一落地）
