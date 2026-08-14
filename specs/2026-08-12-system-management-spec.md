# 系统管理模块 细化设计文档

- 日期：2026-08-12
- 状态：细化设计（对应主 spec：`2026-08-12-inventory-production-system-design.md` 第 5.1、6、7、8 节）
- 对应端到端测试文档：`docs/test/2026-08-12-系统管理模块端到端测试.md`

## 1. 模块职责与范围

系统管理是**整个系统的认证与权限地基**，负责：用户管理、角色管理（RBAC）、权限管理、数据字典管理。其他 7 个模块的全部页面与接口均受本模块的登录态与 RBAC 权限控制。

本模块不产生任何库存变动，与库存引擎无直接耦合。

## 2. 前置依赖

| 依赖项 | 说明 |
|---|---|
| 无业务模块依赖 | 本模块最先实施（主 spec §11 迭代 2），自身即为其他模块的前置条件 |
| 环境依赖 | MySQL 8.4（Docker）、Laravel 13 后端、Vue 3 + Element Plus 前端、数据库已执行 `users/roles/permissions/role_user/permission_role/dictionaries` 迁移并 seed 超级管理员 |

**本模块被依赖方**：其余 7 个模块的接口全部挂在 RBAC 中间件后，前端路由守卫依赖 `/auth/me` 返回的 `permissions` 数组；字典数据（如计量单位、单据类型）被基础资料模块下拉选项引用。

## 3. 数据模型

```
users            id, name, username(唯一), password(hash), email, avatar, status(1启用/0禁用),
                 last_login_at, created_at, updated_at
roles            id, name(唯一), code(唯一, 如 admin/operator), remark, created_at, updated_at
permissions      id, name, code(唯一, 如 user.create/role.update/inbound.approve), group(权限分组),
                 created_at, updated_at
role_user        role_id, user_id(联合唯一)
permission_role  permission_id, role_id(联合唯一)
dictionaries     id, name(如"计量单位"), code(唯一, 如 unit), remark, created_at, updated_at
dictionary_items id, dictionary_id(FK), label(显示名), value(值), sort(排序), status, created_at, updated_at
```

**权限 code 命名约定**（全系统统一，其余模块沿用）：`{资源}.{动作}`，动作含 `create/update/delete/approve/list`，如 `user.create`、`role.update`、`product.list`、`inbound.approve`。

## 4. API 接口清单

统一前缀 `/api/v1`，统一响应 `{code, message, data}`；`code=0` 成功，非 0 失败并带 `message` 中文错误说明；认证失败 `code=401`，无权限 `code=403`。

### 4.1 认证接口（公开）

| 接口 | 方法 | 说明 |
|---|---|---|
| `/api/v1/auth/login` | POST | 登录。请求体：`{username, password}`。成功响应：`{code:0, data:{token:"1|xxxx", user:{id,name,username,roles:[],permissions:[]}}}`；失败（用户名或密码错误）：`{code:1001, message:"用户名或密码错误"}` |
| `/api/v1/auth/logout` | POST | 登出（Bearer Token）。成功：`{code:0, message:"已退出登录"}` |
| `/api/v1/auth/me` | GET | 获取当前用户信息与权限。响应 `data:{id,name,username,roles:[{id,name}],permissions:["user.list",...]}` |

### 4.2 用户管理（权限：`user.*`）

| 接口 | 方法 | 权限 | 说明 |
|---|---|---|---|
| `/api/v1/users` | GET | `user.list` | 分页列表。Query：`page, per_page, keyword(用户名/姓名模糊), status`。响应 `data:{items:[{id,name,username,email,status,roles:[{id,name}],last_login_at}], total, page, per_page}` |
| `/api/v1/users` | POST | `user.create` | 新建用户。请求体：`{name, username, password, email, status, role_ids:[1,2]}`。成功：`{code:0, data:{id, name, username}}`；用户名重复：`{code:1002, message:"用户名已存在"}` |
| `/api/v1/users/{id}` | PUT | `user.update` | 更新用户。请求体同上（password 可空=不修改）。成功：`{code:0}` |
| `/api/v1/users/{id}` | DELETE | `user.delete` | 删除用户。被禁止删除内置 admin：`{code:1003, message:"内置管理员不可删除"}`；成功：`{code:0}` |
| `/api/v1/users/{id}/reset-password` | PUT | `user.update` | 重置密码。请求体：`{password}`。成功：`{code:0}` |

