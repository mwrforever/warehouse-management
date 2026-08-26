<?php

// 用户服务：用户创建/更新/删除/重置密码（原 UserController 写流程下沉）
// 内置提权保护（B01）：非管理员不可操作管理员账号、不可分配管理员角色（1003）；
// 判定口径（admin 角色全量放行）与路由 permission 中间件一致，属中间件无法表达的
// 动态场景（目标用户是否管理员、角色列表是否含 admin），故在 Service 内校验。
// 写操作为单行原子写，不包 DB::transaction（与既有行为一致）

namespace App\Services;

use App\Exceptions\SystemException;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

class UserService
{
    /**
     * 新建用户（原控制器 store 下沉）：提权保护 → 建号 → 挂角色
     *
     * @param  User  $actor  当前操作人（提权保护判定依据，路由已挂 auth:sanctum 恒非空）
     * @param  array  $data  已过 UserStoreRequest 格式校验的载荷（含 role_ids）
     * @return User 新建用户（供控制器回显 id/name/username）
     *
     * @throws SystemException 非管理员分配管理员角色 1003
     */
    public function create(User $actor, array $data): User
    {
        $roleIds = $data['role_ids'] ?? [];

        // 提权保护：非管理员不得给新用户挂管理员角色（防借新建账号提权）
        if (! $this->actorIsAdmin($actor) && $this->containsAdminRole($roleIds)) {
            throw new SystemException('仅管理员可分配管理员角色', 1003);
        }
        $user = User::create(Arr::except($data, ['role_ids']));
        $user->roles()->sync($roleIds);

        Log::info('用户创建成功', ['user_id' => $user->id, 'username' => $user->username, 'operator' => $actor->id]);

        return $user;
    }

    /**
     * 更新用户（原控制器 update 下沉）：提权保护 → 更新资料 → 重挂角色 → 改密时撤销旧 token；
     * password 为空则不变更密码
     *
     * @param  User  $actor  当前操作人（提权保护判定依据，恒非空）
     * @param  User  $user  路由绑定的目标用户模型
     * @param  array  $data  已过 UserUpdateRequest 格式校验的载荷（含 role_ids）
     *
     * @throws SystemException 非管理员操作管理员账号 / 内置 admin 保护 / 非管理员分配管理员角色 1003
     */
    public function update(User $actor, User $user, array $data): void
    {
        $roleIds = $data['role_ids'] ?? [];

        // 提权保护：非管理员不可修改管理员账号（内置 admin 或已挂管理员角色者），防改密/挂角色接管
        if (! $this->actorIsAdmin($actor) && $this->isAdminUser($user)) {
            throw new SystemException('仅管理员可修改管理员账号', 1003);
        }
        // 内置管理员保护：禁止改 username（防改名后绕过删除保护）与 status（防禁用唯一管理员锁死系统）
        if (
            $user->username === 'admin'
            && ($data['username'] !== 'admin' || (int) $data['status'] !== User::STATUS_ENABLED)
        ) {
            throw new SystemException('内置管理员不可修改', 1003);
        }
        // 提权保护：非管理员不得给任何用户授予管理员角色（防自挂 admin 角色绕过全部权限校验）
        if (! $this->actorIsAdmin($actor) && $this->containsAdminRole($roleIds)) {
            throw new SystemException('仅管理员可分配管理员角色', 1003);
        }

        // 排除空密码：避免覆盖原密码
        $data = Arr::except($data, ['role_ids']);
        if (empty($data['password'])) {
            unset($data['password']);
        }
        $user->update($data);
        $user->roles()->sync($roleIds);

        // 修改了密码则撤销该用户全部旧 token：旧会话强制重新登录
        if (! empty($data['password'])) {
            $user->tokens()->delete();
        }

        Log::info('用户更新成功', [
            'user_id' => $user->id, 'username' => $user->username,
            'password_changed' => ! empty($data['password']), 'operator' => $actor->id,
        ]);
    }

    /**
     * 删除用户（原控制器 destroy 下沉）：内置 admin 与管理员账号保护
     *
     * @param  User  $actor  当前操作人（提权保护判定依据，恒非空）
     * @param  User  $user  路由绑定的目标用户模型
     *
     * @throws SystemException 非管理员删除管理员账号 / 内置 admin 保护 1003
     */
    public function delete(User $actor, User $user): void
    {
        // 提权保护：非管理员不可删除管理员账号（含非内置用户名但已挂管理员角色者）
        if (! $this->actorIsAdmin($actor) && $this->isAdminUser($user)) {
            throw new SystemException('仅管理员可删除管理员账号', 1003);
        }
        if ($user->username === 'admin') {
            throw new SystemException('内置管理员不可删除', 1003);
        }
        $user->delete();

        Log::info('用户删除成功', ['user_id' => $user->id, 'username' => $user->username, 'operator' => $actor->id]);
    }

    /**
     * 重置密码（原控制器 resetPassword 下沉）：仅更新密码并撤销全部旧 token；
     * 管理员账号密码仅限管理员重置
     *
     * @param  User  $actor  当前操作人（提权保护判定依据，恒非空）
     * @param  User  $user  路由绑定的目标用户模型
     * @param  array  $data  已过 ResetPasswordRequest 格式校验的载荷（password）
     *
     * @throws SystemException 非管理员重置管理员账号密码 1003
     */
    public function resetPassword(User $actor, User $user, array $data): void
    {
        // 提权保护：非管理员不可重置管理员账号密码（防借重置密码登录接管全系统）
        if (! $this->actorIsAdmin($actor) && $this->isAdminUser($user)) {
            throw new SystemException('仅管理员可重置管理员密码', 1003);
        }
        $user->update(['password' => $data['password']]);
        // 密码已变更：撤销全部 token，旧 token 调 /auth/me 返回 401
        $user->tokens()->delete();

        Log::info('用户密码重置成功', ['user_id' => $user->id, 'username' => $user->username, 'operator' => $actor->id]);
    }

    /** 当前操作人是否持有 admin 角色（与权限中间件同口径：admin 角色全量放行） */
    private function actorIsAdmin(User $actor): bool
    {
        return $actor->roles()->where('code', 'admin')->exists();
    }

    /** 目标用户是否属管理员账号（内置 admin 用户名或已挂管理员角色） */
    private function isAdminUser(User $user): bool
    {
        return $user->username === 'admin' || $user->roles()->where('code', 'admin')->exists();
    }

    /** 角色 id 列表是否包含管理员角色（防提权链：自挂/互挂 admin 角色） */
    private function containsAdminRole(array $roleIds): bool
    {
        $adminRoleId = Role::where('code', 'admin')->value('id');

        return $adminRoleId !== null && in_array($adminRoleId, array_map('intval', $roleIds), true);
    }
}
