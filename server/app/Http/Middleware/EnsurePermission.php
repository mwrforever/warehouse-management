<?php
// 权限校验中间件：permission:user.list 用法，用户无权限时返回 403
namespace App\Http\Middleware;

use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;

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
