<?php

// 认证服务：登录签发 token / 登出撤销 token（原 AuthController 写流程下沉）
// 依赖 User 模型与 Sanctum token 机制；凭证/状态校验失败抛 SystemException
// （1001/1006）由全局渲染器转统一响应；写为单行更新 + token 签发，无跨表事务需求

namespace App\Services;

use App\Exceptions\SystemException;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class AuthService
{
    /**
     * 登录（原控制器 login 下沉）：凭证校验 → 禁用拦截 → 签发 token 并记录最后登录时间
     *
     * @param  array  $data  已过 LoginRequest 格式校验的载荷（username/password）
     * @return array{token: string, user: User} 签发的明文 token 与登录用户
     *
     * @throws SystemException 凭证错误 1001（不泄露具体原因）；账号被禁用 1006
     */
    public function login(array $data): array
    {
        $user = User::where('username', $data['username'])->first();

        // 用户不存在或密码错误：统一提示，不泄露具体原因
        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw new SystemException('用户名或密码错误', 1001);
        }

        // 禁用账号拦截：不签发 token
        if ($user->status !== User::STATUS_ENABLED) {
            throw new SystemException('账号已被禁用', 1006);
        }

        // 签发 token 并更新最后登录时间
        $user->update(['last_login_at' => now()]);
        $token = $user->createToken('api')->plainTextToken;

        Log::info('用户登录成功', ['user_id' => $user->id, 'username' => $user->username]);

        return ['token' => $token, 'user' => $user];
    }

    /**
     * 登出（原控制器 logout 下沉）：撤销当前请求的 token
     *
     * @param  User  $user  当前认证用户（路由已挂 auth:sanctum，恒非空）
     */
    public function logout(User $user): void
    {
        $user->currentAccessToken()->delete();

        Log::info('用户登出', ['user_id' => $user->id, 'username' => $user->username]);
    }
}
