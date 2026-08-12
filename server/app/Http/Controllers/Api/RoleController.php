<?php

// 角色管理控制器：CRUD + 权限分配 + 删除保护（被引用/最后一个 admin 角色）

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\RoleRequest;
use App\Models\Permission;
use App\Models\Role;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

/**
 * 角色管理控制器
 *
 * 负责角色的 CRUD、权限分配与权限清单分组输出，供系统管理-角色管理页使用。
 * 依赖 Role/Permission 模型、RoleRequest 表单校验与 ApiResponse 统一响应；
 * 删除保护：被用户引用的角色拒绝删除（1004），唯一 admin 编码角色拒绝删除（1007）。
 */
class RoleController extends Controller
{
    use ApiResponse;

    /** 角色分页列表：每角色带权限 code 集合 */
    public function index(Request $request)
    {
        // per_page 钳制到 1-100：防 0 值除零 500 与超大分页拖垮性能
        $roles = Role::with('permissions')->orderByDesc('id')->paginate(max(1, min(100, $request->integer('per_page', 10))));

        return $this->ok([
            'items' => $roles->map(fn ($r) => [
                'id' => $r->id, 'name' => $r->name, 'code' => $r->code, 'remark' => $r->remark,
                'permissions' => $r->permissions->pluck('code'),
            ]),
            'total' => $roles->total(), 'page' => $roles->currentPage(), 'per_page' => $roles->perPage(),
        ]);
    }

    /** 新建角色并分配权限 */
    public function store(RoleRequest $request)
    {
        $role = Role::create($request->safe()->except('permission_ids'));
        $role->permissions()->sync($request->input('permission_ids', []));

        return $this->ok(['id' => $role->id]);
    }

    /** 更新角色并全量重挂权限 */
    public function update(RoleRequest $request, Role $role)
    {
        $role->update($request->safe()->except('permission_ids'));
        $role->permissions()->sync($request->input('permission_ids', []));

        return $this->ok();
    }

    /** 删除角色：被用户引用或为唯一 admin 角色时拒绝 */
    public function destroy(Role $role)
    {
        // admin 编码角色若为最后一个：拒绝删除（先于引用检查，保证唯一 admin 保护始终生效）
        if ($role->code === 'admin' && Role::where('code', 'admin')->count() === 1) {
            return $this->fail(1007, '至少保留一个管理员角色');
        }
        // 角色已分配给用户：拒绝删除
        if ($role->users()->exists()) {
            return $this->fail(1004, '该角色已分配给用户，不可删除');
        }
        $role->delete();

        return $this->ok();
    }

    /** 权限清单（按 group 分组）：角色编辑页权限树数据源 */
    public function permissions()
    {
        $groups = Permission::orderBy('group')->get()->groupBy('group')
            ->map(fn ($perms, $group) => [
                'group' => $group,
                'permissions' => $perms->map(fn (Permission $p) => [
                    'id' => $p->id,
                    'name' => $p->name,
                    'code' => $p->code,
                ])->values(),
            ])
            ->values();

        return $this->ok(['groups' => $groups]);
    }
}
