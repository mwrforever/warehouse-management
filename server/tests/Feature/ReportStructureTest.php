<?php

// 报表模块结构测试：权限注册/角色持有边界（operator 不持有 = E2E TC-RPT-06 语义锁）

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportStructureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_report_permissions_seeded_with_group(): void
    {
        // 正常路径：4 项报表权限注册且 group=统计报表
        $codes = ['report.inventory', 'report.movements', 'report.production', 'report.purchase_sales'];
        $this->assertSame(4, Permission::where('group', '统计报表')->count());
        foreach ($codes as $code) {
            $this->assertNotNull(Permission::where('code', $code)->first(), "权限 {$code} 未注册");
        }
    }

    public function test_admin_holds_all_report_permissions(): void
    {
        // 正常路径：admin 角色全量持有报表权限
        $admin = Role::where('code', 'admin')->first();
        $this->assertSame(4, $admin->permissions()->whereIn('code', [
            'report.inventory', 'report.movements', 'report.production', 'report.purchase_sales',
        ])->count());
    }

    public function test_operator_does_not_hold_report_permissions(): void
    {
        // 关键边界（TC-RPT-06 语义锁）：operator 仅持有 %.list——报表权限不带 .list 后缀，
        // 故 operator 不持有（若实现者误加后缀本用例立即失败）
        $operator = Role::where('code', 'operator')->first();
        $this->assertSame(0, $operator->permissions()->whereIn('code', [
            'report.inventory', 'report.movements', 'report.production', 'report.purchase_sales',
        ])->count());
        // operator 持有的权限以 .list 结尾，唯一例外 dashboard.view（仪表盘全角色默认落地页，TC-DSH-07 锁定）
        foreach ($operator->permissions as $p) {
            if ($p->code === 'dashboard.view') {
                continue;
            }
            $this->assertStringEndsWith('.list', $p->code);
        }
    }
}
