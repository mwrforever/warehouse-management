<?php

// 认证服务：登录双通道（web 会话 + Sanctum token）签发 / 登出双通道失效（R4-3）
// 依赖 User 模型、SessionGuard 与 Sanctum token 机制；凭证/状态校验失败抛 SystemException
// （1001/1006）由全局渲染器转统一响应；写为单行更新 + 会话建立 + token 签发，无跨表事务需求

namespace App\Services;

use App\Exceptions\SystemException;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\TransientToken;

class AuthService
{
    /**
     * 登录（原控制器 login 下沉）：凭证校验 → 禁用拦截 → 建立 web 会话（SPA 主通道）
     * + 签发 token（兼容通道）并记录最后登录时间
     *
     * 双通道设计说明（R4-3 兼容性决策）：会话通道为未来前端 http.ts 迁移后的主鉴权路径；
     * token 通道保留是兼容性决策——既有 API 客户端与全部 withToken 测试基线依赖它，
     * 前端批次切换后不再使用。若此保留与裁决冲突应回报主控定夺，不得私自废弃。
     *
     * @param  array  $data  已过 LoginRequest 格式校验的载荷（username/password）
     * @param  Request  $request  当前请求（用于识别会话上下文：仅前端 Origin 命中的会话请求才建立 web 会话）
     * @return array{token: string, user: User} 签发的明文 token 与登录用户（结构不变，兼容既有调用方）
     *
     * @throws SystemException 凭证错误 1001（不泄露具体原因）；账号被禁用 1006
     */
    public function login(array $data, Request $request): array
    {
        $user = User::where('username', $data['username'])->first();

        // 用户不存在或密码错误：统一提示，不泄露具体原因
        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw new SystemException('用户名或密码错误', 1001);
        }

        // 禁用账号拦截：不建立会话、不签发 token
        if ($user->status !== User::STATUS_ENABLED) {
            throw new SystemException('账号已被禁用', 1006);
        }

        // 更新最后登录时间（登录成功业务留痕）
        $user->update(['last_login_at' => now()]);

        // SPA 会话通道：web guard 建立登录会话（SessionGuard::login 内部 regenerate 会话 ID，防会话固定）。
        // 仅当请求已处于会话上下文（前端 Origin 命中 stateful 域、StartSession 已启动）才建立——
        // 纯 token 客户端（第三方 API/测试基线）无会话，若写入 app 级单例会话存储，
        // 会因 sanctum guard 的 web 优先解析而污染同进程后续请求的身份判定（D-19 双通道兼容）
        if ($request->hasSession()) {
            Auth::guard('web')->login($user);
        }
        // token 兼容通道：签发明文 token，维持既有 API 客户端与测试基线可用（见类注释）
        $token = $user->createToken('api')->plainTextToken;

        Log::info('用户登录成功', ['user_id' => $user->id, 'username' => $user->username]);

        return ['token' => $token, 'user' => $user];
    }

    /**
     * 登出（原控制器 logout 下沉）：token 与会话双通道同时失效（R4-3）
     *
     * token 通道：撤销当前请求的 access token（仅 token 鉴权请求持有）；
     * 会话通道：登出 web guard 并作废会话 + 轮换 CSRF token（防会话固定）。
     * 纯 token 客户端（无会话请求）跳过会话失效分支，互不干扰。
     *
     * @param  User  $user  当前认证用户（路由已挂 auth:sanctum，恒非空）
     * @param  Request  $request  当前请求（用于区分 token/会话通道：会话通道下不撤销 token）
     */
    public function logout(User $user, Request $request): void
    {
        // token 通道：仅撤销请求实际携带的 Bearer token。会话通道下 currentAccessToken 是
        // TransientToken 占位（无 delete 能力且无需撤销，见 Sanctum\Guard），故以 bearerToken 判据区分两条通道
        /** @var PersonalAccessToken|TransientToken|null $accessToken 运行时双通道值（phpstan 静态类型仅见默认泛型 PersonalAccessToken） */
        $accessToken = $user->currentAccessToken();
        if ($request->bearerToken() !== null && $accessToken instanceof PersonalAccessToken) {
            $accessToken->delete();
        }

        // 会话通道：登出 web guard 并作废会话（含 CSRF token 轮换），防会话固定
        Auth::guard('web')->logout();
        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        Log::info('用户登出', ['user_id' => $user->id, 'username' => $user->username]);
    }
}
