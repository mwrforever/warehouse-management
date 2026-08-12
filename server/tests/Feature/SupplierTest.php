<?php

// 供应商接口测试：CRUD/搜索/编码唯一/删除保护（正常+边界+异常）

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Supplier;
use App\Models\User;
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
        // 正常路径：创建成功
        $this->withToken($this->token)->postJson('/api/v1/suppliers', [
            'name' => '测试供应商',
            'code' => 'SUP-001',
            'contact' => '张三',
            'phone' => '13800000000',
            'address' => '工业园1号',
            'status' => 1,
        ])
            ->assertJsonPath('code', 0);
        // 异常路径：重复编码 1108
        $this->withToken($this->token)->postJson('/api/v1/suppliers', ['name' => '重复', 'code' => 'SUP-001'])
            ->assertJsonPath('code', 1108);
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
}
