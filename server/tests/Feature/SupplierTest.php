<?php

// 供应商接口测试：CRUD/搜索/编码唯一/删除保护（正常+边界+异常）

namespace Tests\Feature;

use App\Models\BomHeader;
use App\Models\Category;
use App\Models\Location;
use App\Models\Process;
use App\Models\Product;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SupplierTest extends TestCase
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

    public function test_index_keyword_search_filters_name_and_code(): void
    {
        // 正常路径：关键字按名称/编码/联系人模糊过滤
        Supplier::create([
            'name' => '测试供应商',
            'code' => 'SUP-001',
            'contact' => '张三',
            'phone' => '13800000000',
            'status' => 1,
        ]);
        Supplier::create(['name' => '其他供应商', 'code' => 'SUP-002', 'contact' => '李四', 'status' => 1]);
        $this->withToken($this->token)->getJson('/api/v1/suppliers?keyword=测试')
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.items.0.code', 'SUP-001');
    }

    public function test_store_and_duplicate_code_fails_with_1108(): void
    {
        // 正常路径：创建成功（含四级地址 + 详细地址落库）
        $this->withToken($this->token)->postJson('/api/v1/suppliers', [
            'name' => '测试供应商',
            'code' => 'SUP-001',
            'contact' => '张三',
            'phone' => '13800000000',
            'province' => '浙江省',
            'city' => '杭州市',
            'district' => '西湖区',
            'town' => '三墩镇',
            'address' => '工业园1号',
            'status' => 1,
        ])
            ->assertJsonPath('code', 0);
        $this->assertDatabaseHas('suppliers', [
            'code' => 'SUP-001',
            'province' => '浙江省', 'city' => '杭州市', 'district' => '西湖区', 'town' => '三墩镇',
            'address' => '工业园1号',
        ]);
        // 异常路径：重复编码 1108
        $this->withToken($this->token)->postJson('/api/v1/suppliers', ['name' => '重复', 'code' => 'SUP-001'])
            ->assertJsonPath('code', 1108);
    }

    public function test_store_without_region_fields_stores_null(): void
    {
        // 边界路径：不传四级地址（仅详细地址）时各列均为 null，保证可空
        $this->withToken($this->token)->postJson('/api/v1/suppliers', [
            'name' => '无区域供应商',
            'code' => 'SUP-002',
            'address' => '某街巷 12 号',
            'status' => 1,
        ])
            ->assertJsonPath('code', 0);
        $this->assertDatabaseHas('suppliers', [
            'code' => 'SUP-002',
            'province' => null, 'city' => null, 'district' => null, 'town' => null,
            'address' => '某街巷 12 号',
        ]);
    }

    public function test_update_contact_and_phone(): void
    {
        // 正常路径：更新联系人电话
        $s = Supplier::create(['name' => '测试供应商', 'code' => 'SUP-001', 'contact' => '张三', 'status' => 1]);
        $this->withToken($this->token)->putJson("/api/v1/suppliers/{$s->id}", [
            'name' => '测试供应商',
            'code' => 'SUP-001',
            'contact' => '王五',
            'phone' => '13900000000',
            'status' => 1,
        ])
            ->assertJsonPath('code', 0);
        $this->assertDatabaseHas('suppliers', ['id' => $s->id, 'contact' => '王五', 'phone' => '13900000000']);
    }

    public function test_destroy_succeeds_when_purchase_tables_missing(): void
    {
        // 边界路径：采购模块表未建（守卫放行），供应商可删
        $s = Supplier::create(['name' => '测试供应商', 'code' => 'SUP-001', 'status' => 1]);
        $this->withToken($this->token)->deleteJson("/api/v1/suppliers/{$s->id}")->assertJsonPath('code', 0);
        $this->assertDatabaseMissing('suppliers', ['id' => $s->id]);
    }

    public function test_destroy_with_purchase_order_reference_fails(): void
    {
        // 边界路径：purchase_orders 有引用时删除被拒 1109（本 Task 迁移落地后直接使用真实表）
        $s = Supplier::create(['name' => '测试供应商', 'code' => 'SUP-001', 'status' => 1]);
        DB::table('purchase_orders')->insert([
            'no' => 'PO-TEST-001', 'supplier_id' => $s->id, 'order_date' => now()->toDateString(),
            'status' => 0, 'total_amount' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->withToken($this->token)->deleteJson("/api/v1/suppliers/{$s->id}")
            ->assertJsonPath('code', 1109);
    }

    public function test_destroy_with_purchase_inbound_reference_fails(): void
    {
        // 边界路径：purchase_inbounds 有引用时删除被拒 1109（入库单引用同码保护）
        $s = Supplier::create(['name' => '测试供应商', 'code' => 'SUP-001', 'status' => 1]);
        $w = Warehouse::create(['name' => '测试仓', 'code' => 'WH01', 'status' => 1]);
        $l = Location::create(['warehouse_id' => $w->id, 'name' => 'A-01', 'code' => 'A-01', 'status' => 1]);
        DB::table('purchase_inbounds')->insert([
            'no' => 'PI-TEST-001', 'supplier_id' => $s->id, 'warehouse_id' => $w->id, 'location_id' => $l->id,
            'status' => 0, 'total_amount' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->withToken($this->token)->deleteJson("/api/v1/suppliers/{$s->id}")
            ->assertJsonPath('code', 1109);
    }

    public function test_destroy_with_outsourcing_order_reference_fails(): void
    {
        // 边界路径（B10）：outsourcing_orders 引用该供应商时删除被拒 1109（委外模块落地后守卫自动生效）
        $s = Supplier::create(['name' => '测试供应商', 'code' => 'SUP-001', 'status' => 1]);
        $w = Warehouse::create(['name' => '测试仓', 'code' => 'WH01', 'status' => 1]);
        $l = Location::create(['warehouse_id' => $w->id, 'name' => 'A-01', 'code' => 'A-01', 'status' => 1]);
        // 委外单外键链：成品 → BOM → 工单 → 工序 → 委外单
        $category = Category::create(['name' => '成品', 'parent_id' => 0, 'sort' => 1, 'status' => 1]);
        $unit = Unit::create(['name' => '个', 'code' => 'pc', 'status' => 1]);
        $fin = Product::create([
            'name' => '成品B', 'code' => 'FIN-002', 'type' => 'finished',
            'category_id' => $category->id, 'unit_id' => $unit->id, 'status' => 1,
        ]);
        $bom = BomHeader::create([
            'code' => 'BOM-TEST-001', 'product_id' => $fin->id, 'version' => 'v1', 'quantity' => 1, 'status' => 1,
        ]);
        $orderId = DB::table('production_orders')->insertGetId([
            'no' => 'MO-TEST-001', 'product_id' => $fin->id, 'quantity' => 10,
            'plan_date' => now()->toDateString(), 'bom_id' => $bom->id, 'status' => 0,
            'completed_qty' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $process = Process::create(['name' => '机加工', 'code' => 'P001', 'sort' => 1, 'status' => 1]);
        $opId = DB::table('work_order_operations')->insertGetId([
            'order_id' => $orderId, 'process_id' => $process->id, 'seq' => 1, 'status' => 0,
            'qualified_qty' => 0, 'defective_qty' => 0, 'hours' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('outsourcing_orders')->insert([
            'no' => 'OS-TEST-001', 'order_id' => $orderId, 'operation_id' => $opId, 'supplier_id' => $s->id,
            'warehouse_id' => $w->id, 'location_id' => $l->id, 'status' => 0, 'quantity' => 5,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->withToken($this->token)->deleteJson("/api/v1/suppliers/{$s->id}")
            ->assertJsonPath('code', 1109);
        $this->assertDatabaseHas('suppliers', ['id' => $s->id]);
    }
}
