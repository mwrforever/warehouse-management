<?php
// 用户管理控制器：CRUD + 重置密码 + 角色分配
// 依赖 ApiResponse/UserResource；删除保护内置 admin；用户名唯一由 FormRequest 校验
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UserStoreRequest;
use App\Http\Requests\UserUpdateRequest;
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
            $query->where(fn ($q) => $q->where('username', 'like', "%{$keyword}%")->orWhere('name', 'like', "%{$keyword}%"));
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
                'roles' => $u->roles->map(fn ($r) => ['id' => $r->id, 'name' => $r->name]),
            ]),
            'total' => $users->total(), 'page' => $users->currentPage(), 'per_page' => $users->perPage(),
        ]);
    }

    /** 新建用户：校验后创建并分配角色 */
    public function store(UserStoreRequest $request)
    {
        $user = User::create($request->safe()->except('role_ids'));
        $user->roles()->sync($request->input('role_ids', []));
        return $this->ok(['id' => $user->id, 'name' => $user->name, 'username' => $user->username]);
    }

    /** 更新用户：password 为空则不变更；重新分配角色 */
    public function update(UserUpdateRequest $request, User $user)
    {
        // 排除空密码：避免覆盖原密码
        $data = $request->safe()->except('role_ids');
        if (empty($data['password'])) {
            unset($data['password']);
        }
        $user->update($data);
        $user->roles()->sync($request->input('role_ids', []));
        return $this->ok();
    }

    /** 删除用户：内置 admin（username=admin）保护 */
    public function destroy(User $user)
    {
        if ($user->username === 'admin') {
            return $this->fail(1003, '内置管理员不可删除');
        }
        $user->delete();
        return $this->ok();
    }

    /** 重置密码：仅更新密码字段 */
    public function resetPassword(Request $request, User $user)
    {
        $data = $request->validate(['password' => ['required', 'string', 'min:8', 'regex:/[A-Za-z]/', 'regex:/[0-9]/']]);
        $user->update(['password' => $data['password']]);
        return $this->ok();
    }
}
