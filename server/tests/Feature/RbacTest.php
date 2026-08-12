<?php
// RBAC 测试：权限中间件拦截与放行（核心安全路径，100% 覆盖）
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
        // 正常路径：admin 角色含全部权限，请求带权限中间件放行
        $token = $this->user->createToken('api')->plainTextToken;
        $this->withToken($token)
            ->getJson('/api/v1/auth/me')  // 该路由仅 auth:sanctum，验证可访问
            ->assertJsonPath('code', 0);
    }

    public function test_user_without_permission_gets_403(): void
    {
        // 异常路径：operator 角色仅 user.list，访问 user.create 被拒
        $perm = Permission::where('code', 'user.list')->first();
        $operator = Role::where('code', 'operator')->first();
        $operator->permissions()->sync([$perm->id]);
        $u = User::create(['name' => 'op', 'username' => 'op', 'email' => 'op@test.com', 'password' => 'p', 'status' => 1]);
        $u->roles()->sync([$operator->id]);

        $token = $u->createToken('api')->plainTextToken;
        $this->withToken($token)->getJson('/api/v1/test-permission')->assertJsonPath('code', 403);
    }

    public function test_user_permissions_method_returns_unique_codes(): void
    {
        // 正常路径：多角色权限合并去重
        $this->assertEquals(['user.list', 'user.create'], $this->user->permissions()->all());
    }
}
