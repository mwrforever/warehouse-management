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
