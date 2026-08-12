<?php
// 基础资料数据模型测试：表结构、种子完整性、引用保护守卫（核心数据结构，100% 覆盖）
namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Support\DeletionGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MasterDataStructureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // 种子：RBAC（权限/角色/admin 用户）+ 基础资料主数据
        $this->seed();
    }

    public function test_all_master_data_tables_exist(): void
    {
        // 正常路径：10 张基础资料表全部建立
        foreach (['categories', 'units', 'warehouses', 'locations', 'suppliers', 'customers', 'processes', 'products', 'bom_headers', 'bom_items'] as $table) {
            $this->assertTrue(Schema::hasTable($table), "表 {$table} 不存在");
        }
    }

    public function test_master_data_seed_creates_base_data(): void
    {
        // 正常路径：种子含分类/单位/仓库/商品/工序
        $this->assertDatabaseHas('categories', ['name' => '原材料']);
        $this->assertDatabaseHas('units', ['code' => 'pc']);
        $this->assertDatabaseHas('warehouses', ['code' => 'WH01']);
        $this->assertDatabaseHas('products', ['code' => 'RAW-001', 'type' => 'raw_material']);
        $this->assertDatabaseHas('processes', ['code' => 'PROC-01']);
    }

    public function test_master_permissions_seeded_for_admin(): void
    {
        // 正常路径：基础资料 32 个权限已注册且 admin 角色全量持有
        $this->assertSame(32, Permission::where('group', '基础资料')->count());
        $admin = Role::where('code', 'admin')->first();
        $this->assertSame(2, $admin->permissions()->whereIn('code', ['product.list', 'bom.delete'])->count());
    }

    public function test_deletion_guard_returns_false_for_missing_table(): void
    {
        // 边界路径：下游模块表未建时守卫返回 false（不阻止删除）
        $this->assertFalse(DeletionGuard::referenced('inventory_balances', 'warehouse_id', 1));
    }

    public function test_deletion_guard_detects_reference_in_existing_table(): void
    {
        // 正常路径：已有表存在引用时守卫返回 true（临时表验证守卫逻辑本身）
        Schema::create('guard_test_tmp', function ($table) {
            $table->id();
            $table->unsignedBigInteger('ref_id');
        });
        DB::table('guard_test_tmp')->insert(['ref_id' => 7]);
        try {
            $this->assertTrue(DeletionGuard::referenced('guard_test_tmp', 'ref_id', 7));
            $this->assertFalse(DeletionGuard::referenced('guard_test_tmp', 'ref_id', 8));
        } finally {
            Schema::dropIfExists('guard_test_tmp');
        }
    }
}
