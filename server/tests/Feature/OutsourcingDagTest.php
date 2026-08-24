<?php

// 委外回收 DAG 推进测试（修复回归，对应 E2E TC-RTG-04）：委外节点（OP30）回收满量 → 汇合点后继推进（OUT-04）；
// 分支未全完成不推进；无路线旧工单行为不变（仅置 DONE 不推进线性后继）
// 结构复用 OperationReportTest::dagOrder（钻石 A→B/C/D→E，OP30 委外，计划 6）

namespace Tests\Feature;

use App\Models\BomHeader;
use App\Models\Category;
use App\Models\InventoryBalance;
use App\Models\Location;
use App\Models\OutsourcingOrder;
use App\Models\Process;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WorkOrderOperation;
use App\Services\InventoryService;
use Database\Seeders\DocumentNumberConfigSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class OutsourcingDagTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    private User $admin;

    private Warehouse $wh;

    private Location $b01;

    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        // 编号规则配置种子（Spec 2）：单据号按配置生成 OS/OSR/MO 等业务前缀
        $this->seed(DocumentNumberConfigSeeder::class);
        $role = Role::create(['name' => '管理员', 'code' => 'admin']);
        $this->admin = User::create(['name' => '管理员', 'username' => 'admin', 'password' => 'admin123', 'status' => 1]);
        $this->admin->roles()->sync([$role->id]);
        $this->token = $this->admin->createToken('api')->plainTextToken;

        $this->wh = Warehouse::create(['name' => '主仓', 'code' => 'WH01', 'status' => 1]);
        $this->b01 = Location::create(['warehouse_id' => $this->wh->id, 'name' => 'B-01', 'code' => 'B-01', 'status' => 1]);
        $this->supplier = Supplier::create(['name' => '测试供应商', 'code' => 'SUP-001', 'status' => 1]);
        Unit::create(['name' => '个', 'code' => 'pc']);
        // 工序：下料/组装/质检（DAG 节点与旧工单共用；旧工单线性展开按 sort 升序取全部启用工序）
        foreach ([['下料', 'CUT', 1], ['组装', 'ASSY', 2], ['质检', 'QC', 3]] as [$name, $code, $sort]) {
            Process::create(['name' => $name, 'code' => $code, 'sort' => $sort, 'status' => 1]);
        }
    }

    /**
     * DAG 工单辅助：造钻石路线工单（OP10→OP20/OP30/OP40→OP50，OP30 委外）并下达开工
     *
     * 结构同 OperationReportTest::dagOrder（可复制）：OP10 产半成品A×3（耗原料×3），三分支各耗 A×1、
     * 各产互异半成品 B/C/D×2，OP50 汇合耗 B/C/D 各×2 产成品；工单计划 6；委外发出需成品库存 → FIN-DAG@B-01 注入 6。
     * 返回 ['order' => 工单, 'ops' => 按 node_no 键控的工序映射（开工后刷新态）]
     */
    private function dagOrder(): array
    {
        // 钻石 DAG 物料族：原料 + 半成品A + 分支互异半成品 B/C/D + 成品
        $cat = Category::create(['name' => 'DAG 物料']);
        $unitId = Unit::where('code', 'pc')->firstOrFail()->id;
        $processId = Process::where('code', 'CUT')->firstOrFail()->id;
        $raw = Product::create(['name' => '铝材', 'code' => 'RAW-DAG', 'type' => 'raw_material', 'category_id' => $cat->id, 'unit_id' => $unitId, 'status' => 1]);
        $semiA = Product::create(['name' => '半成品A', 'code' => 'SEMI-DA', 'type' => 'semi_finished', 'category_id' => $cat->id, 'unit_id' => $unitId, 'status' => 1]);
        $semiB = Product::create(['name' => '半成品B', 'code' => 'SEMI-DB', 'type' => 'semi_finished', 'category_id' => $cat->id, 'unit_id' => $unitId, 'status' => 1]);
        $semiC = Product::create(['name' => '半成品C', 'code' => 'SEMI-DC', 'type' => 'semi_finished', 'category_id' => $cat->id, 'unit_id' => $unitId, 'status' => 1]);
        $semiD = Product::create(['name' => '半成品D', 'code' => 'SEMI-DD', 'type' => 'semi_finished', 'category_id' => $cat->id, 'unit_id' => $unitId, 'status' => 1]);
        $fin = Product::create(['name' => '机柜DAG', 'code' => 'FIN-DAG', 'type' => 'finished', 'category_id' => $cat->id, 'unit_id' => $unitId, 'status' => 1]);
        // 成品启用 BOM：原料×3 + 半成品A×1（工单创建前置，与路线数量口径一致）
        $bom = BomHeader::create(['code' => 'BOM-DAG-1', 'product_id' => $fin->id, 'version' => 'v1', 'quantity' => 1, 'status' => 1]);
        $bom->items()->createMany([
            ['material_id' => $raw->id, 'quantity' => 3, 'unit_id' => $unitId],
            ['material_id' => $semiA->id, 'quantity' => 1, 'unit_id' => $unitId],
        ]);

        // 启用钻石路线（OP30 委外）：下达后工单按 DAG 展开快照节点/边
        $this->withToken($this->token)->postJson('/api/v1/routings', [
            'product_id' => $fin->id, 'version' => 'v1', 'quantity' => 3, 'status' => 1, 'remark' => null,
            'nodes' => [
                ['node_no' => 'OP10', 'process_id' => $processId, 'name' => '下料', 'output_product_id' => $semiA->id, 'output_qty' => 3, 'is_outsourced' => 0, 'remark' => null, 'materials' => [
                    ['material_id' => $raw->id, 'qty_per_unit' => 3, 'unit_id' => $unitId],
                ]],
                ['node_no' => 'OP20', 'process_id' => $processId, 'name' => '冲压', 'output_product_id' => $semiB->id, 'output_qty' => 2, 'is_outsourced' => 0, 'remark' => null, 'materials' => [
                    ['material_id' => $semiA->id, 'qty_per_unit' => 1, 'unit_id' => $unitId],
                ]],
                ['node_no' => 'OP30', 'process_id' => $processId, 'name' => '焊接', 'output_product_id' => $semiC->id, 'output_qty' => 2, 'is_outsourced' => 1, 'remark' => null, 'materials' => [
                    ['material_id' => $semiA->id, 'qty_per_unit' => 1, 'unit_id' => $unitId],
                ]],
                ['node_no' => 'OP40', 'process_id' => $processId, 'name' => '组装', 'output_product_id' => $semiD->id,
                    'output_qty' => 2, 'is_outsourced' => 0, 'remark' => null, 'materials' => [
                        ['material_id' => $semiA->id, 'qty_per_unit' => 1, 'unit_id' => $unitId],
                    ]],
                ['node_no' => 'OP50', 'process_id' => $processId, 'name' => '质检', 'output_product_id' => $fin->id,
                    'output_qty' => 1, 'is_outsourced' => 0, 'remark' => null, 'materials' => [
                        ['material_id' => $semiB->id, 'qty_per_unit' => 2, 'unit_id' => $unitId],
                        ['material_id' => $semiC->id, 'qty_per_unit' => 2, 'unit_id' => $unitId],
                        ['material_id' => $semiD->id, 'qty_per_unit' => 2, 'unit_id' => $unitId],
                    ]],
            ],
            'edges' => [
                ['from_node_no' => 'OP10', 'to_node_no' => 'OP20'],
                ['from_node_no' => 'OP10', 'to_node_no' => 'OP30'],
                ['from_node_no' => 'OP10', 'to_node_no' => 'OP40'],
                ['from_node_no' => 'OP20', 'to_node_no' => 'OP50'],
                ['from_node_no' => 'OP30', 'to_node_no' => 'OP50'],
                ['from_node_no' => 'OP40', 'to_node_no' => 'OP50'],
            ],
        ])->assertJsonPath('code', 0);

        // 建单（计划 6）→ 下达 → 开工（入度 0 的 OP10 置进行中）
        $res = $this->withToken($this->token)->postJson('/api/v1/production/orders', [
            'product_id' => $fin->id, 'quantity' => 6, 'plan_date' => now()->toDateString(),
        ]);
        $res->assertJsonPath('code', 0);
        $order = ProductionOrder::where('id', $res->json('data.id'))->firstOrFail();
        $this->withToken($this->token)
            ->postJson("/api/v1/production/orders/{$order->id}/release")
            ->assertJsonPath('code', 0);
        $this->withToken($this->token)
            ->postJson("/api/v1/production/orders/{$order->id}/start")
            ->assertJsonPath('code', 0);

        // 委外发出需扣成品库存（委外商品 = 工单成品），基线注入 6@B-01
        app(InventoryService::class)->apply([[
            'product_id' => $fin->id, 'warehouse_id' => $this->wh->id, 'location_id' => $this->b01->id,
            'direction' => 1, 'quantity' => 6, 'source_type' => 'purchase_inbound', 'source_id' => 0,
            'source_no' => 'SEED', 'remark' => '测试基线',
        ]]);

        // 按 node_no 键控的工序映射（开工后已刷新，直接承载起点状态断言）
        return ['order' => $order, 'ops' => $order->operations()->get()->keyBy('node_no')];
    }

    /** 对工序报合格数量并断言成功（DAG 推进用例的步进动作，同 OperationReportTest） */
    private function report(WorkOrderOperation $op, string $qty): void
    {
        $this->withToken($this->token)->postJson("/api/v1/production/operations/{$op->id}/reports", [
            'qualified_qty' => $qty,
        ])->assertJsonPath('code', 0);
    }

    /** 经 API 建委外单（指定工序×数量）并返回单据行 */
    private function createOutsourcing(ProductionOrder $order, WorkOrderOperation $op, string $qty): OutsourcingOrder
    {
        $res = $this->withToken($this->token)->postJson('/api/v1/production/outsourcings', [
            'order_id' => $order->id,
            'operation_id' => $op->id,
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->wh->id,
            'location_id' => $this->b01->id,
            'quantity' => $qty,
        ]);
        $res->assertJsonPath('code', 0);

        return OutsourcingOrder::where('no', $res->json('data.no'))->firstOrFail();
    }

    /** 发出 + 回收满量（一次性回收） */
    private function approveAndReceipt(OutsourcingOrder $os, string $qty): TestResponse
    {
        $this->withToken($this->token)
            ->postJson("/api/v1/production/outsourcings/{$os->id}/approve")
            ->assertJsonPath('code', 0);

        return $this->withToken($this->token)->postJson("/api/v1/production/outsourcings/{$os->id}/receipts", [
            'quantity' => $qty,
            'warehouse_id' => $this->wh->id,
            'location_id' => $this->b01->id,
        ]);
    }

    // 正向：OP10 报满 → 三分支并行；OP20/OP40 报满 → 汇合点仍待开工；
    // OP30（委外）回收满量 → OP30 已完成 + 汇合点 OP50 进行中（OUT-04 DAG 后继推进，修复回归）
    public function test_receipt_full_advances_dag_join_successor(): void
    {
        ['order' => $order, 'ops' => $ops] = $this->dagOrder();
        // OP10 报满 → 三分支同时进行中（并行），汇合点待开工
        $this->report($ops['OP10'], '6');
        $this->assertSame(WorkOrderOperation::STATUS_DONE, (int) $ops['OP10']->fresh()->status);
        foreach (['OP20', 'OP30', 'OP40'] as $no) {
            $this->assertSame(WorkOrderOperation::STATUS_RUNNING, (int) $ops[$no]->fresh()->status, "节点 {$no} 应进行中");
        }
        $this->assertSame(WorkOrderOperation::STATUS_PENDING, (int) $ops['OP50']->fresh()->status);
        // OP20/OP40 报满 → 汇合点 OP50 仍待开工（委外 OP30 未完成）
        $this->report($ops['OP20'], '6');
        $this->report($ops['OP40'], '6');
        $this->assertSame(WorkOrderOperation::STATUS_RUNNING, (int) $ops['OP30']->fresh()->status);
        $this->assertSame(WorkOrderOperation::STATUS_PENDING, (int) $ops['OP50']->fresh()->status);
        // OP30 委外回收满量 → 节点完成 + 汇合点推进（末批回收先于/后于分支报工均可达）
        $os = $this->createOutsourcing($order, $ops['OP30'], '6');
        $this->approveAndReceipt($os, '6')->assertJsonPath('code', 0);
        $this->assertSame(WorkOrderOperation::STATUS_DONE, (int) $ops['OP30']->fresh()->status);
        $this->assertSame(WorkOrderOperation::STATUS_RUNNING, (int) $ops['OP50']->fresh()->status);
        // 余额不变式：发出 6 → 回收 6，成品余额归零后再回补（FIN-DAG@B-01）
        $this->assertSame('6.00', InventoryBalance::where('product_id', $order->product_id)->first()->quantity);
        $this->assertSame(OutsourcingOrder::STATUS_RECEIVED, $os->fresh()->status);
    }

    // 反例：分支 OP40 未完成时 OP30 回收满量 → OP30 已完成但汇合点仍待开工（全部前驱 DONE 才推进）
    public function test_receipt_full_keeps_join_pending_until_all_preds_done(): void
    {
        ['order' => $order, 'ops' => $ops] = $this->dagOrder();
        $this->report($ops['OP10'], '6');
        $this->report($ops['OP20'], '6');
        // OP40 保留进行中（未完成）
        $this->assertSame(WorkOrderOperation::STATUS_RUNNING, (int) $ops['OP40']->fresh()->status);
        $this->assertSame(WorkOrderOperation::STATUS_PENDING, (int) $ops['OP50']->fresh()->status);
        // OP30 回收满量 → 自身完成，汇合点仍待开工（前驱 OP40 未完成）
        $os = $this->createOutsourcing($order, $ops['OP30'], '6');
        $this->approveAndReceipt($os, '6')->assertJsonPath('code', 0);
        $this->assertSame(WorkOrderOperation::STATUS_DONE, (int) $ops['OP30']->fresh()->status);
        $this->assertSame(WorkOrderOperation::STATUS_PENDING, (int) $ops['OP50']->fresh()->status);
        // OP40 补报满 → 汇合点推进（报工路径既有行为回归）
        $this->report($ops['OP40'], '6');
        $this->assertSame(WorkOrderOperation::STATUS_RUNNING, (int) $ops['OP50']->fresh()->status);
    }

    // 兼容：无路线旧工单委外节点回收满量仅置 DONE，不推进线性后继（修复前后行为一致，保持现状）
    public function test_receipt_full_legacy_order_does_not_advance_next_op(): void
    {
        // 独立成品（无启用路线）：线性 3 工序快照（下料→组装→质检）
        $cat = Category::create(['name' => '旧工单物料']);
        $unitId = Unit::where('code', 'pc')->firstOrFail()->id;
        $fin = Product::create([
            'name' => '成品旧', 'code' => 'FIN-LEG', 'type' => 'finished',
            'category_id' => $cat->id, 'unit_id' => $unitId, 'status' => 1,
        ]);
        $bom = BomHeader::create([
            'code' => 'BOM-LEG-1', 'product_id' => $fin->id, 'version' => 'v1', 'quantity' => 1, 'status' => 1,
        ]);
        $res = $this->withToken($this->token)->postJson('/api/v1/production/orders', [
            'product_id' => $fin->id, 'quantity' => 5, 'plan_date' => now()->toDateString(),
        ]);
        $res->assertJsonPath('code', 0);
        $order = ProductionOrder::where('id', $res->json('data.id'))->firstOrFail();
        $this->assertNull($order->routing_id);
        $this->withToken($this->token)
            ->postJson("/api/v1/production/orders/{$order->id}/release")
            ->assertJsonPath('code', 0);
        $this->withToken($this->token)
            ->postJson("/api/v1/production/orders/{$order->id}/start")
            ->assertJsonPath('code', 0);
        // 首工序（下料）报满 → 组装（委外对象）进行中
        $ops = $order->operations()->orderBy('seq')->get();
        $this->report($ops[0], '5');
        $this->assertSame(WorkOrderOperation::STATUS_RUNNING, (int) $ops[1]->fresh()->status);
        // 委外发出需扣成品库存：基线注入 5@B-01
        app(InventoryService::class)->apply([[
            'product_id' => $fin->id, 'warehouse_id' => $this->wh->id, 'location_id' => $this->b01->id,
            'direction' => 1, 'quantity' => 5, 'source_type' => 'purchase_inbound', 'source_id' => 0,
            'source_no' => 'SEED', 'remark' => '测试基线',
        ]]);
        // 组装回收满量 → 组装 DONE，质检仍待开工（旧工单不推进，行为不变）
        $os = $this->createOutsourcing($order, $ops[1], '5');
        $this->approveAndReceipt($os, '5')->assertJsonPath('code', 0);
        $this->assertSame(WorkOrderOperation::STATUS_DONE, (int) $ops[1]->fresh()->status);
        $this->assertSame(WorkOrderOperation::STATUS_PENDING, (int) $ops[2]->fresh()->status);
    }
}
