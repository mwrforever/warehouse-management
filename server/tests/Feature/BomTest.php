<?php

// BOM 接口测试：单头+明细事务/类型校验/启用版本唯一/启用切换/删除（正常+边界+异常）

namespace Tests\Feature;

use App\Models\BomHeader;
use App\Models\Category;
use App\Models\Product;
use App\Models\Role;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\DocumentNumberConfigSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BomTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    private Category $rawCat;

    private Category $finCat;

    private Unit $unit;

    private Product $material;

    private Product $finished;

    protected function setUp(): void
    {
        parent::setUp();
        // 编号规则配置种子（Spec 2）：单据号按配置生成 CK/PO/MO 等业务前缀
        $this->seed(DocumentNumberConfigSeeder::class);
        $role = Role::create(['name' => '管理员', 'code' => 'admin']);
        $u = User::create(['name' => '管理员', 'username' => 'admin', 'password' => 'admin123', 'status' => 1]);
        $u->roles()->sync([$role->id]);
        $this->token = $u->createToken('api')->plainTextToken;
        $this->rawCat = Category::create(['name' => '原材料', 'parent_id' => 0]);
        $this->finCat = Category::create(['name' => '成品', 'parent_id' => 0]);
        $this->unit = Unit::create(['name' => '个', 'code' => 'pc']);
        $this->material = Product::create([
            'name' => '铝材',
            'code' => 'MAT-001',
            'type' => 'raw_material',
            'category_id' => $this->rawCat->id,
            'unit_id' => $this->unit->id,
            'status' => 1,
        ]);
        $this->finished = Product::create([
            'name' => '成品B',
            'code' => 'FIN-002',
            'type' => 'finished',
            'category_id' => $this->finCat->id,
            'unit_id' => $this->unit->id,
            'status' => 1,
        ]);
    }

    public function test_store_creates_header_and_items_in_one_submit(): void
    {
        // 正常路径：单头+明细一次提交成功，单号格式 BOM{date}-{seq}
        $res = $this->withToken($this->token)->postJson('/api/v1/boms', [
            'product_id' => $this->finished->id, 'version' => 'v1', 'quantity' => 1, 'remark' => '',
            'items' => [['material_id' => $this->material->id, 'quantity' => 2, 'unit_id' => $this->unit->id]],
        ]);
        $res->assertJsonPath('code', 0);
        $code = $res->json('data.code');
        $this->assertMatchesRegularExpression('/^BOM\d{12}\d{3}$/', $code);
        $this->assertDatabaseCount('bom_items', 1);
        $this->assertDatabaseHas('bom_headers', ['code' => $code, 'status' => 1]);
    }

    public function test_store_product_not_finished_fails_with_1118(): void
    {
        // 异常路径：BOM 关联商品不是成品 1118（信封契约：code/message/data 统一结构，D-12）
        $this->withToken($this->token)->postJson('/api/v1/boms', [
            'product_id' => $this->material->id, 'version' => 'v1', 'quantity' => 1,
            'items' => [['material_id' => $this->material->id, 'quantity' => 1, 'unit_id' => $this->unit->id]],
        ])
            ->assertStatus(200)
            ->assertJsonPath('code', 1118)
            ->assertJsonPath('message', 'BOM 关联商品必须是成品')
            ->assertJsonPath('data', null);
    }

    public function test_store_material_is_finished_fails_with_1119(): void
    {
        // 异常路径：明细物料是成品（不允许成品嵌套）1119（信封契约同 1118，D-12）
        $this->withToken($this->token)->postJson('/api/v1/boms', [
            'product_id' => $this->finished->id, 'version' => 'v1', 'quantity' => 1,
            'items' => [['material_id' => $this->finished->id, 'quantity' => 1, 'unit_id' => $this->unit->id]],
        ])
            ->assertStatus(200)
            ->assertJsonPath('code', 1119)
            ->assertJsonPath('message', 'BOM 明细物料必须是原料或半成品')
            ->assertJsonPath('data', null);
    }

    public function test_store_duplicate_enabled_version_fails_with_1120(): void
    {
        // 异常路径：同成品已有启用版本，再建启用版本 1120
        $this->withToken($this->token)->postJson('/api/v1/boms', [
            'product_id' => $this->finished->id, 'version' => 'v1', 'quantity' => 1,
            'items' => [['material_id' => $this->material->id, 'quantity' => 2, 'unit_id' => $this->unit->id]],
        ])->assertJsonPath('code', 0);
        $this->withToken($this->token)->postJson('/api/v1/boms', [
            'product_id' => $this->finished->id, 'version' => 'v2', 'quantity' => 1, 'status' => 1,
            'items' => [['material_id' => $this->material->id, 'quantity' => 3, 'unit_id' => $this->unit->id]],
        ])->assertJsonPath('code', 1120);
    }

    public function test_store_generates_next_sequence_after_existing_today_code(): void
    {
        // 边界路径：当日已有单号历史遗留行时新单顺延（不撞唯一索引；legacyMax 按尾部数字段衔接）
        $today = now()->format('Ymd');
        BomHeader::create([
            'code' => "BOM{$today}-001",
            'product_id' => $this->finished->id,
            'version' => 'v0',
            'quantity' => 1,
            'status' => 0,
        ]);
        $res = $this->withToken($this->token)->postJson('/api/v1/boms', [
            'product_id' => $this->finished->id, 'version' => 'v1', 'quantity' => 1,
            'items' => [['material_id' => $this->material->id, 'quantity' => 2, 'unit_id' => $this->unit->id]],
        ]);
        $res->assertJsonPath('code', 0);
        $this->assertSame('BOM'.now()->format('YmdHi').'002', $res->json('data.code'));
    }

    public function test_store_disabled_version_succeeds_even_when_enabled_exists(): void
    {
        // 边界路径：同成品已启用时，以停用状态建新版本允许
        $this->withToken($this->token)->postJson('/api/v1/boms', [
            'product_id' => $this->finished->id, 'version' => 'v1', 'quantity' => 1,
            'items' => [['material_id' => $this->material->id, 'quantity' => 2, 'unit_id' => $this->unit->id]],
        ])->assertJsonPath('code', 0);
        $this->withToken($this->token)->postJson('/api/v1/boms', [
            'product_id' => $this->finished->id, 'version' => 'v2', 'quantity' => 1, 'status' => 0,
            'items' => [['material_id' => $this->material->id, 'quantity' => 3, 'unit_id' => $this->unit->id]],
        ])->assertJsonPath('code', 0);
        $this->assertSame(2, BomHeader::count());
    }

    public function test_store_duplicate_material_rows_fails_with_1123(): void
    {
        // 异常路径：明细存在重复物料 1123（信封契约同 1118，D-12）
        $this->withToken($this->token)->postJson('/api/v1/boms', [
            'product_id' => $this->finished->id, 'version' => 'v1', 'quantity' => 1,
            'items' => [
                ['material_id' => $this->material->id, 'quantity' => 2, 'unit_id' => $this->unit->id],
                ['material_id' => $this->material->id, 'quantity' => 1, 'unit_id' => $this->unit->id],
            ],
        ])
            ->assertStatus(200)
            ->assertJsonPath('code', 1123)
            ->assertJsonPath('message', 'BOM 明细存在重复物料')
            ->assertJsonPath('data', null);
    }

    public function test_update_product_not_finished_fails_with_1118(): void
    {
        // 异常路径（D-12）：update 路径同样走 validateBom 三处业务校验——
        // 散装响应改统一 fail 后，控制器早退返回信封（锁 update 调用链不被重构破坏）
        $bom = BomHeader::create([
            'code' => 'BOM20260812-002', 'product_id' => $this->finished->id,
            'version' => 'v1', 'quantity' => 1, 'status' => 0,
        ]);
        $bom->items()->create(['material_id' => $this->material->id, 'quantity' => 2, 'unit_id' => $this->unit->id]);
        $this->withToken($this->token)->putJson("/api/v1/boms/{$bom->id}", [
            'product_id' => $this->material->id, 'version' => 'v2', 'quantity' => 1,
            'items' => [['material_id' => $this->material->id, 'quantity' => 1, 'unit_id' => $this->unit->id]],
        ])
            ->assertStatus(200)
            ->assertJsonPath('code', 1118)
            ->assertJsonPath('message', 'BOM 关联商品必须是成品')
            ->assertJsonPath('data', null);
        // 校验失败不落库：单头版本仍为 v1（未进入更新事务）
        $this->assertSame('v1', $bom->refresh()->version);
    }

    public function test_items_returns_material_and_unit_names(): void
    {
        // 正常路径：明细带物料名与单位名
        $bom = BomHeader::create([
            'code' => 'BOM20260812-001',
            'product_id' => $this->finished->id,
            'version' => 'v1',
            'quantity' => 1,
            'status' => 1,
        ]);
        $bom->items()->create(['material_id' => $this->material->id, 'quantity' => 2, 'unit_id' => $this->unit->id]);
        $this->withToken($this->token)->getJson("/api/v1/boms/{$bom->id}/items")
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.items.0.material_name', '铝材')
            ->assertJsonPath('data.items.0.unit_name', '个');
    }

    public function test_update_replaces_items_fully(): void
    {
        // 正常路径：更新后明细全量替换
        $bom = BomHeader::create([
            'code' => 'BOM20260812-001',
            'product_id' => $this->finished->id,
            'version' => 'v1',
            'quantity' => 1,
            'status' => 1,
        ]);
        $bom->items()->create(['material_id' => $this->material->id, 'quantity' => 2, 'unit_id' => $this->unit->id]);
        $other = Product::create([
            'name' => '螺丝',
            'code' => 'MAT-002',
            'type' => 'raw_material',
            'category_id' => $this->rawCat->id,
            'unit_id' => $this->unit->id,
            'status' => 1,
        ]);
        $this->withToken($this->token)->putJson("/api/v1/boms/{$bom->id}", [
            'product_id' => $this->finished->id, 'version' => 'v1', 'quantity' => 1,
            'items' => [['material_id' => $other->id, 'quantity' => 5, 'unit_id' => $this->unit->id]],
        ])->assertJsonPath('code', 0);
        $this->assertSame(1, $bom->items()->count());
        $this->assertDatabaseHas('bom_items', [
            'bom_header_id' => $bom->id,
            'material_id' => $other->id,
            'quantity' => '5.00',
        ]);
    }

    public function test_toggle_enable_auto_disables_other_versions(): void
    {
        // 正常路径：启用 v2 自动停用 v1（同成品启用唯一动态生效）
        $v1 = BomHeader::create([
            'code' => 'BOM20260812-001',
            'product_id' => $this->finished->id,
            'version' => 'v1',
            'quantity' => 1,
            'status' => 1,
        ]);
        $v2 = BomHeader::create([
            'code' => 'BOM20260812-002',
            'product_id' => $this->finished->id,
            'version' => 'v2',
            'quantity' => 1,
            'status' => 0,
        ]);
        $this->withToken($this->token)
            ->putJson("/api/v1/boms/{$v2->id}/toggle", ['status' => 1])
            ->assertJsonPath('code', 0);
        $this->assertDatabaseHas('bom_headers', ['id' => $v2->id, 'status' => 1]);
        $this->assertDatabaseHas('bom_headers', ['id' => $v1->id, 'status' => 0]);
    }

    public function test_destroy_succeeds_when_production_tables_missing(): void
    {
        // 边界路径：生产模块表未建（守卫放行），BOM 可删
        $bom = BomHeader::create([
            'code' => 'BOM20260812-001',
            'product_id' => $this->finished->id,
            'version' => 'v1',
            'quantity' => 1,
            'status' => 1,
        ]);
        $bom->items()->create(['material_id' => $this->material->id, 'quantity' => 2, 'unit_id' => $this->unit->id]);
        $this->withToken($this->token)->deleteJson("/api/v1/boms/{$bom->id}")->assertJsonPath('code', 0);
        $this->assertDatabaseMissing('bom_headers', ['id' => $bom->id]);
        $this->assertDatabaseCount('bom_items', 0);
    }

    public function test_store_item_quantity_not_positive_returns_422(): void
    {
        // 边界路径：明细数量必须 > 0（格式层 422）
        $this->withToken($this->token)->postJson('/api/v1/boms', [
            'product_id' => $this->finished->id, 'version' => 'v1', 'quantity' => 1,
            'items' => [['material_id' => $this->material->id, 'quantity' => 0, 'unit_id' => $this->unit->id]],
        ])->assertStatus(422);
    }

    public function test_bom_code_sequence_does_not_regress_after_delete(): void
    {
        // 正常路径：删除 BOM 后单号不回退（旧 count+1 会复用已删单号撞现存单号 500）
        $first = $this->withToken($this->token)->postJson('/api/v1/boms', [
            'product_id' => $this->finished->id, 'version' => 'v1', 'quantity' => 1, 'status' => 0,
            'items' => [['material_id' => $this->material->id, 'quantity' => 2, 'unit_id' => $this->unit->id]],
        ])->assertJsonPath('code', 0)->json('data.code');
        $this->withToken($this->token)->postJson('/api/v1/boms', [
            'product_id' => $this->finished->id, 'version' => 'v2', 'quantity' => 1, 'status' => 0,
            'items' => [['material_id' => $this->material->id, 'quantity' => 2, 'unit_id' => $this->unit->id]],
        ])->assertJsonPath('code', 0)->json('data.code');
        // 删除第一张（草稿可删），再建 → 必须取新号（不回退到已删 -001）
        $list = $this->withToken($this->token)->getJson('/api/v1/boms?keyword='.$first);
        $this->withToken($this->token)->deleteJson('/api/v1/boms/'.$list->json('data.items.0.id'))->assertJsonPath('code', 0);
        $third = $this->withToken($this->token)->postJson('/api/v1/boms', [
            'product_id' => $this->finished->id, 'version' => 'v3', 'quantity' => 1, 'status' => 0,
            'items' => [['material_id' => $this->material->id, 'quantity' => 2, 'unit_id' => $this->unit->id]],
        ])->assertJsonPath('code', 0)->json('data.code');
        $this->assertMatchesRegularExpression('/BOM\d{12}003$/', $third);
    }

    public function test_destroy_referenced_by_production_order_fails_with_1121(): void
    {
        // 边界路径：production_orders 引用该 BOM 时删除被拒 1121（生产表落地后自动生效）
        $cat = Category::create(['name' => '成品', 'parent_id' => 0]);
        $fin = Product::create(['name' => '成品B', 'code' => 'FIN-003', 'type' => 'finished', 'category_id' => $cat->id, 'unit_id' => $this->unit->id, 'status' => 1]);
        $bom = BomHeader::create(['code' => 'BOM-TEST-001', 'product_id' => $fin->id, 'version' => 'v1', 'quantity' => 1, 'status' => 1]);
        DB::table('production_orders')->insert([
            'no' => 'MO-TEST-001', 'product_id' => $fin->id, 'quantity' => 10,
            'plan_date' => now()->toDateString(), 'bom_id' => $bom->id, 'status' => 0,
            'completed_qty' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->withToken($this->token)->deleteJson("/api/v1/boms/{$bom->id}")
            ->assertJsonPath('code', 1121);
    }
}
