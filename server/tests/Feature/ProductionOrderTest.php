<?php

// 生产工单接口测试：CRUD/BOM 展开快照/工序序列生成/无 BOM 拦截/快照语义/列表详情（核心路径 100%）

namespace Tests\Feature;

use App\Models\BomHeader;
use App\Models\Category;
use App\Models\Location;
use App\Models\PickList;
use App\Models\Process;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\Role;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WorkOrderOperation;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductionOrderTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    private User $admin;

    private Product $mat;

    private Product $semi;

    private Product $fin;

    private BomHeader $bom;

    private array $processes = [];

    protected function setUp(): void
    {
        parent::setUp();
        $role = Role::create(['name' => '管理员', 'code' => 'admin']);
        $this->admin = User::create(['name' => '管理员', 'username' => 'admin', 'password' => 'admin123', 'status' => 1]);
        $this->admin->roles()->sync([$role->id]);
        $this->token = $this->admin->createToken('api')->plainTextToken;

        $cat = Category::create(['name' => '成品', 'parent_id' => 0]);
        $semiCat = Category::create(['name' => '半成品', 'parent_id' => 0]);
        $rawCat = Category::create(['name' => '原材料', 'parent_id' => 0]);
        $unit = Unit::create(['name' => '个', 'code' => 'pc']);
        // 仓库/库位：领料单引用删除用例需要真实外键行（首条 id 即 1，与用例内 warehouse_id=1/location_id=1 一致）
        $wh = Warehouse::create(['name' => '主仓', 'code' => 'WH01', 'status' => 1]);
        Location::create(['warehouse_id' => $wh->id, 'name' => 'A-01', 'code' => 'A-01', 'status' => 1]);
        $this->mat = Product::create(['name' => '测试铝材', 'code' => 'MAT-001', 'type' => 'raw_material', 'category_id' => $rawCat->id, 'unit_id' => $unit->id, 'status' => 1]);
        $this->semi = Product::create(['name' => '半成品A', 'code' => 'SEMI-001', 'type' => 'semi_finished', 'category_id' => $semiCat->id, 'unit_id' => $unit->id, 'status' => 1]);
        $this->fin = Product::create(['name' => '成品B', 'code' => 'FIN-002', 'type' => 'finished', 'category_id' => $cat->id, 'unit_id' => $unit->id, 'status' => 1]);
        // 启用 BOM：成品B = MAT-001×2 + SEMI-001×1（基准产出 1）
        $this->bom = BomHeader::create(['code' => 'BOM-001', 'product_id' => $this->fin->id, 'version' => 'v1', 'quantity' => 1, 'status' => 1]);
        $this->bom->items()->createMany([
            ['material_id' => $this->mat->id, 'quantity' => 2, 'unit_id' => $unit->id],
            ['material_id' => $this->semi->id, 'quantity' => 1, 'unit_id' => $unit->id],
        ]);
        // 工序序列源：3 个启用工序（下料/组装/质检）
        foreach ([['下料', 'CUT', 1], ['组装', 'ASSY', 2], ['质检', 'QC', 3]] as [$name, $code, $sort]) {
            $this->processes[] = Process::create(['name' => $name, 'code' => $code, 'sort' => $sort, 'status' => 1]);
        }
    }

    // 组装工单载荷（默认 FIN-002×10 计划今天）
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'product_id' => $this->fin->id,
            'quantity' => 10,
            'plan_date' => now()->toDateString(),
            'remark' => '测试工单',
        ], $overrides);
    }

    // 通过 API 建草稿工单并返回单号
    private function createOrder(array $payload): string
    {
        $res = $this->withToken($this->token)->postJson('/api/v1/production/orders', $payload);
        $res->assertJsonPath('code', 0);

        return $res->json('data.no');
    }

    public function test_store_creates_draft_with_bom_expansion(): void
    {
        // 正常路径：草稿创建成功，单号 MO{date}-001；BOM 展开物料快照（10×2=20、10×1=10）+ 工序序列 3 行待开工
        $no = $this->createOrder($this->payload());
        $this->assertMatchesRegularExpression('/^MO\d{8}-001$/', $no);
        $order = ProductionOrder::where('no', $no)->first();
        $this->assertSame(ProductionOrder::STATUS_DRAFT, $order->status);
        // 物料快照：需求 = 数量 × 用量（bcmath）
        $mats = $order->materials()->with('material')->get()->keyBy('material_id');
        $this->assertSame('20.00', $mats[$this->mat->id]->required_qty);
        $this->assertSame('10.00', $mats[$this->semi->id]->required_qty);
        $this->assertSame('0.00', $mats[$this->mat->id]->issued_qty);
        // 工序序列：3 行全部待开工，seq 按 sort 升序
        $ops = $order->operations()->with('process')->orderBy('seq')->get();
        $this->assertSame(3, $ops->count());
        $this->assertSame(['下料', '组装', '质检'], $ops->map(fn ($o) => $o->process->name)->all());
        $this->assertSame([1, 2, 3], $ops->map(fn ($o) => $o->seq)->all());
        $this->assertSame(WorkOrderOperation::STATUS_PENDING, $ops->first()->status);
    }

    public function test_store_expands_with_bom_base_quantity(): void
    {
        // 边界路径：BOM 基准产出 2 时需求 = 数量÷基准×用量（10÷2×2=10）
        $bom2 = BomHeader::create(['code' => 'BOM-002', 'product_id' => $this->fin->id, 'version' => 'v2', 'quantity' => 2, 'status' => 1]);
        $bom2->items()->create(['material_id' => $this->mat->id, 'quantity' => 2, 'unit_id' => 1]);
        $no = $this->createOrder($this->payload());
        $order = ProductionOrder::where('no', $no)->first();
        $this->assertSame('10.00', $order->materials()->where('material_id', $this->mat->id)->first()->required_qty);
    }

    public function test_store_rejects_missing_enabled_bom_with_1501(): void
    {
        // 异常路径：成品无启用版本 BOM → 1501（业务码）
        $noBom = Product::create(['name' => '无BOM成品', 'code' => 'FIN-009', 'type' => 'finished', 'category_id' => 1, 'unit_id' => 1, 'status' => 1]);
        $this->withToken($this->token)->postJson('/api/v1/production/orders', $this->payload(['product_id' => $noBom->id]))
            ->assertJsonPath('code', 1501)
            ->assertJsonPath('message', '该成品没有启用版本的 BOM');
    }

    public function test_store_rejects_non_positive_quantity_with_1502(): void
    {
        // 异常路径：数量 ≤ 0 → 1502（业务码，生产 spec 明确；与采购/销售 422 不同）
        $this->withToken($this->token)->postJson('/api/v1/production/orders', $this->payload(['quantity' => 0]))
            ->assertJsonPath('code', 1502);
    }

    public function test_store_uses_enabled_bom_ignoring_request_bom_id(): void
    {
        // 边界路径：请求携带 bom_id 也以启用版本为准（同成品启用版本唯一，停用版不可用）
        $no = $this->createOrder($this->payload(['bom_id' => 999]));
        $order = ProductionOrder::where('no', $no)->first();
        $this->assertSame($this->bom->id, $order->bom_id);
    }

    public function test_update_draft_recalculates_materials(): void
    {
        // 正常路径：草稿可改（数量 10→5），物料快照重建（需求 5×2=10）
        $no = $this->createOrder($this->payload());
        $order = ProductionOrder::where('no', $no)->first();
        $this->withToken($this->token)->putJson("/api/v1/production/orders/{$order->id}", $this->payload(['quantity' => 5]))
            ->assertJsonPath('code', 0);
        $order->refresh();
        $this->assertSame('5.00', $order->quantity);
        $this->assertSame('10.00', $order->materials()->where('material_id', $this->mat->id)->first()->required_qty);
    }

    public function test_update_released_rejected_with_1503(): void
    {
        // 异常路径：已下达工单不可修改 → 1503
        $no = $this->createOrder($this->payload());
        $order = ProductionOrder::where('no', $no)->first();
        $order->status = ProductionOrder::STATUS_RELEASED;
        $order->save();
        $this->withToken($this->token)->putJson("/api/v1/production/orders/{$order->id}", $this->payload())
            ->assertJsonPath('code', 1503);
    }

    public function test_destroy_draft_ok_and_released_rejected_with_1504(): void
    {
        // 正常+异常路径：草稿可删；已下达不可删 → 1504
        $no = $this->createOrder($this->payload());
        $draftId = ProductionOrder::where('no', $no)->first()->id;
        $this->withToken($this->token)->deleteJson("/api/v1/production/orders/{$draftId}")->assertJsonPath('code', 0);
        $this->assertDatabaseMissing('production_orders', ['id' => $draftId]);

        $no2 = $this->createOrder($this->payload());
        $released = ProductionOrder::where('no', $no2)->first();
        $released->status = ProductionOrder::STATUS_RELEASED;
        $released->save();
        $this->withToken($this->token)->deleteJson("/api/v1/production/orders/{$released->id}")
            ->assertJsonPath('code', 1504);
    }

    public function test_destroy_rejected_when_referenced_by_documents(): void
    {
        // 异常路径：草稿工单已被单据引用（领料单挂工单）→ 1504 拒绝删除（防孤儿单据）
        $no = $this->createOrder($this->payload());
        $order = ProductionOrder::where('no', $no)->first();
        PickList::create([
            'no' => 'PL-TEST-001', 'order_id' => $order->id, 'status' => 0, 'issue_status' => 0,
            'warehouse_id' => 1, 'location_id' => 1, 'remark' => null,
        ]);
        $this->withToken($this->token)->deleteJson("/api/v1/production/orders/{$order->id}")
            ->assertJsonPath('code', 1504)
            ->assertJsonPath('message', '工单已被生产单据使用，不可删除');
        $this->assertDatabaseHas('production_orders', ['id' => $order->id]);
    }

    public function test_index_with_filters_and_progress(): void
    {
        // 正常路径：列表含成品名/状态标签/完成率；keyword 筛选
        $no = $this->createOrder($this->payload());
        $order = ProductionOrder::where('no', $no)->first();
        $order->status = ProductionOrder::STATUS_PRODUCING;
        $order->completed_qty = 5;
        $order->save();
        $this->withToken($this->token)->getJson('/api/v1/production/orders')
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.items.0.product_name', '成品B')
            ->assertJsonPath('data.items.0.status_label', '生产中')
            ->assertJsonPath('data.items.0.completed_qty', '5.00')
            ->assertJsonPath('data.items.0.progress', 50.0);
        $this->withToken($this->token)->getJson('/api/v1/production/orders?keyword=MO'.date('Ymd'))
            ->assertJsonPath('data.total', 1);
        $this->withToken($this->token)->getJson('/api/v1/production/orders?status=0')
            ->assertJsonPath('data.total', 0);
    }

    public function test_show_returns_materials_and_operations(): void
    {
        // 正常路径：详情含物料需求（需求/已领/剩余）与工序列表（状态与累计值）
        $no = $this->createOrder($this->payload());
        $id = ProductionOrder::where('no', $no)->first()->id;
        $this->withToken($this->token)->getJson("/api/v1/production/orders/{$id}")
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.product_code', 'FIN-002')
            ->assertJsonPath('data.materials.0.material_code', 'MAT-001')
            ->assertJsonPath('data.materials.0.required_qty', '20.00')
            ->assertJsonPath('data.materials.0.issued_qty', '0.00')
            ->assertJsonPath('data.materials.0.remaining_qty', '20.00')
            ->assertJsonPath('data.operations.0.process_name', '下料')
            ->assertJsonPath('data.operations.0.status', 0)
            ->assertJsonPath('data.operations.0.status_label', '待开工');
    }

    public function test_show_uses_snapshot_not_live_bom(): void
    {
        // 核心不变式（spec §8）：下达后 BOM 被停用/改版不影响已建工单（物料需求已快照）
        $no = $this->createOrder($this->payload());
        $id = ProductionOrder::where('no', $no)->first()->id;
        $this->bom->status = 0;
        $this->bom->save();
        $this->withToken($this->token)->getJson("/api/v1/production/orders/{$id}")
            ->assertJsonPath('data.materials.0.required_qty', '20.00');
    }

    public function test_materials_endpoint_returns_requirements(): void
    {
        // 正常路径：materials 接口返回物料需求（领料单生成预填数据源）
        $no = $this->createOrder($this->payload());
        $id = ProductionOrder::where('no', $no)->first()->id;
        $this->withToken($this->token)->getJson("/api/v1/production/orders/{$id}/materials")
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.items.0.material_code', 'MAT-001')
            ->assertJsonPath('data.items.0.required_qty', '20.00')
            ->assertJsonPath('data.items.0.remaining_qty', '20.00');
    }

    public function test_orders_requires_production_order_permission(): void
    {
        // 异常路径：无 production.order.list 权限的角色被拒（403 JSON 信封）
        $role = Role::create(['name' => '普通', 'code' => 'plain']);
        $u = User::create(['name' => '普通', 'username' => 'plain', 'password' => 'admin123', 'status' => 1]);
        $u->roles()->sync([$role->id]);
        $token = $u->createToken('api')->plainTextToken;
        $this->withToken($token)->getJson('/api/v1/production/orders')->assertStatus(403);
    }

    // 通过 API 建单并下达，返回工单模型（多次用例复用）
    private function releasedOrder(array $overrides = []): ProductionOrder
    {
        $no = $this->createOrder($this->payload($overrides));
        $order = ProductionOrder::where('no', $no)->first();
        $this->withToken($this->token)->postJson("/api/v1/production/orders/{$order->id}/release")->assertJsonPath('code', 0);

        return $order->refresh();
    }

    public function test_release_transitions_and_returns_warnings(): void
    {
        // 正常路径：草稿 → 已下达（released_at 落库）；物料充足时 warnings 空
        // 物料充足基线：MAT-001=30、SEMI-001=20（经 InventoryService 保证恒等式；brief 用例未种库存，补种后 warnings 为空的前提成立）
        app(InventoryService::class)->apply([
            ['product_id' => $this->mat->id, 'warehouse_id' => 1, 'location_id' => 1, 'direction' => 1, 'quantity' => 30, 'source_type' => 'purchase_inbound', 'source_id' => 0, 'source_no' => 'PO-SEED', 'remark' => '测试基线'],
            ['product_id' => $this->semi->id, 'warehouse_id' => 1, 'location_id' => 1, 'direction' => 1, 'quantity' => 20, 'source_type' => 'purchase_inbound', 'source_id' => 0, 'source_no' => 'PO-SEED', 'remark' => '测试基线'],
        ]);
        $no = $this->createOrder($this->payload());
        $order = ProductionOrder::where('no', $no)->first();
        $res = $this->withToken($this->token)->postJson("/api/v1/production/orders/{$order->id}/release");
        $res->assertJsonPath('code', 0)->assertJsonPath('data.warnings', []);
        $this->assertSame(ProductionOrder::STATUS_RELEASED, ProductionOrder::find($order->id)->status);
        $this->assertNotNull(ProductionOrder::find($order->id)->released_at);
    }

    public function test_release_warns_when_material_insufficient(): void
    {
        // 边界路径：物料全局库存不足时仅警告不阻断（缺料由领料环节控制）
        $no = $this->createOrder($this->payload());
        $order = ProductionOrder::where('no', $no)->first();
        $res = $this->withToken($this->token)->postJson("/api/v1/production/orders/{$order->id}/release");
        $res->assertJsonPath('code', 0);
        $warnings = $res->json('data.warnings');
        $this->assertNotEmpty($warnings);
        $mat = collect($warnings)->firstWhere('material_name', '测试铝材');
        $this->assertSame('20.00', $mat['required']);
        $this->assertSame('0.00', $mat['stock']);
        $this->assertSame(ProductionOrder::STATUS_RELEASED, ProductionOrder::find($order->id)->status);
    }

    public function test_release_rejects_non_draft_with_1505(): void
    {
        // 异常路径：重复下达 → 1505「工单已下达」
        $no = $this->createOrder($this->payload());
        $order = ProductionOrder::where('no', $no)->first();
        $this->withToken($this->token)->postJson("/api/v1/production/orders/{$order->id}/release")->assertJsonPath('code', 0);
        $this->withToken($this->token)->postJson("/api/v1/production/orders/{$order->id}/release")
            ->assertJsonPath('code', 1505)
            ->assertJsonPath('message', '工单已下达');
    }

    public function test_start_transitions_first_operation_and_rejects_duplicate_with_1506(): void
    {
        // 正常+异常路径：已下达 → 生产中 + 首工序进行中；重复开工 → 1506
        $order = $this->releasedOrder();
        $this->withToken($this->token)->postJson("/api/v1/production/orders/{$order->id}/start")->assertJsonPath('code', 0);
        $order->refresh();
        $this->assertSame(ProductionOrder::STATUS_PRODUCING, $order->status);
        $first = $order->operations()->orderBy('seq')->first();
        $this->assertSame(WorkOrderOperation::STATUS_RUNNING, $first->status);
        $this->withToken($this->token)->postJson("/api/v1/production/orders/{$order->id}/start")
            ->assertJsonPath('code', 1506);
    }

    public function test_start_rejects_draft_with_1506(): void
    {
        // 异常路径：草稿未下达直接开工 → 1506
        $no = $this->createOrder($this->payload());
        $order = ProductionOrder::where('no', $no)->first();
        $this->withToken($this->token)->postJson("/api/v1/production/orders/{$order->id}/start")
            ->assertJsonPath('code', 1506);
    }

    public function test_complete_requires_all_operations_done_with_1507(): void
    {
        // 异常路径：存在未完成工序 → 1507「存在未完成工序，无法完工」
        $order = $this->releasedOrder();
        $this->withToken($this->token)->postJson("/api/v1/production/orders/{$order->id}/start")->assertJsonPath('code', 0);
        $res = $this->withToken($this->token)->postJson("/api/v1/production/orders/{$order->id}/complete");
        $res->assertJsonPath('code', 1507)->assertJsonPath('message', '存在未完成工序，无法完工');
        $this->assertSame(ProductionOrder::STATUS_PRODUCING, ProductionOrder::find($order->id)->status);
    }

    public function test_complete_requires_finished_inbound_with_1508(): void
    {
        // 异常路径：全部工序已完成但无成品入库 → 1508（completed_qty=0）
        $order = $this->releasedOrder();
        $this->withToken($this->token)->postJson("/api/v1/production/orders/{$order->id}/start")->assertJsonPath('code', 0);
        // 直接置全部工序完成（报工接口 Task 5 覆盖流转，此处绕过前置）
        $order->operations()->update(['status' => WorkOrderOperation::STATUS_DONE]);
        $res = $this->withToken($this->token)->postJson("/api/v1/production/orders/{$order->id}/complete");
        $res->assertJsonPath('code', 1508)->assertJsonPath('message', '无成品入库，无法完工');
    }

    public function test_complete_transitions_when_all_done_and_inbound(): void
    {
        // 正常路径：全部工序完成 + completed_qty>0 → 已完成（completed_at 落库）
        $order = $this->releasedOrder();
        $this->withToken($this->token)->postJson("/api/v1/production/orders/{$order->id}/start")->assertJsonPath('code', 0);
        $order->operations()->update(['status' => WorkOrderOperation::STATUS_DONE]);
        $order->completed_qty = 10;
        $order->save();
        $this->withToken($this->token)->postJson("/api/v1/production/orders/{$order->id}/complete")
            ->assertJsonPath('code', 0);
        $order->refresh();
        $this->assertSame(ProductionOrder::STATUS_COMPLETED, $order->status);
        $this->assertNotNull($order->completed_at);
    }

    public function test_complete_rejects_non_producing_with_1507(): void
    {
        // 异常路径：草稿直接完工 → 1507（当前状态不可完工）
        $no = $this->createOrder($this->payload());
        $order = ProductionOrder::where('no', $no)->first();
        $this->withToken($this->token)->postJson("/api/v1/production/orders/{$order->id}/complete")
            ->assertJsonPath('code', 1507);
    }

    public function test_close_completed_and_rejects_others_with_1505(): void
    {
        // 正常+异常路径：已完成 → 关闭（closed_at 落库）；非已完成（草稿）关闭 → 1505「当前状态不可关闭」
        $order = $this->releasedOrder();
        $this->withToken($this->token)->postJson("/api/v1/production/orders/{$order->id}/start")->assertJsonPath('code', 0);
        $order->operations()->update(['status' => WorkOrderOperation::STATUS_DONE]);
        $order->completed_qty = 10;
        $order->save();
        $this->withToken($this->token)->postJson("/api/v1/production/orders/{$order->id}/complete")->assertJsonPath('code', 0);
        $this->withToken($this->token)->postJson("/api/v1/production/orders/{$order->id}/close")
            ->assertJsonPath('code', 0);
        $order->refresh();
        $this->assertSame(ProductionOrder::STATUS_CLOSED, $order->status);
        $this->assertNotNull($order->closed_at);

        $no2 = $this->createOrder($this->payload());
        $draft = ProductionOrder::where('no', $no2)->first();
        $this->withToken($this->token)->postJson("/api/v1/production/orders/{$draft->id}/close")
            ->assertJsonPath('code', 1505)
            ->assertJsonPath('message', '当前状态不可关闭');
    }

    public function test_closed_order_blocks_release_and_start(): void
    {
        // 异常路径：关闭后无任何操作（PRD-14）——release/start 均被状态机拦截
        $order = $this->releasedOrder();
        $this->withToken($this->token)->postJson("/api/v1/production/orders/{$order->id}/start")->assertJsonPath('code', 0);
        $order->operations()->update(['status' => WorkOrderOperation::STATUS_DONE]);
        $order->completed_qty = 10;
        $order->save();
        $this->withToken($this->token)->postJson("/api/v1/production/orders/{$order->id}/complete")->assertJsonPath('code', 0);
        $this->withToken($this->token)->postJson("/api/v1/production/orders/{$order->id}/close")->assertJsonPath('code', 0);
        $this->withToken($this->token)->postJson("/api/v1/production/orders/{$order->id}/release")
            ->assertJsonPath('code', 1505);
        $this->withToken($this->token)->postJson("/api/v1/production/orders/{$order->id}/start")
            ->assertJsonPath('code', 1506);
    }
}