### 4.3 角色管理（权限：`role.*`）

| 接口 | 方法 | 权限 | 说明 |
|---|---|---|---|
| `/api/v1/roles` | GET | `role.list` | 分页列表 + 每个角色带 `permissions:["user.list",...]` |
| `/api/v1/roles` | POST | `role.create` | 新建角色。请求体：`{name, code, remark, permission_ids:[1,2]}` |
| `/api/v1/roles/{id}` | PUT | `role.update` | 更新角色（含重新分配权限）。请求体同创建 |
| `/api/v1/roles/{id}` | DELETE | `role.delete` | 删除角色。仍被用户引用时：`{code:1004, message:"该角色已分配给用户，不可删除"}` |
| `/api/v1/permissions` | GET | `role.list` | 全部权限（按 group 分组），供角色编辑页勾选树使用。响应 `data:{groups:[{group:"系统管理", permissions:[{id,name,code}]}]}` |

### 4.4 字典管理（权限：`dictionary.*`）

| 接口 | 方法 | 权限 | 说明 |
|---|---|---|---|
| `/api/v1/dictionaries` | GET | `dictionary.list` | 分页列表 |
| `/api/v1/dictionaries` | POST | `dictionary.create` | 新建字典。请求体：`{name, code, remark}`；code 重复：`{code:1005, message:"字典编码已存在"}` |
| `/api/v1/dictionaries/{id}` | PUT | `dictionary.update` | 更新字典 |
| `/api/v1/dictionaries/{id}` | DELETE | `dictionary.delete` | 删除字典（级联删除其字典项） |
| `/api/v1/dictionaries/{id}/items` | GET | `dictionary.list` | 字典项列表，响应 `data:{items:[{id,label,value,sort,status}]}` |
| `/api/v1/dictionaries/{id}/items` | POST | `dictionary.create` | 新增字典项。请求体：`{label, value, sort, status}` |
| `/api/v1/dictionaries/items/{itemId}` | PUT | `dictionary.update` | 更新字典项 |
| `/api/v1/dictionaries/items/{itemId}` | DELETE | `dictionary.delete` | 删除字典项 |
| `/api/v1/dictionaries/code/{code}` | GET | 公开（登录即可） | 按编码取启用字典项，供其他模块下拉使用 |

## 5. 页面与交互设计

路由前缀 `/system`，侧边栏菜单组「系统管理」（位于导航末尾，见主 spec §9 导航结构）。

### 5.1 登录页（`/login`，全系统唯一免登录页面）

- 居中卡片：用户名输入框、密码输入框（password 类型）、「登 录」主按钮（`btn-primary`，库存绿 `#059669`）
- 提交后请求 `POST /api/v1/auth/login`；成功后 `localStorage` 存 token → 跳转 `/dashboard`；失败红色 `ElMessage.error` 提示后端 `message`
- 已登录用户访问 `/login` 自动重定向 `/dashboard`
- 前端路由守卫：无 token 访问任意业务路由 → 重定向 `/login`；有 token 先请求 `/auth/me` 校验有效性并拉取权限

### 5.2 用户管理页（`/system/users`，权限 `user.list`）

- 页面：顶部工具栏（关键字搜索框 + 「新 建」按钮 btn-primary）、`el-table` 列表（列：ID、用户名、姓名、邮箱、状态标签、角色（el-tag 多个）、最后登录时间、操作列）
- 操作列：「编辑」「重置密码」「删除」文字按钮；禁用用户状态显示灰色「已禁用」标签
- 新建/编辑：`el-dialog` 表单（用户名/姓名/邮箱/密码/状态 switch/角色多选 el-select）；提交按钮「保 存」
- 删除：`ElMessageBox.confirm` 二次确认（"确定删除用户 xxx？此操作不可恢复"）
- 分页：`el-pagination`，每页 10 条

