<?php

// 角色管理接口测试：CRUD/权限分配/删除保护（安全路径，100% 覆盖）

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoleManagementTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        // admin 角色 + admin 用户 + 若干权限
        $admin = Role::create(['name' => '管理员', 'code' => 'admin']);
        Permission::create(['name' => '用户列表', 'code' => 'user.list', 'group' => '系统管理']);
        Permission::create(['name' => '角色列表', 'code' => 'role.list', 'group' => '系统管理']);
        $admin->permissions()->sync(Permission::pluck('id'));
        $u = User::create(['name' => '管理员', 'username' => 'admin', 'password' => 'admin123', 'status' => 1]);
        $u->roles()->sync([$admin->id]);
        $this->token = $u->createToken('api')->plainTextToken;
    }

    public function test_index_returns_roles_with_permissions(): void
    {
        // 正常路径：角色列表附带权限 code 集合
        $this->withToken($this->token)->getJson('/api/v1/roles')
            ->assertJsonPath('code', 0)
            ->assertJsonStructure(['data' => ['items' => [['permissions']]]]);
    }

    public function test_store_creates_role_with_permission_ids(): void
    {
        // 正常路径：新建角色并勾选权限
        $permIds = Permission::pluck('id')->all();
        $this->withToken($this->token)->postJson('/api/v1/roles', [
            'name' => '仓库管理员', 'code' => 'warehouse', 'remark' => '', 'permission_ids' => $permIds,
        ])->assertJsonPath('code', 0);
        $this->assertDatabaseHas('roles', ['code' => 'warehouse']);
    }

    public function test_update_role_resyncs_permissions(): void
    {
        // 正常路径：更新角色名称与权限集
        $role = Role::create(['name' => '旧', 'code' => 'r1']);
        $role->permissions()->sync([Permission::first()->id]);
        $this->withToken($this->token)->putJson("/api/v1/roles/{$role->id}", [
            'name' => '新', 'code' => 'r1', 'permission_ids' => [],
        ])->assertJsonPath('code', 0);
        $this->assertDatabaseHas('roles', ['id' => $role->id, 'name' => '新']);
        // 更新后该角色权限集已清空（permission_role 中 admin 角色的关联行属 setUp 数据，不在此断言范围）
        $this->assertDatabaseMissing('permission_role', ['role_id' => $role->id]);
    }

    public function test_delete_role_assigned_to_user_fails_with_1004(): void
    {
        // 异常路径：被用户引用的角色不可删除
        $role = Role::create(['name' => '操作员', 'code' => 'operator']);
        $u = User::create(['name' => 'u', 'username' => 'u2', 'password' => 'p', 'status' => 1]);
        $u->roles()->sync([$role->id]);
        $this->withToken($this->token)->deleteJson("/api/v1/roles/{$role->id}")
            ->assertJsonPath('code', 1004);
    }

    public function test_delete_last_admin_role_fails_with_1007(): void
    {
        // 边界路径：删除唯一 admin 编码角色返回 1007
        $adminRole = Role::where('code', 'admin')->first();
        $this->withToken($this->token)->deleteJson("/api/v1/roles/{$adminRole->id}")
            ->assertJsonPath('code', 1007);
    }

    public function test_delete_normal_role_succeeds(): void
    {
        // 正常路径：未引用的普通角色可删除
        $role = Role::create(['name' => '临时', 'code' => 'tmp']);
        $this->withToken($this->token)->deleteJson("/api/v1/roles/{$role->id}")->assertJsonPath('code', 0);
        $this->assertDatabaseMissing('roles', ['id' => $role->id]);
    }

    public function test_permissions_index_groups_by_group(): void
    {
        // 正常路径：权限按 group 分组输出
        $this->withToken($this->token)->getJson('/api/v1/permissions')
            ->assertJsonPath('code', 0)
            ->assertJsonStructure(['data' => ['groups' => [['group', 'permissions']]]]);
    }

    public function test_index_clamps_per_page_to_valid_range(): void
    {
        // 边界路径：per_page 钳制到 1-100——0 值防除零 500、超上限防超大分页
        $this->withToken($this->token)->getJson('/api/v1/roles?per_page=0')->assertJsonPath('data.per_page', 1);
        $this->withToken($this->token)->getJson('/api/v1/roles?per_page=1000')->assertJsonPath('data.per_page', 100);
    }

    // ---------- B02 保留码保护：admin 编码不可被占用/改出 ----------

    public function test_store_reserved_admin_code_fails_with_422(): void
    {
        // 安全路径（B02）：admin 保留码不可被新建角色占用（与 roles.code 唯一约束双重保险）
        $this->withToken($this->token)->postJson('/api/v1/roles', [
            'name' => '伪管理员', 'code' => 'admin', 'remark' => '', 'permission_ids' => [],
        ])->assertStatus(422)->assertJsonPath('code', 422);
        $this->assertSame(1, Role::where('code', 'admin')->count());
    }

    public function test_update_role_to_admin_code_fails_with_422(): void
    {
        // 安全路径（B02）：普通角色不可改名占用 admin 保留码（防伪造第二个管理员角色）
        $role = Role::create(['name' => '普通', 'code' => 'plain']);
        $this->withToken($this->token)->putJson("/api/v1/roles/{$role->id}", [
            'name' => '普通', 'code' => 'admin', 'permission_ids' => [],
        ])->assertStatus(422)->assertJsonPath('code', 422);
        $this->assertDatabaseHas('roles', ['id' => $role->id, 'code' => 'plain']);
    }

    public function test_update_admin_role_code_locked_fails_with_422(): void
    {
        // 安全路径（B02）：内置管理员角色编码锁定不可改出（防改名后重建 admin 角色架空删除保护与权限放行）
        $adminRole = Role::where('code', 'admin')->firstOrFail();
        $this->withToken($this->token)->putJson("/api/v1/roles/{$adminRole->id}", [
            'name' => '管理员', 'code' => 'boss', 'permission_ids' => [],
        ])->assertStatus(422)->assertJsonPath('code', 422);
        $this->assertDatabaseHas('roles', ['id' => $adminRole->id, 'code' => 'admin']);
    }

    public function test_update_admin_role_keeps_code_succeeds(): void
    {
        // 正常路径：内置管理员角色保持编码 admin 时可正常更新名称与权限（保护不误伤管理员操作）
        $adminRole = Role::where('code', 'admin')->firstOrFail();
        $this->withToken($this->token)->putJson("/api/v1/roles/{$adminRole->id}", [
            'name' => '系统管理员', 'code' => 'admin', 'permission_ids' => Permission::pluck('id')->all(),
        ])->assertJsonPath('code', 0);
        $this->assertDatabaseHas('roles', ['id' => $adminRole->id, 'name' => '系统管理员']);
    }
}
