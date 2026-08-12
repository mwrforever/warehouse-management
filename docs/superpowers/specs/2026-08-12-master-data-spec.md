# 基础资料模块 细化设计文档

- 日期：2026-08-12
- 状态：细化设计（对应主 spec：`2026-08-12-inventory-production-system-design.md` 第 5.2、6、7.3、8 节）
- 对应端到端测试文档：`docs/test/2026-08-12-基础资料模块端到端测试.md`

## 1. 模块职责与范围

维护全系统的**主数据**：商品（原料/半成品/成品）、商品分类、计量单位、仓库与库位、供应商、客户、生产工序、BOM（物料清单，单头+明细）。本模块不产生库存变动，但为库存/采购/销售/生产模块提供数据引用与下拉选项，商品上的安全库存上下限是库存预警的数据源。

## 2. 前置依赖

| 依赖项 | 说明 |
|---|---|
| 系统管理模块（必须先行） | 本模块所有接口挂在 RBAC 下（`product.*`/`warehouse.*` 等）；登录态来自 `/auth/login`；下拉中的通用选项可引用字典 |
| 数据准备 | `categories/units/warehouses/locations/suppliers/customers/processes/products/bom_headers/bom_items` 迁移完成；种子含基础分类与单位 |

**本模块被依赖方**：

| 下游模块 | 依赖点 |
|---|---|
| 库存管理 | 商品（含类型/上下限）、仓库/库位 → 余额与流水 |
| 采购管理 | 供应商、商品 → 采购订单与入库单 |
| 销售管理 | 客户、商品 → 销售订单与出库单 |
| 生产管理 | 商品（成品）、BOM、工序 → 工单展开与工序流转 |
| 统计报表/仪表盘 | 全量主数据参与汇总与维度过滤 |

## 3. 数据模型

```
categories     id, name, parent_id(自关联, 0=顶级), sort, status
units          id, name(如"个"), code(如"pc"), status
warehouses     id, name, code(唯一, 如 WH01), address, manager, status
locations      id, warehouse_id(FK), name(如"A-01"), code(唯一: 仓库code-位置code), status
suppliers      id, name, code(唯一), contact, phone, address, remark, status
customers      id, name, code(唯一), contact, phone, address, remark, status
processes      id, name(如"车削"), code, sort, description, status
products       id, name, code(唯一, 支持扫码条码), type(enum: raw_material/semi_finished/finished),
               category_id(FK), unit_id(FK), spec(规格), barcode(条码, 唯一可空),
               safety_min(安全库存下限), safety_max(上限), status, remark
bom_headers    id, code(唯一, BOM20260812-001), product_id(FK 成品), version(版本号),
               quantity(基准产出数量, 默认1), status(启用/停用), remark
bom_items      id, bom_header_id(FK), material_id(FK 原料/半成品), quantity(单位产品用量), unit_id
```

**约束**：BOM 头关联商品必须为成品（type=finished）；BOM 明细物料必须为原料或半成品（不允许成品嵌套）；同一成品只允许一个启用版本（停用后可再启用，启用时自动停用其他版本）；删除商品/分类/单位/仓库等被业务单据引用时一律拒绝。

## 4. API 接口清单

统一前缀 `/api/v1`，响应 `{code, message, data}`，认证与权限约定同系统管理模块（§4）。

### 4.1 商品分类 / 计量单位（权限：`category.*`/`unit.*`）

| 接口 | 方法 | 说明 |
|---|---|---|
| `/api/v1/categories` | GET | 树形列表（含全部层级），响应 `data:[{id,name,parent_id,children:[...]}]` |
| `/api/v1/categories` | POST | 新建。请求体 `{name, parent_id, sort}` |
| `/api/v1/categories/{id}` | PUT / DELETE | 更新/删除；删除含子分类：`{code:1101, message:"存在子分类，不可删除"}`；删除被商品引用：`{code:1102, message:"分类已被商品使用，不可删除"}` |
| `/api/v1/units` | GET/POST/PUT/DELETE | CRUD。code 重复：`{code:1103, message:"单位编码已存在"}`；被商品引用不可删 `{code:1104}` |

### 4.2 仓库 / 库位（权限：`warehouse.*`）

| 接口 | 方法 | 说明 |
|---|---|---|
| `/api/v1/warehouses` | GET/POST/PUT/DELETE | CRUD。code 重复：`{code:1105, message:"仓库编码已存在"}`；有库存余额时不可删 `{code:1106, message:"仓库存在库存，不可删除"}` |
| `/api/v1/warehouses/{id}/locations` | GET | 库位列表（按仓库过滤） |
| `/api/v1/warehouses/{id}/locations` | POST | 新建库位，请求体 `{name, code, status}` |
| `/api/v1/locations/{id}` | PUT / DELETE | 更新/删除；有库存余额时不可删 `{code:1107}` |

