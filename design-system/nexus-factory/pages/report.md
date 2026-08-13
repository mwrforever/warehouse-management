# 统计报表模块（pages/report.md 页覆盖）

> 依据：MASTER.md（Swiss Modernism 2.0）+ ui-ux-pro-max 检索（chart 域：Trend Over Time 折线图/Compare Categories 分组柱状；ux 域：空态/千分位/表格横向滚动）
> LOGIC：本页存在时覆盖 MASTER.md 通用规范，仅对统计报表模块生效

## 1. 页面骨架（报表模块通用）
- .page-card/.page-title/.toolbar 与各模块一致；本模块只读无写操作按钮、无弹窗
- 结构顺序：筛选区（toolbar）→ KPI 卡片区 → 图表区（有则）→ 表格区
- 数量/金额/比率列 Fira Code 右对齐；数字一律千分位（formatThousand，金额已后端转元）
- 所有可点击元素 cursor:pointer；hover/状态变化 150-300ms transition
- 空态三要素（E2E TC-RPT-05）：KPI 卡显示 0、图表不渲染（v-if 有数据才渲染）、
  表格用 el-table 自带 empty 文案 + 图表区 el-empty「暂无数据」；页面永不整页空白

## 2. KPI 卡片（2-4 张/页，ui-ux-pro-max：数值恒以文本可见，非 hover-only）
- 白底卡片、border 1px #E6E8EA、border-radius 8px、shadow-sm、padding 16px
- 标签：12px #64748B（如「库存总量」）；数值：Fira Code 24px bold #0F172A + 千分位 + 单位
- 网格：repeat(auto-fit, minmax(180px, 1fr))、gap 16px（密集 8/10 令牌；窄屏自动换行不横向滚动）
- 差额类 KPI（净变动/差额）允许负值：负数红色 #DC2626

## 3. 折线图（出入库汇总，chart 域 Trend Over Time）
- 双系列：入库 #059669 实线 / 出库 #DC2626 **虚线**（颜色+线型双编码，色盲友好，不靠颜色单独传达）
- tooltip trigger=axis；图例顶部水平；X 轴周期字符串；图表容器 ReportChart 固定高 320px（防 CLS）
- 数据点 <4 个时不渲染图表只显表格（chart 域「Fewer than 4 data points → use stat card」）
- 粒度切换 radio 即重新请求并全量重设图表

## 4. 分组柱状图（采购销售汇总，chart 域 Compare Categories）
- 双系列分组柱：采购 #3B82F6 / 销售 #059669；值标签默认可见（chart 域 AAA 可访问性要求）
- X 轴时间周期升序；tooltip axis；图例顶部；同样固定高 320px、<4 周期不渲染

## 5. 表格
- overflow-x-auto 包裹（ux 域：表格横向滚动不破坏布局）
- 数量占比列（库存报表）：横向条形 = 灰底 #F2F3F4 轨道 + 绿 #059669 填充（宽度=占比%）+ 百分比文本
- 达成率/良率标签（生产统计）：el-tag——≥100 深绿 #047857（复用 tag-done）/ ≥80 琥珀 #D97706 / <80 红 #DC2626
- 物料耗用展开行（生产统计）：el-table type=expand，明细小表（物料编码/名称/耗用/单位）
- 下钻行（出入库汇总）：hover 背景 #F8FAFC + cursor:pointer；行点击跳转 /inventory/movements 带 query

## 6. 筛选区
- el-date-picker type=daterange（默认近 30 天）+ shortcuts：今天/近 7 天/近 30 天/本月（复用 MovementsView 模式）
- 粒度/维度切换用 el-radio-group（切换即重新请求，无需确认按钮）
- 生产统计成品下拉：productApi.list({type:'finished', per_page:100})，可清空
- 无「查 询」按钮——筛选变化即时联动（报表只读聚合的交互共识；E2E 按此断言）

## 7. 反模式（禁止）
- 图表区 0 数据渲染空坐标系（应整块 el-empty）
- 金额/数量列裸数字（必须 formatThousand）
- 折线双系列同线型（色盲不可区分）
- 下钻行无 hover 反馈/cursor:pointer
- 空态整页空白
