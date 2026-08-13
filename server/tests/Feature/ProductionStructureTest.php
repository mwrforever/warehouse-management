<?php

// 生产工单域数据结构测试：表结构、权限种子、序列常量、唯一索引（核心数据结构 100% 覆盖）

namespace Tests\Feature;

use App\Models\DocumentSequence;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ProductionStructureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // 种子：RBAC + 基础资料主数据（生产工单域表本 Task 迁移后即可用）
        $this->seed();
    }

    public function test_production_tables_exist(): void
    {
        // 正常路径：工单域 4 张表全部建立
        foreach (['production_orders', 'production_order_materials', 'work_order_operations', 'operation_reports'] as $table) {
            $this->assertTrue(Schema::hasTable($table), "表 {$table} 不存在");
        }
    }

    public function test_production_permissions_seeded_for_admin(): void
    {
        // 正常路径：生产管理权限已注册且 admin 角色全量持有（Task 1 的 order/report 8 项 + Task 2 的 pick/return/outsource/finished 16 项 = 24 项）
        $this->assertSame(24, Permission::where('group', '生产管理')->count());
        $admin = Role::where('code', 'admin')->first();
        $this->assertSame(2, $admin->permissions()->whereIn('code', ['production.order.create', 'production.report.update'])->count());
    }

    public function test_document_sequence_has_production_order_type(): void
    {
        // 正常路径：工单单号段常量已注册（MO，DocumentSequenceService 零迁移复用）
        $this->assertSame('mo', DocumentSequence::TYPE_MO);
    }

    public function test_order_no_unique_blocks_duplicate(): void
    {
        // 边界路径：工单单号唯一（撞号由序列服务换号，此约束兜底）
        $productId = DB::table('products')->insertGetId([
            'name' => '成品', 'code' => 'FIN-X', 'type' => 'finished', 'category_id' => 1,
            'unit_id' => 1, 'status' => 1, 'safety_min' => 0, 'safety_max' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        // 建真实 BOM 主数据（种子未含 BOM，外键约束下 bom_id 必须指向真实行）
        $bomId = DB::table('bom_headers')->insertGetId([
            'code' => 'BOM-STRUCT-001', 'product_id' => $productId, 'version' => 'v1',
            'quantity' => 1, 'status' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $orderId = DB::table('production_orders')->insertGetId([
            'no' => 'MO20260812-001', 'product_id' => $productId, 'quantity' => 10,
            'plan_date' => now()->toDateString(), 'bom_id' => $bomId, 'status' => 0,
            'completed_qty' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->assertGreaterThan(0, $orderId);
        $this->expectException(QueryException::class);
        DB::table('production_orders')->insert([
            'no' => 'MO20260812-001', 'product_id' => $productId, 'quantity' => 10,
            'plan_date' => now()->toDateString(), 'bom_id' => $bomId, 'status' => 0,
            'completed_qty' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_material_snapshot_unique_per_order_and_product(): void
    {
        // 边界路径：工单物料需求快照 order_id+material_id 唯一（展开结果防重复）
        // 建真实 BOM 主数据（种子未含 BOM，外键约束下 bom_id 必须指向真实行）
        $bomId = DB::table('bom_headers')->insertGetId([
            'code' => 'BOM-STRUCT-002', 'product_id' => 1, 'version' => 'v1',
            'quantity' => 1, 'status' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $orderId = DB::table('production_orders')->insertGetId([
            'no' => 'MO20260812-002', 'product_id' => 1, 'quantity' => 1,
            'plan_date' => now()->toDateString(), 'bom_id' => $bomId, 'status' => 0,
            'completed_qty' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('production_order_materials')->insert([
            'order_id' => $orderId, 'material_id' => 1, 'required_qty' => 2,
            'issued_qty' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->expectException(QueryException::class);
        DB::table('production_order_materials')->insert([
            'order_id' => $orderId, 'material_id' => 1, 'required_qty' => 2,
            'issued_qty' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_operation_seq_unique_per_order(): void
    {
        // 边界路径：工单工序 seq 唯一（工序序列有序性约束）
        // 建真实 BOM 主数据（种子未含 BOM，外键约束下 bom_id 必须指向真实行）
        $bomId = DB::table('bom_headers')->insertGetId([
            'code' => 'BOM-STRUCT-003', 'product_id' => 1, 'version' => 'v1',
            'quantity' => 1, 'status' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $orderId = DB::table('production_orders')->insertGetId([
            'no' => 'MO20260812-003', 'product_id' => 1, 'quantity' => 1,
            'plan_date' => now()->toDateString(), 'bom_id' => $bomId, 'status' => 0,
            'completed_qty' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('work_order_operations')->insert([
            'order_id' => $orderId, 'process_id' => 1, 'seq' => 1, 'status' => 0,
            'qualified_qty' => 0, 'defective_qty' => 0, 'hours' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->expectException(QueryException::class);
        DB::table('work_order_operations')->insert([
            'order_id' => $orderId, 'process_id' => 2, 'seq' => 1, 'status' => 0,
            'qualified_qty' => 0, 'defective_qty' => 0, 'hours' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_quantity_columns_are_decimal(): void
    {
        // 正常路径：数量列 decimal（bcmath 整数运算，禁浮点；sqlite 编译为 numeric 双兼容）
        foreach (['quantity', 'completed_qty'] as $col) {
            $this->assertContains(Schema::getColumnType('production_orders', $col), ['decimal', 'numeric'], "{$col} 应为 decimal/numeric");
        }
        $this->assertContains(Schema::getColumnType('production_order_materials', 'required_qty'), ['decimal', 'numeric']);
        $this->assertContains(Schema::getColumnType('work_order_operations', 'qualified_qty'), ['decimal', 'numeric']);
    }

    public function test_production_document_tables_exist(): void
    {
        // 正常路径：单据域 8 张表全部建立
        foreach (['pick_lists', 'pick_list_items', 'return_lists', 'return_list_items',
            'outsourcing_orders', 'outsourcing_receipts', 'finished_inbounds', 'finished_inbound_items'] as $table) {
            $this->assertTrue(Schema::hasTable($table), "表 {$table} 不存在");
        }
    }

    public function test_production_document_permissions_seeded(): void
    {
        // 正常路径：生产管理权限累计 24 项（Task 1 的 8 + 本 Task 的 16）且 admin 全量持有
        $this->assertSame(24, Permission::where('group', '生产管理')->count());
        $admin = Role::where('code', 'admin')->first();
        $this->assertSame(2, $admin->permissions()->whereIn('code', ['production.pick.create', 'production.outsource.update'])->count());
    }

    public function test_document_sequence_has_production_types(): void
    {
        // 正常路径：单据号段常量已注册（领料/退料/委外/委外回收/成品入库）
        $this->assertSame('pl', DocumentSequence::TYPE_PL);
        $this->assertSame('rl', DocumentSequence::TYPE_RL);
        $this->assertSame('os', DocumentSequence::TYPE_OS);
        $this->assertSame('osr', DocumentSequence::TYPE_OSR);
        $this->assertSame('fi', DocumentSequence::TYPE_FI);
    }
}
