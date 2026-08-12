<?php

// 库存余额接口测试：列表筛选/预警计算/CSV 导出（正常+边界+异常）

namespace Tests\Feature;

use App\Models\Category;
use App\Models\InventoryBalance;
use App\Models\Location;
use App\Models\Product;
use App\Models\Role;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryBalanceTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    private Warehouse $wh;

    private Location $a01;

    private Product $mat;

    protected function setUp(): void
    {
        parent::setUp();
        // admin 用户挂 admin 角色（权限中间件放行）
        $role = Role::create(['name' => '管理员', 'code' => 'admin']);
        $u = User::create(['name' => '管理员', 'username' => 'admin', 'password' => 'admin123', 'status' => 1]);
        $u->roles()->sync([$role->id]);
        $this->token = $u->createToken('api')->plainTextToken;

        // 基础数据：主仓 + A-01 库位 + 两个商品（MAT-001 有库存、FIN-001 无库存）
        $this->wh = Warehouse::create(['name' => '主仓', 'code' => 'WH01', 'status' => 1]);
        $this->a01 = Location::create([
            'warehouse_id' => $this->wh->id,
            'name' => 'A-01',
            'code' => 'A-01',
            'status' => 1,
        ]);
        $cat = Category::create(['name' => '原材料', 'parent_id' => 0]);
        $unit = Unit::create(['name' => '个', 'code' => 'pc']);
        $this->mat = Product::create([
            'name' => '测试铝材', 'code' => 'MAT-001', 'type' => 'raw_material', 'category_id' => $cat->id,
            'unit_id' => $unit->id, 'barcode' => '100001', 'safety_min' => 50, 'safety_max' => 500, 'status' => 1,
        ]);
        InventoryBalance::create([
            'product_id' => $this->mat->id, 'warehouse_id' => $this->wh->id, 'location_id' => $this->a01->id,
            'quantity' => 100, 'safety_min' => 50, 'safety_max' => 500,
        ]);
    }

    public function test_index_returns_balance_fields(): void
    {
        // 正常路径：列表返回商品/仓库/库位/上下限/预警级别完整字段
        $this->withToken($this->token)->getJson('/api/v1/inventory/balances')
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.items.0.product_code', 'MAT-001')
            ->assertJsonPath('data.items.0.warehouse_name', '主仓')
            ->assertJsonPath('data.items.0.location_name', 'A-01')
            ->assertJsonPath('data.items.0.quantity', '100.00')
            ->assertJsonPath('data.items.0.alert_level', 0);
    }

    public function test_keyword_filters_by_code_name_barcode(): void
    {
        // 正常路径：关键字按编码/名称/条码模糊过滤
        $this->withToken($this->token)->getJson('/api/v1/inventory/balances?keyword=MAT')
            ->assertJsonPath('data.total', 1);
        $this->withToken($this->token)->getJson('/api/v1/inventory/balances?keyword=100001')
            ->assertJsonPath('data.total', 1);
        $this->withToken($this->token)->getJson('/api/v1/inventory/balances?keyword=不存在')
            ->assertJsonPath('data.total', 0);
    }

    public function test_warehouse_and_type_filters(): void
    {
        // 正常路径：仓库/类型筛选生效
        $this->withToken($this->token)->getJson('/api/v1/inventory/balances?warehouse_id='.$this->wh->id)
            ->assertJsonPath('data.total', 1);
        $this->withToken($this->token)->getJson('/api/v1/inventory/balances?type=finished')
            ->assertJsonPath('data.total', 0);
        $this->withToken($this->token)->getJson('/api/v1/inventory/balances?type=raw_material')
            ->assertJsonPath('data.total', 1);
    }

    public function test_alert_filter_returns_only_warned_rows(): void
    {
        // 边界路径：低于下限预警（alert=1 只返回预警行）
        InventoryBalance::query()->update(['quantity' => 40]);
        $this->withToken($this->token)->getJson('/api/v1/inventory/balances?alert=1')
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.items.0.alert_level', 1);
    }

    public function test_alert_level_above_max_when_max_positive(): void
    {
        // 边界路径：高于上限预警（max>0 才检查该侧）
        InventoryBalance::query()->update(['quantity' => 600]);
        $this->withToken($this->token)->getJson('/api/v1/inventory/balances?alert=1')
            ->assertJsonPath('data.items.0.alert_level', 2);
    }

    public function test_alert_level_zero_when_limits_disabled(): void
    {
        // 边界路径：上下限为 0 不预警该侧（quantity 超 0 也不触发）
        $this->mat->update(['safety_min' => 0, 'safety_max' => 0]);
        $this->withToken($this->token)->getJson('/api/v1/inventory/balances')
            ->assertJsonPath('data.items.0.alert_level', 0);
    }

    public function test_export_csv_has_bom_header_and_rows(): void
    {
        // 正常路径：导出 CSV 含 UTF-8 BOM、表头、行数一致（中文无乱码）
        $res = $this->withToken($this->token)->get('/api/v1/inventory/balances/export');
        $res->assertOk();
        $csv = $res->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        // trim 默认字符集不含 BOM，先剥离 BOM 再按行解析（BOM 恒为 3 字节）
        $lines = explode("\n", trim(substr($csv, 3)));
        $this->assertSame('商品编码,商品名称,仓库,库位,数量,下限,上限,状态', $lines[0]);
        $this->assertCount(2, $lines); // 表头 + 1 行数据
        $this->assertStringContainsString('MAT-001', $lines[1]);
        $this->assertStringContainsString('测试铝材', $lines[1]);
    }

    public function test_export_csv_status_column_reflects_alert(): void
    {
        // 边界路径：导出状态列与预警一致（低库存）
        InventoryBalance::query()->update(['quantity' => 40]);
        $csv = $this->withToken($this->token)->get('/api/v1/inventory/balances/export')->streamedContent();
        $this->assertStringContainsString('低库存', $csv);
    }

    public function test_balances_requires_inventory_permission(): void
    {
        // 异常路径：无 inventory.list 权限的角色被拒（403 JSON 信封）
        $role = Role::create(['name' => '普通', 'code' => 'plain']);
        $u = User::create(['name' => '普通', 'username' => 'plain', 'password' => 'admin123', 'status' => 1]);
        $u->roles()->sync([$role->id]);
        $token = $u->createToken('api')->plainTextToken;
        $this->withToken($token)->getJson('/api/v1/inventory/balances')->assertStatus(403);
    }

    public function test_alerts_ordered_by_product_id(): void
    {
        // 边界路径：多个预警商品时按商品 ID 升序（顺序稳定不抖动）
        $cat = Category::where('name', '原材料')->first() ?? Category::create(['name' => '原材料', 'parent_id' => 0]);
        $unit = Unit::where('code', 'pc')->first() ?? Unit::create(['name' => '个', 'code' => 'pc']);
        $semi = Product::create([
            'name' => '半成品A', 'code' => 'SEMI-001', 'type' => 'semi_finished', 'category_id' => $cat->id,
            'unit_id' => $unit->id, 'safety_min' => 10, 'safety_max' => 200, 'status' => 1,
        ]);
        InventoryBalance::create([
            'product_id' => $semi->id, 'warehouse_id' => $this->wh->id, 'location_id' => $this->a01->id,
            'quantity' => 5, 'safety_min' => 10, 'safety_max' => 200,
        ]);
        InventoryBalance::query()->where('product_id', $this->mat->id)->update(['quantity' => 40]);
        $res = $this->withToken($this->token)->getJson('/api/v1/inventory/alerts');
        $codes = array_column($res->json('data.items'), 'product_code');
        // MAT-001 id 小在前，SEMI-001 在后（product_id 升序）
        $this->assertSame(['MAT-001', 'SEMI-001'], $codes);
    }
}