### 5.3 角色管理页（`/system/roles`，权限 `role.list`）

- 列表：ID、角色名称、编码、备注、权限数、操作列
- 新建/编辑弹窗：名称、编码、备注 + 权限树 `el-tree`（按 group 分组，check-strictly 勾选，含全选/半选状态）；保存提交 `permission_ids`
- 删除：二次确认；后端拒绝时提示「该角色已分配给用户，不可删除」

### 5.4 字典管理页（`/system/dictionaries`，权限 `dictionary.list`）

- 左侧字典列表（el-table，行内「字典项」按钮），右侧或弹窗展示字典项列表
- 字典项表格：标签、值、排序、状态、操作（编辑/删除）
- 新建字典/字典项均为 `el-dialog` 表单

## 6. 业务流转说明

```
登录流转：输入账号密码 → 点击「登 录」→ POST /api/v1/auth/login
  → 成功：token 存 localStorage，跳转 /dashboard（前端并行请求 /auth/me 拉取权限列表）
  → 失败：ElMessage 显示后端 message，停留登录页

权限控制链：管理员在「角色管理」编辑角色勾选权限 → PUT /api/v1/roles/{id}
  → 用户登录后 /auth/me 返回其所有角色合并后的 permissions
  → 前端路由守卫：无对应权限的路由跳转 403 页；后端中间件二次拦截（返回 code=403）

用户-角色-权限关系：用户可挂多角色（role_user），角色可挂多权限（permission_role），
权限只授权给角色，不直接授权给用户（RBAC 标准模型）
```

## 7. 功能清单与通过条件

| 编号 | 功能 | 通过条件 |
|---|---|---|
| SYS-01 | 登录 | 正确账号密码返回 token 并跳转仪表盘；错误密码提示「用户名或密码错误」且不跳转；空表单点击登录提示必填 |
| SYS-02 | 登出 | 点击头像下拉「退出登录」，token 清除，跳转登录页；登出后旧 token 调 `/auth/me` 返回 401 |
| SYS-03 | 用户分页搜索 | 关键字搜索按用户名/姓名模糊匹配；分页器页码切换重新请求 |
| SYS-04 | 新建/编辑用户 | 保存成功后列表即时刷新显示新用户；用户名重复提示「用户名已存在」 |
| SYS-05 | 分配角色 | 新建用户勾选角色后，`/auth/me` 返回对应角色权限；不勾选则无任何权限 |
| SYS-06 | 重置密码 | 重置后旧密码登录失败，新密码登录成功 |
| SYS-07 | 删除用户 | 二次确认后删除成功；内置 admin 提示「内置管理员不可删除」 |
| SYS-08 | 角色 CRUD | 角色增删改即时生效；删除被引用角色提示不可删 |
| SYS-09 | 权限分配 | 编辑角色勾选权限保存后，具有该角色的用户权限即时变化（重新登录后生效） |
| SYS-10 | 字典 CRUD | 字典与字典项增删改即时生效；编码重复被拦截 |
| SYS-11 | 字典取值 | `/dictionaries/code/unit` 返回启用项，供基础资料模块引用 |
| SYS-12 | 越权拦截 | 无 `user.delete` 权限的用户访问删除接口返回 `code=403`；前端隐藏无权限按钮 |

## 8. 边界与异常场景

- 账号禁用（status=0）后登录：`{code:1006, message:"账号已被禁用"}`，不返回 token
- 登录接口连续失败 5 次不做锁定（V1 不做，预留后续版本）
- 删除最后一个超级管理员角色：`{code:1007, message:"至少保留一个管理员角色"}`
- 用户重置自己密码后，旧 token 失效（`/auth/me` 返回 401，需重新登录）
- 字典 code 引用中（被下拉使用）时仍可删除字典，但其他模块下拉将取空——由前端在删除确认中提示「删除后引用此字典的下拉将失效」
