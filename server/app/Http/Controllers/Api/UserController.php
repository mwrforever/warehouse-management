<?php

// 用户管理控制器：CRUD + 重置密码 + 角色分配
// 依赖 ApiResponse/UserResource；删除保护内置 admin；用户名唯一由 FormRequest 校验

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UserStoreRequest;
use App\Http\Requests\UserUpdateRequest;
use App\Models\Role;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class UserController extends Controller
{
    use ApiResponse;

    /** 分页列表：支持用户名/姓名模糊搜索与状态过滤，附带角色 */
    public function index(Request $request)
    {
        $query = User::with('roles')->orderByDesc('id');

        // 关键字搜索：匹配 username 或 name
        if ($keyword = $request->input('keyword')) {
            $query->where(fn ($q) => $q->where('username', 'like', "%{$keyword}%")
                ->orWhere('name', 'like', "%{$keyword}%"));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        // per_page 钳制到 1-100：防 0 值除零与超大分页拖垮性能
        $users = $query->paginate(max(1, min(100, (int) $request->input('per_page', 10))));

        // 分页映射：items 字段 + 用户资源（含角色，不含权限避免大响应）
        return $this->ok([
            'items' => $users->map(fn ($u) => [
                'id' => $u->id, 'name' => $u->name, 'username' => $u->username,
                'email' => $u->email, 'status' => $u->status, 'last_login_at' => $u->last_login_at?->toDateTimeString(),
                'roles' => $u->roles->map(fn (Role $r) => ['id' => $r->id, 'name' => $r->name]),
            ]),
            'total' => $users->total(), 'page' => $users->currentPage(), 'per_page' => $users->perPage(),
        ]);
    }

    /** 新建用户：校验后创建并分配角色；admin 角色仅限管理员分配（防自建提权账号） */
    public function store(UserStoreRequest $request)
    {
        // 提权保护：非管理员不得给新用户挂管理员角色（防借新建账号提权）
        if (! $this->actorIsAdmin($request) && $this->containsAdminRole($request->input('role_ids', []))) {
            return $this->fail(1003, '仅管理员可分配管理员角色');
        }
        $user = User::create($request->safe()->except('role_ids'));
        $user->roles()->sync($request->input('role_ids', []));

        return $this->ok(['id' => $user->id, 'name' => $user->name, 'username' => $user->username]);
    }

    /** 更新用户：password 为空则不变更；重新分配角色；内置 admin 禁止改 username/status；管理员账号与角色仅限管理员操作 */
    public function update(UserUpdateRequest $request, User $user)
    {
        // 提权保护：非管理员不可修改管理员账号（内置 admin 或已挂管理员角色者），防改密/挂角色接管
        if (! $this->actorIsAdmin($request) && $this->isAdminUser($user)) {
            return $this->fail(1003, '仅管理员可修改管理员账号');
        }
        // 内置管理员保护：禁止改 username（防改名后绕过删除保护）与 status（防禁用唯一管理员锁死系统）
        if (
            $user->username === 'admin'
            && ($request->input('username') !== 'admin' || (int) $request->input('status') !== User::STATUS_ENABLED)
        ) {
            return $this->fail(1003, '内置管理员不可修改');
        }
        // 提权保护：非管理员不得给任何用户授予管理员角色（防自挂 admin 角色绕过全部权限校验）
        if (! $this->actorIsAdmin($request) && $this->containsAdminRole($request->input('role_ids', []))) {
            return $this->fail(1003, '仅管理员可分配管理员角色');
        }

        // 排除空密码：避免覆盖原密码
        $data = $request->safe()->except('role_ids');
        if (empty($data['password'])) {
            unset($data['password']);
        }
        $user->update($data);
        $user->roles()->sync($request->input('role_ids', []));

        // 修改了密码则撤销该用户全部旧 token：旧会话强制重新登录
        if (! empty($data['password'])) {
            $user->tokens()->delete();
        }

        return $this->ok();
    }

    /** 删除用户：内置 admin（username=admin）保护；管理员账号仅限管理员删除 */
    public function destroy(Request $request, User $user)
    {
        // 提权保护：非管理员不可删除管理员账号（含非内置用户名但已挂管理员角色者）
        if (! $this->actorIsAdmin($request) && $this->isAdminUser($user)) {
            return $this->fail(1003, '仅管理员可删除管理员账号');
        }
        if ($user->username === 'admin') {
            return $this->fail(1003, '内置管理员不可删除');
        }
        $user->delete();

        return $this->ok();
    }

    /** 重置密码：仅更新密码字段，并撤销该用户全部旧 token（旧会话强制重新登录）；管理员账号密码仅限管理员重置 */
    public function resetPassword(Request $request, User $user)
    {
        // 提权保护：非管理员不可重置管理员账号密码（防借重置密码登录接管全系统）
        if (! $this->actorIsAdmin($request) && $this->isAdminUser($user)) {
            return $this->fail(1003, '仅管理员可重置管理员密码');
        }
        $data = $request->validate([
            'password' => ['required', 'string', 'min:8', 'regex:/[A-Za-z]/', 'regex:/[0-9]/'],
        ]);
        $user->update(['password' => $data['password']]);
        // 密码已变更：撤销全部 token，旧 token 调 /auth/me 返回 401
        $user->tokens()->delete();

        return $this->ok();
    }

    /** 当前操作人是否持有 admin 角色（中间件同口径：admin 角色全量放行） */
    private function actorIsAdmin(Request $request): bool
    {
        return $request->user()->roles()->where('code', 'admin')->exists();
    }

    /** 目标用户是否属管理员账号（内置 admin 用户名或已挂管理员角色） */
    private function isAdminUser(User $user): bool
    {
        return $user->username === 'admin' || $user->roles()->where('code', 'admin')->exists();
    }

    /** 请求的角色 id 列表是否包含管理员角色（防提权链：自挂/互挂 admin 角色） */
    private function containsAdminRole(array $roleIds): bool
    {
        $adminRoleId = Role::where('code', 'admin')->value('id');

        return $adminRoleId !== null && in_array($adminRoleId, array_map('intval', $roleIds), true);
    }
}
