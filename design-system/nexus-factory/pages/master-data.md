# Page Override: Master Data（基础资料模块页面）

> 依据 ui-ux-pro-max 检索（UX: table-handling / form-validation / submit-feedback / error-feedback / z-index 层级）与 MASTER.md 设计系统生成。

## 页面通用规范

- 页面结构：`页面标题（h2, Fira Sans 600）+ 白色卡片容器 .page-card（radius 8px、--shadow-sm、padding 20px）`；工具栏在卡片顶部，表格在卡片内
- 工具栏：搜索输入框（220px，clearable，Enter 触发）+「新 建」主按钮（.btn-primary 语义色 #059669）
- 表格：`el-table` + `el-pagination`（每页 10，layout: total, prev, pager, next）
- 弹窗：新建/编辑统一 `el-dialog`；表单项全部 `el-form-item label` 可见标签（禁止 placeholder-only）；提交按钮 `:loading` 反馈；保存成功 `ElMessage.success`、后端业务错误 `ElMessage.error` 就近提示
- 状态标签：启用 `el-tag type="success"`、停用 `el-tag type="info"`（与系统管理模块一致）
- 交互反馈：所有可点击元素 `cursor:pointer`；hover 过渡 150-300ms；焦点可见（不关闭 Element Plus 默认 focus 样式）；z-index 只用 Element Plus 默认层级，不写任意大值
- 删除：`ElMessageBox.confirm` 二次确认；取消/关闭静默返回（catch `'cancel'/'close'`），不产生未处理 rejection
- 图标：禁用 emoji，统一使用 `@element-plus/icons-vue` 或 Element Plus 内置图标

## 类型标签语义色（商品页）

| 类型 | 标签样式 |
|---|---|
| 原料 raw_material | 蓝 `#3B82F6`（`.tag-raw`） |
| 半成品 semi_finished | 琥珀 `#D97706`（`.tag-semi`） |
| 成品 finished | 绿 `#059669`（`.tag-fin`） |

```css
.tag-raw { background: rgba(59, 130, 246, 0.12); color: #2563EB; border: 1px solid rgba(59, 130, 246, 0.3); border-radius: 4px; padding: 2px 8px; font-size: 12px; }
.tag-semi { background: rgba(217, 119, 6, 0.12); color: #D97706; border: 1px solid rgba(217, 119, 6, 0.3); border-radius: 4px; padding: 2px 8px; font-size: 12px; }
.tag-fin { background: rgba(5, 150, 105, 0.12); color: #059669; border: 1px solid rgba(5, 150, 105, 0.3); border-radius: 4px; padding: 2px 8px; font-size: 12px; }
```

## 扫枪交互（商品页）

- 新建弹窗条码输入框 `autofocus`；聚焦条码框输入后按 Enter → 触发 `productApi.byBarcode` 即时校验：命中 → `ElMessage.success('条码匹配：{name}')` 并回填编码/名称；未命中 → `ElMessage.error('条码未匹配到商品')` 且**不清空输入**（便于修正重扫）
- 命中信息同时展示在弹窗内（如条码框下方提示行），满足 E2E TC-MST-08「页面显示匹配商品信息」

## 安全库存校验（商品页）

- 表单规则：`safety_max > 0 && safety_min > safety_max` → 前端拦截 `ElMessage.error('安全库存下限不能大于上限')` 且**不发请求**（与后端 1122 双保险）
