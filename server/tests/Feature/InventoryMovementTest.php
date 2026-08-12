<?php

// 库存流水接口测试：倒序/筛选/标签映射（正常+边界+异常）

namespace Tests\Feature;

use App\Models\Category;
use App\Models\InventoryMovement;
use App\Models\Location;
use App\Models\Product;
use App\Models\Role;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryMovementTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    private Product $mat;

    protected function setUp(): void
    {
        parent::setUp();
        $role = Role::create(['name' => '管理员', 'code' => 'admin']);
        $u = User::create(['name' => '管理员', 'username' => 'admin', 'password' => 'admin123', 'status' => 1]);
        $u->roles()->sync([$role->id]);
        $this->token = $u->createToken('api')->plainTextToken;

        $wh = Warehouse::create(['name' => '主仓', 'code' => 'WH01', 'status' => 1]);
        $loc = Location::create(['warehouse_id' => $wh->id, 'name' => 'A-01', 'code' => 'A-01', 'status' => 1]);
        $cat = Category::create(['name' => '原材料', 'parent_id' => 0]);
        $unit = Unit::create(['name' => '个', 'code' => 'pc']);
        $this->mat = Product::create([
            'name' => '测试铝材', 'code' => 'MAT-001', 'type' => 'raw_material', 'category_id' => $cat->id,
            'unit_id' => $unit->id, 'status' => 1,
        ]);
        // 两条流水：采购入库（最早）+ 盘盈（最新）
        InventoryMovement::create([
            'product_id' => $this->mat->id, 'warehouse_id' => $wh->id, 'location_id' => $loc->id,
            'direction' => 1, 'quantity' => 100, 'balance_after' => 100,
            'source_type' => 'purchase_inbound', 'source_id' => 1, 'source_no' => 'PO20260812-001',
            'remark' => null, 'operator_id' => null, 'created_at' => now()->subDay(),
        ]);
        InventoryMovement::create([
            'product_id' => $this->mat->id, 'warehouse_id' => $wh->id, 'location_id' => $loc->id,
            'direction' => 1, 'quantity' => 5, 'balance_after' => 105,
            'source_type' => 'check_in', 'source_id' => 2, 'source_no' => 'CK20260812-001',
            'remark' => '盘盈', 'operator_id' => $u->id, 'created_at' => now(),
        ]);
    }

    public function test_index_ordered_desc_with_labels(): void
    {
        // 正常路径：时间倒序 + 中文类型标签 + 操作人名称
        $this->withToken($this->token)->getJson('/api/v1/inventory/movements')
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.total', 2)
            ->assertJsonPath('data.items.0.source_type_label', '盘盈')
            ->assertJsonPath('data.items.0.source_no', 'CK20260812-001')
            ->assertJsonPath('data.items.0.operator_name', '管理员')
            ->assertJsonPath('data.items.1.source_type_label', '采购入库');
    }

    public function test_filters_by_product_source_type_direction(): void
    {
        // 正常路径：商品/类型/方向筛选
        $this->withToken($this->token)->getJson('/api/v1/inventory/movements?product_id='.$this->mat->id)
            ->assertJsonPath('data.total', 2);
        $this->withToken($this->token)->getJson('/api/v1/inventory/movements?source_type=check_in')
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.items.0.source_type', 'check_in');
        $this->withToken($this->token)->getJson('/api/v1/inventory/movements?direction=-1')
            ->assertJsonPath('data.total', 0);
    }

    public function test_filters_by_date_range(): void
    {
        // 边界路径：日期范围筛选（date_from/date_to 闭区间）
        $this->withToken($this->token)->getJson('/api/v1/inventory/movements?date_from='.now()->toDateString().'&date_to='.now()->toDateString())
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.items.0.source_no', 'CK20260812-001');
    }
}
