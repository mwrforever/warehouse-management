# admin 提权：user.update 持有者可重置内置 admin 密码或自挂 admin 角色

- 发现日期：2026-08-13
- 严重级别：高（权限绕过/提权）
- 状态：已修复（2026-08-13 修复波次，提交号待补）
- 涉及提交：90cab76（resetPassword 无守卫）、0f7e383（1003 守卫仅补 update/destroy）
- 涉及文件：`server/app/Http/Controllers/Api/UserController.php`、`server/app/Http/Middleware/EnsurePermission.php`

## 问题描述

`UserController::update` 对内置 admin 的 1003 保护**只覆盖 username 与 status**，不覆盖 password 与角色分配；`resetPassword` 则完全没有 admin 守卫。任何持有 `user.update` 权限的角色（含仅用户管理权限的普通角色）均可：

1. 调用 `PUT /users/{user}/reset-password` 重置内置 admin 密码 → 登录 admin → 全系统接管；
2. 或经 `update` 携带新密码/`role_ids` 直接改 admin 密码、给自己挂 admin 角色（中间件 `EnsurePermission` 按角色 `code='admin'` 全量放行）。

1003 守卫是 `0f7e383` 特意补的（"admin 保护"），但只补了 `update` 的 username/status 分支与 `destroy`；`resetPassword` 自 `90cab76` 起就无守卫——与既有守卫意图明显不一致，判定为遗漏而非设计。

## 证据

```php
// UserController.php:58-78（update：守卫只挡 username/status，password 与角色放行）
if (
    $user->username === 'admin'
    && ($request->input('username') !== 'admin' || (int) $request->input('status') !== 1)
) {
    return $this->fail(1003, '内置管理员不可修改');
}
$data = $request->safe()->except('role_ids');
if (empty($data['password'])) { unset($data['password']); }
$user->update($data);
$user->roles()->sync($request->input('role_ids', []));

// UserController.php:96-103（resetPassword：仅校验密码格式，无 admin 判断）
public function resetPassword(Request $request, User $user)
{
    $data = $request->validate(['password' => ['required', 'string', 'min:8', ...]]);
    $user->update(['password' => $data['password']]);
    $user->tokens()->delete();

// EnsurePermission.php:30（admin 判定只按角色 code）
$isAdmin = $user->roles()->where('code', 'admin')->exists();
```

## 影响

- 持有 `user.update` 权限的最低权限角色可成为超级管理员，RBAC 边界被击穿。
- 与「角色 admin 保留码未拦截」（同批登记）组合成完整提权路径。

## 建议修复方向

- `resetPassword` 增加与 `update` 同款 `username === 'admin'` 守卫（如 1003 同族或独立业务码）；
- 或限定：无 `role.list`/管理员级权限者不可对 admin 账号改密/挂角色；`update` 对 admin 用户的 password 与 `role_ids` 同样纳入 1003 拦截范围。

## 处理结论（2026-08-13 修复波次）

**确认存在，已修复。** 核实后问题面比描述更广：除 resetPassword 无守卫外，`update` 可通过 role_ids 自挂 admin 角色、`store` 可新建挂 admin 角色的提权账号、非管理员可删除/改密其他已挂 admin 角色的账号（非内置用户名）。

修复方案（UserController，全部走 1003 保护族）：

1. 新增 `actorIsAdmin()`/`isAdminUser()`/`containsAdminRole()` 三个私有判定辅助；
2. `update`：非管理员不可修改管理员账号（内置 admin 或已挂管理员角色者）；非管理员不可给任何用户授予 admin 角色；原 username/status 守卫保留；
3. `resetPassword`：非管理员不可重置管理员账号密码；
4. `store`：非管理员不可给新用户挂 admin 角色；
5. `destroy`：非管理员不可删除管理员账号（原「内置 admin 不可删除」守卫保留，管理员自身删除内置 admin 仍被 1003 拦）。

配套测试：`UserManagementTest` 新增 7 条提权防护用例（非管理员改密/改字段/自挂角色/新建提权账号/删除管理员/重置副管理员密码均 1003，管理员重置自身密码与非管理员日常维护不受影响）。全量 375 单测、phpstan level 5、pint、phpcs 全绿。
