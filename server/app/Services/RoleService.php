<?php

// 角色服务：角色创建/更新/删除与权限分配（原 RoleController 写流程下沉）
// 删除保护：被用户引用的角色拒绝删除（1004）、唯一 admin 编码角色拒绝删除（1007）；
// 写操作为单行原子写，不包 DB::transaction（与既有行为一致）

namespace App\Services;

use App\Exceptions\SystemException;
use App\Models\Role;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

class RoleService
{
    /**
     * 新建角色并分配权限（原控制器 store 下沉）
     *
     * @param  array  $data  已过 RoleRequest 格式校验的载荷（含 permission_ids）
     * @return Role 新建角色（供控制器回显 id）
     */
    public function create(array $data): Role
    {
        $role = Role::create(Arr::except($data, ['permission_ids']));
        $role->permissions()->sync($data['permission_ids'] ?? []);

        Log::info('角色创建成功', ['role_id' => $role->id, 'code' => $role->code, 'operator' => auth()->id()]);

        return $role;
    }

    /**
     * 更新角色并全量重挂权限（原控制器 update 下沉）
     *
     * @param  Role  $role  路由绑定的角色模型
     * @param  array  $data  已过 RoleRequest 格式校验的载荷（含 permission_ids）
     */
    public function update(Role $role, array $data): void
    {
        $role->update(Arr::except($data, ['permission_ids']));
        $role->permissions()->sync($data['permission_ids'] ?? []);

        Log::info('角色更新成功', ['role_id' => $role->id, 'code' => $role->code, 'operator' => auth()->id()]);
    }

    /**
     * 删除角色（原控制器 destroy 下沉）：被用户引用或为唯一 admin 角色时拒绝
     *
     * @param  Role  $role  路由绑定的角色模型
     *
     * @throws SystemException 唯一 admin 角色 1007；角色已被用户引用 1004
     */
    public function delete(Role $role): void
    {
        // admin 编码角色若为最后一个：拒绝删除（先于引用检查，保证唯一 admin 保护始终生效）
        if ($role->code === 'admin' && Role::where('code', 'admin')->count() === 1) {
            throw new SystemException('至少保留一个管理员角色', 1007);
        }
        // 角色已分配给用户：拒绝删除
        if ($role->users()->exists()) {
            throw new SystemException('该角色已分配给用户，不可删除', 1004);
        }
        $role->delete();

        Log::info('角色删除成功', ['role_id' => $role->id, 'code' => $role->code, 'operator' => auth()->id()]);
    }
}
