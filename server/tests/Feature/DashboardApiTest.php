<?php

// 仪表盘 HTTP 层测试：响应形状/权限 403/未登录 401/operator 可见但待审核空（核心接口 100% 覆盖）

namespace Tests\Feature;

use App\Models\PurchaseOrder;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->admin = User::where('username', 'admin')->first();
        $this->app['auth']->forgetGuards(); // 同 app 实例 guard 缓存清理（权限用例约定）
    }

    private User $admin;

    public function test_summary_ok_shape(): void
    {
        // 正常路径：200 + 7 字段形状完整
        $res = $this->actingAs($this->admin)->getJson('/api/v1/dashboard/summary');
        $res->assertOk()->assertJsonPath('code', 0);
        $data = $res->json('data');
        // 7 字段形状校验：字段清单提取为变量（多行控制结构 PSR-12 排版）
        $keys = [
            'inventory_total_qty', 'inventory_value', 'today_inbound_qty', 'today_outbound_qty',
            'pending_approvals', 'work_order_running', 'alert_count',
        ];
        foreach ($keys as $key) {
            $this->assertArrayHasKey($key, $data, "字段 {$key} 缺失");
        }
    }

    public function test_pending_approvals_ok_shape(): void
    {
        // 正常路径：200 + items 数组
        $res = $this->actingAs($this->admin)->getJson('/api/v1/dashboard/pending-approvals');
        $res->assertOk()->assertJsonPath('code', 0);
        $this->assertIsArray($res->json('data.items'));
    }

    public function test_work_order_progress_ok_shape(): void
    {
        // 正常路径：200 + items 数组
        $this->actingAs($this->admin)
            ->getJson('/api/v1/dashboard/work-order-progress')
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonStructure(['data' => ['items']]);
    }

    public function test_alerts_ok_shape(): void
    {
        // 正常路径：200 + items 数组
        $this->actingAs($this->admin)
            ->getJson('/api/v1/dashboard/alerts')
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonStructure(['data' => ['items']]);
    }

    public function test_dashboard_routes_require_authentication(): void
    {
        // 边界路径：未登录访问 4 接口 → 401（auth:sanctum）
        foreach (['summary', 'pending-approvals', 'work-order-progress', 'alerts'] as $path) {
            $this->getJson("/api/v1/dashboard/{$path}")->assertStatus(401);
        }
    }

    public function test_role_without_dashboard_view_gets_403(): void
    {
        // 边界路径：无 dashboard.view 的角色访问 4 接口 → 403
        $role = Role::create(['code' => 'NO-DASH', 'name' => '无仪表盘角色', 'remark' => '']);
        $user = User::create([
            'name' => '无仪表盘用户', 'username' => 'nodash01', 'email' => 'nodash01@php-design.local',
            'password' => 'Test@12345', 'status' => 1,
        ]);
        $user->roles()->sync([$role->id]);
        $this->app['auth']->forgetGuards();

        foreach (['summary', 'pending-approvals', 'work-order-progress', 'alerts'] as $path) {
            $this->actingAs($user)
                ->getJson("/api/v1/dashboard/{$path}")
                ->assertStatus(403)
                ->assertJsonPath('code', 403);
            $this->app['auth']->forgetGuards();
        }
    }

    public function test_operator_can_access_dashboard_but_pending_is_empty(): void
    {
        // 边界路径（TC-DSH-07 后端语义）：operator 可访问 4 接口；无审核权限 → 待审核 0/空列表
        $operator = Role::where('code', 'operator')->first();
        $user = User::create([
            'name' => '只读用户', 'username' => 'limited01', 'email' => 'limited01@php-design.local',
            'password' => 'Test@12345', 'status' => 1,
        ]);
        $user->roles()->sync([$operator->id]);
        $this->app['auth']->forgetGuards();

        // 先以 admin 造一张采购草稿（operator 应看不到）
        $sup = Supplier::create(['name' => '供应商A', 'code' => 'SUP-A', 'status' => 1]);
        PurchaseOrder::create([
            'no' => 'PO20260813-001', 'supplier_id' => $sup->id,
            'order_date' => now()->toDateString(), 'status' => PurchaseOrder::STATUS_DRAFT,
            'total_amount' => 100,
        ]);

        $summary = $this->actingAs($user)->getJson('/api/v1/dashboard/summary');
        $summary->assertOk()->assertJsonPath('data.pending_approvals', 0);

        $pending = $this->actingAs($user)->getJson('/api/v1/dashboard/pending-approvals');
        $pending->assertOk()->assertJsonPath('data.items', []);
        $this->app['auth']->forgetGuards();

        $this->actingAs($user)->getJson('/api/v1/dashboard/work-order-progress')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->actingAs($user)->getJson('/api/v1/dashboard/alerts')->assertOk();
    }
}
