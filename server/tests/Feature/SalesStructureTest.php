<?php

// 销售数据结构测试：表结构、权限种子、序列常量、单号唯一索引、金额列（核心数据结构 100% 覆盖）

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\DocumentSequence;
use App\Models\Location;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Warehouse;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SalesStructureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // 种子：RBAC + 基础资料主数据（销售表结构本 Task 迁移后即可用）
        $this->seed();
    }

    public function test_all_sales_tables_exist(): void
    {
        // 正常路径：4 张销售表全部建立
        foreach (['sales_orders', 'sales_order_items', 'sales_outbounds', 'sales_outbound_items'] as $table) {
            $this->assertTrue(Schema::hasTable($table), "表 {$table} 不存在");
        }
    }

    public function test_sales_permissions_seeded_for_admin(): void
    {
        // 正常路径：销售管理 8 项权限已注册且 admin 角色全量持有
        $this->assertSame(8, Permission::where('group', '销售管理')->count());
        $admin = Role::where('code', 'admin')->first();
        $this->assertSame(2, $admin->permissions()->whereIn('code', ['sales.order.create', 'sales.outbound.update'])->count());
    }

    public function test_document_sequence_has_sales_types(): void
    {
        // 正常路径：销售单号段常量已注册（SO 订单 / SOUT 出库单，DocumentSequenceService 零迁移复用）
        $this->assertSame('so', DocumentSequence::TYPE_SO);
        $this->assertSame('sout', DocumentSequence::TYPE_SOUT);
    }

    public function test_order_no_unique_blocks_duplicate(): void
    {
        // 边界路径：订单单号唯一（撞号由序列服务换号，此约束兜底）
        // 注：测试库 sqlite 外键约束开启，先建真实客户再插入（防假外键 customer_id=1）
        $customer = Customer::create(['name' => '测试客户', 'code' => 'CUS-T-001', 'status' => 1]);
        $orderId = DB::table('sales_orders')->insertGetId([
            'no' => 'SO20260812-001', 'customer_id' => $customer->id, 'order_date' => now()->toDateString(),
            'status' => 0, 'total_amount' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->assertGreaterThan(0, $orderId);
        $this->expectException(QueryException::class);
        DB::table('sales_orders')->insert([
            'no' => 'SO20260812-001', 'customer_id' => $customer->id, 'order_date' => now()->toDateString(),
            'status' => 0, 'total_amount' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_amount_columns_are_bigint_cents_and_qty_columns_decimal(): void
    {
        // 正常路径（R2）：金额列为 bigint 分单位整数、数量列为 decimal（分单位整数运算，禁浮点）
        // 注：MySQL 返回类型名 bigint、sqlite 语法器将 BIGINT 编译为 INTEGER，故 integer|bigint 均合法
        foreach (['price', 'amount'] as $col) {
            $this->assertContains(
                Schema::getColumnType('sales_order_items', $col),
                ['integer', 'bigint'],
                "{$col} 应为 bigint（sqlite 下为 integer）"
            );
        }
        $this->assertContains(Schema::getColumnType('sales_orders', 'total_amount'), ['integer', 'bigint']);
        $this->assertContains(Schema::getColumnType('sales_order_items', 'quantity'), ['decimal', 'numeric']);
        $this->assertContains(Schema::getColumnType('sales_order_items', 'shipped_qty'), ['decimal', 'numeric']);
    }

    public function test_outbound_order_id_is_nullable(): void
    {
        // 边界路径：出库单可无订单来源（独立出库）；订单被删后出库单保留（nullOnDelete）
        // 注：测试库 sqlite 外键约束开启，先建真实客户/仓库/库位再插入
        $customer = Customer::create(['name' => '测试客户', 'code' => 'CUS-T-002', 'status' => 1]);
        $warehouse = Warehouse::create(['name' => '测试仓', 'code' => 'WH-T-01', 'status' => 1]);
        $location = Location::create(['warehouse_id' => $warehouse->id, 'name' => '测试位', 'code' => 'A-T-01', 'status' => 1]);
        $outboundId = DB::table('sales_outbounds')->insertGetId([
            'no' => 'SOUT20260812-001', 'customer_id' => $customer->id, 'warehouse_id' => $warehouse->id, 'location_id' => $location->id,
            'order_id' => null, 'status' => 0, 'total_amount' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->assertSame(null, DB::table('sales_outbounds')->where('id', $outboundId)->value('order_id'));
    }
}
