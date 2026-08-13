<?php

// 库存数据模型测试：表结构、权限种子、流水类型枚举、联合唯一索引（核心数据结构，100% 覆盖）

namespace Tests\Feature;

use App\Models\InventoryMovement;
use App\Models\Location;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use Database\Seeders\InventorySeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class InventoryStructureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // 种子：RBAC + 基础资料主数据（本模块种子 InventorySeeder 在 Task 2 注册）
        $this->seed();
    }

    public function test_all_inventory_tables_exist(): void
    {
        // 正常路径：4 张库存表全部建立
        foreach (['inventory_balances', 'inventory_movements', 'inventory_checks', 'inventory_check_items'] as $table) {
            $this->assertTrue(Schema::hasTable($table), "表 {$table} 不存在");
        }
    }

    public function test_inventory_permissions_seeded_for_admin(): void
    {
        // 正常路径：库存管理 5 项权限已注册且 admin 角色全量持有（盘点审核复用 check.update）
        $this->assertSame(5, Permission::where('group', '库存管理')->count());
        $admin = Role::where('code', 'admin')->first();
        $this->assertSame(2, $admin->permissions()->whereIn('code', ['inventory.list', 'check.update'])->count());
    }

    public function test_movement_source_types_cover_spec_enum(): void
    {
        // 正常路径：9 种来源类型与中文标签一一映射（采购/销售/生产模块将复用）
        $this->assertSame(
            [
                'purchase_inbound', 'sales_outbound', 'pick', 'return',
                'finished_inbound', 'outsourcing_out', 'outsourcing_in',
                'check_in', 'check_out',
            ],
            InventoryMovement::SOURCE_TYPES
        );
        $this->assertSame('采购入库', InventoryMovement::SOURCE_TYPE_LABELS['purchase_inbound']);
        $this->assertSame('盘盈', InventoryMovement::SOURCE_TYPE_LABELS['check_in']);
        $this->assertSame('盘亏', InventoryMovement::SOURCE_TYPE_LABELS['check_out']);
    }

    public function test_balance_unique_index_blocks_duplicate_row(): void
    {
        // 边界路径：联合唯一索引兜底并发首次入库（重复行插入被 DB 拒绝）
        // 库位与余额行由 InventorySeeder 提供，直接复用种子行验证索引
        $loc = Location::where('code', 'A-01')->firstOrFail();
        $mat = Product::where('code', 'MAT-001')->firstOrFail();
        $this->expectException(QueryException::class);
        DB::table('inventory_balances')->insert([
            'product_id' => $mat->id, 'warehouse_id' => $loc->warehouse_id, 'location_id' => $loc->id,
            'quantity' => 2, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_movements_created_at_index_exists(): void
    {
        // 正常路径：流水日期筛选索引已建（date_from/date_to 免全表扫）
        $indexes = collect(Schema::getIndexes('inventory_movements'));
        $this->assertTrue(
            $indexes->contains(fn ($i) => $i['columns'] === ['created_at']),
            'created_at 单列索引不存在'
        );
    }

    public function test_inventory_seeder_is_idempotent(): void
    {
        // 边界路径（B04）：重复执行库存种子不重复累加基线余额/流水（幂等保护，防 E2E 数值断言失效）
        $mat = Product::where('code', 'MAT-001')->firstOrFail();
        $movementCount = DB::table('inventory_movements')->where('product_id', $mat->id)->count();
        // 第二次执行种子：余额行已存在时应跳过入账
        $this->seed(InventorySeeder::class);
        $balance = DB::table('inventory_balances')->where('product_id', $mat->id)->value('quantity');
        $this->assertEquals(100, (float) $balance);
        $this->assertSame($movementCount, DB::table('inventory_movements')->where('product_id', $mat->id)->count());
    }
}
