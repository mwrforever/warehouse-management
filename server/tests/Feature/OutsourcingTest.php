<?php

// 委外加工接口测试：CRUD/发出扣成品/回收加成品/分批回收/超收/工序联动/幂等（核心路径 100%）

namespace Tests\Feature;

use App\Models\BomHeader;
use App\Models\Category;
use App\Models\InventoryBalance;
use App\Models\Location;
use App\Models\OutsourcingOrder;
use App\Models\OutsourcingReceipt;
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
use Tests\TestCase;

class OutsourcingTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    private User $admin;

    private Warehouse $wh;

    private Location $b01;

    private Product $mat;

    private Product $fin;

    private Supplier $supplier;

    private ProductionOrder $order;

    private int $assemblyOpId; // 组装工序（委外对象）

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
        $rawCat = Category::create(['name' => '原材料', 'parent_id' => 0]);
        $cat = Category::create(['name' => '成品', 'parent_id' => 0]);
        $unit = Unit::create(['name' => '个', 'code' => 'pc']);
        $this->mat = Product::create(['name' => '测试铝材', 'code' => 'MAT-001', 'type' => 'raw_material', 'category_id' => $rawCat->id, 'unit_id' => $unit->id, 'status' => 1]);
        $this->fin = Product::create(['name' => '成品B', 'code' => 'FIN-002', 'type' => 'finished', 'category_id' => $cat->id, 'unit_id' => $unit->id, 'status' => 1]);
        $bom = BomHeader::create(['code' => 'BOM-001', 'product_id' => $this->fin->id, 'version' => 'v1', 'quantity' => 1, 'status' => 1]);
        $bom->items()->create(['material_id' => $this->mat->id, 'quantity' => 2, 'unit_id' => $unit->id]);
        foreach ([['下料', 'CUT', 1], ['组装', 'ASSY', 2], ['质检', 'QC', 3]] as [$name, $code, $sort]) {
            Process::create(['name' => $name, 'code' => $code, 'sort' => $sort, 'status' => 1]);
        }
        // 基线库存：FIN-002 @B-01=50（委外发出 5 → 45；回收 5 → 50）
        app(InventoryService::class)->apply([
            ['product_id' => $this->fin->id, 'warehouse_id' => $this->wh->id, 'location_id' => $this->b01->id, 'direction' => 1, 'quantity' => 50, 'source_type' => 'purchase_inbound', 'source_id' => 0, 'source_no' => 'SEED', 'remark' => '测试基线'],
        ]);

        // 建单（FIN-002×5）→ 下达 → 开工
        $res = $this->withToken($this->token)->postJson('/api/v1/production/orders', [
            'product_id' => $this->fin->id, 'quantity' => 5, 'plan_date' => now()->toDateString(),
        ]);
        $res->assertJsonPath('code', 0);
        $this->order = ProductionOrder::where('no', $res->json('data.no'))->first();
        $this->withToken($this->token)->postJson("/api/v1/production/orders/{$this->order->id}/release")->assertJsonPath('code', 0);
        $this->withToken($this->token)->postJson("/api/v1/production/orders/{$this->order->id}/start")->assertJsonPath('code', 0);
        // 首工序（下料）报工完成 → 组装（委外对象）自动置进行中（与 E2E TC-PRD-06 流程一致）
        $cutOp = $this->order->operations()->where('seq', 1)->first();
        $this->withToken($this->token)->postJson("/api/v1/production/operations/{$cutOp->id}/reports", [
            'qualified_qty' => 5,
        ])->assertJsonPath('code', 0);
        $this->assemblyOpId = $this->order->operations()->where('seq', 2)->first()->id;
    }

    // 组装委外载荷（默认组装工序×5，发出仓 B-01——与基线库存同库位，审核扣减与回收入账落在同一余额行）
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'order_id' => $this->order->id,
            'operation_id' => $this->assemblyOpId,
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->wh->id,
            'location_id' => $this->b01->id,
            'quantity' => 5,
        ], $overrides);
    }

    // 通过 API 建草稿委外单并返回单号
    private function createOutsourcing(array $payload): string
    {
        $res = $this->withToken($this->token)->postJson('/api/v1/production/outsourcings', $payload);
        $res->assertJsonPath('code', 0);

        return $res->json('data.no');
    }

    public function test_store_creates_draft_with_no(): void
    {
        // 正常路径：草稿创建成功，单号 OS{date}-001
        $no = $this->createOutsourcing($this->payload());
        $this->assertMatchesRegularExpression('/^OS\d{12}001$/', $no);
        $os = OutsourcingOrder::where('no', $no)->first();
        $this->assertSame(OutsourcingOrder::STATUS_DRAFT, $os->status);
        $this->assertSame('5.00', $os->quantity);
    }

    public function test_store_rejects_over_plan_with_1520(): void
    {
        // 异常路径：委外量超工单计划数（6 > 5）→ 1520
        $this->withToken($this->token)->postJson('/api/v1/production/outsourcings', $this->payload(['quantity' => 6]))
            ->assertJsonPath('code', 1520)
            ->assertJsonPath('message', '委外数量超过工单计划数量');
    }

    public function test_store_rejects_operation_not_in_order_with_422(): void
    {
        // 异常路径：工序不属于该工单 → 422（格式层；spec 码段满）
        $other = ProductionOrder::where('no', '!=', $this->order->no)->first();
        if (! $other) {
            // 建第二个工单的工序作反例
            $res = $this->withToken($this->token)->postJson('/api/v1/production/orders', [
                'product_id' => $this->fin->id, 'quantity' => 5, 'plan_date' => now()->toDateString(),
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
        $this->withToken($this->token)->postJson('/api/v1/production/outsourcings', $this->payload(['quantity' => 0]))
            ->assertJsonPath('code', 422);
    }

    public function test_store_rejects_missing_supplier_with_422(): void
    {
        // 异常路径：供应商缺失 → 422（格式层）
        $this->withToken($this->token)->postJson('/api/v1/production/outsourcings', $this->payload(['supplier_id' => null]))
            ->assertJsonPath('code', 422);
    }

    public function test_store_rejects_missing_warehouse_or_location_with_422(): void
    {
        // 异常路径：仓库/库位缺失 → 422（格式层）
        $this->withToken($this->token)->postJson('/api/v1/production/outsourcings', $this->payload(['warehouse_id' => null]))
            ->assertJsonPath('code', 422);
        $this->withToken($this->token)->postJson('/api/v1/production/outsourcings', $this->payload(['location_id' => null]))
            ->assertJsonPath('code', 422);
    }

    public function test_approve_deducts_finished_inventory_and_writes_movement(): void
    {
        // 核心不变式（发出）：余额 50→45、outsourcing_out 流水（direction=-1，商品=工单成品）
        $no = $this->createOutsourcing($this->payload());
        $os = OutsourcingOrder::where('no', $no)->first();
        $this->withToken($this->token)->postJson("/api/v1/production/outsourcings/{$os->id}/approve")
            ->assertJsonPath('code', 0);
        $balance = InventoryBalance::where('product_id', $this->fin->id)->first();
        $this->assertSame('45.00', $balance->quantity);
        $this->assertDatabaseHas('inventory_movements', [
            'product_id' => $this->fin->id, 'direction' => -1, 'quantity' => '5.00',
            'balance_after' => '45.00', 'source_type' => 'outsourcing_out', 'source_no' => $no,
        ]);
        $os->refresh();
        $this->assertSame(OutsourcingOrder::STATUS_APPROVED, $os->status);
        $this->assertSame('管理员', $os->operator);
        $this->assertNotNull($os->approved_at);
    }

    public function test_approve_rejects_insufficient_balance_with_1522_rollback(): void
    {
        // 核心不变式（超卖拦截）：库存不足 → 1522 整体回滚
        // 先把成品余额压到 3（低于委外量 5）：草稿期 1520 校验（≤ 工单计划 5）仍通过，审核期才触发 1522
        app(InventoryService::class)->apply([[
            'product_id' => $this->fin->id, 'warehouse_id' => $this->wh->id, 'location_id' => $this->b01->id,
            'direction' => -1, 'quantity' => 47, 'source_type' => 'check_out', 'source_id' => 0, 'source_no' => 'DRAIN', 'remark' => '测试压库存',
        ]]);
        $no = $this->createOutsourcing($this->payload());
        $os = OutsourcingOrder::where('no', $no)->first();
        $res = $this->withToken($this->token)->postJson("/api/v1/production/outsourcings/{$os->id}/approve");
        $res->assertJsonPath('code', 1522)
            ->assertJsonPath('message', '商品[FIN-002]库存不足');
        $this->assertSame('3.00', InventoryBalance::where('product_id', $this->fin->id)->first()->quantity);
        $this->assertDatabaseMissing('inventory_movements', ['source_no' => $no]);
        $this->assertSame(OutsourcingOrder::STATUS_DRAFT, $os->refresh()->status);
    }

    public function test_approve_idempotent_with_1523(): void
    {
        // 核心不变式：重复审核 → 1523，库存不重复扣减
        $no = $this->createOutsourcing($this->payload());
        $os = OutsourcingOrder::where('no', $no)->first();
        $this->withToken($this->token)->postJson("/api/v1/production/outsourcings/{$os->id}/approve")->assertJsonPath('code', 0);
        $this->withToken($this->token)->postJson("/api/v1/production/outsourcings/{$os->id}/approve")
            ->assertJsonPath('code', 1523)
            ->assertJsonPath('message', '该委外单已审核');
        $this->assertSame('45.00', InventoryBalance::where('product_id', $this->fin->id)->first()->quantity);
    }

    public function test_receipt_credits_inventory_and_marks_received(): void
    {
        // 核心不变式（回收）：余额 45→50、outsourcing_in 流水(+1，单号 OSR..)、委外单已回收、工序标记完成
        $no = $this->createOutsourcing($this->payload());
        $os = OutsourcingOrder::where('no', $no)->first();
        $this->withToken($this->token)->postJson("/api/v1/production/outsourcings/{$os->id}/approve")->assertJsonPath('code', 0);
        $res = $this->withToken($this->token)->postJson("/api/v1/production/outsourcings/{$os->id}/receipts", [
            'quantity' => 5, 'warehouse_id' => $this->wh->id, 'location_id' => $this->b01->id, 'remark' => '回收',
        ]);
        $res->assertJsonPath('code', 0)
            ->assertJsonPath('data.no', 'OSR'.date('YmdHi').'001');
        $this->assertSame('50.00', InventoryBalance::where('product_id', $this->fin->id)->first()->quantity);
        $this->assertDatabaseHas('inventory_movements', [
            'product_id' => $this->fin->id, 'direction' => 1, 'quantity' => '5.00',
            'balance_after' => '50.00', 'source_type' => 'outsourcing_in', 'source_no' => 'OSR'.date('YmdHi').'001',
        ]);
        $os->refresh();
        $this->assertSame(OutsourcingOrder::STATUS_RECEIVED, $os->status);
        // 委外工序（组装）标记完成（spec §6：回收量≥委外量时）
        $this->assertSame(WorkOrderOperation::STATUS_DONE, WorkOrderOperation::find($this->assemblyOpId)->status);
        // 回收单落库
        $this->assertDatabaseHas('outsourcing_receipts', [
            'outsourcing_id' => $os->id, 'quantity' => '5.00', 'status' => OutsourcingReceipt::STATUS_APPROVED,
        ]);
    }

    public function test_receipt_allows_partial_batches_and_rejects_over_with_1524(): void
    {
        // 边界路径：分批回收（3+2）；累计超委外量（再收 1）→ 1524 拦截且不产生流水
        $no = $this->createOutsourcing($this->payload());
        $os = OutsourcingOrder::where('no', $no)->first();
        $this->withToken($this->token)->postJson("/api/v1/production/outsourcings/{$os->id}/approve")->assertJsonPath('code', 0);
        // 第一批 3
        $this->withToken($this->token)->postJson("/api/v1/production/outsourcings/{$os->id}/receipts", [
            'quantity' => 3, 'warehouse_id' => $this->wh->id, 'location_id' => $this->b01->id,
        ])->assertJsonPath('code', 0);
        $this->assertSame('48.00', InventoryBalance::where('product_id', $this->fin->id)->first()->quantity);
        // 状态未回收（累计 3 < 5），工序未完成
        $this->assertSame(OutsourcingOrder::STATUS_APPROVED, $os->refresh()->status);
        $this->assertSame(WorkOrderOperation::STATUS_RUNNING, WorkOrderOperation::find($this->assemblyOpId)->status);
        // 第二批 2 → 已回收
        $this->withToken($this->token)->postJson("/api/v1/production/outsourcings/{$os->id}/receipts", [
            'quantity' => 2, 'warehouse_id' => $this->wh->id, 'location_id' => $this->b01->id,
        ])->assertJsonPath('code', 0);
        $this->assertSame('50.00', InventoryBalance::where('product_id', $this->fin->id)->first()->quantity);
        $this->assertSame(OutsourcingOrder::STATUS_RECEIVED, $os->refresh()->status);
        // 超收（累计已 5，再收 1）→ 1524 整体回滚
        $res = $this->withToken($this->token)->postJson("/api/v1/production/outsourcings/{$os->id}/receipts", [
            'quantity' => 1, 'warehouse_id' => $this->wh->id, 'location_id' => $this->b01->id,
        ]);
        $res->assertJsonPath('code', 1524)
            ->assertJsonPath('message', '回收数量超过委外数量');
        $this->assertSame('50.00', InventoryBalance::where('product_id', $this->fin->id)->first()->quantity);
        $this->assertDatabaseCount('outsourcing_receipts', 2);
    }

    public function test_receipt_rejects_draft_outsourcing_with_422(): void
    {
        // 异常路径：未发出（草稿）不可回收 → 422
        $no = $this->createOutsourcing($this->payload());
        $os = OutsourcingOrder::where('no', $no)->first();
        $this->withToken($this->token)->postJson("/api/v1/production/outsourcings/{$os->id}/receipts", [
            'quantity' => 1, 'warehouse_id' => $this->wh->id, 'location_id' => $this->b01->id,
        ])->assertJsonPath('code', 422);
    }

    public function test_receipt_rejects_order_not_released_or_producing_with_1523(): void
    {
        // 异常路径（bug #2 回归）：发出后工单被关闭 → 回收被拒 1523（与发出 approve 同口径），无流水无回收单
        $no = $this->createOutsourcing($this->payload());
        $os = OutsourcingOrder::where('no', $no)->first();
        $this->withToken($this->token)->postJson("/api/v1/production/outsourcings/{$os->id}/approve")->assertJsonPath('code', 0);
        // 发出已扣减：50 → 45
        $this->assertSame('45.00', InventoryBalance::where('product_id', $this->fin->id)->first()->quantity);
        $this->order->status = ProductionOrder::STATUS_CLOSED;
        $this->order->save();
        $this->withToken($this->token)->postJson("/api/v1/production/outsourcings/{$os->id}/receipts", [
            'quantity' => 5, 'warehouse_id' => $this->wh->id, 'location_id' => $this->b01->id,
        ])
            ->assertJsonPath('code', 1523)
            ->assertJsonPath('message', '工单当前状态不可委外');
        // 被拒回收：库存不变、无回收单、委外单仍为已发出
        $this->assertSame('45.00', InventoryBalance::where('product_id', $this->fin->id)->first()->quantity);
        $this->assertDatabaseCount('outsourcing_receipts', 0);
        $this->assertSame(OutsourcingOrder::STATUS_APPROVED, $os->refresh()->status);
    }

    public function test_receipts_index_lists_records(): void
    {
        // 正常路径：回收记录列表（单号/数量/时间）
        $no = $this->createOutsourcing($this->payload());
        $os = OutsourcingOrder::where('no', $no)->first();
        $this->withToken($this->token)->postJson("/api/v1/production/outsourcings/{$os->id}/approve")->assertJsonPath('code', 0);
        $this->withToken($this->token)->postJson("/api/v1/production/outsourcings/{$os->id}/receipts", [
            'quantity' => 5, 'warehouse_id' => $this->wh->id, 'location_id' => $this->b01->id,
        ])->assertJsonPath('code', 0);
        $this->withToken($this->token)->getJson("/api/v1/production/outsourcings/{$os->id}/receipts")
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.items.0.quantity', '5.00')
            ->assertJsonPath('data.items.0.no', 'OSR'.date('YmdHi').'001');
    }

    public function test_update_destroy_draft_ok_approved_rejected_with_1521(): void
    {
        // 正常+异常路径：草稿可改可删；已审核不可改删 → 1521
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
        // 正常路径：列表含工单单号/供应商/工序名/状态标签
        $no = $this->createOutsourcing($this->payload());
        $os = OutsourcingOrder::where('no', $no)->first();
        $this->withToken($this->token)->postJson("/api/v1/production/outsourcings/{$os->id}/approve")->assertJsonPath('code', 0);
        $this->withToken($this->token)->getJson('/api/v1/production/outsourcings')
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.items.0.order_no', 'MO'.date('YmdHi').'001')
            ->assertJsonPath('data.items.0.supplier_name', '测试供应商')
            ->assertJsonPath('data.items.0.process_name', '组装')
            ->assertJsonPath('data.items.0.status_label', '已审核');
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
}
