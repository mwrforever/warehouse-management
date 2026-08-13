<?php

// 仪表盘模块结构测试：权限注册/角色持有边界（operator 持有 dashboard.view = E2E TC-DSH-07 语义锁）

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardStructureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_dashboard_permission_seeded_with_group(): void
    {
        // 正常路径：dashboard.view 注册且 group=仪表盘
        $p = Permission::where('code', 'dashboard.view')->first();
        $this->assertNotNull($p, '权限 dashboard.view 未注册');
        $this->assertSame('仪表盘', $p->group);
        $this->assertSame('仪表盘查看', $p->name);
    }

    public function test_admin_holds_dashboard_view(): void
    {
        // 正常路径：admin 角色全量持有
        $admin = Role::where('code', 'admin')->first();
        $this->assertSame(1, $admin->permissions()->where('code', 'dashboard.view')->count());
    }

    public function test_operator_holds_dashboard_view(): void
    {
        // 关键边界（TC-DSH-07 语义锁）：operator 持有 dashboard.view——
        // 仪表盘为登录默认落地页，limited01 必须能加载 KPI；待审核数据由接口内部按审核权限过滤，
        // 不构成数据泄露（若实现者遗漏 operator 例外本用例立即失败）
        $operator = Role::where('code', 'operator')->first();
        $this->assertSame(1, $operator->permissions()->where('code', 'dashboard.view')->count());
    }
}
