<?php

// 客户接口测试：CRUD/搜索/编码唯一/删除保护（正常+边界+异常）

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Location;
use App\Models\Role;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CustomerTest extends TestCase
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
        Customer::create([
            'name' => '测试客户',
            'code' => 'CUS-001',
            'contact' => '张三',
            'phone' => '13800000000',
            'status' => 1,
        ]);
        Customer::create(['name' => '其他客户', 'code' => 'CUS-002', 'contact' => '李四', 'status' => 1]);
        $this->withToken($this->token)->getJson('/api/v1/customers?keyword=测试')
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.items.0.code', 'CUS-001');
    }

    public function test_store_and_duplicate_code_fails_with_1110(): void
    {
        // 正常路径：创建成功（含四级地址 + 详细地址落库）
        $this->withToken($this->token)->postJson('/api/v1/customers', [
            'name' => '测试客户',
            'code' => 'CUS-001',
            'contact' => '张三',
            'phone' => '13800000000',
            'province' => '广东省',
            'city' => '深圳市',
            'district' => '南山区',
            'town' => '粤海街道',
            'address' => '科苑路1号',
            'status' => 1,
        ])
            ->assertJsonPath('code', 0);
        $this->assertDatabaseHas('customers', [
            'code' => 'CUS-001',
            'province' => '广东省', 'city' => '深圳市', 'district' => '南山区', 'town' => '粤海街道',
            'address' => '科苑路1号',
        ]);
        // 异常路径：重复编码 1110
        $this->withToken($this->token)->postJson('/api/v1/customers', ['name' => '重复', 'code' => 'CUS-001'])
            ->assertJsonPath('code', 1110);
    }

    public function test_store_without_region_fields_stores_null(): void
    {
        // 边界路径：不传四级地址（仅详细地址）时各列均为 null，保证可空
        $this->withToken($this->token)->postJson('/api/v1/customers', [
            'name' => '无区域客户',
            'code' => 'CUS-002',
            'address' => '某街巷 12 号',
            'status' => 1,
        ])
            ->assertJsonPath('code', 0);
        $this->assertDatabaseHas('customers', [
            'code' => 'CUS-002',
            'province' => null, 'city' => null, 'district' => null, 'town' => null,
            'address' => '某街巷 12 号',
        ]);
    }

    public function test_update_contact_and_phone(): void
    {
        // 正常路径：更新联系人电话
        $c = Customer::create(['name' => '测试客户', 'code' => 'CUS-001', 'contact' => '张三', 'status' => 1]);
        $this->withToken($this->token)->putJson("/api/v1/customers/{$c->id}", [
            'name' => '测试客户',
            'code' => 'CUS-001',
            'contact' => '王五',
            'phone' => '13900000000',
            'status' => 1,
        ])
            ->assertJsonPath('code', 0);
        $this->assertDatabaseHas('customers', ['id' => $c->id, 'contact' => '王五', 'phone' => '13900000000']);
    }

    public function test_destroy_succeeds_when_no_sales_reference(): void
    {
        // 边界路径：无任何销售单据引用（销售表已建），客户可删
        $c = Customer::create(['name' => '测试客户', 'code' => 'CUS-001', 'status' => 1]);
        $this->withToken($this->token)->deleteJson("/api/v1/customers/{$c->id}")->assertJsonPath('code', 0);
        $this->assertDatabaseMissing('customers', ['id' => $c->id]);
    }

    public function test_destroy_with_sales_order_reference_fails(): void
    {
        // 边界路径：sales_orders 真实表引用该客户时删除被拒 1111（订单引用保护）
        $c = Customer::create(['name' => '测试客户', 'code' => 'CUS-001', 'status' => 1]);
        DB::table('sales_orders')->insert([
            'no' => 'SO-TEST-001', 'customer_id' => $c->id, 'order_date' => now()->toDateString(),
            'status' => 0, 'total_amount' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->withToken($this->token)->deleteJson("/api/v1/customers/{$c->id}")
            ->assertJsonPath('code', 1111);
    }

    public function test_destroy_with_sales_outbound_reference_fails(): void
    {
        // 边界路径：sales_outbounds 真实表引用该客户时删除被拒 1111（出库单引用保护，独立出库必选客户）
        // 注：测试库 sqlite 外键约束开启，出库单需真实仓库/库位（本测试类不种子，先建再插入）
        $c = Customer::create(['name' => '测试客户', 'code' => 'CUS-001', 'status' => 1]);
        $w = Warehouse::create(['name' => '测试仓', 'code' => 'WH02', 'status' => 1]);
        $l = Location::create(['warehouse_id' => $w->id, 'name' => 'A-01', 'code' => 'A-01', 'status' => 1]);
        DB::table('sales_outbounds')->insert([
            'no' => 'SOUT-TEST-001', 'customer_id' => $c->id, 'warehouse_id' => $w->id, 'location_id' => $l->id,
            'status' => 0, 'total_amount' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->withToken($this->token)->deleteJson("/api/v1/customers/{$c->id}")
            ->assertJsonPath('code', 1111);
    }
}
