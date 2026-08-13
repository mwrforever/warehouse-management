<?php

// 报表 HTTP 层测试：响应形状/1601 倒置日期/422 格式层/truncated/权限 403（核心接口 100% 覆盖）

namespace Tests\Feature;

use App\Models\Category;
use App\Models\InventoryBalance;
use App\Models\Location;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportApiTest extends TestCase
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

    // 直接插余额行（HTTP 层测试聚焦契约，不重复服务层口径用例）
    private function seedBalance(string $quantity): void
    {
        $cat = Category::where('name', '原材料')->first();
        $p = Product::create([
            'name' => '报表测试原料', 'code' => 'MAT-RPT', 'type' => 'raw_material',
            'category_id' => $cat->id, 'unit_id' => 1, 'safety_min' => 0, 'safety_max' => 0, 'status' => 1,
        ]);
        InventoryBalance::create([
            'product_id' => $p->id, 'warehouse_id' => Warehouse::where('code', 'WH01')->first()->id,
            'location_id' => Location::where('code', 'A-01')->first()->id,
            'quantity' => $quantity, 'safety_min' => 0, 'safety_max' => 0,
        ]);
    }

    public function test_inventory_summary_ok_shape_and_default_group_by(): void
    {
        // 正常路径：200 形状（items/total/truncated），group_by 缺省=category
        $this->seedBalance('10.00');
        $res = $this->actingAs($this->admin)->getJson('/api/v1/reports/inventory-summary');
        $res->assertOk()->assertJsonPath('code', 0);
        $data = $res->json('data');
        $this->assertArrayHasKey('items', $data);
        $this->assertArrayHasKey('total', $data);
        $this->assertFalse($data['truncated']);
    }

    public function test_inventory_summary_invalid_group_by_returns_422(): void
    {
        // 边界路径：group_by 非法枚举 → 422 格式层（非业务码）
        $this->actingAs($this->admin)
            ->getJson('/api/v1/reports/inventory-summary?group_by=color')
            ->assertStatus(422);
    }

    public function test_movements_summary_inverted_dates_return_1601(): void
    {
        // 边界路径：倒置日期 → 业务码 1601 + 精确消息（E2E TC-RPT-05 断言）
        $this->actingAs($this->admin)
            ->getJson('/api/v1/reports/movements-summary?date_from=2099-01-31&date_to=2099-01-01&granularity=day')
            ->assertOk()
            ->assertJson(['code' => 1601, 'message' => '开始日期不能晚于结束日期']);
    }

    public function test_production_inverted_dates_return_1601(): void
    {
        // 边界路径：生产统计同样拦截倒置日期
        $this->actingAs($this->admin)
            ->getJson('/api/v1/reports/production?date_from=2026-08-31&date_to=2026-08-01')
            ->assertOk()
            ->assertJsonPath('code', 1601);
    }

    public function test_purchase_sales_inverted_dates_return_1601(): void
    {
        // 边界路径：采购销售汇总同样拦截倒置日期
        $this->actingAs($this->admin)
            ->getJson('/api/v1/reports/purchase-sales?date_from=2026-08-31&date_to=2026-08-01&granularity=month')
            ->assertOk()
            ->assertJsonPath('code', 1601);
    }

    public function test_movements_summary_missing_dates_return_422(): void
    {
        // 边界路径：缺日期参数 → 422 格式层
        $this->actingAs($this->admin)
            ->getJson('/api/v1/reports/movements-summary')
            ->assertStatus(422);
    }

    public function test_movements_summary_bad_date_format_returns_422(): void
    {
        // 边界路径：日期格式非 Y-m-d → 422
        $this->actingAs($this->admin)
            ->getJson('/api/v1/reports/movements-summary?date_from=2026/08/01&date_to=2026-08-31&granularity=day')
            ->assertStatus(422);
    }

    public function test_movements_summary_invalid_granularity_returns_422(): void
    {
        // 边界路径：granularity 非法枚举 → 422
        $this->actingAs($this->admin)
            ->getJson('/api/v1/reports/movements-summary?date_from=2026-08-01&date_to=2026-08-31&granularity=hour')
            ->assertStatus(422);
    }

    public function test_movements_summary_invalid_source_type_returns_422(): void
    {
        // 边界路径：source_type 不在流水枚举 → 422
        $this->actingAs($this->admin)
            ->getJson('/api/v1/reports/movements-summary?date_from=2026-08-01&date_to=2026-08-31&granularity=day&source_type=nope')
            ->assertStatus(422);
    }

    public function test_movements_summary_ok_empty_range_zero_totals(): void
    {
        // 边界路径：空区间 200 + items 空 + totals 全 0（E2E TC-RPT-05 空态）
        $res = $this->actingAs($this->admin)
            ->getJson('/api/v1/reports/movements-summary?date_from=2099-01-01&date_to=2099-01-31&granularity=day');
        $res->assertOk()->assertJsonPath('data.items', []);
        $this->assertSame('0', $res->json('data.totals.inbound_qty'));
        $this->assertSame('0', $res->json('data.totals.outbound_qty'));
    }

    public function test_operator_without_report_permission_gets_403_on_all_report_routes(): void
    {
        // 边界路径（TC-RPT-06 后端拦截）：operator（仅 %.list）访问 4 个报表接口全部 403
        $operator = Role::where('code', 'operator')->first();
        $user = User::create([
            'name' => '只读用户', 'username' => 'limited01', 'email' => 'limited01@php-design.local',
            'password' => 'Test@12345', 'status' => 1,
        ]);
        $user->roles()->sync([$operator->id]);
        $this->app['auth']->forgetGuards();

        $routes = [
            '/api/v1/reports/inventory-summary',
            '/api/v1/reports/movements-summary?date_from=2026-08-01&date_to=2026-08-31&granularity=day',
            '/api/v1/reports/production?date_from=2026-08-01&date_to=2026-08-31',
            '/api/v1/reports/purchase-sales?date_from=2026-08-01&date_to=2026-08-31&granularity=month',
        ];
        foreach ($routes as $route) {
            $this->actingAs($user)
                ->getJson($route)
                ->assertStatus(403)
                ->assertJsonPath('code', 403);
            $this->app['auth']->forgetGuards();
        }
    }

    public function test_report_routes_require_authentication(): void
    {
        // 边界路径：未登录访问 → 401（auth:sanctum）
        $this->getJson('/api/v1/reports/inventory-summary')->assertStatus(401);
    }
}
