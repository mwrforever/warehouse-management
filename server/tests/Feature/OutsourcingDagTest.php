<?php

// 委外回收 DAG 推进测试（修复回归，对应 E2E TC-RTG-04）：委外节点（OP30）回收满量 → 汇合点后继推进（OUT-04）；
// 分支未全完成不推进；复用 DagOrderFactory 钻石基线（OP30 委外，组件口径：原料×2/半成品B×1 应发，产出半成品B）

namespace Tests\Feature;

use App\Models\InventoryBalance;
use App\Models\Location;
use App\Models\OutsourcingOrder;
use App\Models\Process;
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
use Tests\Feature\Concerns\DagOrderFactory;
use Tests\TestCase;

class OutsourcingDagTest extends TestCase
{
    use DagOrderFactory;
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
        // DagOrderFactory 依赖的基线字典（单位 pc / 工序 CUT）
        Unit::create(['name' => '个', 'code' => 'pc']);
        Process::create(['name' => '下料', 'code' => 'CUT', 'sort' => 1, 'status' => 1]);
    }

    /** 对工序报合格数量并断言成功（DAG 推进用例的步进动作，同 OperationReportTest） */
    private function report(WorkOrderOperation $op, string $qty): void
    {
        $this->withToken($this->token)->postJson("/api/v1/production/operations/{$op->id}/reports", [
            'qualified_qty' => $qty,
        ])->assertJsonPath('code', 0);
    }

    /**
     * 经 API 建委外单（OP30 委外，组件载荷=单位用量折算应发：原料×2、半成品B×1）并返回单据行；
     * 前置注入组件基线（原料/半成品B = 应发 @B-01）——发出按应发全额扣减归零，回收回补半成品B
     */
    private function createOutsourcing(array $dag, string $qty): OutsourcingOrder
    {
        ['raw' => $raw, 'semiB' => $semiB, 'unit' => $unit] = $dag;
        app(InventoryService::class)->apply([
            ['product_id' => $raw->id, 'warehouse_id' => $this->wh->id, 'location_id' => $this->b01->id,
                'direction' => 1, 'quantity' => bcmul($qty, '2', 2), 'source_type' => 'purchase_inbound', 'source_id' => 0,
                'source_no' => 'SEED', 'remark' => '测试基线'],
            ['product_id' => $semiB->id, 'warehouse_id' => $this->wh->id, 'location_id' => $this->b01->id,
                'direction' => 1, 'quantity' => $qty, 'source_type' => 'purchase_inbound', 'source_id' => 0,
                'source_no' => 'SEED', 'remark' => '测试基线'],
        ]);
        $res = $this->withToken($this->token)->postJson('/api/v1/production/outsourcings', [
            'order_id' => $dag['order']->id,
            'operation_id' => $dag['ops']['OP30']->id,
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->wh->id,
            'location_id' => $this->b01->id,
            'quantity' => $qty,
            'items' => [
                ['material_id' => $raw->id, 'required_qty' => bcmul($qty, '2', 2), 'unit_id' => $unit->id],
                ['material_id' => $semiB->id, 'required_qty' => $qty, 'unit_id' => $unit->id],
            ],
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

    // 组件余额读取（该委外仓位的余额行；无行=0，decimal 归一字符串——测试断言口径与实现 bcmath 一致）
    private function balanceOf(int $productId): string
    {
        $balance = InventoryBalance::where('product_id', $productId)->first();

        return $balance ? (string) $balance->quantity : '0';
    }

    // 正向：OP10 报满 → 三分支并行；OP20/OP40 报满 → 汇合点仍待开工；
    // OP30（委外）回收满量 → OP30 已完成 + 汇合点 OP50 进行中（OUT-04 DAG 后继推进，修复回归）
    public function test_receipt_full_advances_dag_join_successor(): void
    {
        $dag = $this->dagOrder();
        $ops = $dag['ops'];
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
        $os = $this->createOutsourcing($dag, '6');
        $this->approveAndReceipt($os, '6')->assertJsonPath('code', 0);
        $this->assertSame(WorkOrderOperation::STATUS_DONE, (int) $ops['OP30']->fresh()->status);
        $this->assertSame(WorkOrderOperation::STATUS_RUNNING, (int) $ops['OP50']->fresh()->status);
        // 组件不变式：发出扣光两组件（原料/半成品B 归零）→ 回收回补半成品B（0→6），原料保持 0
        $this->assertSame('0.00', $this->balanceOf($dag['raw']->id));
        $this->assertSame('6.00', $this->balanceOf($dag['semiB']->id));
        $this->assertSame(OutsourcingOrder::STATUS_RECEIVED, $os->fresh()->status);
    }

    // 反例：分支 OP40 未完成时 OP30 回收满量 → OP30 已完成但汇合点仍待开工（全部前驱 DONE 才推进）
    public function test_receipt_full_keeps_join_pending_until_all_preds_done(): void
    {
        $dag = $this->dagOrder();
        $ops = $dag['ops'];
        $this->report($ops['OP10'], '6');
        $this->report($ops['OP20'], '6');
        // OP40 保留进行中（未完成）
        $this->assertSame(WorkOrderOperation::STATUS_RUNNING, (int) $ops['OP40']->fresh()->status);
        $this->assertSame(WorkOrderOperation::STATUS_PENDING, (int) $ops['OP50']->fresh()->status);
        // OP30 回收满量 → 自身完成，汇合点仍待开工（前驱 OP40 未完成）
        $os = $this->createOutsourcing($dag, '6');
        $this->approveAndReceipt($os, '6')->assertJsonPath('code', 0);
        $this->assertSame(WorkOrderOperation::STATUS_DONE, (int) $ops['OP30']->fresh()->status);
        $this->assertSame(WorkOrderOperation::STATUS_PENDING, (int) $ops['OP50']->fresh()->status);
        // OP40 补报满 → 汇合点推进（报工路径既有行为回归）
        $this->report($ops['OP40'], '6');
        $this->assertSame(WorkOrderOperation::STATUS_RUNNING, (int) $ops['OP50']->fresh()->status);
    }
}
