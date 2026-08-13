<?php

// 商品接口测试：CRUD/筛选/编码条码唯一/上下限校验/扫码查询/删除保护（正常+边界+异常）

namespace Tests\Feature;

use App\Models\BomHeader;
use App\Models\BomItem;
use App\Models\Category;
use App\Models\Customer;
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

class ProductTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    private Category $rawCat;

    private Category $finCat;

    private Unit $unit;

    protected function setUp(): void
    {
        parent::setUp();
        $role = Role::create(['name' => '管理员', 'code' => 'admin']);
        $u = User::create(['name' => '管理员', 'username' => 'admin', 'password' => 'admin123', 'status' => 1]);
        $u->roles()->sync([$role->id]);
        $this->token = $u->createToken('api')->plainTextToken;
        $this->rawCat = Category::create(['name' => '原材料', 'parent_id' => 0]);
        $this->finCat = Category::create(['name' => '成品', 'parent_id' => 0]);
        $this->unit = Unit::create(['name' => '个', 'code' => 'pc']);
    }

    public function test_index_filters_by_keyword_type_and_category(): void
    {
        // 正常路径：关键字/类型/分类组合过滤
        Product::create([
            'name' => '铝材',
            'code' => 'RAW-001',
            'type' => 'raw_material',
            'category_id' => $this->rawCat->id,
            'unit_id' => $this->unit->id,
            'status' => 1,
        ]);
        Product::create([
            'name' => '成品A',
            'code' => 'FIN-001',
            'type' => 'finished',
            'category_id' => $this->finCat->id,
            'unit_id' => $this->unit->id,
            'status' => 1,
        ]);
        $this->withToken($this->token)->getJson('/api/v1/products?keyword=RAW-001&type=raw_material')
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.items.0.type_label', '原料');
    }

    public function test_store_creates_product_with_unit_name(): void
    {
        // 正常路径：创建成功且响应带 unit_name
        $this->withToken($this->token)->postJson('/api/v1/products', [
            'name' => '测试铝材', 'code' => 'MAT-001', 'type' => 'raw_material',
            'category_id' => $this->rawCat->id, 'unit_id' => $this->unit->id, 'spec' => '1mm',
            'barcode' => '999999', 'safety_min' => 10, 'safety_max' => 100, 'status' => 1,
        ])->assertJsonPath('code', 0);
        $this->assertDatabaseHas('products', ['code' => 'MAT-001']);
    }

    public function test_store_duplicate_code_fails_with_1114(): void
    {
        // 异常路径：编码重复 1114
        Product::create([
            'name' => '铝材',
            'code' => 'RAW-001',
            'type' => 'raw_material',
            'category_id' => $this->rawCat->id,
            'unit_id' => $this->unit->id,
            'status' => 1,
        ]);
        $this->withToken($this->token)->postJson('/api/v1/products', [
            'name' => '重复', 'code' => 'RAW-001', 'type' => 'raw_material',
            'category_id' => $this->rawCat->id, 'unit_id' => $this->unit->id, 'status' => 1,
        ])->assertJsonPath('code', 1114);
    }

    public function test_store_duplicate_barcode_fails_with_1115(): void
    {
        // 异常路径：条码重复 1115
        Product::create([
            'name' => '铝材',
            'code' => 'RAW-001',
            'type' => 'raw_material',
            'category_id' => $this->rawCat->id,
            'unit_id' => $this->unit->id,
            'barcode' => '888888',
            'status' => 1,
        ]);
        $this->withToken($this->token)->postJson('/api/v1/products', [
            'name' => '重复', 'code' => 'MAT-002', 'type' => 'raw_material',
            'category_id' => $this->rawCat->id, 'unit_id' => $this->unit->id, 'barcode' => '888888', 'status' => 1,
        ])->assertJsonPath('code', 1115);
    }

    public function test_store_min_greater_than_max_fails_with_1122(): void
    {
        // 异常路径：安全库存下限大于上限 1122
        $this->withToken($this->token)->postJson('/api/v1/products', [
            'name' => '异常', 'code' => 'MAT-003', 'type' => 'raw_material',
            'category_id' => $this->rawCat->id, 'unit_id' => $this->unit->id,
            'safety_min' => 200, 'safety_max' => 100, 'status' => 1,
        ])->assertJsonPath('code', 1122);
    }

    public function test_store_min_greater_than_zero_max_ok_when_max_is_zero(): void
    {
        // 边界路径：上限 0=不预警该侧，下限可大于上限，创建成功（与 1122 拦截用例互补）
        $this->withToken($this->token)->postJson('/api/v1/products', [
            'name' => '只设下限', 'code' => 'MAT-004', 'type' => 'raw_material',
            'category_id' => $this->rawCat->id, 'unit_id' => $this->unit->id,
            'safety_min' => 500, 'safety_max' => 0, 'status' => 1,
        ])->assertJsonPath('code', 0);
        $this->assertDatabaseHas('products', ['code' => 'MAT-004', 'safety_min' => '500.00', 'safety_max' => '0.00']);
    }

    public function test_update_product_keeps_unit_name(): void
    {
        // 正常路径：更新规格与上下限
        $p = Product::create([
            'name' => '铝材',
            'code' => 'RAW-001',
            'type' => 'raw_material',
            'category_id' => $this->rawCat->id,
            'unit_id' => $this->unit->id,
            'status' => 1,
        ]);
        $this->withToken($this->token)->putJson("/api/v1/products/{$p->id}", [
            'name' => '铝材2', 'code' => 'RAW-001', 'type' => 'raw_material',
            'category_id' => $this->rawCat->id, 'unit_id' => $this->unit->id, 'spec' => '2mm',
            'safety_min' => 5, 'safety_max' => 50, 'status' => 1,
        ])->assertJsonPath('code', 0);
        $this->assertDatabaseHas('products', ['id' => $p->id, 'name' => '铝材2', 'spec' => '2mm']);
    }

    public function test_by_barcode_returns_product_info(): void
    {
        // 正常路径：扫码命中返回商品信息
        Product::create([
            'name' => '成品B',
            'code' => 'FIN-002',
            'type' => 'finished',
            'category_id' => $this->finCat->id,
            'unit_id' => $this->unit->id,
            'barcode' => '888888',
            'status' => 1,
        ]);
        $this->withToken($this->token)->getJson('/api/v1/products/barcode/888888')
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.name', '成品B')
            ->assertJsonPath('data.type', 'finished');
    }

    public function test_by_barcode_not_found_fails_with_1117(): void
    {
        // 异常路径：未知条码 1117
        $this->withToken($this->token)->getJson('/api/v1/products/barcode/000000')
            ->assertJsonPath('code', 1117);
    }

    public function test_destroy_referenced_by_bom_fails_with_1116(): void
    {
        // 异常路径：被 BOM 明细引用的商品不可删 1116
        $material = Product::create([
            'name' => '铝材',
            'code' => 'RAW-001',
            'type' => 'raw_material',
            'category_id' => $this->rawCat->id,
            'unit_id' => $this->unit->id,
            'status' => 1,
        ]);
        $fin = Product::create([
            'name' => '成品A',
            'code' => 'FIN-001',
            'type' => 'finished',
            'category_id' => $this->finCat->id,
            'unit_id' => $this->unit->id,
            'status' => 1,
        ]);
        $bom = BomHeader::create([
            'code' => 'BOM20260812-001',
            'product_id' => $fin->id,
            'version' => 'v1',
            'quantity' => 1,
            'status' => 1,
        ]);
        BomItem::create([
            'bom_header_id' => $bom->id,
            'material_id' => $material->id,
            'quantity' => 2,
            'unit_id' => $this->unit->id,
        ]);
        $this->withToken($this->token)->deleteJson("/api/v1/products/{$material->id}")
            ->assertJsonPath('code', 1116);
    }

    public function test_destroy_referenced_by_purchase_inbound_fails_with_1116(): void
    {
        // 异常路径：被采购入库单明细引用的商品不可删 1116（采购模块表落地后守卫自动生效）
        $p = Product::create([
            'name' => '铝材',
            'code' => 'RAW-001',
            'type' => 'raw_material',
            'category_id' => $this->rawCat->id,
            'unit_id' => $this->unit->id,
            'status' => 1,
        ]);
        $s = Supplier::create(['name' => '测试供应商', 'code' => 'SUP-001', 'status' => 1]);
        $w = Warehouse::create(['name' => '测试仓', 'code' => 'WH01', 'status' => 1]);
        $l = Location::create(['warehouse_id' => $w->id, 'name' => 'A-01', 'code' => 'A-01', 'status' => 1]);
        DB::table('purchase_inbounds')->insert([
            'no' => 'PI-TEST-001', 'supplier_id' => $s->id, 'warehouse_id' => $w->id, 'location_id' => $l->id,
            'status' => 0, 'total_amount' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('purchase_inbound_items')->insert([
            'inbound_id' => DB::table('purchase_inbounds')->value('id'), 'product_id' => $p->id,
            'quantity' => 1, 'price' => 100, 'amount' => 100, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->withToken($this->token)->deleteJson("/api/v1/products/{$p->id}")
            ->assertJsonPath('code', 1116);
    }

    public function test_destroy_referenced_by_sales_outbound_item_fails_with_1116(): void
    {
        // 边界路径：sales_outbound_items 引用该商品时删除被拒 1116（出库单明细引用同码保护）
        $p = Product::create([
            'name' => '铝材',
            'code' => 'RAW-001',
            'type' => 'raw_material',
            'category_id' => $this->rawCat->id,
            'unit_id' => $this->unit->id,
            'status' => 1,
        ]);
        $c = Customer::create(['name' => '测试客户', 'code' => 'CUS-001', 'status' => 1]);
        $w = Warehouse::create(['name' => '测试仓', 'code' => 'WH02', 'status' => 1]);
        $l = Location::create(['warehouse_id' => $w->id, 'name' => 'A-01', 'code' => 'A-01', 'status' => 1]);
        $outboundId = DB::table('sales_outbounds')->insertGetId([
            'no' => 'SOUT-TEST-001', 'customer_id' => $c->id, 'warehouse_id' => $w->id, 'location_id' => $l->id,
            'status' => 0, 'total_amount' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('sales_outbound_items')->insert([
            'outbound_id' => $outboundId, 'product_id' => $p->id,
            'quantity' => 1, 'price' => 100, 'amount' => 100, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->withToken($this->token)->deleteJson("/api/v1/products/{$p->id}")
            ->assertJsonPath('code', 1116);
    }

    public function test_destroy_unreferenced_product_succeeds(): void
    {
        // 正常路径：未被引用的商品可删
        $p = Product::create([
            'name' => '临时',
            'code' => 'TMP-001',
            'type' => 'raw_material',
            'category_id' => $this->rawCat->id,
            'unit_id' => $this->unit->id,
            'status' => 1,
        ]);
        $this->withToken($this->token)->deleteJson("/api/v1/products/{$p->id}")->assertJsonPath('code', 0);
        $this->assertDatabaseMissing('products', ['id' => $p->id]);
    }

    public function test_destroy_referenced_by_production_material_fails_with_1116(): void
    {
        // 边界路径：production_order_materials 引用该物料时删除被拒 1116（工单物料快照引用保护）
        $p = Product::create([
            'name' => '铝材',
            'code' => 'RAW-001',
            'type' => 'raw_material',
            'category_id' => $this->rawCat->id,
            'unit_id' => $this->unit->id,
            'status' => 1,
        ]);
        $cat = Category::create(['name' => '成品', 'parent_id' => 0]);
        $fin = Product::create(['name' => '成品B', 'code' => 'FIN-002', 'type' => 'finished', 'category_id' => $cat->id, 'unit_id' => $this->unit->id, 'status' => 1]);
        $bom = BomHeader::create(['code' => 'BOM-TEST-001', 'product_id' => $fin->id, 'version' => 'v1', 'quantity' => 1, 'status' => 1]);
        $orderId = DB::table('production_orders')->insertGetId([
            'no' => 'MO-TEST-001', 'product_id' => $fin->id, 'quantity' => 10,
            'plan_date' => now()->toDateString(), 'bom_id' => $bom->id, 'status' => 0,
            'completed_qty' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('production_order_materials')->insert([
            'order_id' => $orderId, 'material_id' => $p->id, 'required_qty' => 20,
            'issued_qty' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->withToken($this->token)->deleteJson("/api/v1/products/{$p->id}")
            ->assertJsonPath('code', 1116);
    }

    // ---------- B05 删除保护缺口：盘点明细与生产单据明细 4 表 ----------

    public function test_destroy_referenced_by_check_item_fails_with_1116(): void
    {
        // 异常路径（B05）：被草稿盘点单明细引用的商品不可删 1116（守卫补齐 inventory_check_items）
        $p = Product::create([
            'name' => '铝材',
            'code' => 'RAW-001',
            'type' => 'raw_material',
            'category_id' => $this->rawCat->id,
            'unit_id' => $this->unit->id,
            'status' => 1,
        ]);
        $w = Warehouse::create(['name' => '测试仓', 'code' => 'WH03', 'status' => 1]);
        $l = Location::create(['warehouse_id' => $w->id, 'name' => 'A-01', 'code' => 'A-01', 'status' => 1]);
        $checkId = DB::table('inventory_checks')->insertGetId([
            'no' => 'CK-TEST-001', 'warehouse_id' => $w->id, 'status' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('inventory_check_items')->insert([
            'check_id' => $checkId, 'product_id' => $p->id, 'location_id' => $l->id,
            'book_qty' => 0, 'actual_qty' => 5, 'diff_qty' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->withToken($this->token)->deleteJson("/api/v1/products/{$p->id}")
            ->assertJsonPath('code', 1116);
    }

    public function test_destroy_referenced_by_pick_list_item_fails_with_1116(): void
    {
        // 边界路径（B05）：被领料单明细引用的物料不可删 1116（生产单据表落地后守卫自动生效）
        $p = $this->rawMaterial();
        $orderId = $this->seedProductionOrder();
        $w = Warehouse::create(['name' => '测试仓', 'code' => 'WH03', 'status' => 1]);
        $l = Location::create(['warehouse_id' => $w->id, 'name' => 'A-01', 'code' => 'A-01', 'status' => 1]);
        $pickId = DB::table('pick_lists')->insertGetId([
            'no' => 'PL-TEST-001', 'order_id' => $orderId, 'warehouse_id' => $w->id, 'location_id' => $l->id,
            'status' => 0, 'issue_status' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('pick_list_items')->insert([
            'pick_id' => $pickId, 'product_id' => $p->id, 'required_qty' => 2, 'pick_qty' => 2,
            'issued_qty' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->withToken($this->token)->deleteJson("/api/v1/products/{$p->id}")
            ->assertJsonPath('code', 1116);
    }

    public function test_destroy_referenced_by_return_list_item_fails_with_1116(): void
    {
        // 边界路径（B05）：被退料单明细引用的物料不可删 1116（生产单据表落地后守卫自动生效）
        $p = $this->rawMaterial();
        $orderId = $this->seedProductionOrder();
        $w = Warehouse::create(['name' => '测试仓', 'code' => 'WH03', 'status' => 1]);
        $l = Location::create(['warehouse_id' => $w->id, 'name' => 'A-01', 'code' => 'A-01', 'status' => 1]);
        $returnId = DB::table('return_lists')->insertGetId([
            'no' => 'RL-TEST-001', 'order_id' => $orderId, 'pick_id' => null, 'warehouse_id' => $w->id,
            'location_id' => $l->id, 'status' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('return_list_items')->insert([
            'return_id' => $returnId, 'product_id' => $p->id, 'quantity' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->withToken($this->token)->deleteJson("/api/v1/products/{$p->id}")
            ->assertJsonPath('code', 1116);
    }

    public function test_destroy_referenced_by_finished_inbound_item_fails_with_1116(): void
    {
        // 边界路径（B05）：被成品入库单明细引用的商品不可删 1116（生产单据表落地后守卫自动生效）
        $p = $this->rawMaterial();
        $orderId = $this->seedProductionOrder();
        $w = Warehouse::create(['name' => '测试仓', 'code' => 'WH03', 'status' => 1]);
        $l = Location::create(['warehouse_id' => $w->id, 'name' => 'B-01', 'code' => 'B-01', 'status' => 1]);
        $inboundId = DB::table('finished_inbounds')->insertGetId([
            'no' => 'FI-TEST-001', 'order_id' => $orderId, 'warehouse_id' => $w->id, 'location_id' => $l->id,
            'status' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('finished_inbound_items')->insert([
            'finished_inbound_id' => $inboundId, 'product_id' => $p->id, 'quantity' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->withToken($this->token)->deleteJson("/api/v1/products/{$p->id}")
            ->assertJsonPath('code', 1116);
    }

    /** 构造原料商品（分类/单位齐全），供生产单据明细引用测试复用 */
    private function rawMaterial(): Product
    {
        return Product::create([
            'name' => '铝材',
            'code' => 'RAW-001',
            'type' => 'raw_material',
            'category_id' => $this->rawCat->id,
            'unit_id' => $this->unit->id,
            'status' => 1,
        ]);
    }

    /** 构造成品 + BOM + 生产工单，返回工单 ID（生产单据外键链依赖） */
    private function seedProductionOrder(): int
    {
        $fin = Product::create([
            'name' => '成品B', 'code' => 'FIN-002', 'type' => 'finished',
            'category_id' => $this->finCat->id, 'unit_id' => $this->unit->id, 'status' => 1,
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
