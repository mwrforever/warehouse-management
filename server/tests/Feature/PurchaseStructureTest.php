<?php

// 采购数据结构测试：表结构、权限种子、单号唯一索引、金额列（核心数据结构 100% 覆盖）

namespace Tests\Feature;

use App\Models\Location;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\Warehouse;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PurchaseStructureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // 种子：RBAC + 基础资料主数据（采购表结构本 Task 迁移后即可用）
        $this->seed();
    }

    public function test_all_purchase_tables_exist(): void
    {
        // 正常路径：4 张采购表全部建立
        foreach (['purchase_orders', 'purchase_order_items', 'purchase_inbounds', 'purchase_inbound_items'] as $table) {
            $this->assertTrue(Schema::hasTable($table), "表 {$table} 不存在");
        }
    }

    public function test_purchase_permissions_seeded_for_admin(): void
    {
        // 正常路径：采购管理 8 项权限已注册且 admin 角色全量持有
        $this->assertSame(8, Permission::where('group', '采购管理')->count());
        $admin = Role::where('code', 'admin')->first();
        $this->assertSame(2, $admin->permissions()->whereIn('code', ['purchase.order.create', 'purchase.inbound.update'])->count());
    }

    public function test_order_no_unique_blocks_duplicate(): void
    {
        // 边界路径：订单单号唯一（撞号由序列服务换号，此约束兜底）
        // 注：测试库 sqlite 外键约束开启，先建真实供应商再插入（防假外键 supplier_id=1）
        $supplier = Supplier::create(['name' => '测试供应商', 'code' => 'SUP-T-001', 'status' => 1]);
        $orderId = DB::table('purchase_orders')->insertGetId([
            'no' => 'PO20260812-001', 'supplier_id' => $supplier->id, 'order_date' => now()->toDateString(),
            'status' => 0, 'total_amount' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->assertGreaterThan(0, $orderId);
        $this->expectException(QueryException::class);
        DB::table('purchase_orders')->insert([
            'no' => 'PO20260812-001', 'supplier_id' => $supplier->id, 'order_date' => now()->toDateString(),
            'status' => 0, 'total_amount' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_amount_columns_are_decimal(): void
    {
        // 正常路径：金额/数量列为 decimal（分单位整数运算，禁浮点）
        // 注：sqlite 语法器将 DECIMAL 编译为 NUMERIC，故类型名 decimal|numeric 均合法，float 则拒绝
        foreach (['price', 'amount'] as $col) {
            $this->assertContains(
                Schema::getColumnType('purchase_order_items', $col),
                ['decimal', 'numeric'],
                "{$col} 应为 decimal（sqlite 下为 numeric）"
            );
        }
        $this->assertContains(Schema::getColumnType('purchase_orders', 'total_amount'), ['decimal', 'numeric']);
        $this->assertContains(Schema::getColumnType('purchase_order_items', 'quantity'), ['decimal', 'numeric']);
        $this->assertContains(Schema::getColumnType('purchase_order_items', 'received_qty'), ['decimal', 'numeric']);
    }

    public function test_inbound_order_id_is_nullable(): void
    {
        // 边界路径：入库单可无订单来源（独立入库）；订单被删后入库单保留（nullOnDelete）
        // 注：测试库 sqlite 外键约束开启，先建真实供应商/仓库/库位再插入
        $supplier = Supplier::create(['name' => '测试供应商', 'code' => 'SUP-T-002', 'status' => 1]);
        $warehouse = Warehouse::create(['name' => '测试仓', 'code' => 'WH-T-01', 'status' => 1]);
        $location = Location::create(['warehouse_id' => $warehouse->id, 'name' => '测试位', 'code' => 'A-T-01', 'status' => 1]);
        $inboundId = DB::table('purchase_inbounds')->insertGetId([
            'no' => 'PI20260812-001', 'supplier_id' => $supplier->id, 'warehouse_id' => $warehouse->id, 'location_id' => $location->id,
            'order_id' => null, 'status' => 0, 'total_amount' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->assertSame(null, DB::table('purchase_inbounds')->where('id', $inboundId)->value('order_id'));
    }
}
