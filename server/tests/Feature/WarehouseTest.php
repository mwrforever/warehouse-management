<?php

// 仓库/库位接口测试：CRUD/编码自动生成（老库衔接）/区域落库/子资源/删除保护（正常+边界+异常）

namespace Tests\Feature;

use App\Models\BomHeader;
use App\Models\Category;
use App\Models\Customer;
use App\Models\DocumentNumberConfig;
use App\Models\InventoryBalance;
use App\Models\Location;
use App\Models\Product;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WarehouseTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $role = Role::create(['name' => '管理员', 'code' => 'admin']);
        $u = User::create(['name' => '管理员', 'username' => 'admin', 'password' => 'admin123', 'status' => 1]);
        $u->roles()->sync([$role->id]);
        $this->token = $u->createToken('api')->plainTextToken;
    }

    public function test_index_supports_keyword_search(): void
    {
        // 正常路径：关键字按名称/编码模糊过滤
        Warehouse::create(['name' => '主仓', 'code' => 'WH01', 'status' => 1]);
        $this->withToken($this->token)->getJson('/api/v1/warehouses?keyword=WH01')
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.items.0.code', 'WH01');
    }

    public function test_store_auto_generates_code_without_code_input(): void
    {
        // 正常路径：新建不再接收 code，由 DocumentSequenceService 自动生成（'wh' 配置缺失时
        // 按 type 大写兜底 → WH 前缀），响应回填且两次创建编码不重复
        $res = $this->withToken($this->token)->postJson('/api/v1/warehouses', [
            'name' => '测试仓',
            'address' => '厂区B',
            'manager' => '李四',
            'status' => 1,
        ])->assertJsonPath('code', 0);
        $code = $res->json('data.code');
        $this->assertStringStartsWith('WH', $code);
        $res2 = $this->withToken($this->token)->postJson('/api/v1/warehouses', ['name' => '测试仓2', 'status' => 1])
            ->assertJsonPath('code', 0);
        $this->assertNotSame($code, $res2->json('data.code'));
        $this->assertDatabaseHas('warehouses', ['code' => $code]);
    }

    public function test_store_auto_code_continues_after_legacy_wh_codes(): void
    {
        // 老库衔接：已有 WH01/WH02（字母+数字无分隔符样式，序号 2 为当前最大值），
        // 自动编码以最大序号为起点 → WH000003，不与历史编码撞号
        DocumentNumberConfig::create([
            'type' => 'wh', 'prefix' => 'WH', 'date_format' => '', 'seq_length' => 6,
            'is_enabled' => true,
        ]);
        Warehouse::create(['name' => '老仓1', 'code' => 'WH01', 'status' => 1]);
        Warehouse::create(['name' => '老仓2', 'code' => 'WH02', 'status' => 1]);
        $res = $this->withToken($this->token)->postJson('/api/v1/warehouses', ['name' => '新仓', 'status' => 1])
            ->assertJsonPath('code', 0);
        $this->assertSame('WH000003', $res->json('data.code'));
        $this->assertDatabaseHas('warehouses', ['code' => 'WH000003']);
    }

    public function test_store_persists_region_and_detail_address(): void
    {
        // 正常路径：四级地址名称（省/市/区县/乡镇）与详细地址一并落库
        $this->withToken($this->token)->postJson('/api/v1/warehouses', [
            'name' => '区域仓',
            'province' => '广东省', 'city' => '深圳市', 'district' => '南山区', 'town' => '粤海街道',
            'address' => '科技园路 1 号',
            'manager' => '李四',
            'status' => 1,
        ])->assertJsonPath('code', 0);
        $this->assertDatabaseHas('warehouses', [
            'name' => '区域仓', 'province' => '广东省', 'city' => '深圳市',
            'district' => '南山区', 'town' => '粤海街道', 'address' => '科技园路 1 号',
        ]);
    }

    public function test_update_warehouse_keeps_original_code(): void
    {
        // 正常路径：更新属性时带上 code 字段不改变原编码（载荷已不收 code，忽略传入值）
        $w = Warehouse::create(['name' => '主仓', 'code' => 'WH01', 'status' => 1]);
        $this->withToken($this->token)->putJson("/api/v1/warehouses/{$w->id}", [
            'name' => '主仓2',
            'code' => 'WH99',
            'address' => '新地址',
            'province' => '浙江省', 'city' => '杭州市', 'district' => '西湖区', 'town' => '文新街道',
            'manager' => '王五',
            'status' => 1,
        ])
            ->assertJsonPath('code', 0);
        $this->assertDatabaseHas('warehouses', [
            'id' => $w->id, 'name' => '主仓2', 'manager' => '王五', 'code' => 'WH01',
            'province' => '浙江省', 'city' => '杭州市', 'district' => '西湖区', 'town' => '文新街道',
        ]);
    }

    public function test_location_crud_under_warehouse(): void
    {
        // 正常路径：库位增查改删全链路
        $w = Warehouse::create(['name' => '测试仓', 'code' => 'WH02', 'status' => 1]);
        $this->withToken($this->token)->postJson("/api/v1/warehouses/{$w->id}/locations", [
            'name' => 'A-01',
            'code' => 'A-01',
            'status' => 1,
        ])
            ->assertJsonPath('code', 0);
        $this->withToken($this->token)->getJson("/api/v1/warehouses/{$w->id}/locations")
            ->assertJsonPath('data.items.0.name', 'A-01');
        $location = Location::first();
        $this->withToken($this->token)
            ->putJson("/api/v1/locations/{$location->id}", ['name' => 'A-02', 'code' => 'A-02', 'status' => 1])
            ->assertJsonPath('code', 0);
        $this->withToken($this->token)->deleteJson("/api/v1/locations/{$location->id}")->assertJsonPath('code', 0);
        $this->assertDatabaseMissing('locations', ['id' => $location->id]);
    }

    public function test_location_duplicate_code_returns_422(): void
    {
        // 异常路径：库位编码全局唯一（格式层 422，非业务码）
        $w = Warehouse::create(['name' => '测试仓', 'code' => 'WH02', 'status' => 1]);
        Location::create(['warehouse_id' => $w->id, 'name' => 'A-01', 'code' => 'A-01', 'status' => 1]);
        $this->withToken($this->token)->postJson("/api/v1/warehouses/{$w->id}/locations", [
            'name' => 'A-01b',
            'code' => 'A-01',
            'status' => 1,
        ])
            ->assertStatus(422);
    }

    public function test_destroy_warehouse_cascades_locations(): void
    {
        // 正常路径：删除仓库级联删除其库位（余额表未建，守卫放行）
        $w = Warehouse::create(['name' => '测试仓', 'code' => 'WH02', 'status' => 1]);
        Location::create(['warehouse_id' => $w->id, 'name' => 'A-01', 'code' => 'A-01', 'status' => 1]);
        $this->withToken($this->token)->deleteJson("/api/v1/warehouses/{$w->id}")->assertJsonPath('code', 0);
        $this->assertDatabaseMissing('warehouses', ['id' => $w->id]);
        $this->assertDatabaseMissing('locations', ['warehouse_id' => $w->id]);
    }

    public function test_destroy_with_balances_table_reference_fails(): void
    {
        // 边界路径：真实 inventory_balances 表存在引用时，仓库删除被拒 1106（库存模块表落地后守卫自动生效）
        $p = $this->createBalanceProduct();
        $w = Warehouse::create(['name' => '测试仓', 'code' => 'WH02', 'status' => 1]);
        $l = Location::create(['warehouse_id' => $w->id, 'name' => 'A-01', 'code' => 'A-01', 'status' => 1]);
        InventoryBalance::create([
            'product_id' => $p->id,
            'warehouse_id' => $w->id,
            'location_id' => $l->id,
            'quantity' => 1,
        ]);
        $this->withToken($this->token)->deleteJson("/api/v1/warehouses/{$w->id}")
            ->assertJsonPath('code', 1106);
    }

    public function test_destroy_warehouse_with_purchase_inbound_reference_fails(): void
    {
        // 边界路径：purchase_inbounds 引用该仓库时删除被拒 1106（入库单引用同码保护）
        $w = Warehouse::create(['name' => '测试仓', 'code' => 'WH02', 'status' => 1]);
        $l = Location::create(['warehouse_id' => $w->id, 'name' => 'A-01', 'code' => 'A-01', 'status' => 1]);
        $s = Supplier::create(['name' => '测试供应商', 'code' => 'SUP-001', 'status' => 1]);
        DB::table('purchase_inbounds')->insert([
            'no' => 'PI-TEST-001', 'supplier_id' => $s->id, 'warehouse_id' => $w->id, 'location_id' => $l->id,
            'status' => 0, 'total_amount' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->withToken($this->token)->deleteJson("/api/v1/warehouses/{$w->id}")
            ->assertJsonPath('code', 1106);
    }

    public function test_destroy_location_with_balances_table_reference_fails(): void
    {
        // 边界路径：真实 inventory_balances 表存在 location_id 引用时，库位删除被拒 1107（库存模块表落地后守卫自动生效）
        $p = $this->createBalanceProduct();
        $w = Warehouse::create(['name' => '测试仓', 'code' => 'WH02', 'status' => 1]);
        $l = Location::create(['warehouse_id' => $w->id, 'name' => 'A-01', 'code' => 'A-01', 'status' => 1]);
        InventoryBalance::create([
            'product_id' => $p->id,
            'warehouse_id' => $w->id,
            'location_id' => $l->id,
            'quantity' => 1,
        ]);
        $this->withToken($this->token)->deleteJson("/api/v1/locations/{$l->id}")
            ->assertJsonPath('code', 1107);
    }

    public function test_destroy_location_with_purchase_inbound_reference_fails(): void
    {
        // 边界路径：purchase_inbounds 引用该库位时删除被拒 1107（入库单引用同码保护）
        $w = Warehouse::create(['name' => '测试仓', 'code' => 'WH02', 'status' => 1]);
        $l = Location::create(['warehouse_id' => $w->id, 'name' => 'A-01', 'code' => 'A-01', 'status' => 1]);
        $s = Supplier::create(['name' => '测试供应商', 'code' => 'SUP-001', 'status' => 1]);
        DB::table('purchase_inbounds')->insert([
            'no' => 'PI-TEST-001', 'supplier_id' => $s->id, 'warehouse_id' => $w->id, 'location_id' => $l->id,
            'status' => 0, 'total_amount' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->withToken($this->token)->deleteJson("/api/v1/locations/{$l->id}")
            ->assertJsonPath('code', 1107);
    }

    public function test_destroy_warehouse_with_sales_outbound_reference_fails(): void
    {
        // 边界路径：sales_outbounds 引用该仓库时删除被拒 1106（出库单引用同码保护）
        $w = Warehouse::create(['name' => '测试仓', 'code' => 'WH02', 'status' => 1]);
        $l = Location::create(['warehouse_id' => $w->id, 'name' => 'A-01', 'code' => 'A-01', 'status' => 1]);
        $c = Customer::create(['name' => '测试客户', 'code' => 'CUS-001', 'status' => 1]);
        DB::table('sales_outbounds')->insert([
            'no' => 'SOUT-TEST-001', 'customer_id' => $c->id, 'warehouse_id' => $w->id, 'location_id' => $l->id,
            'status' => 0, 'total_amount' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->withToken($this->token)->deleteJson("/api/v1/warehouses/{$w->id}")
            ->assertJsonPath('code', 1106);
    }

    public function test_destroy_location_with_sales_outbound_reference_fails(): void
    {
        // 边界路径：sales_outbounds 引用该库位时删除被拒 1107（出库单引用同码保护）
        $w = Warehouse::create(['name' => '测试仓', 'code' => 'WH02', 'status' => 1]);
        $l = Location::create(['warehouse_id' => $w->id, 'name' => 'A-01', 'code' => 'A-01', 'status' => 1]);
        $c = Customer::create(['name' => '测试客户', 'code' => 'CUS-001', 'status' => 1]);
        DB::table('sales_outbounds')->insert([
            'no' => 'SOUT-TEST-001', 'customer_id' => $c->id, 'warehouse_id' => $w->id, 'location_id' => $l->id,
            'status' => 0, 'total_amount' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->withToken($this->token)->deleteJson("/api/v1/locations/{$l->id}")
            ->assertJsonPath('code', 1107);
    }

    /**
     * 构造余额引用所需商品（分类/单位外键齐全），供真实余额表的守卫测试复用
     */
    private function createBalanceProduct(): Product
    {
        $category = Category::create(['name' => '原材料', 'parent_id' => 0, 'sort' => 1, 'status' => 1]);
        $unit = Unit::create(['name' => '个', 'code' => 'pc', 'status' => 1]);

        return Product::create([
            'name' => '铝材', 'code' => 'RAW-001', 'type' => 'raw_material',
            'category_id' => $category->id, 'unit_id' => $unit->id, 'spec' => '1mm',
            'barcode' => null, 'safety_min' => 10, 'safety_max' => 100, 'status' => 1,
        ]);
    }

    // ---------- B08 删除保护缺口：盘点单与生产单据引用表 ----------

    public function test_destroy_warehouse_with_check_reference_fails(): void
    {
        // 异常路径（B08）：被草稿盘点单引用的仓库不可删 1106（守卫补齐 inventory_checks）
        $w = Warehouse::create(['name' => '测试仓', 'code' => 'WH02', 'status' => 1]);
        DB::table('inventory_checks')->insert([
            'no' => 'CK-TEST-001', 'warehouse_id' => $w->id, 'status' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->withToken($this->token)->deleteJson("/api/v1/warehouses/{$w->id}")
            ->assertJsonPath('code', 1106);
        $this->assertDatabaseHas('warehouses', ['id' => $w->id]);
    }

    public function test_destroy_location_with_check_item_reference_fails(): void
    {
        // 异常路径（B08）：被盘点明细引用的库位不可删 1107（守卫补齐 inventory_check_items）
        $p = $this->createBalanceProduct();
        $w = Warehouse::create(['name' => '测试仓', 'code' => 'WH02', 'status' => 1]);
        $l = Location::create(['warehouse_id' => $w->id, 'name' => 'A-01', 'code' => 'A-01', 'status' => 1]);
        $checkId = DB::table('inventory_checks')->insertGetId([
            'no' => 'CK-TEST-001', 'warehouse_id' => $w->id, 'status' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('inventory_check_items')->insert([
            'check_id' => $checkId, 'product_id' => $p->id, 'location_id' => $l->id,
            'book_qty' => 0, 'actual_qty' => 1, 'diff_qty' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->withToken($this->token)->deleteJson("/api/v1/locations/{$l->id}")
            ->assertJsonPath('code', 1107);
        $this->assertDatabaseHas('locations', ['id' => $l->id]);
    }

    public function test_destroy_warehouse_with_pick_list_reference_fails(): void
    {
        // 边界路径（B08）：被领料单引用的仓库不可删 1106（生产单据表落地后守卫自动生效）
        $w = Warehouse::create(['name' => '测试仓', 'code' => 'WH02', 'status' => 1]);
        $l = Location::create(['warehouse_id' => $w->id, 'name' => 'A-01', 'code' => 'A-01', 'status' => 1]);
        $orderId = $this->seedProductionOrder();
        DB::table('pick_lists')->insert([
            'no' => 'PL-TEST-001', 'order_id' => $orderId, 'warehouse_id' => $w->id, 'location_id' => $l->id,
            'status' => 0, 'issue_status' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->withToken($this->token)->deleteJson("/api/v1/warehouses/{$w->id}")
            ->assertJsonPath('code', 1106);
    }

    public function test_destroy_location_with_finished_inbound_reference_fails(): void
    {
        // 边界路径（B08）：被成品入库单引用的库位不可删 1107（生产单据表落地后守卫自动生效）
        $w = Warehouse::create(['name' => '测试仓', 'code' => 'WH02', 'status' => 1]);
        $l = Location::create(['warehouse_id' => $w->id, 'name' => 'B-01', 'code' => 'B-01', 'status' => 1]);
        $orderId = $this->seedProductionOrder();
        DB::table('finished_inbounds')->insert([
            'no' => 'FI-TEST-001', 'order_id' => $orderId, 'warehouse_id' => $w->id, 'location_id' => $l->id,
            'status' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->withToken($this->token)->deleteJson("/api/v1/locations/{$l->id}")
            ->assertJsonPath('code', 1107);
    }

    /** 构造成品 + BOM + 生产工单，返回工单 ID（生产单据外键链依赖） */
    private function seedProductionOrder(): int
    {
        $category = Category::create(['name' => '成品', 'parent_id' => 0, 'sort' => 2, 'status' => 1]);
        $unit = Unit::create(['name' => '个', 'code' => 'pc2', 'status' => 1]);
        $fin = Product::create([
            'name' => '成品B', 'code' => 'FIN-002', 'type' => 'finished',
            'category_id' => $category->id, 'unit_id' => $unit->id, 'status' => 1,
        ]);
        $bom = BomHeader::create([
            'code' => 'BOM-TEST-002', 'product_id' => $fin->id, 'version' => 'v1', 'quantity' => 1, 'status' => 1,
        ]);

        return DB::table('production_orders')->insertGetId([
            'no' => 'MO-TEST-002', 'product_id' => $fin->id, 'quantity' => 10,
            'plan_date' => now()->toDateString(), 'bom_id' => $bom->id, 'status' => 0,
            'completed_qty' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
