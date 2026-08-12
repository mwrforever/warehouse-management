<?php
// 用户管理接口测试：CRUD/角色分配/重置密码/删除保护（安全路径，100% 覆盖）
namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    private string $token;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        // admin 用户挂 admin 角色（中间件放行）
        $role = Role::create(['name' => '管理员', 'code' => 'admin']);
        $this->admin = User::create(['name' => '管理员', 'username' => 'admin', 'password' => 'admin123', 'status' => 1]);
        $this->admin->roles()->sync([$role->id]);
        $this->token = $this->admin->createToken('api')->plainTextToken;
    }

    public function test_index_returns_paginated_users(): void
    {
        // 正常路径：分页列表含 total/page/per_page
        $this->withToken($this->token)->getJson('/api/v1/users?page=1&per_page=10')
            ->assertJsonPath('code', 0)
            ->assertJsonStructure(['data' => ['items', 'total', 'page', 'per_page']]);
    }

    public function test_index_keyword_search_filters_username(): void
    {
        // 边界路径：keyword 按用户名/姓名模糊过滤
        User::create(['name' => '张三', 'username' => 'zhangsan', 'password' => 'p', 'status' => 1]);
        $this->withToken($this->token)->getJson('/api/v1/users?keyword=zhangsan')
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.items.0.username', 'zhangsan');
    }

    public function test_store_creates_user_with_roles(): void
    {
        // 正常路径：新建用户并挂角色
        $res = $this->withToken($this->token)->postJson('/api/v1/users', [
            'name' => '测试员', 'username' => 'tester01', 'password' => 'Test@12345',
            'email' => 't@t.com', 'status' => 1, 'role_ids' => [],
        ]);
        $res->assertJsonPath('code', 0);
        $this->assertDatabaseHas('users', ['username' => 'tester01']);
    }

    public function test_store_duplicate_username_fails_with_1002(): void
    {
        // 异常路径：重复用户名返回 1002
        User::create(['name' => '已有', 'username' => 'dup', 'password' => 'p', 'status' => 1]);
        $this->withToken($this->token)->postJson('/api/v1/users', [
            'name' => 'x', 'username' => 'dup', 'password' => 'Test@12345', 'status' => 1,
        ])->assertJsonPath('code', 1002);
    }

    public function test_update_user_and_roles(): void
    {
        // 正常路径：更新姓名与角色
        $u = User::create(['name' => '旧名', 'username' => 'u1', 'password' => 'p', 'status' => 1]);
        $this->withToken($this->token)->putJson("/api/v1/users/{$u->id}", ['name' => '新名', 'username' => 'u1', 'status' => 1, 'role_ids' => []])
            ->assertJsonPath('code', 0);
        $this->assertDatabaseHas('users', ['id' => $u->id, 'name' => '新名']);
    }

    public function test_delete_builtin_admin_fails_with_1003(): void
    {
        // 异常路径：内置 admin 不可删除（按 username=admin 判定）
        $this->withToken($this->token)->deleteJson('/api/v1/users/' . $this->admin->id)
            ->assertJsonPath('code', 1003);
    }

    public function test_delete_normal_user_succeeds(): void
    {
        // 正常路径：普通用户可删除
        $u = User::create(['name' => '临时', 'username' => 'tmp', 'password' => 'p', 'status' => 1]);
        $this->withToken($this->token)->deleteJson("/api/v1/users/{$u->id}")->assertJsonPath('code', 0);
        $this->assertDatabaseMissing('users', ['id' => $u->id]);
    }

    public function test_reset_password_takes_effect(): void
    {
        // 正常路径：重置密码后新密码可登录、旧密码失败
        $u = User::create(['name' => '重置', 'username' => 'rp', 'password' => 'Old@12345', 'status' => 1]);
        $this->withToken($this->token)->putJson("/api/v1/users/{$u->id}/reset-password", ['password' => 'New@12345'])
            ->assertJsonPath('code', 0);
        $this->postJson('/api/v1/auth/login', ['username' => 'rp', 'password' => 'New@12345'])->assertJsonPath('code', 0);
        $this->postJson('/api/v1/auth/login', ['username' => 'rp', 'password' => 'Old@12345'])->assertJsonPath('code', 1001);
    }

    public function test_update_without_password_keeps_old_password(): void
    {
        // 边界路径：更新不带 password 时跳过密码变更分支，旧密码与旧 token 均保持有效
        $u = User::create(['name' => '保密', 'username' => 'keep', 'password' => 'Old@12345', 'status' => 1]);
        $oldToken = $this->postJson('/api/v1/auth/login', ['username' => 'keep', 'password' => 'Old@12345'])->json('data.token');
        $this->withToken($this->token)->putJson("/api/v1/users/{$u->id}", ['name' => '保密改', 'username' => 'keep', 'status' => 1, 'role_ids' => []])
            ->assertJsonPath('code', 0);
        $this->postJson('/api/v1/auth/login', ['username' => 'keep', 'password' => 'Old@12345'])->assertJsonPath('code', 0);
        // 未改密码不得误撤销旧 token（防止误伤在线会话）
        $this->app['auth']->forgetGuards();
        $this->withToken($oldToken)->getJson('/api/v1/auth/me')->assertJsonPath('code', 0);
    }

    public function test_reset_password_revokes_old_tokens(): void
    {
        // 安全路径：重置密码后旧 token 全部失效（/auth/me 返回 401，需重新登录）
        $u = User::create(['name' => '重置', 'username' => 'rp2', 'password' => 'Old@12345', 'status' => 1]);
        $oldToken = $this->postJson('/api/v1/auth/login', ['username' => 'rp2', 'password' => 'Old@12345'])->json('data.token');
        $this->withToken($this->token)->putJson("/api/v1/users/{$u->id}/reset-password", ['password' => 'New@12345'])
            ->assertJsonPath('code', 0);
        // 旧 token 立即失效：需重新登录才能继续访问
        $this->app['auth']->forgetGuards();
        $this->withToken($oldToken)->getJson('/api/v1/auth/me')->assertStatus(401);
        // 新密码可正常登录签发新 token
        $this->postJson('/api/v1/auth/login', ['username' => 'rp2', 'password' => 'New@12345'])->assertJsonPath('code', 0);
    }

    public function test_update_with_password_revokes_old_tokens(): void
    {
        // 安全路径：编辑用户时若携带 password，旧 token 一并失效
        $u = User::create(['name' => '改密', 'username' => 'chpw', 'password' => 'Old@12345', 'status' => 1]);
        $oldToken = $this->postJson('/api/v1/auth/login', ['username' => 'chpw', 'password' => 'Old@12345'])->json('data.token');
        $this->withToken($this->token)->putJson("/api/v1/users/{$u->id}", [
            'name' => '改密', 'username' => 'chpw', 'password' => 'New@12345', 'status' => 1, 'role_ids' => [],
        ])->assertJsonPath('code', 0);
        $this->app['auth']->forgetGuards();
        $this->withToken($oldToken)->getJson('/api/v1/auth/me')->assertStatus(401);
    }

    public function test_update_builtin_admin_username_rejected_with_1003(): void
    {
        // 异常路径：内置 admin 禁止改名（防改名后绕过 1003 删除保护）
        $this->withToken($this->token)->putJson('/api/v1/users/' . $this->admin->id, [
            'name' => '管理员', 'username' => 'super', 'status' => 1, 'role_ids' => [],
        ])->assertJsonPath('code', 1003);
        $this->assertDatabaseHas('users', ['id' => $this->admin->id, 'username' => 'admin']);
    }

    public function test_update_builtin_admin_status_rejected_with_1003(): void
    {
        // 异常路径：内置 admin 禁止禁用（防禁用唯一管理员锁死系统）
        $this->withToken($this->token)->putJson('/api/v1/users/' . $this->admin->id, [
            'name' => '管理员', 'username' => 'admin', 'status' => 0, 'role_ids' => [],
        ])->assertJsonPath('code', 1003);
        $this->assertDatabaseHas('users', ['id' => $this->admin->id, 'status' => 1]);
    }

    public function test_update_builtin_admin_name_allowed(): void
    {
        // 边界路径：内置 admin 仅禁止改 username/status，姓名等普通字段仍可更新
        $this->withToken($this->token)->putJson('/api/v1/users/' . $this->admin->id, [
            'name' => '系统管理员', 'username' => 'admin', 'status' => 1, 'role_ids' => [],
        ])->assertJsonPath('code', 0);
        $this->assertDatabaseHas('users', ['id' => $this->admin->id, 'name' => '系统管理员']);
    }

    public function test_get_nonexistent_user_returns_404_envelope(): void
    {
        // 异常路径：隐式绑定资源不存在返回统一 404 信封（非 Laravel 默认 message 体）
        $this->withToken($this->token)->putJson('/api/v1/users/999999', [
            'name' => 'x', 'username' => 'ghost', 'status' => 1, 'role_ids' => [],
        ])->assertStatus(404)
            ->assertJsonPath('code', 404)
            ->assertJsonPath('message', '资源不存在')
            ->assertJsonPath('data', null);
    }

    public function test_store_attaches_roles_and_list_shows_them(): void
    {
        // 正常路径：新建用户挂角色后，列表返回的 roles 数组含对应角色
        $role = Role::create(['name' => '操作员', 'code' => 'operator']);
        $this->withToken($this->token)->postJson('/api/v1/users', [
            'name' => '角色员', 'username' => 'withrole', 'password' => 'Test@12345', 'status' => 1, 'role_ids' => [$role->id],
        ])->assertJsonPath('code', 0);
        $this->withToken($this->token)->getJson('/api/v1/users?keyword=withrole')
            ->assertJsonPath('data.items.0.roles.0.id', $role->id)
            ->assertJsonPath('data.items.0.roles.0.name', '操作员');
    }

    public function test_store_invalid_role_id_returns_422(): void
    {
        // 异常路径：role_ids 传不存在的角色 id 返回 code=422（参数错误，非业务错误 1002）
        $this->withToken($this->token)->postJson('/api/v1/users', [
            'name' => 'x', 'username' => 'badrole', 'password' => 'Test@12345', 'status' => 1, 'role_ids' => [999999],
        ])->assertStatus(422)->assertJsonPath('code', 422);
    }

    public function test_index_clamps_per_page_to_valid_range(): void
    {
        // 边界路径：per_page 钳制到 1-100——0 值防除零、超上限防超大分页
        $this->withToken($this->token)->getJson('/api/v1/users?per_page=0')->assertJsonPath('data.per_page', 1);
        $this->withToken($this->token)->getJson('/api/v1/users?per_page=1000')->assertJsonPath('data.per_page', 100);
    }

    public function test_user_management_requires_permission(): void
    {
        // 异常路径：无 user.list 权限的用户访问返回 403
        $role = Role::create(['name' => '操作员', 'code' => 'operator']);
        $u = User::create(['name' => 'op', 'username' => 'op1', 'password' => 'p', 'status' => 1]);
        $u->roles()->sync([$role->id]);
        $this->withToken($u->createToken('api')->plainTextToken)
            ->getJson('/api/v1/users')->assertJsonPath('code', 403);
    }
}
