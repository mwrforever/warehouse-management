<?php

// 角色管理控制器：列表/权限清单 读取 + 写流程薄壳（创建/更新/删除全部下沉 RoleService）
// 删除保护由 RoleService 执行：被用户引用的角色拒绝删除（1004）、唯一 admin 编码角色拒绝删除（1007）

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\RoleRequest;
use App\Models\Permission;
use App\Models\Role;
use App\Services\RoleService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class RoleController extends Controller
{
    use ApiResponse;

    public function __construct(private RoleService $roleService) {}

    /** 角色分页列表：每角色带权限 code 集合（纯读） */
    public function index(Request $request)
    {
        // per_page 钳制到 1-100：防 0 值除零 500 与超大分页拖垮性能
        $roles = Role::with('permissions')->orderByDesc('id')
            ->paginate(max(1, min(100, $request->integer('per_page', 10))));

        return $this->ok([
            'items' => $roles->map(fn ($r) => [
                'id' => $r->id, 'name' => $r->name, 'code' => $r->code, 'remark' => $r->remark,
                'permissions' => $r->permissions->pluck('code'),
            ]),
            'total' => $roles->total(), 'page' => $roles->currentPage(), 'per_page' => $roles->perPage(),
        ]);
    }

    /** 新建角色并分配权限（写流程下沉 RoleService） */
    public function store(RoleRequest $request)
    {
        return $this->ok(['id' => $this->roleService->create($request->validated())->id]);
    }

    /** 更新角色并全量重挂权限（写流程下沉 RoleService） */
    public function update(RoleRequest $request, Role $role)
    {
        $this->roleService->update($role, $request->validated());

        return $this->ok();
    }

    /** 删除角色：被用户引用 1004 或唯一 admin 角色 1007 时拒绝（写流程下沉 RoleService） */
    public function destroy(Role $role)
    {
        $this->roleService->delete($role);

        return $this->ok();
    }

    /** 权限清单（按 group 分组）：角色编辑页权限树数据源（纯读） */
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
