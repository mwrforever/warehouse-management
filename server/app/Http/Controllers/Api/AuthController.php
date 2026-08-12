<?php
// 认证控制器：登录/登出/当前用户信息
// 依赖 Sanctum Token 认证；登录成功签发 token，登出撤销当前 token
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    use ApiResponse;

    /** 登录：校验凭证 → 禁用拦截 → 签发 token 并记录最后登录时间 */
    public function login(Request $request)
    {
        // 表单校验：username/password 必填
        $data = $request->validate([
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('username', $data['username'])->first();

        // 用户不存在或密码错误：统一提示，不泄露具体原因
        if (! $user || ! Hash::check($data['password'], $user->password)) {
            return $this->fail(1001, '用户名或密码错误');
        }

        // 禁用账号拦截：不签发 token
        if ($user->status !== 1) {
            return $this->fail(1006, '账号已被禁用');
        }

        // 签发 token 并更新最后登录时间
        $user->update(['last_login_at' => now()]);
        $token = $user->createToken('api')->plainTextToken;

        return $this->ok(['token' => $token, 'user' => new UserResource($user)]);
    }

    /** 登出：撤销当前请求的 token */
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();
        return $this->ok(null, '已退出登录');
    }

    /** 当前用户信息：供前端路由守卫拉取角色与权限 */
    public function me(Request $request)
    {
        return $this->ok(new UserResource($request->user()));
    }
}