### 4.3 供应商 / 客户（权限：`supplier.*`/`customer.*`）

| 接口 | 方法 | 说明 |
|---|---|---|
| `/api/v1/suppliers` | GET/POST/PUT/DELETE | CRUD。code 重复：`{code:1108}`；被采购单据引用不可删 `{code:1109, message:"供应商已被采购单据使用，不可删除"}` |
| `/api/v1/customers` | GET/POST/PUT/DELETE | CRUD。code 重复：`{code:1110}`；被销售单据引用不可删 `{code:1111}` |
| 上述两者 | GET | 均支持 `keyword` 模糊搜索、`status` 过滤、分页 |

### 4.4 工序（权限：`process.*`）

| 接口 | 方法 | 说明 |
|---|---|---|
| `/api/v1/processes` | GET | 列表（sort 升序），供生产模块下拉 |
| `/api/v1/processes` | POST | 新建 `{name, code, sort, description}`；code 重复：`{code:1112}` |
| `/api/v1/processes/{id}` | PUT / DELETE | 更新/删除；被工单工序引用不可删 `{code:1113, message:"工序已被生产工单使用，不可删除"}` |

### 4.5 商品（权限：`product.*`）

| 接口 | 方法 | 说明 |
|---|---|---|
| `/api/v1/products` | GET | 分页列表。Query：`page, per_page, keyword(编码/名称/条码模糊), type, category_id, status`。响应 items 含 `{id, name, code, type, type_label, category_id, unit_id, unit_name, spec, barcode, safety_min, safety_max, status}` |
| `/api/v1/products` | POST | 新建。请求体 `{name, code, type, category_id, unit_id, spec, barcode, safety_min, safety_max, status}`；code/barcode 重复：`{code:1114, message:"商品编码已存在"}` / `{code:1115, message:"条码已存在"}` |
| `/api/v1/products/{id}` | PUT / DELETE | 更新/删除；被任何库存流水/单据明细引用不可删 `{code:1116, message:"商品已被业务单据使用，不可删除"}` |
| `/api/v1/products/barcode/{barcode}` | GET | 扫码查询（扫枪场景），返回 `data:{id,name,code,type,spec,unit_name}`；未找到：`{code:1117, message:"条码未匹配到商品"}` |

### 4.6 BOM（权限：`bom.*`）

| 接口 | 方法 | 说明 |
|---|---|---|
| `/api/v1/boms` | GET | 分页列表，Query 支持 `product_id, keyword`；items 含 `{id, code, product_id, product_name, version, quantity, status}` |
| `/api/v1/boms` | POST | 新建单头+明细一次性提交。请求体 `{product_id, version, quantity, remark, items:[{material_id, quantity, unit_id}]}`；校验：成品类型错误 `{code:1118, message:"BOM 关联商品必须是成品"}`；物料类型错误 `{code:1119}`；重复启用版本 `{code:1120, message:"该成品已有启用版本的 BOM"}` |
| `/api/v1/boms/{id}` | PUT | 更新（含明细全量替换） |
| `/api/v1/boms/{id}` | DELETE | 删除；被生产工单引用不可删 `{code:1121, message:"BOM 已被生产工单使用，不可删除"}` |
| `/api/v1/boms/{id}/items` | GET | 明细列表：`[{id, material_id, material_name, quantity, unit_id, unit_name}]` |
| `/api/v1/boms/{id}/toggle` | PUT | 启用/停用切换（body `{status:0|1}`）；启用时自动停用同成品其他版本 |

## 5. 页面与交互设计

侧边栏菜单组「基础资料」，含 8 个子菜单：商品、分类、单位、仓库、供应商、客户、BOM、工序。列表页统一模式：顶部工具栏（搜索 + 新建）+ `el-table` + `el-pagination`（每页 10）+ 行操作（编辑/删除）；新建编辑统一 `el-dialog` 表单。

### 5.1 商品管理页（`/master/products`，权限 `product.list`）

