<?php

// 工序接口测试：CRUD/编码唯一/排序（正常+边界+异常）

namespace Tests\Feature;

use App\Models\BomHeader;
use App\Models\Category;
use App\Models\Process;
use App\Models\Product;
use App\Models\Role;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProcessTest extends TestCase
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

    public function test_index_returns_sorted_by_sort_asc(): void
    {
        // 正常路径：列表按 sort 升序（生产模块下拉顺序）
        Process::create(['name' => '打磨', 'code' => 'P2', 'sort' => 2, 'status' => 1]);
        Process::create(['name' => '下料', 'code' => 'P1', 'sort' => 1, 'status' => 1]);
        $this->withToken($this->token)->getJson('/api/v1/processes')
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.items.0.name', '下料')
            ->assertJsonPath('data.items.1.name', '打磨');
    }

    public function test_store_and_duplicate_code_fails_with_1112(): void
    {
        // 正常路径：创建成功
        $this->withToken($this->token)->postJson('/api/v1/processes', [
            'name' => '车削',
            'code' => 'PROC-02',
            'sort' => 2,
            'description' => '',
        ])
            ->assertJsonPath('code', 0);
        // 异常路径：重复编码 1112
        $this->withToken($this->token)->postJson('/api/v1/processes', ['name' => '重复', 'code' => 'PROC-02'])
            ->assertJsonPath('code', 1112);
    }

    public function test_update_changes_sort(): void
    {
        // 正常路径：更新排序生效
        $p = Process::create(['name' => '测试工序', 'code' => 'PROC-99', 'sort' => 99, 'status' => 1]);
        $this->withToken($this->token)->putJson("/api/v1/processes/{$p->id}", [
            'name' => '测试工序',
            'code' => 'PROC-99',
            'sort' => 1,
            'status' => 1,
        ])
            ->assertJsonPath('code', 0);
        $this->assertDatabaseHas('processes', ['id' => $p->id, 'sort' => 1]);
    }

    public function test_destroy_succeeds_when_work_orders_table_missing(): void
    {
        // 边界路径：生产模块表未建（守卫放行），工序可删
        $p = Process::create(['name' => '测试工序', 'code' => 'PROC-99', 'sort' => 99, 'status' => 1]);
        $this->withToken($this->token)->deleteJson("/api/v1/processes/{$p->id}")->assertJsonPath('code', 0);
        $this->assertDatabaseMissing('processes', ['id' => $p->id]);
    }

    public function test_destroy_referenced_by_work_order_operation_fails_with_1113(): void
    {
        // 边界路径：work_order_operations 引用该工序时删除被拒 1113（生产表落地后自动生效）
        $p = Process::create(['name' => '下料', 'code' => 'PROC-01', 'sort' => 1, 'status' => 1]);
        $cat = Category::create(['name' => '成品', 'parent_id' => 0]);
        $unit = Unit::create(['name' => '个', 'code' => 'pc']);
        $fin = Product::create(['name' => '成品B', 'code' => 'FIN-002', 'type' => 'finished', 'category_id' => $cat->id, 'unit_id' => $unit->id, 'status' => 1]);
        $bom = BomHeader::create(['code' => 'BOM-TEST-001', 'product_id' => $fin->id, 'version' => 'v1', 'quantity' => 1, 'status' => 1]);
        $orderId = DB::table('production_orders')->insertGetId([
            'no' => 'MO-TEST-001', 'product_id' => $fin->id, 'quantity' => 10,
            'plan_date' => now()->toDateString(), 'bom_id' => $bom->id, 'status' => 0,
            'completed_qty' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('work_order_operations')->insert([
            'order_id' => $orderId, 'process_id' => $p->id, 'seq' => 1, 'status' => 0,
            'qualified_qty' => 0, 'defective_qty' => 0, 'hours' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->withToken($this->token)->deleteJson("/api/v1/processes/{$p->id}")
            ->assertJsonPath('code', 1113);
    }
}
