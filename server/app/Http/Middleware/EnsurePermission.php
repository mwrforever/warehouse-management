<?php

// 权限校验中间件：permission:user.list 用法，用户无权限时返回 403

namespace App\Http\Middleware;

use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;

/**
 * 权限校验中间件
 *
 * 校验已认证用户是否拥有指定权限 code（用法：permission:user.list）。
 * admin 角色拥有全部权限直接放行（bypass 分支）；其余用户按角色权限集合判断，
 * 无对应权限时返回 403（{code:403, message:'无权限操作'}）。
 * 依赖 User::roles()/permissions() 与 ApiResponse trait；必须挂在 auth:sanctum 之后，
 * 否则 $request->user() 为空。
 */
class EnsurePermission
{
    use ApiResponse;

    /** 校验当前用户是否拥有指定权限 code（admin 角色拥有全部权限） */
    public function handle(Request $request, Closure $next, string $permission): mixed
    {
        $user = $request->user();

        // admin 角色放行所有权限；其余按角色权限集合判断
        $isAdmin = $user->roles()->where('code', 'admin')->exists();
        if (! $isAdmin && ! $user->permissions()->contains($permission)) {
            return $this->fail(403, '无权限操作', 403);
        }

        return $next($request);
    }
}