- 工具栏：关键字输入（编码/名称/条码）、类型下拉（原料/半成品/成品）、「新 建」按钮
- 表格列：编码、名称、类型标签（原料蓝/半成品琥珀/成品绿）、分类、规格、单位、条码、安全库存（min~max）、状态、操作
- 新建弹窗字段：名称*、编码*、类型*（radio：原料/半成品/成品）、分类*（el-tree-select）、单位*（el-select）、规格、条码、安全库存下限/上限（number）、状态 switch、备注
- 类型切换联动：选择「成品」后提示「可为其维护 BOM」
- 条码输入框**自动聚焦**（`autofocus`），支持扫枪输入后回车直接提交保存（主 spec §9 扫码交互约定）

### 5.2 分类管理页（`/master/categories`）

- `el-tree` 树形展示 + 右侧「新 建」按钮（新建时选上级分类）
- 行内操作：编辑、删除（含子分类时禁用删除按钮）

### 5.3 单位管理页（`/master/units`）

- 简单列表：名称、编码、状态、操作

### 5.4 仓库管理页（`/master/warehouses`）

- 列表：编码、名称、地址、负责人、状态、操作（「库 位」按钮）
- 「库 位」点击打开该仓库的库位 `el-dialog`：库位表格 + 新增/编辑/删除

### 5.5 供应商/客户管理页（`/master/suppliers`、`/master/customers`）

- 列表：编码、名称、联系人、电话、地址、状态、操作

### 5.6 工序管理页（`/master/processes`）

- 列表：名称、编码、排序、说明、状态、操作；排序字段用于生产模块工序下拉顺序

### 5.7 BOM 管理页（`/master/boms`，权限 `bom.list`）

- 列表：BOM 编码、成品名称、版本、基准数量、状态标签（启用绿/停用灰）、操作（「明细」「编辑」「删除」「启用/停用」）
- 新建弹窗（较大，`el-dialog` 宽 800px）：
  - 单头区：成品*（el-select 仅成品类型商品）、版本*（默认 v1）、基准数量（默认 1）
  - 明细区：动态行表格，每行「物料*（el-select 仅原料/半成品）+ 用量*（number）+ 单位（自动带出）」，行尾删除按钮，底部「+ 添加物料行」
  - 「保 存」一次提交单头+明细
- 明细查看弹窗：只读表格

## 6. 业务流转说明

```
商品录入流转：填名称/编码/类型/分类/单位/上下限 → 保存(POST /api/v1/products)
  → 商品进入库存管理可选列表；扫枪时 GET /products/barcode/{barcode} 即时校验

BOM 维护流转：选成品 → 填版本与明细物料用量 → 保存（同成品启用版本唯一）
  → 生产模块建工单时按成品自动带出 BOM 物料需求

引用保护链：商品被库存/单据引用 → 删除被拒；分类有子分类 → 删除被拒；
供应商被采购单引用 → 删除被拒；工序被工单引用 → 删除被拒
（全部由后端事务 + 引用计数校验保证）
```

## 7. 功能清单与通过条件

| 编号 | 功能 | 通过条件 |
|---|---|---|
| MST-01 | 商品 CRUD | 增删改查即时生效；编码/条码重复被拦截；类型限定 |
| MST-02 | 商品扫码查询 | 扫枪输入条码回车 → 返回商品信息；未知条码提示「条码未匹配到商品」 |
| MST-03 | 分类树 | 树形展示层级；删除含子分类/被引用分类被拒 |
| MST-04 | 单位 CRUD | 正常增删改；被引用不可删 |
| MST-05 | 仓库/库位 CRUD | 库位挂仓库下管理；有库存的仓库/库位不可删 |
| MST-06 | 供应商/客户 CRUD | 正常增删改；被单据引用不可删 |
| MST-07 | 工序 CRUD | 排序生效；被工单引用不可删 |
| MST-08 | BOM 单头+明细 | 一次保存成功；成品/物料类型校验生效；启用版本唯一 |
| MST-09 | BOM 启用切换 | 启用新版本自动停用旧版本 |
| MST-10 | 删除保护全链路 | 所有被引用主数据删除均返回明确中文错误提示且列表不变 |

## 8. 边界与异常场景

- 商品编码、条码均支持字母数字与连字符；条码允许为空（非必录，扫枪场景可选）
- safety_min 默认 0、safety_max 默认 0（0 表示不预警该侧）；min > max 时前端校验拦截：`{code:1122, message:"安全库存下限不能大于上限"}`
- BOM 明细数量必须 > 0；同一 BOM 不允许重复物料行（合并提示：`{code:1123, message:"BOM 明细存在重复物料"}`）
- 分类最多两级（parent 必须是顶级或空，第三级被拒 `{code:1124}`）
- 停用（status=0）的商品/供应商/客户不出现在单据选择下拉中，但历史单据仍显示原名称
