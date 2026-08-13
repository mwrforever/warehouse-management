<?php

// 成品入库单接口测试：CRUD/审核加库存/防超量/成品一致性/工单自动完成/幂等（核心路径 100%）

namespace Tests\Feature;

use App\Models\BomHeader;
use App\Models\Category;
use App\Models\FinishedInbound;
use App\Models\InventoryBalance;
use App\Models\Location;
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

class FinishedInboundTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    private User $admin;

    private Warehouse $wh;

    private Location $b01;

    private Product $mat;

    private Product $fin;

    private ProductionOrder $order;

    private int $finishItemId; // finished_inbound_items 行 id（预填用）

    protected function setUp(): void
    {
        parent::setUp();
        $role = Role::create(['name' => '管理员', 'code' => 'admin']);
        $this->admin = User::create(['name' => '管理员', 'username' => 'admin', 'password' => 'admin123', 'status' => 1]);
        $this->admin->roles()->sync([$role->id]);
        $this->token = $this->admin->createToken('api')->plainTextToken;

        $this->wh = Warehouse::create(['name' => '主仓', 'code' => 'WH01', 'status' => 1]);
        $this->b01 = Location::create(['warehouse_id' => $this->wh->id, 'name' => 'B-01', 'code' => 'B-01', 'status' => 1]);
        $rawCat = Category::create(['name' => '原材料', 'parent_id' => 0]);
        $cat = Category::create(['name' => '成品', 'parent_id' => 0]);
        $unit = Unit::create(['name' => '个', 'code' => 'pc']);
        $this->mat = Product::create(['name' => '测试铝材', 'code' => 'MAT-001', 'type' => 'raw_material', 'category_id' => $rawCat->id, 'unit_id' => $unit->id, 'status' => 1]);
        $this->fin = Product::create(['name' => '成品B', 'code' => 'FIN-002', 'type' => 'finished', 'category_id' => $cat->id, 'unit_id' => $unit->id, 'status' => 1]);
        $bom = BomHeader::create(['code' => 'BOM-001', 'product_id' => $this->fin->id, 'version' => 'v1', 'quantity' => 1, 'status' => 1]);
        $bom->items()->create(['material_id' => $this->mat->id, 'quantity' => 2, 'unit_id' => $unit->id]);
        foreach ([['下料', 'CUT', 1], ['组装', 'ASSY', 2]] as [$name, $code, $sort]) {
            Process::create(['name' => $name, 'code' => $code, 'sort' => $sort, 'status' => 1]);
        }
        // 基线库存：FIN-002 @B-01=20（入库 10 → 30）
        app(InventoryService::class)->apply([
            ['product_id' => $this->fin->id, 'warehouse_id' => $this->wh->id, 'location_id' => $this->b01->id, 'direction' => 1, 'quantity' => 20, 'source_type' => 'purchase_inbound', 'source_id' => 0, 'source_no' => 'SEED', 'remark' => '测试基线'],
        ]);

        // 建单（FIN-002×10）→ 下达 → 开工 → 全部工序完成（completed_qty=0 未入库）
        $res = $this->withToken($this->token)->postJson('/api/v1/production/orders', [
            'product_id' => $this->fin->id, 'quantity' => 10, 'plan_date' => now()->toDateString(),
        ]);
        $res->assertJsonPath('code', 0);
        $this->order = ProductionOrder::where('no', $res->json('data.no'))->first();
        $this->withToken($this->token)->postJson("/api/v1/production/orders/{$this->order->id}/release")->assertJsonPath('code', 0);
        $this->withToken($this->token)->postJson("/api/v1/production/orders/{$this->order->id}/start")->assertJsonPath('code', 0);
        $this->order->operations()->update(['status' => WorkOrderOperation::STATUS_DONE]);
    }

    // 组装入库载荷（默认 FIN-002×10 满产）
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'order_id' => $this->order->id,
            'warehouse_id' => $this->wh->id,
            'location_id' => $this->b01->id,
            'items' => [
                ['product_id' => $this->fin->id, 'quantity' => 10],
            ],
        ], $overrides);
    }

    // 通过 API 建草稿入库单并返回单号
    private function createInbound(array $payload): string
    {
        $res = $this->withToken($this->token)->postJson('/api/v1/production/finished-inbounds', $payload);
        $res->assertJsonPath('code', 0);

        return $res->json('data.no');
    }

    public function test_store_creates_draft_with_no(): void
    {
        // 正常路径：草稿创建成功，单号 FI{date}-001
        $no = $this->createInbound($this->payload());
        $this->assertMatchesRegularExpression('/^FI\d{8}-001$/', $no);
        $fi = FinishedInbound::where('no', $no)->first();
        $this->assertSame(FinishedInbound::STATUS_DRAFT, $fi->status);
        $this->assertSame('10.00', $fi->items()->first()->quantity);
    }

    public function test_store_rejects_over_remaining_with_1525(): void
    {
        // 异常路径：入库量超剩余产量（11 > 10）→ 1525（草稿期即拦截）
        $this->withToken($this->token)->postJson('/api/v1/production/finished-inbounds', $this->payload(['items' => [
            ['product_id' => $this->fin->id, 'quantity' => 11],
        ]]))
            ->assertJsonPath('code', 1525)
            ->assertJsonPath('message', '入库数量超过工单剩余产量');
    }

    public function test_store_rejects_wrong_product_with_1526(): void
    {
        // 异常路径：入库商品与工单产品不一致 → 1526
        $this->withToken($this->token)->postJson('/api/v1/production/finished-inbounds', $this->payload(['items' => [
            ['product_id' => $this->mat->id, 'quantity' => 1],
        ]]))
            ->assertJsonPath('code', 1526)
            ->assertJsonPath('message', '入库商品与工单产品不一致');
    }

    public function test_approve_credits_inventory_and_completes_order(): void
    {
        // 核心不变式：审核后余额 20→30、finished_inbound 流水（direction=+1）、completed_qty 回写 10、
        // 末工序已完成且满产 → 工单自动「已完成」（completed_at 落库）
        $no = $this->createInbound($this->payload());
        $fi = FinishedInbound::where('no', $no)->first();
        $this->withToken($this->token)->postJson("/api/v1/production/finished-inbounds/{$fi->id}/approve")
            ->assertJsonPath('code', 0);
        $balance = InventoryBalance::where('product_id', $this->fin->id)->first();
        $this->assertSame('30.00', $balance->quantity);
        $this->assertDatabaseHas('inventory_movements', [
            'product_id' => $this->fin->id, 'direction' => 1, 'quantity' => '10.00',
            'balance_after' => '30.00', 'source_type' => 'finished_inbound', 'source_no' => $no,
        ]);
        $this->order->refresh();
        $this->assertSame('10.00', $this->order->completed_qty);
        $this->assertSame(ProductionOrder::STATUS_COMPLETED, $this->order->status);
        $this->assertNotNull($this->order->completed_at);
        $fi->refresh();
        $this->assertSame(FinishedInbound::STATUS_APPROVED, $fi->status);
        $this->assertSame('管理员', $fi->operator);
        $this->assertNotNull($fi->approved_at);
    }

    public function test_approve_partial_batch_keeps_order_producing(): void
    {
        // 边界路径：分批入库（4+6）——第一批后工单仍生产中；第二批满产自动完成
        $no1 = $this->createInbound($this->payload(['items' => [
            ['product_id' => $this->fin->id, 'quantity' => 4],
        ]]));
        $fi1 = FinishedInbound::where('no', $no1)->first();
        $this->withToken($this->token)->postJson("/api/v1/production/finished-inbounds/{$fi1->id}/approve")->assertJsonPath('code', 0);
        $this->order->refresh();
        $this->assertSame('4.00', $this->order->completed_qty);
        $this->assertSame(ProductionOrder::STATUS_PRODUCING, $this->order->status);

        $no2 = $this->createInbound($this->payload(['items' => [
            ['product_id' => $this->fin->id, 'quantity' => 6],
        ]]));
        $fi2 = FinishedInbound::where('no', $no2)->first();
        $this->withToken($this->token)->postJson("/api/v1/production/finished-inbounds/{$fi2->id}/approve")->assertJsonPath('code', 0);
        $this->order->refresh();
        $this->assertSame('10.00', $this->order->completed_qty);
        $this->assertSame(ProductionOrder::STATUS_COMPLETED, $this->order->status);
    }

    public function test_approve_rejects_when_remaining_shrunk_with_1525_rollback(): void
    {
        // 核心不变式：两张草稿各 10 均在剩余 10 时创建（草稿期合法）；先审第一张（completed 10 剩余 0）
        // → 审核第二张超量 1525 整体回滚（审核期锁工单行复核）
        $no1 = $this->createInbound($this->payload());
        $no2 = $this->createInbound($this->payload());
        $fi1 = FinishedInbound::where('no', $no1)->first();
        $fi2 = FinishedInbound::where('no', $no2)->first();
        $this->withToken($this->token)->postJson("/api/v1/production/finished-inbounds/{$fi1->id}/approve")->assertJsonPath('code', 0);
        $res = $this->withToken($this->token)->postJson("/api/v1/production/finished-inbounds/{$fi2->id}/approve");
        $res->assertJsonPath('code', 1525)
            ->assertJsonPath('message', '入库数量超过工单剩余产量');
        // 回滚验证：余额仍 30（第二张未入）、第二张仍草稿
        $this->assertSame('30.00', InventoryBalance::where('product_id', $this->fin->id)->first()->quantity);
        $this->assertDatabaseMissing('inventory_movements', ['source_no' => $no2]);
        $this->assertSame(FinishedInbound::STATUS_DRAFT, $fi2->refresh()->status);
    }

    public function test_approve_idempotent_with_1528(): void
    {
        // 核心不变式：重复审核 → 1528，库存不重复变动
        $no = $this->createInbound($this->payload());
        $fi = FinishedInbound::where('no', $no)->first();
        $this->withToken($this->token)->postJson("/api/v1/production/finished-inbounds/{$fi->id}/approve")->assertJsonPath('code', 0);
        $this->withToken($this->token)->postJson("/api/v1/production/finished-inbounds/{$fi->id}/approve")
            ->assertJsonPath('code', 1528)
            ->assertJsonPath('message', '该成品入库单已审核');
        $this->assertSame('30.00', InventoryBalance::where('product_id', $this->fin->id)->first()->quantity);
    }

    public function test_update_and_destroy_draft_ok_approved_rejected_with_1527(): void
    {
        // 正常+异常路径：草稿可改可删；已审核不可改删 → 1527
        $no = $this->createInbound($this->payload());
        $id = FinishedInbound::where('no', $no)->first()->id;
        $this->withToken($this->token)->putJson("/api/v1/production/finished-inbounds/{$id}", $this->payload(['remark' => '改后']))
            ->assertJsonPath('code', 0);
        $this->withToken($this->token)->deleteJson("/api/v1/production/finished-inbounds/{$id}")
            ->assertJsonPath('code', 0);
        $this->assertDatabaseMissing('finished_inbounds', ['id' => $id]);

        $no2 = $this->createInbound($this->payload());
        $fi2 = FinishedInbound::where('no', $no2)->first();
        $this->withToken($this->token)->postJson("/api/v1/production/finished-inbounds/{$fi2->id}/approve")->assertJsonPath('code', 0);
        $this->withToken($this->token)->putJson("/api/v1/production/finished-inbounds/{$fi2->id}", $this->payload())
            ->assertJsonPath('code', 1527);
        $this->withToken($this->token)->deleteJson("/api/v1/production/finished-inbounds/{$fi2->id}")
            ->assertJsonPath('code', 1527);
    }

    public function test_index_with_labels_and_requires_permission(): void
    {
        // 正常路径：列表含工单单号/成品名/状态标签
        $no = $this->createInbound($this->payload());
        $fi = FinishedInbound::where('no', $no)->first();
        $this->withToken($this->token)->postJson("/api/v1/production/finished-inbounds/{$fi->id}/approve")->assertJsonPath('code', 0);
        $this->withToken($this->token)->getJson('/api/v1/production/finished-inbounds')
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.items.0.order_no', 'MO'.date('Ymd').'-001')
            ->assertJsonPath('data.items.0.product_name', '成品B')
            ->assertJsonPath('data.items.0.quantity', '10.00')
            ->assertJsonPath('data.items.0.status_label', '已审核');
        // 异常路径：无 production.finished.list 权限的角色被拒（403 JSON 信封）
        $role = Role::create(['name' => '普通', 'code' => 'plain']);
        $u = User::create(['name' => '普通', 'username' => 'plain', 'password' => 'admin123', 'status' => 1]);
        $u->roles()->sync([$role->id]);
        $token = $u->createToken('api')->plainTextToken;
        // 测试框架在同一 app 实例内缓存 auth guard 的已认证用户（setUp 已用管理员 token 请求过；
        // 真实 HTTP 每次请求独立容器不受影响），故先重置 guard，再以普通用户 token 验证无权限被拒
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/production/finished-inbounds')->assertStatus(403);
    }
}
