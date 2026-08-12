<?php
// 仓库/库位接口测试：CRUD/编码唯一/子资源/删除保护（正常+边界+异常）
namespace Tests\Feature;

use App\Models\Location;
use App\Models\Role;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_store_and_duplicate_code_fails_with_1105(): void
    {
        // 正常路径：创建成功
        $this->withToken($this->token)->postJson('/api/v1/warehouses', ['name' => '测试仓', 'code' => 'WH02', 'address' => '厂区B', 'manager' => '李四', 'status' => 1])
            ->assertJsonPath('code', 0);
        // 异常路径：重复编码 1105
        $this->withToken($this->token)->postJson('/api/v1/warehouses', ['name' => '重复', 'code' => 'WH02'])
            ->assertJsonPath('code', 1105);
    }

    public function test_update_warehouse(): void
    {
        // 正常路径：更新地址与负责人
        $w = Warehouse::create(['name' => '主仓', 'code' => 'WH01', 'status' => 1]);
        $this->withToken($this->token)->putJson("/api/v1/warehouses/{$w->id}", ['name' => '主仓2', 'code' => 'WH01', 'address' => '新地址', 'manager' => '王五', 'status' => 1])
            ->assertJsonPath('code', 0);
        $this->assertDatabaseHas('warehouses', ['id' => $w->id, 'name' => '主仓2', 'manager' => '王五']);
    }

    public function test_location_crud_under_warehouse(): void
    {
        // 正常路径：库位增查改删全链路
        $w = Warehouse::create(['name' => '测试仓', 'code' => 'WH02', 'status' => 1]);
        $this->withToken($this->token)->postJson("/api/v1/warehouses/{$w->id}/locations", ['name' => 'A-01', 'code' => 'A-01', 'status' => 1])
            ->assertJsonPath('code', 0);
        $this->withToken($this->token)->getJson("/api/v1/warehouses/{$w->id}/locations")
            ->assertJsonPath('data.items.0.name', 'A-01');
        $location = Location::first();
        $this->withToken($this->token)->putJson("/api/v1/locations/{$location->id}", ['name' => 'A-02', 'code' => 'A-02', 'status' => 1])
            ->assertJsonPath('code', 0);
        $this->withToken($this->token)->deleteJson("/api/v1/locations/{$location->id}")->assertJsonPath('code', 0);
        $this->assertDatabaseMissing('locations', ['id' => $location->id]);
    }

    public function test_location_duplicate_code_returns_422(): void
    {
        // 异常路径：库位编码全局唯一（格式层 422，非业务码）
        $w = Warehouse::create(['name' => '测试仓', 'code' => 'WH02', 'status' => 1]);
        Location::create(['warehouse_id' => $w->id, 'name' => 'A-01', 'code' => 'A-01', 'status' => 1]);
        $this->withToken($this->token)->postJson("/api/v1/warehouses/{$w->id}/locations", ['name' => 'A-01b', 'code' => 'A-01', 'status' => 1])
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
        // 边界路径：inventory_balances 表存在且有引用时，仓库删除被拒 1106（临时表验证守卫联动）
        $w = Warehouse::create(['name' => '测试仓', 'code' => 'WH02', 'status' => 1]);
        \Illuminate\Support\Facades\Schema::create('inventory_balances', function ($table) {
            $table->id();
            $table->unsignedBigInteger('warehouse_id');
            $table->unsignedBigInteger('location_id')->nullable();
        });
        \Illuminate\Support\Facades\DB::table('inventory_balances')->insert(['warehouse_id' => $w->id, 'location_id' => null]);
        try {
            $this->withToken($this->token)->deleteJson("/api/v1/warehouses/{$w->id}")
                ->assertJsonPath('code', 1106);
        } finally {
            \Illuminate\Support\Facades\Schema::dropIfExists('inventory_balances');
        }
    }
}
