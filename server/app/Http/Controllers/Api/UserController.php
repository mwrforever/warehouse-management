<?php

// 用户管理控制器：分页列表 读取 + 写流程薄壳（创建/更新/删除/重置密码全部下沉 UserService）
// UserService 内置提权保护（B01）：非管理员不可操作管理员账号/分配管理员角色（1003），
// 判定为中间件无法表达的动态场景（目标用户是否管理员、角色列表是否含 admin），注释见 UserService

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\System\ResetPasswordRequest;
use App\Http\Requests\UserStoreRequest;
use App\Http\Requests\UserUpdateRequest;
use App\Models\Role;
use App\Models\User;
use App\Services\UserService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class UserController extends Controller
{
    use ApiResponse;

    public function __construct(private UserService $userService) {}

    /** 分页列表：支持用户名/姓名模糊搜索与状态过滤，附带角色（纯读） */
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

    /** 新建用户：校验后创建并分配角色（写流程下沉 UserService，提权 1003 由其抛出） */
    public function store(UserStoreRequest $request)
    {
        /** @var User $actor 当前操作人（路由已挂 auth:sanctum，恒非空） */
        $actor = $request->user();
        $user = $this->userService->create($actor, $request->validated());

        return $this->ok(['id' => $user->id, 'name' => $user->name, 'username' => $user->username]);
    }

    /** 更新用户：password 为空则不变更；重新分配角色（写流程下沉 UserService） */
    public function update(UserUpdateRequest $request, User $user)
    {
        /** @var User $actor 当前操作人（路由已挂 auth:sanctum，恒非空） */
        $actor = $request->user();
        $this->userService->update($actor, $user, $request->validated());

        return $this->ok();
    }

    /** 删除用户：内置 admin 与管理员账号保护（写流程下沉 UserService） */
    public function destroy(Request $request, User $user)
    {
        /** @var User $actor 当前操作人（路由已挂 auth:sanctum，恒非空） */
        $actor = $request->user();
        $this->userService->delete($actor, $user);

        return $this->ok();
    }

    /** 重置密码：仅更新密码字段并撤销旧 token（写流程下沉 UserService） */
    public function resetPassword(ResetPasswordRequest $request, User $user)
    {
        /** @var User $actor 当前操作人（路由已挂 auth:sanctum，恒非空） */
        $actor = $request->user();
        $this->userService->resetPassword($actor, $user, $request->validated());

        return $this->ok();
    }
}
