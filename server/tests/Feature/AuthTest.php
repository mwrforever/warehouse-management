<?php

// 认证接口测试：登录/登出/me 全链路（核心路径，100% 覆盖）

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Cookie;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // 种子：admin 用户（密码 admin123）与普通用户（禁用态）
        User::create([
            'name' => '管理员',
            'username' => 'admin',
            'email' => 'admin@test.com',
            'password' => bcrypt('admin123'),
            'status' => 1,
        ]);
        User::create([
            'name' => '禁用用户',
            'username' => 'disabled',
            'email' => 'd@test.com',
            'password' => bcrypt('pass'),
            'status' => 0,
        ]);
    }

    public function test_login_success_returns_token_and_user(): void
    {
        // 正常路径：正确凭证返回 token 与用户信息
        $res = $this->postJson('/api/v1/auth/login', ['username' => 'admin', 'password' => 'admin123']);
        $res->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonStructure(['data' => ['token', 'user' => ['id', 'name', 'username', 'roles', 'permissions']]]);
    }

    public function test_login_wrong_password_fails_with_1001(): void
    {
        // 异常路径：错误密码返回 1001 且不返回 token
        $res = $this->postJson('/api/v1/auth/login', ['username' => 'admin', 'password' => 'wrong']);
        $res->assertOk()->assertJsonPath('code', 1001)->assertJsonMissingPath('data.token');
    }

    public function test_login_missing_username_returns_validation_error(): void
    {
        // 边界路径：空表单被 422 校验拦截
        $this->postJson('/api/v1/auth/login', [])->assertStatus(422);
    }

    public function test_login_missing_username_returns_422_not_1002(): void
    {
        // 边界路径：仅缺 username 的校验失败返回 code=422（与 HTTP 状态一致），不得误报业务错误 1002「用户名已存在」
        $this->postJson('/api/v1/auth/login', ['password' => 'x1234567'])
            ->assertStatus(422)
            ->assertJsonPath('code', 422);
    }

    public function test_login_disabled_account_fails_with_1006(): void
    {
        // 边界路径：禁用账号登录返回 1006
        $this->postJson('/api/v1/auth/login', ['username' => 'disabled', 'password' => 'pass'])
            ->assertJsonPath('code', 1006);
    }

    public function test_me_returns_user_with_permissions(): void
    {
        // 正常路径：带 token 访问 me 返回用户与权限数组
        $token = $this->postJson('/api/v1/auth/login', ['username' => 'admin', 'password' => 'admin123'])
            ->json('data.token');
        $res = $this->withToken($token)->getJson('/api/v1/auth/me');
        $res->assertJsonPath('code', 0)
            ->assertJsonPath('data.username', 'admin')
            ->assertJsonStructure(['data' => ['roles', 'permissions']]);
        // 登录应持久化最后登录时间：非 null 且为日期时间字符串（防 mass assignment 丢弃与 Carbon 转换缺失回归）
        $this->assertNotNull($res->json('data.last_login_at'));
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/',
            $res->json('data.last_login_at')
        );
    }

    public function test_me_without_token_returns_401(): void
    {
        // 异常路径：无 token 返回 401
        $this->getJson('/api/v1/auth/me')->assertStatus(401);
    }

    public function test_logout_revokes_token(): void
    {
        // 正常路径：登出后 token 失效
        $token = $this->postJson('/api/v1/auth/login', ['username' => 'admin', 'password' => 'admin123'])
            ->json('data.token');
        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertJsonPath('code', 0);
        // 测试框架在同一 app 实例内缓存 auth guard 的已认证用户（真实 HTTP 每次请求独立容器不受影响），
        // 故先重置 guard，再验证被撤销的 token 无法访问 me
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertStatus(401);
    }

    public function test_spa_login_establishes_cookie_session_for_api(): void
    {
        // 正常路径（SPA 会话通道）：csrf-cookie 握手 + cookie 会话登录后，
        // 不带 Authorization 头、仅凭会话 cookie 即可访问 me——会话鉴权链路（R4-3）生效
        $this->spaLogin();

        $me = $this->withCredentials()
            ->withHeader('Origin', 'http://localhost:4000')
            ->getJson('/api/v1/auth/me');
        $me->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.username', 'admin');
    }

    public function test_spa_logout_invalidates_cookie_session(): void
    {
        // 正常路径（SPA 会话通道）：会话登出后，同一会话 cookie 再访问 me 返回 401
        // （会话作废 + CSRF token 轮换，防会话固定）
        $this->spaLogin();

        $this->withCredentials()
            ->withHeader('Origin', 'http://localhost:4000')
            ->withHeader('X-CSRF-TOKEN', session()->token())
            ->postJson('/api/v1/auth/logout')
            ->assertOk()
            ->assertJsonPath('code', 0);

        // 测试框架在同一 app 实例内缓存 sanctum 守卫的已认证用户（真实 HTTP 每次请求独立容器不受影响），
        // 故先重置守卫，再验证会话已作废、重放会话 cookie 无法访问 me
        $this->app['auth']->forgetGuards();

        // 登出后原会话 cookie 已失效：不再携带任何凭证重放会话 cookie 访问业务接口
        $this->withCredentials()
            ->withHeader('Origin', 'http://localhost:4000')
            ->getJson('/api/v1/auth/me')
            ->assertStatus(401);
    }

    /**
     * SPA 握手登录：GET /sanctum/csrf-cookie 建立会话（204）→ 响应 cookie 透传 → 携带
     * 前端 Origin 与 CSRF 头走会话通道登录。成功后响应 cookie 已就位，可直接发起业务请求。
     *
     * 动机：完整模拟浏览器行为（先握手拿 cookie，再 POST 登录），证明会话通道真实可用，
     * 而非仅依赖 Sanctum::actingAs 之类的捷径。
     */
    private function spaLogin(): void
    {
        // 握手端点可达（204 no content），并下发 XSRF-TOKEN 与 laravel_session cookie
        $handshake = $this->get('/sanctum/csrf-cookie');
        $handshake->assertNoContent();
        $this->persistResponseCookies($handshake);

        // 会话通道登录：cookie 会话续接 + CSRF 头 + 前端 Origin（命中 SANCTUM_STATEFUL_DOMAINS 才走会话链路）
        $login = $this->withCredentials()
            ->withHeader('Origin', 'http://localhost:4000')
            ->withHeader('X-CSRF-TOKEN', session()->token())
            ->postJson('/api/v1/auth/login', ['username' => 'admin', 'password' => 'admin123']);
        $login->assertOk()
            ->assertJsonPath('code', 0)
            // 双通道兼容性：登录响应仍返回 token（token 兼容通道，前端批次切换前保留，测试基线不碎）
            ->assertJsonStructure(['data' => ['token', 'user' => ['id', 'name', 'username']]]);
        $this->persistResponseCookies($login);
    }

    /**
     * 把响应 Set-Cookie 的原始值透传给后续请求：Laravel 测试客户端不会自动携带 cookie，
     * 需手动收集后经 withUnencryptedCookies 原样回传（服务端 EncryptCookies 对称解密，与原浏览器行为一致）
     */
    private function persistResponseCookies(TestResponse $response): void
    {
        $cookies = collect($response->headers->getCookies())
            ->mapWithKeys(fn (Cookie $cookie) => [$cookie->getName() => $cookie->getValue()])
            ->all();
        $this->withUnencryptedCookies($cookies);
    }
}
