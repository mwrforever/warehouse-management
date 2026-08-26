<?php

// 认证控制器：登录/登出/当前用户信息（写流程已下沉 AuthService）
// 依赖 Sanctum 双通道认证（R4-3）：登录同时建立 web 会话（SPA 主通道，cookie 鉴权）
// 与签发 token（兼容通道，前端批次切换前保留）；登出双通道同时失效；
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

    /** 登录：凭证校验 → 禁用拦截 → 建立会话（仅会话请求）+ 签发 token（写流程下沉 AuthService，失败抛 1001/1006） */
    public function login(LoginRequest $request)
    {
        $result = $this->authService->login($request->validated(), $request);

        return $this->ok(['token' => $result['token'], 'user' => new UserResource($result['user'])]);
    }

    /** 登出：token 与会话双通道同时失效（写流程下沉 AuthService；会话通道含 CSRF token 轮换） */
    public function logout(Request $request)
    {
        /** @var User $user 当前认证用户（路由已挂 auth:sanctum，恒非空） */
        $user = $request->user();
        $this->authService->logout($user, $request);

        return $this->ok(null, '已退出登录');
    }

    /** 当前用户信息：供前端路由守卫拉取角色与权限（纯读） */
    public function me(Request $request)
    {
        return $this->ok(new UserResource($request->user()));
    }
}
