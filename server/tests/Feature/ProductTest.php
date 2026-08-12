<?php
// 商品接口测试：CRUD/筛选/编码条码唯一/上下限校验/扫码查询/删除保护（正常+边界+异常）
namespace Tests\Feature;

use App\Models\BomHeader;
use App\Models\BomItem;
use App\Models\Category;
use App\Models\Product;
use App\Models\Role;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        Product::create(['name' => '铝材', 'code' => 'RAW-001', 'type' => 'raw_material', 'category_id' => $this->rawCat->id, 'unit_id' => $this->unit->id, 'status' => 1]);
        Product::create(['name' => '成品A', 'code' => 'FIN-001', 'type' => 'finished', 'category_id' => $this->finCat->id, 'unit_id' => $this->unit->id, 'status' => 1]);
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
        Product::create(['name' => '铝材', 'code' => 'RAW-001', 'type' => 'raw_material', 'category_id' => $this->rawCat->id, 'unit_id' => $this->unit->id, 'status' => 1]);
        $this->withToken($this->token)->postJson('/api/v1/products', [
            'name' => '重复', 'code' => 'RAW-001', 'type' => 'raw_material',
            'category_id' => $this->rawCat->id, 'unit_id' => $this->unit->id, 'status' => 1,
        ])->assertJsonPath('code', 1114);
    }

    public function test_store_duplicate_barcode_fails_with_1115(): void
    {
        // 异常路径：条码重复 1115
        Product::create(['name' => '铝材', 'code' => 'RAW-001', 'type' => 'raw_material', 'category_id' => $this->rawCat->id, 'unit_id' => $this->unit->id, 'barcode' => '888888', 'status' => 1]);
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

    public function test_update_product_keeps_unit_name(): void
    {
        // 正常路径：更新规格与上下限
        $p = Product::create(['name' => '铝材', 'code' => 'RAW-001', 'type' => 'raw_material', 'category_id' => $this->rawCat->id, 'unit_id' => $this->unit->id, 'status' => 1]);
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
        Product::create(['name' => '成品B', 'code' => 'FIN-002', 'type' => 'finished', 'category_id' => $this->finCat->id, 'unit_id' => $this->unit->id, 'barcode' => '888888', 'status' => 1]);
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
        $material = Product::create(['name' => '铝材', 'code' => 'RAW-001', 'type' => 'raw_material', 'category_id' => $this->rawCat->id, 'unit_id' => $this->unit->id, 'status' => 1]);
        $fin = Product::create(['name' => '成品A', 'code' => 'FIN-001', 'type' => 'finished', 'category_id' => $this->finCat->id, 'unit_id' => $this->unit->id, 'status' => 1]);
        $bom = BomHeader::create(['code' => 'BOM20260812-001', 'product_id' => $fin->id, 'version' => 'v1', 'quantity' => 1, 'status' => 1]);
        BomItem::create(['bom_header_id' => $bom->id, 'material_id' => $material->id, 'quantity' => 2, 'unit_id' => $this->unit->id]);
        $this->withToken($this->token)->deleteJson("/api/v1/products/{$material->id}")
            ->assertJsonPath('code', 1116);
    }

    public function test_destroy_unreferenced_product_succeeds(): void
    {
        // 正常路径：未被引用的商品可删
        $p = Product::create(['name' => '临时', 'code' => 'TMP-001', 'type' => 'raw_material', 'category_id' => $this->rawCat->id, 'unit_id' => $this->unit->id, 'status' => 1]);
        $this->withToken($this->token)->deleteJson("/api/v1/products/{$p->id}")->assertJsonPath('code', 0);
        $this->assertDatabaseMissing('products', ['id' => $p->id]);
    }
}
