# 仪表盘模块（pages/dashboard.md 页覆盖）

> 依据：MASTER.md（Swiss Modernism 2.0）+ pages/report.md（KPI 卡样式复用）+ ui-ux-pro-max 检索
> （ux 域：Loading States「骨架屏或 spinner，禁无反馈冻结」/ Empty States「无内容时给出引导文案与动作」/
> Progress Indicators「多步流程给进度指示」；color 域：Analytics Dashboard 蓝色数据+琥珀高亮——
> 本项目沿用 nexus 语义令牌，方向色为入库绿/出库红/待审核琥珀）
> LOGIC：本页存在时覆盖 MASTER.md 通用规范，仅对仪表盘模块生效

## 1. 页面骨架
- .page-card 骨架与各模块一致；本模块只读、无写操作按钮、无弹窗、无手动刷新按钮
- 结构顺序：KPI 卡区（4 张一行，grid 12 布局）→ 中部双栏（左 2/3 待审核、右 1/3 工单进度）→ 底部库存预警
- 单号/数量/进度/金额一律 Fira Code；数字千分位（formatThousand；库存总值后端已转元）
- 所有可点击元素 cursor:pointer；hover/状态变化 150-300ms transition；页面永不整页空白

## 2. KPI 卡片（4 张/行，复用 report.md §2 KPI 卡规范）
- 白底卡片、border 1px #E6E8EA、border-radius 8px、shadow-sm、padding 16px
- 标签：12px #64748B（库存总量/今日入库/今日出库/待审核单据）；数值：Fira Code 24px bold #0F172A + 千分位
- 次级文案（kpi-sub）：12px #64748B——卡 1「库存总值 ¥xx」或「未启用成本核算」（value=null 时，禁显 ¥0）；
  卡 2 次级「出库 Σxx」（spec §4 原文）
- 方向色：今日入库数值 #059669 前缀 `+`；今日出库数值 #DC2626 前缀 `-`；待审核数值 #D97706（琥珀，无前缀）
- 网格：repeat(4, 1fr)、gap 16px（1440 断点一行 4 张；窄屏自动降列不横向滚动）
- 待审核卡可点击：点击平滑滚动到待审核区（scrollIntoView）；hover shadow-md

## 3. 中部双栏
- 网格 2fr 1fr、gap 24px；面板 .panel（白底/border/8px 圆角/16px padding），标题 .panel-title 14px 600 #0F172A
- 左：待审核单据——按 module 分组（分组标签 .pending-tag：灰底 #F2F3F4 12px #475569）；
  行 .pending-row：类型标签（12px 描边 #334155）+ 单号 Fira Code 13px + 时间 12px #94A3B8 右对齐 + 右箭头 SVG；
  hover 背景 #F8FAFC；行点击按后端 url 跳转（前端 ALLOWED_PATHS 白名单放行，不在白名单不跳）
- 右：工单进度——行 .order-row：单号 Fira Code + 状态标签（生产中琥珀 el-tag type=warning / 已完成绿 el-tag type=success，
  复用生产模块五态语义色）；商品名 13px #475569；el-progress 8px + 进度文本 Fira Code 12px 右对齐「xx.xx%」；
  行点击跳 /production/orders（V1 无独立工单详情路由，列表页承载详情 tabs）

## 4. 底部库存预警
- 红色低库存卡片网格 repeat(auto-fill, minmax(240px, 1fr))、gap 16px
- 卡片：border #FECACA + 左边框 4px #DC2626 + 浅红底 #FEF2F2 + 8px 圆角 + 12px padding
- 内容：商品名 13px 600 + 编码 Fira Code 12px #64748B + 仓库名 12px #64748B + 「当前 xx / 下限 xx」Fira Code 12px #DC2626
- 卡片点击跳 /inventory/alerts；hover shadow-md

## 5. 加载/错误/空态（spec §7 + ux 域）
- 加载中：各区 el-skeleton 占位（固定行数，防 CLS）；挂载即并行请求 4 接口（无刷新按钮）
- 单区失败：该区「加载失败 + 重 试」按钮（骨架屏换重试），**其余区照常渲染**（并行容错，TC-DSH-08）
- 空态：待审核「全部单据已审核 ✓」（SVG 绿勾图标组件 Check + 绿字 #059669）；预警「库存状态正常」；
  工单进度 el-empty「暂无进行中工单」（image-size 60 小尺寸）
- 权限：无任一审核权限的用户隐藏待审核 KPI 卡与待审核区（TC-DSH-07）

## 6. 反模式（禁止）
- emoji 当图标（绿勾/箭头必须 @element-plus/icons-vue SVG 组件）
- 单区失败连带整页错误提示（必须区级隔离）
- 待审核时间/单号裸字体（必须 Fira Code）
- 预警卡无 hover 反馈/cursor:pointer
- 空态整页空白；KPI 数值 hover 才显示（数值恒文本可见）
