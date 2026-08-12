<?php

// RBAC 测试：权限中间件三个分支（admin bypass / 授权放行 / 无权限拒绝）+ 权限合并去重（核心安全路径，100% 覆盖）

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RbacTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        // 造角色与权限：admin 全权限、operator 仅 user.list
        $admin = Role::create(['name' => '管理员', 'code' => 'admin']);
        $operator = Role::create(['name' => '操作员', 'code' => 'operator']);
        $perm = Permission::create(['name' => '用户列表', 'code' => 'user.list', 'group' => '系统管理']);
        Permission::create(['name' => '用户创建', 'code' => 'user.create', 'group' => '系统管理']);
        $admin->permissions()->sync(Permission::all());
        $operator->permissions()->sync([$perm->id]);

        // email 为建表非空字段（脚手架默认），测试用户需补齐
        $this->user = User::create(['name' => '测试', 'username' => 't', 'email' => 't@test.com', 'password' => 'p', 'status' => 1]);
        $this->user->roles()->sync([$admin->id]);
    }

    public function test_user_with_admin_role_passes_permission_check(): void
    {
        // 正常路径（bypass 分支）：admin 角色命中 $isAdmin 短路，跳过权限集合校验直接放行
        $token = $this->user->createToken('api')->plainTextToken;
        $this->withToken($token)
            ->getJson('/api/v1/users')  // 真实用户路由挂 permission:user.list 中间件
            ->assertJsonPath('code', 0);
    }

    public function test_user_without_permission_gets_403(): void
    {
        // 异常路径（拒绝分支）：operator 角色无 user.list 权限，访问用户列表被拒
        $operator = Role::where('code', 'operator')->first();
        $operator->permissions()->sync([]); // 清空权限：仅保留角色壳，验证中间件按权限集合拒绝
        $u = User::create(['name' => 'op', 'username' => 'op', 'email' => 'op@test.com', 'password' => 'p', 'status' => 1]);
        $u->roles()->sync([$operator->id]);

        $token = $u->createToken('api')->plainTextToken;
        $this->withToken($token)->getJson('/api/v1/users')
            ->assertStatus(403)
            ->assertJsonPath('code', 403)
            ->assertJsonPath('message', '无权限操作');
    }

    public function test_user_with_permission_passes_permission_check(): void
    {
        // 正常路径（授权放行分支）：非 admin 用户持有 user.list，请求用户列表放行
        $operator = Role::where('code', 'operator')->first();
        $u = User::create(['name' => 'op', 'username' => 'op', 'email' => 'op@test.com', 'password' => 'p', 'status' => 1]);
        $u->roles()->sync([$operator->id]);

        $token = $u->createToken('api')->plainTextToken;
        $this->withToken($token)->getJson('/api/v1/users')->assertJsonPath('code', 0);
    }

    public function test_user_permissions_method_returns_unique_codes(): void
    {
        // 正常路径：多角色权限合并去重（admin 全权限 + 仅含 user.list 的角色，user.list 重叠应被去重）
        $listOnly = Role::create(['name' => '仅列表', 'code' => 'list-only']);
        $listOnly->permissions()->sync([Permission::where('code', 'user.list')->first()->id]);
        $this->user->roles()->syncWithoutDetaching([$listOnly->id]);

        $this->assertEquals(['user.list', 'user.create'], $this->user->permissions()->all());
    }
}
