<?php

// 认证控制器：登录/登出/当前用户信息（写流程已下沉 AuthService）
// 依赖 Sanctum Token 认证；登录成功签发 token，登出撤销当前 token；
// 本组接口无 permission 中间件（login 为匿名入口，logout/me 仅需认证），
// 故 LoginRequest.authorize() 恒为 true，属权限中间件豁免场景

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\System\LoginRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\AuthService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    use ApiResponse;

    public function __construct(private AuthService $authService) {}

    /** 登录：凭证校验 → 禁用拦截 → 签发 token（写流程下沉 AuthService，失败抛 1001/1006） */
    public function login(LoginRequest $request)
    {
        $result = $this->authService->login($request->validated());

        return $this->ok(['token' => $result['token'], 'user' => new UserResource($result['user'])]);
    }

    /** 登出：撤销当前请求的 token（写流程下沉 AuthService） */
    public function logout(Request $request)
    {
        /** @var User $user 当前认证用户（路由已挂 auth:sanctum，恒非空） */
        $user = $request->user();
        $this->authService->logout($user);

        return $this->ok(null, '已退出登录');
    }

    /** 当前用户信息：供前端路由守卫拉取角色与权限（纯读） */
    public function me(Request $request)
    {
        return $this->ok(new UserResource($request->user()));
    }
}
