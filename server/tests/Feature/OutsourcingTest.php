<?php

// 委外加工接口测试：CRUD/发出扣组件/回收加回收品（节点输出）/分批回收/超收/工序联动/幂等（核心路径 100%）

namespace Tests\Feature;

use App\Models\BomHeader;
use App\Models\Category;
use App\Models\InventoryBalance;
use App\Models\InventoryMovement;
use App\Models\Location;
use App\Models\OutsourcingOrder;
use App\Models\OutsourcingReceipt;
use App\Models\Process;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\Role;
use App\Models\RoutingNode;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WorkOrderOperation;
use App\Services\InventoryService;
use Database\Seeders\DocumentNumberConfigSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\DagOrderFactory;
use Tests\TestCase;

class OutsourcingTest extends TestCase
{
    use DagOrderFactory;
    use RefreshDatabase;

    private string $token;

    private User $admin;

    private Warehouse $wh;

    private Location $b01;

    private Unit $unit;

    private Supplier $supplier;

    private ProductionOrder $order;

    private array $dag; // DagOrderFactory 钻石基线（OP30 委外节点 + 物料行）

    private int $assemblyOpId; // 组装（委外）工序 id

    protected function setUp(): void
    {
        parent::setUp();
        // 编号规则配置种子（Spec 2）：单据号按配置生成 CK/PO/MO 等业务前缀
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

    // 基准 DAG 工单（OP30 委外，计划 6）+ 组件基线（原料 12/半成品B 6 @B-01——默认载荷 5 的应发 10/5 余量）；
    // 供 payload 族用例共享（各用例自建造数，避免在 setUp 预建导致二次 dagOrder 撞唯一键）
    private function baseDag(): array
    {
        $this->dag = $this->dagOrder();
        $this->order = $this->dag['order'];
        $this->unit = $this->dag['unit'];
        $this->assemblyOpId = $this->dag['ops']['OP30']->id;
        app(InventoryService::class)->apply([
            ['product_id' => $this->dag['raw']->id, 'warehouse_id' => $this->wh->id, 'location_id' => $this->b01->id,
                'direction' => 1, 'quantity' => 12, 'source_type' => 'purchase_inbound', 'source_id' => 0,
                'source_no' => 'SEED', 'remark' => '测试基线'],
            ['product_id' => $this->dag['semiB']->id, 'warehouse_id' => $this->wh->id, 'location_id' => $this->b01->id,
                'direction' => 1, 'quantity' => 6, 'source_type' => 'purchase_inbound', 'source_id' => 0,
                'source_no' => 'SEED', 'remark' => '测试基线'],
        ]);

        return $this->dag;
    }

    // 组装委外载荷（默认 OP30 委外节点×5，发出仓 B-01——与组件基线同库位，审核扣减与回收入账落在同一余额行；
    // items=节点输入材料折算应发：原料×2/半成品B×1 单位用量 → 10/5，载荷必填（422 格式层））
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'order_id' => $this->order->id,
            'operation_id' => $this->assemblyOpId,
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->wh->id,
            'location_id' => $this->b01->id,
            'quantity' => 5,
            'items' => [
                ['material_id' => $this->dag['raw']->id, 'required_qty' => 10, 'unit_id' => $this->unit->id],
                ['material_id' => $this->dag['semiB']->id, 'required_qty' => 5, 'unit_id' => $this->unit->id],
            ],
        ], $overrides);
    }

    // 通过 API 建草稿委外单并返回单号
    private function createOutsourcing(array $payload): string
    {
        $res = $this->withToken($this->token)->postJson('/api/v1/production/outsourcings', $payload);
        $res->assertJsonPath('code', 0);

        return $res->json('data.no');
    }

    // DAG 委外单辅助：建 OP30 委外单（数量 6，组件=节点输入原料 12/半成品B 6）并审核发出
    // （组件基线注入后按应发全额扣减归零；回收品=节点输出半成品B——1529 一致性口径落地后回收断言以此为准）
    private function approvedDagOutsourcing(array $dag): OutsourcingOrder
    {
        app(InventoryService::class)->apply([
            ['product_id' => $dag['raw']->id, 'warehouse_id' => $this->wh->id, 'location_id' => $this->b01->id,
                'direction' => 1, 'quantity' => 12, 'source_type' => 'purchase_inbound', 'source_id' => 0,
                'source_no' => 'SEED', 'remark' => '测试基线'],
            ['product_id' => $dag['semiB']->id, 'warehouse_id' => $this->wh->id, 'location_id' => $this->b01->id,
                'direction' => 1, 'quantity' => 6, 'source_type' => 'purchase_inbound', 'source_id' => 0,
                'source_no' => 'SEED', 'remark' => '测试基线'],
        ]);
        $no = $this->createOutsourcing([
            'order_id' => $dag['order']->id,
            'operation_id' => $dag['ops']['OP30']->id,
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->wh->id,
            'location_id' => $this->b01->id,
            'quantity' => 6,
            'items' => [
                ['material_id' => $dag['raw']->id, 'required_qty' => 12, 'unit_id' => $dag['unit']->id],
                ['material_id' => $dag['semiB']->id, 'required_qty' => 6, 'unit_id' => $dag['unit']->id],
            ],
        ]);
        $os = OutsourcingOrder::where('no', $no)->firstOrFail();
        $this->withToken($this->token)->postJson("/api/v1/production/outsourcings/{$os->id}/approve")
            ->assertJsonPath('code', 0);

        return $os;
    }

    public function test_store_creates_draft_with_no(): void
    {
        // 正常路径：草稿创建成功，单号 OS{date}-001
        $this->baseDag();
        $no = $this->createOutsourcing($this->payload());
        $this->assertMatchesRegularExpression('/^OS\d{12}001$/', $no);
        $os = OutsourcingOrder::where('no', $no)->first();
        $this->assertSame(OutsourcingOrder::STATUS_DRAFT, $os->status);
        $this->assertSame('5.00', $os->quantity);
    }

    public function test_store_rejects_over_plan_with_1520(): void
    {
        // 异常路径：委外量超节点剩余计划量（7 > 工单计划 6）→ 1520
        $this->baseDag();
        $this->withToken($this->token)->postJson('/api/v1/production/outsourcings', $this->payload(['quantity' => 7]))
            ->assertJsonPath('code', 1520)
            ->assertJsonPath('message', '委外数量超过节点剩余计划量');
    }

    public function test_store_rejects_operation_not_in_order_with_422(): void
    {
        // 异常路径：工序不属于该工单 → 422（格式层；spec 码段满）
        $this->baseDag();
        $other = ProductionOrder::where('no', '!=', $this->order->no)->first();
        if (! $other) {
            // 建第二个工单的工序作反例（同一成品，二次快照独立 DAG）
            $res = $this->withToken($this->token)->postJson('/api/v1/production/orders', [
                'product_id' => $this->dag['fin']->id, 'quantity' => 5, 'plan_date' => now()->toDateString(),
            ]);
            $res->assertJsonPath('code', 0);
            $other = ProductionOrder::where('no', $res->json('data.no'))->first();
        }
        $foreignOp = $other->operations()->where('order_id', '!=', $this->order->id)->first();
        $this->withToken($this->token)->postJson('/api/v1/production/outsourcings', $this->payload(['operation_id' => $foreignOp->id]))
            ->assertJsonPath('code', 422);
    }

    public function test_store_rejects_non_positive_qty_with_422(): void
    {
        // 异常路径：委外数量 ≤ 0 → 422（格式层；spec 码段满）
        $this->baseDag();
        $this->withToken($this->token)->postJson('/api/v1/production/outsourcings', $this->payload(['quantity' => 0]))
            ->assertJsonPath('code', 422);
    }

    public function test_store_rejects_missing_supplier_with_422(): void
    {
        // 异常路径：供应商缺失 → 422（格式层）
        $this->baseDag();
        $this->withToken($this->token)->postJson('/api/v1/production/outsourcings', $this->payload(['supplier_id' => null]))
            ->assertJsonPath('code', 422);
    }

    public function test_store_rejects_missing_warehouse_or_location_with_422(): void
    {
        // 异常路径：仓库/库位缺失 → 422（格式层）
        $this->baseDag();
        $this->withToken($this->token)->postJson('/api/v1/production/outsourcings', $this->payload(['warehouse_id' => null]))
            ->assertJsonPath('code', 422);
        $this->withToken($this->token)->postJson('/api/v1/production/outsourcings', $this->payload(['location_id' => null]))
            ->assertJsonPath('code', 422);
    }

    // 核心不变式（发出，默认载荷 5）：组件余额 12→2、6→1，分量 outsourcing_out 流水（direction=-1、
    // 商品=发料组件）+ 操作人/时间落库
    public function test_approve_deducts_default_payload_components_and_writes_movement(): void
    {
        $this->baseDag();
        $no = $this->createOutsourcing($this->payload());
        $os = OutsourcingOrder::where('no', $no)->first();
        $this->withToken($this->token)->postJson("/api/v1/production/outsourcings/{$os->id}/approve")
            ->assertJsonPath('code', 0);
        $this->assertSame('2.00', $this->balanceOf($this->dag['raw']->id));
        $this->assertSame('1.00', $this->balanceOf($this->dag['semiB']->id));
        $this->assertDatabaseHas('inventory_movements', [
            'product_id' => $this->dag['raw']->id, 'direction' => -1, 'quantity' => '10.00',
            'balance_after' => '2.00', 'source_type' => 'outsourcing_out', 'source_no' => $no,
        ]);
        $this->assertDatabaseHas('inventory_movements', [
            'product_id' => $this->dag['semiB']->id, 'direction' => -1, 'quantity' => '5.00',
            'balance_after' => '1.00', 'source_type' => 'outsourcing_out', 'source_no' => $no,
        ]);
        $os->refresh();
        $this->assertSame(OutsourcingOrder::STATUS_APPROVED, $os->status);
        $this->assertSame('管理员', $os->operator);
        $this->assertNotNull($os->approved_at);
    }

    public function test_approve_idempotent_with_1523(): void
    {
        // 核心不变式：重复审核 → 1523，组件库存不重复扣减（发出后原料 12→2，二次拒绝后保持 2）
        $this->baseDag();
        $no = $this->createOutsourcing($this->payload());
        $os = OutsourcingOrder::where('no', $no)->first();
        $this->withToken($this->token)->postJson("/api/v1/production/outsourcings/{$os->id}/approve")->assertJsonPath('code', 0);
        $this->withToken($this->token)->postJson("/api/v1/production/outsourcings/{$os->id}/approve")
            ->assertJsonPath('code', 1523)
            ->assertJsonPath('message', '该委外单已审核');
        $this->assertSame('2.00', $this->balanceOf($this->dag['raw']->id));
    }

    public function test_receipt_credits_inventory_and_marks_received(): void
    {
        // 核心不变式（回收，DAG 口径）：回收品=节点输出半成品B——发出扣光后回收回补（0→6）、
        // outsourcing_in 流水(+6，单号 OSR..，商品=半成品B)、委外单已回收、工序标记完成
        $dag = $this->dagOrder();
        ['semiB' => $semiB] = $dag;
        $os = $this->approvedDagOutsourcing($dag);
        $res = $this->withToken($this->token)->postJson("/api/v1/production/outsourcings/{$os->id}/receipts", [
            'quantity' => 6, 'warehouse_id' => $this->wh->id, 'location_id' => $this->b01->id, 'remark' => '回收',
        ]);
        $res->assertJsonPath('code', 0)
            ->assertJsonPath('data.no', 'OSR'.date('YmdHi').'001');
        $this->assertSame('6.00', $this->balanceOf($semiB->id));
        $this->assertDatabaseHas('inventory_movements', [
            'product_id' => $semiB->id, 'direction' => 1, 'quantity' => '6.00',
            'balance_after' => '6.00', 'source_type' => 'outsourcing_in', 'source_no' => 'OSR'.date('YmdHi').'001',
        ]);
        $os->refresh();
        $this->assertSame(OutsourcingOrder::STATUS_RECEIVED, $os->status);
        // 委外工序（焊接 OP30）标记完成（spec §6：回收量≥委外量时）
        $this->assertSame(WorkOrderOperation::STATUS_DONE, WorkOrderOperation::find($os->operation_id)->status);
        // 回收单落库
        $this->assertDatabaseHas('outsourcing_receipts', [
            'outsourcing_id' => $os->id, 'quantity' => '6.00', 'status' => OutsourcingReceipt::STATUS_APPROVED,
        ]);
    }

    public function test_receipt_allows_partial_batches_and_rejects_over_with_1524(): void
    {
        // 边界路径：分批回收（3+3，DAG 口径回收品=半成品B）；累计超委外量（再收 1）→ 1524 拦截且不产生流水
        $dag = $this->dagOrder();
        ['ops' => $ops, 'semiB' => $semiB] = $dag;
        $os = $this->approvedDagOutsourcing($dag);
        // 首工序（下料）报满 → 委外工序（焊接）置进行中（DAG 推进前置，与 E2E TC-PRD-06 流程一致）
        $this->withToken($this->token)->postJson("/api/v1/production/operations/{$ops['OP10']->id}/reports", [
            'qualified_qty' => 6,
        ])->assertJsonPath('code', 0);
        // 第一批 3
        $this->withToken($this->token)->postJson("/api/v1/production/outsourcings/{$os->id}/receipts", [
            'quantity' => 3, 'warehouse_id' => $this->wh->id, 'location_id' => $this->b01->id,
        ])->assertJsonPath('code', 0);
        $this->assertSame('3.00', $this->balanceOf($semiB->id));
        // 状态未回收（累计 3 < 6），工序未完成
        $this->assertSame(OutsourcingOrder::STATUS_APPROVED, $os->refresh()->status);
        $this->assertSame(WorkOrderOperation::STATUS_RUNNING, WorkOrderOperation::find($os->operation_id)->status);
        // 第二批 3 → 已回收（累计 6 = 委外量 6）
        $this->withToken($this->token)->postJson("/api/v1/production/outsourcings/{$os->id}/receipts", [
            'quantity' => 3, 'warehouse_id' => $this->wh->id, 'location_id' => $this->b01->id,
        ])->assertJsonPath('code', 0);
        $this->assertSame('6.00', $this->balanceOf($semiB->id));
        $this->assertSame(OutsourcingOrder::STATUS_RECEIVED, $os->refresh()->status);
        // 超收（累计已 6，再收 1）→ 1524 整体回滚
        $res = $this->withToken($this->token)->postJson("/api/v1/production/outsourcings/{$os->id}/receipts", [
            'quantity' => 1, 'warehouse_id' => $this->wh->id, 'location_id' => $this->b01->id,
        ]);
        $res->assertJsonPath('code', 1524)
            ->assertJsonPath('message', '回收数量超过委外数量');
        $this->assertSame('6.00', $this->balanceOf($semiB->id));
        $this->assertDatabaseCount('outsourcing_receipts', 2);
    }

    public function test_receipt_rejects_draft_outsourcing_with_422(): void
    {
        // 异常路径：未发出（草稿）不可回收 → 422
        $this->baseDag();
        $no = $this->createOutsourcing($this->payload());
        $os = OutsourcingOrder::where('no', $no)->first();
        $this->withToken($this->token)->postJson("/api/v1/production/outsourcings/{$os->id}/receipts", [
            'quantity' => 1, 'warehouse_id' => $this->wh->id, 'location_id' => $this->b01->id,
        ])->assertJsonPath('code', 422);
    }

    public function test_receipt_rejects_order_not_released_or_producing_with_1523(): void
    {
        // 异常路径（bug #2 回归）：发出后工单被关闭 → 回收被拒 1523（与发出 approve 同口径），无流水无回收单
        $dag = $this->dagOrder();
        $os = $this->approvedDagOutsourcing($dag);
        // 发出已按组件扣减：原料 12→0、半成品B 6→0
        $this->assertSame('0.00', $this->balanceOf($dag['raw']->id));
        $this->assertSame('0.00', $this->balanceOf($dag['semiB']->id));
        $dag['order']->status = ProductionOrder::STATUS_CLOSED;
        $dag['order']->save();
        $this->withToken($this->token)->postJson("/api/v1/production/outsourcings/{$os->id}/receipts", [
            'quantity' => 6, 'warehouse_id' => $this->wh->id, 'location_id' => $this->b01->id,
        ])
            ->assertJsonPath('code', 1523)
            ->assertJsonPath('message', '工单当前状态不可委外');
        // 被拒回收：库存不变、无回收单、委外单仍为已发出
        $this->assertSame('0.00', $this->balanceOf($dag['semiB']->id));
        $this->assertDatabaseCount('outsourcing_receipts', 0);
        $this->assertSame(OutsourcingOrder::STATUS_APPROVED, $os->refresh()->status);
    }

    public function test_receipts_index_lists_records(): void
    {
        // 正常路径：回收记录列表（单号/数量/时间）
        $dag = $this->dagOrder();
        $os = $this->approvedDagOutsourcing($dag);
        $this->withToken($this->token)->postJson("/api/v1/production/outsourcings/{$os->id}/receipts", [
            'quantity' => 6, 'warehouse_id' => $this->wh->id, 'location_id' => $this->b01->id,
        ])->assertJsonPath('code', 0);
        $this->withToken($this->token)->getJson("/api/v1/production/outsourcings/{$os->id}/receipts")
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.items.0.quantity', '6.00')
            ->assertJsonPath('data.items.0.no', 'OSR'.date('YmdHi').'001');
    }

    public function test_update_destroy_draft_ok_approved_rejected_with_1521(): void
    {
        // 正常+异常路径：草稿可改可删；已审核不可改删 → 1521
        $this->baseDag();
        $no = $this->createOutsourcing($this->payload());
        $id = OutsourcingOrder::where('no', $no)->first()->id;
        $this->withToken($this->token)->putJson("/api/v1/production/outsourcings/{$id}", $this->payload(['remark' => '改后']))
            ->assertJsonPath('code', 0);
        $this->withToken($this->token)->deleteJson("/api/v1/production/outsourcings/{$id}")
            ->assertJsonPath('code', 0);
        $this->assertDatabaseMissing('outsourcing_orders', ['id' => $id]);

        $no2 = $this->createOutsourcing($this->payload());
        $os2 = OutsourcingOrder::where('no', $no2)->first();
        $this->withToken($this->token)->postJson("/api/v1/production/outsourcings/{$os2->id}/approve")->assertJsonPath('code', 0);
        $this->withToken($this->token)->putJson("/api/v1/production/outsourcings/{$os2->id}", $this->payload())
            ->assertJsonPath('code', 1521);
        $this->withToken($this->token)->deleteJson("/api/v1/production/outsourcings/{$os2->id}")
            ->assertJsonPath('code', 1521);
    }

    public function test_index_with_labels_and_outsourcings_requires_permission(): void
    {
        // 正常路径：列表含工单单号/供应商/工序名/状态标签 + 节点口径字段（节点号/回收品/已回收累计）
        $this->baseDag();
        $no = $this->createOutsourcing($this->payload());
        $os = OutsourcingOrder::where('no', $no)->first();
        $this->withToken($this->token)->postJson("/api/v1/production/outsourcings/{$os->id}/approve")->assertJsonPath('code', 0);
        $this->withToken($this->token)->getJson('/api/v1/production/outsourcings')
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.items.0.order_no', 'MO'.date('YmdHi').'001')
            ->assertJsonPath('data.items.0.supplier_name', '测试供应商')
            ->assertJsonPath('data.items.0.process_name', '下料')
            ->assertJsonPath('data.items.0.status_label', '已审核')
            // 节点口径契约：委外工序展示=节点号、回收品=节点输出（半成品B）、已回收累计=0
            ->assertJsonPath('data.items.0.node_no', 'OP30')
            ->assertJsonPath('data.items.0.output_product_name', '半成品B')
            ->assertJsonPath('data.items.0.received_qty', '0.00');
        // 异常路径：无 production.outsource.list 权限的角色被拒（403 JSON 信封）
        $role = Role::create(['name' => '普通', 'code' => 'plain']);
        $u = User::create(['name' => '普通', 'username' => 'plain', 'password' => 'admin123', 'status' => 1]);
        $u->roles()->sync([$role->id]);
        $token = $u->createToken('api')->plainTextToken;
        // 测试框架在同一 app 实例内缓存 auth guard 的已认证用户（setUp 已用管理员 token 请求过；
        // 真实 HTTP 每次请求独立容器不受影响），故先重置 guard，再以普通用户 token 验证无权限被拒
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/production/outsourcings')->assertStatus(403);
    }

    // show：详情含头信息（节点号/回收品/已回收累计）+ 组件明细（material_name/应发/已发/已退/单位）
    public function test_show_returns_detail_with_items_and_received_qty(): void
    {
        $this->baseDag();
        $no = $this->createOutsourcing($this->payload());
        $os = OutsourcingOrder::where('no', $no)->firstOrFail();
        // 草稿详情：节点口径字段 + 组件行（应发 10/5，未发出已发=0）
        $this->withToken($this->token)->getJson("/api/v1/production/outsourcings/{$os->id}")
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.node_no', 'OP30')
            ->assertJsonPath('data.process_name', '下料')
            ->assertJsonPath('data.output_product_name', '半成品B')
            ->assertJsonPath('data.status_label', '草稿')
            ->assertJsonPath('data.received_qty', '0.00')
            ->assertJsonPath('data.items.0.material_name', '铝材')
            ->assertJsonPath('data.items.0.required_qty', '10.00')
            ->assertJsonPath('data.items.0.issued_qty', '0.00')
            ->assertJsonPath('data.items.0.returned_qty', '0.00')
            ->assertJsonPath('data.items.1.material_name', '半成品B')
            ->assertJsonPath('data.items.1.required_qty', '5.00');
        // 发出后：已发=应发（实发口径）；回收一批后已回收累计回写
        $this->withToken($this->token)->postJson("/api/v1/production/outsourcings/{$os->id}/approve")->assertJsonPath('code', 0);
        $this->withToken($this->token)->postJson("/api/v1/production/outsourcings/{$os->id}/receipts", [
            'quantity' => 2, 'warehouse_id' => $this->wh->id, 'location_id' => $this->b01->id,
        ])->assertJsonPath('code', 0);
        $this->withToken($this->token)->getJson("/api/v1/production/outsourcings/{$os->id}")
            ->assertJsonPath('data.status_label', '已审核')
            ->assertJsonPath('data.received_qty', '2.00')
            ->assertJsonPath('data.items.0.issued_qty', '10.00')
            ->assertJsonPath('data.items.1.issued_qty', '5.00');
    }

    // OUT-01：从工序节点预填组件清单（应发基数=节点单位用量）+ 回收品 + 剩余可委外量（DAG 委外节点 OP30：
    // 输入=原料×2/半成品B×1，产出=半成品B，工单计划 6）
    public function test_from_operation_prefills_components(): void
    {
        ['ops' => $ops, 'raw' => $raw, 'semiB' => $semiB] = $this->dagOrder();
        $res = $this->withToken($this->token)
            ->getJson("/api/v1/production/outsourcings/from-operation/{$ops['OP30']->id}");
        $res->assertJsonPath('code', 0)
            ->assertJsonPath('data.output_product_id', $semiB->id)
            ->assertJsonPath('data.plan_qty', '6.00')
            ->assertJsonPath('data.outsourced_qty', '0.00')
            ->assertJsonPath('data.remaining_qty', '6.00');
        $items = collect($res->json('data.items'));
        $this->assertSame('2.00', $items->firstWhere('material_id', $raw->id)['qty_per_unit']);
        $this->assertSame('1.00', $items->firstWhere('material_id', $semiB->id)['qty_per_unit']);
    }

    // OUT-01：非委外节点不可预填（422 结构不符）
    public function test_from_operation_rejects_non_outsourced_node(): void
    {
        ['ops' => $ops] = $this->dagOrder();
        $this->withToken($this->token)
            ->getJson("/api/v1/production/outsourcings/from-operation/{$ops['OP20']->id}")
            ->assertJsonPath('code', 422);
    }

    // OUT-01：带组件新建草稿（items 落库 + output_product 快照自节点）
    public function test_store_creates_draft_with_items(): void
    {
        ['order' => $order, 'ops' => $ops, 'raw' => $raw, 'semiB' => $semiB] = $this->dagOrder();
        $no = $this->createOutsourcing([
            'order_id' => $order->id,
            'operation_id' => $ops['OP30']->id,
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->wh->id,
            'location_id' => $this->b01->id,
            'quantity' => 6,
            'items' => [
                ['material_id' => $raw->id, 'required_qty' => 12, 'unit_id' => $raw->unit_id],
                ['material_id' => $semiB->id, 'required_qty' => 6, 'unit_id' => $semiB->unit_id],
            ],
        ]);
        $this->assertDatabaseHas('outsourcing_order_items', [
            'material_id' => $raw->id, 'required_qty' => '12.00', 'issued_qty' => '0.00',
        ]);
        $this->assertSame($semiB->id, OutsourcingOrder::where('no', $no)->first()->output_product_id);
    }

    // 载荷校验：应发超单位用量×数量 → 422（bcmath 后端权威）
    public function test_store_rejects_items_over_required_cap(): void
    {
        ['order' => $order, 'ops' => $ops, 'raw' => $raw] = $this->dagOrder();
        $this->withToken($this->token)->postJson('/api/v1/production/outsourcings', [
            'order_id' => $order->id,
            'operation_id' => $ops['OP30']->id,
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->wh->id,
            'location_id' => $this->b01->id,
            'quantity' => 6,
            'items' => [['material_id' => $raw->id, 'required_qty' => 13, 'unit_id' => $raw->unit_id]],
        ])->assertJsonPath('code', 422)
            ->assertJsonPath('message', '应发数量超过单位用量折算上限');
    }

    // 载荷校验：组件物料重复 → 422（格式层拦截，防重复物料直落撞唯一键 uniq_outsourcing_order_items 抛 500）
    public function test_store_rejects_duplicate_items_with_422(): void
    {
        ['order' => $order, 'ops' => $ops, 'raw' => $raw] = $this->dagOrder();
        $this->withToken($this->token)->postJson('/api/v1/production/outsourcings', [
            'order_id' => $order->id,
            'operation_id' => $ops['OP30']->id,
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->wh->id,
            'location_id' => $this->b01->id,
            'quantity' => 6,
            'items' => [
                ['material_id' => $raw->id, 'required_qty' => 6, 'unit_id' => $raw->unit_id],
                ['material_id' => $raw->id, 'required_qty' => 6, 'unit_id' => $raw->unit_id],
            ],
        ])->assertJsonPath('code', 422)
            ->assertJsonPath('message', '发料组件重复');
    }

    // 修复轮 2（N1）：有路线但节点缺失（数据异常，如节点改名/删除）→ store 返回 422 而非 500，不落单据
    public function test_store_rejects_missing_routing_node_with_422(): void
    {
        ['order' => $order, 'ops' => $ops, 'raw' => $raw] = $this->dagOrder();
        // 模拟数据异常：路线节点 OP30 改名，工单工序快照 node_no 仍指向 OP30
        RoutingNode::where('node_no', 'OP30')->update(['node_no' => 'OP30-OLD']);
        $this->withToken($this->token)->postJson('/api/v1/production/outsourcings', [
            'order_id' => $order->id,
            'operation_id' => $ops['OP30']->id,
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->wh->id,
            'location_id' => $this->b01->id,
            'quantity' => 6,
            'items' => [['material_id' => $raw->id, 'required_qty' => 6, 'unit_id' => $raw->unit_id]],
        ])->assertJsonPath('code', 422)
            ->assertJsonPath('message', '工艺路线节点不存在');
        $this->assertDatabaseCount('outsourcing_orders', 0);
    }

    // 修复轮 1（评审 I1）：仅 is_outsourced=1 节点可委外——无启用路线的工单 store/from-operation 均 422
    // 「该工单没有工艺路线，不可委外」且不落单据（原 legacy 用例删除后该分支零覆盖，补测）
    public function test_store_and_from_operation_reject_order_without_routing_with_422(): void
    {
        // 独立成品（无启用路线）：BOM 启用 + 全量工序线性快照（回退旧逻辑告警路径）
        $cat = Category::create(['name' => '无路线物料']);
        $unit = Unit::where('code', 'pc')->firstOrFail();
        $fin = Product::create([
            'name' => '成品无路线', 'code' => 'FIN-NOROUTE', 'type' => 'finished',
            'category_id' => $cat->id, 'unit_id' => $unit->id, 'status' => 1,
        ]);
        BomHeader::create(['code' => 'BOM-NOROUTE-1', 'product_id' => $fin->id, 'version' => 'v1', 'quantity' => 1, 'status' => 1]);
        $res = $this->withToken($this->token)->postJson('/api/v1/production/orders', [
            'product_id' => $fin->id, 'quantity' => 5, 'plan_date' => now()->toDateString(),
        ]);
        $res->assertJsonPath('code', 0);
        $order = ProductionOrder::where('no', $res->json('data.no'))->firstOrFail();
        $this->assertNull($order->routing_id);
        $this->withToken($this->token)->postJson("/api/v1/production/orders/{$order->id}/release")->assertJsonPath('code', 0);
        $this->withToken($this->token)->postJson("/api/v1/production/orders/{$order->id}/start")->assertJsonPath('code', 0);
        $op = $order->operations()->firstOrFail();
        // from-operation 预填同口径 422
        $this->withToken($this->token)->getJson("/api/v1/production/outsourcings/from-operation/{$op->id}")
            ->assertJsonPath('code', 422)
            ->assertJsonPath('message', '该工单没有工艺路线，不可委外');
        // store 建单同口径 422 且不落单据
        $this->withToken($this->token)->postJson('/api/v1/production/outsourcings', [
            'order_id' => $order->id,
            'operation_id' => $op->id,
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->wh->id,
            'location_id' => $this->b01->id,
            'quantity' => 5,
            'items' => [['material_id' => $fin->id, 'required_qty' => 5, 'unit_id' => $unit->id]],
        ])->assertJsonPath('code', 422)
            ->assertJsonPath('message', '该工单没有工艺路线，不可委外');
        $this->assertDatabaseCount('outsourcing_orders', 0);
    }

    // OUT-02：发出扣各组件库存（委外商品口径=发料组件）——
    // 原料 12@B-01、半成品B 6@B-01 基线，发出 6 后两组件按应发全额扣减归零，
    // outsourcing_out 分量流水（每组件一条，source_no=委外单号、remark=委外发出）+ issued_qty 回写 = 应发
    public function test_approve_deducts_components_and_writes_movements(): void
    {
        ['order' => $order, 'ops' => $ops, 'raw' => $raw, 'semiB' => $semiB, 'unit' => $unit] = $this->dagOrder();
        // 组件基线注入（采购入库直插引擎，同仓同库位 B-01：原料 12 = 应发 12、半成品B 6 = 应发 6）
        app(InventoryService::class)->apply([
            ['product_id' => $raw->id, 'warehouse_id' => $this->wh->id, 'location_id' => $this->b01->id,
                'direction' => 1, 'quantity' => 12, 'source_type' => 'purchase_inbound', 'source_id' => 0,
                'source_no' => 'SEED', 'remark' => '测试基线'],
            ['product_id' => $semiB->id, 'warehouse_id' => $this->wh->id, 'location_id' => $this->b01->id,
                'direction' => 1, 'quantity' => 6, 'source_type' => 'purchase_inbound', 'source_id' => 0,
                'source_no' => 'SEED', 'remark' => '测试基线'],
        ]);
        $no = $this->createOutsourcing([
            'order_id' => $order->id,
            'operation_id' => $ops['OP30']->id,
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->wh->id,
            'location_id' => $this->b01->id,
            'quantity' => 6,
            'items' => [
                ['material_id' => $raw->id, 'required_qty' => 12, 'unit_id' => $unit->id],
                ['material_id' => $semiB->id, 'required_qty' => 6, 'unit_id' => $unit->id],
            ],
        ]);
        $os = OutsourcingOrder::where('no', $no)->firstOrFail();
        $this->withToken($this->token)->postJson("/api/v1/production/outsourcings/{$os->id}/approve")
            ->assertJsonPath('code', 0);
        // 两组件按应发全额扣减归零（12→0、6→0）
        $this->assertSame('0.00', $this->balanceOf($raw->id));
        $this->assertSame('0.00', $this->balanceOf($semiB->id));
        // 分量流水：每组件一条 outsourcing_out（source_no=委外单号、remark=委外发出）
        $this->assertDatabaseHas('inventory_movements', [
            'source_type' => 'outsourcing_out', 'source_no' => $no, 'product_id' => $raw->id,
            'direction' => -1, 'quantity' => '12.00', 'balance_after' => '0.00', 'remark' => '委外发出',
        ]);
        $this->assertDatabaseHas('inventory_movements', [
            'source_type' => 'outsourcing_out', 'source_no' => $no, 'product_id' => $semiB->id,
            'direction' => -1, 'quantity' => '6.00', 'balance_after' => '0.00', 'remark' => '委外发出',
        ]);
        // issued_qty 回写 = 应发（发出全额，草稿期可调应发）；状态已发出
        $item = $os->items()->where('material_id', $raw->id)->first();
        $this->assertSame('12.00', (string) $item->fresh()->issued_qty);
        $this->assertSame(OutsourcingOrder::STATUS_APPROVED, (int) $os->fresh()->status);
    }

    // OUT-02：组件库存不足整单回滚 1522——不足落在第二组件（半成品B 5 < 应发 6，首组件原料 12 充足校验通过），
    // 验证任一组分不足即整体原子回滚：两组件余额均不变 / 无流水 / 状态仍草稿
    public function test_approve_rejects_insufficient_stock_rollback(): void
    {
        ['order' => $order, 'ops' => $ops, 'raw' => $raw, 'semiB' => $semiB, 'unit' => $unit] = $this->dagOrder();
        app(InventoryService::class)->apply([
            ['product_id' => $raw->id, 'warehouse_id' => $this->wh->id, 'location_id' => $this->b01->id,
                'direction' => 1, 'quantity' => 12, 'source_type' => 'purchase_inbound', 'source_id' => 0,
                'source_no' => 'SEED', 'remark' => '测试基线'],
            ['product_id' => $semiB->id, 'warehouse_id' => $this->wh->id, 'location_id' => $this->b01->id,
                'direction' => 1, 'quantity' => 5, 'source_type' => 'purchase_inbound', 'source_id' => 0,
                'source_no' => 'SEED', 'remark' => '测试基线'],
        ]);
        $no = $this->createOutsourcing([
            'order_id' => $order->id,
            'operation_id' => $ops['OP30']->id,
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->wh->id,
            'location_id' => $this->b01->id,
            'quantity' => 6,
            'items' => [
                ['material_id' => $raw->id, 'required_qty' => 12, 'unit_id' => $unit->id],
                ['material_id' => $semiB->id, 'required_qty' => 6, 'unit_id' => $unit->id],
            ],
        ]);
        $os = OutsourcingOrder::where('no', $no)->firstOrFail();
        $this->withToken($this->token)->postJson("/api/v1/production/outsourcings/{$os->id}/approve")
            ->assertJsonPath('code', 1522)
            ->assertJsonPath('message', '商品[半成品B]库存不足');
        // 三连断言：余额均不变 / 无流水 / 状态仍草稿
        $this->assertSame('12.00', $this->balanceOf($raw->id));
        $this->assertSame('5.00', $this->balanceOf($semiB->id));
        $this->assertDatabaseMissing('inventory_movements', ['source_no' => $no]);
        $this->assertSame(OutsourcingOrder::STATUS_DRAFT, (int) $os->fresh()->status);
    }

    // OUT-08：重复发出 → 1523 幂等拦截（components 路径），不重复扣减、不产生重复流水
    public function test_approve_idempotent(): void
    {
        ['order' => $order, 'ops' => $ops, 'raw' => $raw, 'semiB' => $semiB, 'unit' => $unit] = $this->dagOrder();
        app(InventoryService::class)->apply([
            ['product_id' => $raw->id, 'warehouse_id' => $this->wh->id, 'location_id' => $this->b01->id,
                'direction' => 1, 'quantity' => 12, 'source_type' => 'purchase_inbound', 'source_id' => 0,
                'source_no' => 'SEED', 'remark' => '测试基线'],
            ['product_id' => $semiB->id, 'warehouse_id' => $this->wh->id, 'location_id' => $this->b01->id,
                'direction' => 1, 'quantity' => 6, 'source_type' => 'purchase_inbound', 'source_id' => 0,
                'source_no' => 'SEED', 'remark' => '测试基线'],
        ]);
        $no = $this->createOutsourcing([
            'order_id' => $order->id,
            'operation_id' => $ops['OP30']->id,
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->wh->id,
            'location_id' => $this->b01->id,
            'quantity' => 6,
            'items' => [
                ['material_id' => $raw->id, 'required_qty' => 12, 'unit_id' => $unit->id],
                ['material_id' => $semiB->id, 'required_qty' => 6, 'unit_id' => $unit->id],
            ],
        ]);
        $os = OutsourcingOrder::where('no', $no)->firstOrFail();
        $this->withToken($this->token)->postJson("/api/v1/production/outsourcings/{$os->id}/approve")
            ->assertJsonPath('code', 0);
        $this->withToken($this->token)->postJson("/api/v1/production/outsourcings/{$os->id}/approve")
            ->assertJsonPath('code', 1523)
            ->assertJsonPath('message', '该委外单已审核');
        // 二次发出被拒：余额不再扣减（12→0 后保持 0，未变负）、该委外单流水仅首次发出的 2 条分量流水（无重复）
        $this->assertSame('0.00', $this->balanceOf($raw->id));
        $this->assertSame('0.00', $this->balanceOf($semiB->id));
        $this->assertSame(2, InventoryMovement::where('source_no', $no)->count());
        $this->assertSame(OutsourcingOrder::STATUS_APPROVED, (int) $os->fresh()->status);
    }

    // 组件余额读取（该委外仓位的余额行；无行=0，decimal 归一字符串——测试断言口径与实现 bcmath 一致）
    private function balanceOf(int $productId): string
    {
        $balance = InventoryBalance::where('product_id', $productId)->first();

        return $balance ? (string) $balance->quantity : '0';
    }
}
