<?php

// 采购入库单接口测试：审核加库存/超量拦截/幂等/订单状态联动/独立入库/预填（核心不变式 100%）

namespace Tests\Feature;

use App\Models\Category;
use App\Models\InventoryBalance;
use App\Models\Location;
use App\Models\Product;
use App\Models\PurchaseInbound;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurchaseInboundTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    private User $admin;

    private Supplier $supplier;

    private Warehouse $wh;

    private Location $a01;

    private Product $mat;

    private Product $semi;

    private int $orderId;   // 已审核订单 id（MAT-001×100、SEMI-001×50）

    private int $matItemId; // 订单行 MAT-001 id

    private int $semiItemId;

    protected function setUp(): void
    {
        parent::setUp();
        $role = Role::create(['name' => '管理员', 'code' => 'admin']);
        $this->admin = User::create(['name' => '管理员', 'username' => 'admin', 'password' => 'admin123', 'status' => 1]);
        $this->admin->roles()->sync([$role->id]);
        $this->token = $this->admin->createToken('api')->plainTextToken;

        $this->supplier = Supplier::create(['name' => '测试供应商', 'code' => 'SUP-001', 'status' => 1]);
        $this->wh = Warehouse::create(['name' => '主仓', 'code' => 'WH01', 'status' => 1]);
        $this->a01 = Location::create(['warehouse_id' => $this->wh->id, 'name' => 'A-01', 'code' => 'A-01', 'status' => 1]);
        $cat = Category::create(['name' => '原材料', 'parent_id' => 0]);
        $unit = Unit::create(['name' => '个', 'code' => 'pc']);
        $this->mat = Product::create(['name' => '测试铝材', 'code' => 'MAT-001', 'type' => 'raw_material', 'category_id' => $cat->id, 'unit_id' => $unit->id, 'status' => 1]);
        $this->semi = Product::create(['name' => '半成品A', 'code' => 'SEMI-001', 'type' => 'semi_finished', 'category_id' => $cat->id, 'unit_id' => $unit->id, 'status' => 1]);
        // 基线库存：MAT-001=100@A-01、SEMI-001=30@A-01（经 InventoryService 保证恒等式）
        app(InventoryService::class)->apply([
            ['product_id' => $this->mat->id, 'warehouse_id' => $this->wh->id, 'location_id' => $this->a01->id, 'direction' => 1, 'quantity' => 100, 'source_type' => 'purchase_inbound', 'source_id' => 0, 'source_no' => 'PO-SEED', 'remark' => '测试基线'],
            ['product_id' => $this->semi->id, 'warehouse_id' => $this->wh->id, 'location_id' => $this->a01->id, 'direction' => 1, 'quantity' => 30, 'source_type' => 'purchase_inbound', 'source_id' => 0, 'source_no' => 'PO-SEED', 'remark' => '测试基线'],
        ]);
        // 已审核订单：MAT-001×100@5元、SEMI-001×50@10元
        $order = PurchaseOrder::create([
            'no' => 'PO'.date('Ymd').'-001', 'supplier_id' => $this->supplier->id,
            'order_date' => now()->toDateString(), 'status' => PurchaseOrder::STATUS_APPROVED,
            'total_amount' => '100000.00', 'approved_at' => now(),
        ]);
        $this->matItemId = $order->items()->create(['product_id' => $this->mat->id, 'quantity' => 100, 'price' => 500, 'received_qty' => 0, 'amount' => 50000])->id;
        $this->semiItemId = $order->items()->create(['product_id' => $this->semi->id, 'quantity' => 50, 'price' => 1000, 'received_qty' => 0, 'amount' => 50000])->id;
        $this->orderId = $order->id;
    }

    // 组装入库单载荷（默认关联订单行 MAT-001×60）
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->wh->id,
            'location_id' => $this->a01->id,
            'order_id' => $this->orderId,
            'items' => [['product_id' => $this->mat->id, 'quantity' => 60, 'price' => 500, 'order_item_id' => $this->matItemId]],
        ], $overrides);
    }

    // 通过 API 建草稿入库单并返回单号
    private function createInbound(array $payload): string
    {
        $res = $this->withToken($this->token)->postJson('/api/v1/purchase/inbounds', $payload);
        $res->assertJsonPath('code', 0);

        return $res->json('data.no');
    }

    // 通过 API 审核入库单（按单号查 id）
    private function approveInbound(string $no, bool $expectOk = true): array
    {
        $list = $this->withToken($this->token)->getJson('/api/v1/purchase/inbounds?keyword='.$no);
        $id = $list->json('data.items.0.id');
        $res = $this->withToken($this->token)->postJson("/api/v1/purchase/inbounds/{$id}/approve");
        if ($expectOk) {
            $res->assertJsonPath('code', 0);
        }

        return $res->json();
    }

    public function test_store_creates_draft_with_no(): void
    {
        // 正常路径：草稿创建成功，单号 PI{date}-001，金额=Σ数量×单价
        $no = $this->createInbound($this->payload());
        $this->assertMatchesRegularExpression('/^PI\d{8}-001$/', $no);
        $inbound = PurchaseInbound::where('no', $no)->first();
        $this->assertSame(PurchaseInbound::STATUS_DRAFT, $inbound->status);
        $this->assertSame('30000.00', $inbound->total_amount);
    }

    public function test_store_requires_warehouse_location_with_1307(): void
    {
        // 异常路径：仓库/库位缺失 → 1307（业务码）
        $body = $this->payload();
        unset($body['warehouse_id']);
        $this->withToken($this->token)->postJson('/api/v1/purchase/inbounds', $body)
            ->assertJsonPath('code', 1307);
        $body2 = $this->payload();
        unset($body2['location_id']);
        $this->withToken($this->token)->postJson('/api/v1/purchase/inbounds', $body2)
            ->assertJsonPath('code', 1307);
    }

    public function test_store_rejects_exceeding_order_remaining_with_1308(): void
    {
        // 异常路径：关联订单行入库量 > 剩余量 → 1308（超量拦截，单据不保存）
        $body = $this->payload();
        $body['items'][0]['quantity'] = 200; // 剩余 100，超量
        $this->withToken($this->token)->postJson('/api/v1/purchase/inbounds', $body)
            ->assertJsonPath('code', 1308);
    }

    public function test_store_rejects_duplicate_item_with_1312(): void
    {
        // 异常路径：同商品+同订单行重复 → 1312
        $body = $this->payload();
        $body['items'][] = ['product_id' => $this->mat->id, 'quantity' => 10, 'price' => 500, 'order_item_id' => $this->matItemId];
        $this->withToken($this->token)->postJson('/api/v1/purchase/inbounds', $body)
            ->assertJsonPath('code', 1312);
    }

    public function test_store_standalone_without_order(): void
    {
        // 正常路径：独立入库（无订单来源）可保存
        $no = $this->createInbound($this->payload(['order_id' => null, 'items' => [
            ['product_id' => $this->mat->id, 'quantity' => 5, 'price' => 100],
        ]]));
        $inbound = PurchaseInbound::where('no', $no)->first();
        $this->assertNull($inbound->order_id);
        $this->assertSame('500.00', $inbound->total_amount);
    }

    public function test_store_rejects_empty_items_with_1301(): void
    {
        // 异常路径：明细为空数组 → 1301（业务码，与订单口径一致）
        $body = $this->payload();
        $body['items'] = [];
        $this->withToken($this->token)->postJson('/api/v1/purchase/inbounds', $body)
            ->assertJsonPath('code', 1301);
    }

    public function test_store_rejects_order_item_ref_without_order_id_with_1308(): void
    {
        // 异常路径：明细带 order_item_id 但未携带 order_id → 1308（防绕过订单状态联动）
        $body = $this->payload();
        $body['order_id'] = null;
        $body['items'] = [[
            'product_id' => $this->mat->id,
            'quantity' => 10,
            'price' => 500,
            'order_item_id' => $this->matItemId,
        ]];
        $this->withToken($this->token)->postJson('/api/v1/purchase/inbounds', $body)
            ->assertJsonPath('code', 1308);
    }

    public function test_store_rejects_supplier_mismatch_with_order_with_1308(): void
    {
        // 异常路径：入库单供应商与来源订单供应商不一致 → 1308（防跨供应商挂单）
        $other = Supplier::create(['name' => '其他供应商', 'code' => 'SUP-002', 'status' => 1]);
        $body = $this->payload(['supplier_id' => $other->id]);
        $this->withToken($this->token)->postJson('/api/v1/purchase/inbounds', $body)
            ->assertJsonPath('code', 1308);
    }

    public function test_store_rejects_scientific_notation_quantity_with_422(): void
    {
        // 异常路径：数量科学计数法 1e2 → 422（正则按字符串形态拦截，防 bcmul ValueError 500）
        $body = $this->payload();
        $body['items'][0]['quantity'] = '1e2';
        $this->withToken($this->token)->postJson('/api/v1/purchase/inbounds', $body)
            ->assertStatus(422);
    }

    public function test_approve_adds_inventory_and_writes_movement(): void
    {
        // 核心不变式：审核后余额+60、purchase_inbound 流水双写、balance_after 正确、回写 received_qty
        $no = $this->createInbound($this->payload());
        $res = $this->approveInbound($no);
        $this->assertSame(0, $res['code']);
        $this->assertSame($no, $res['data']['no']);
        // 余额 +60（基线 100 → 160），流水单号 PI、方向 +
        $balance = InventoryBalance::where('product_id', $this->mat->id)->first();
        $this->assertSame('160.00', $balance->quantity);
        $this->assertDatabaseHas('inventory_movements', [
            'product_id' => $this->mat->id, 'direction' => 1, 'quantity' => '60.00',
            'balance_after' => '160.00', 'source_type' => 'purchase_inbound', 'source_no' => $no,
        ]);
        // 订单行 received_qty 回写 60，订单状态 → 部分入库
        $this->assertSame('60.00', PurchaseOrderItem::find($this->matItemId)->received_qty);
        $this->assertSame(PurchaseOrder::STATUS_PARTIAL, PurchaseOrder::find($this->orderId)->status);
        // 入库单已审核 + 审核人/时间
        $inbound = PurchaseInbound::where('no', $no)->first();
        $this->assertSame(PurchaseInbound::STATUS_APPROVED, $inbound->status);
        $this->assertSame('管理员', $inbound->operator);
        $this->assertNotNull($inbound->inbound_at);
    }

    public function test_approve_completes_order_when_all_received(): void
    {
        // 核心不变式：全部入完 → 订单「已完成」
        $no = $this->createInbound($this->payload(['items' => [
            ['product_id' => $this->mat->id, 'quantity' => 100, 'price' => 500, 'order_item_id' => $this->matItemId],
            ['product_id' => $this->semi->id, 'quantity' => 50, 'price' => 1000, 'order_item_id' => $this->semiItemId],
        ]]));
        $this->approveInbound($no);
        $this->assertSame(PurchaseOrder::STATUS_COMPLETED, PurchaseOrder::find($this->orderId)->status);
    }

    public function test_approve_idempotent_with_1310(): void
    {
        // 核心不变式：重复审核 → 1310，库存不重复增加
        $no = $this->createInbound($this->payload());
        $this->approveInbound($no);
        $res = $this->approveInbound($no, false);
        $this->assertSame(1310, $res['code']);
        $this->assertSame('160.00', InventoryBalance::where('product_id', $this->mat->id)->first()->quantity);
    }

    public function test_approve_rejects_when_order_remaining_shrunk(): void
    {
        // 核心不变式：两张草稿各 60 均在剩余 100 时创建（草稿期合法）；先审第一张（消耗 60 剩余 40）
        // → 审核第二张超量 1308 整体回滚（草稿期校验拦不住审核前的并发消耗，靠审核期锁行复核）
        $no1 = $this->createInbound($this->payload());
        $no2 = $this->createInbound($this->payload());
        $this->approveInbound($no1);
        $res = $this->approveInbound($no2, false);
        $this->assertSame(1308, $res['code']);
        // 回滚验证：余额仍 160（第二张未入），第二张仍草稿
        $this->assertSame('160.00', InventoryBalance::where('product_id', $this->mat->id)->first()->quantity);
        $this->assertSame(PurchaseInbound::STATUS_DRAFT, PurchaseInbound::where('no', $no2)->first()->status);
        $this->assertSame('60.00', PurchaseOrderItem::find($this->matItemId)->received_qty);
    }

    public function test_approve_rejects_when_order_closed(): void
    {
        // 异常路径：草稿创建后订单被关闭，审核 → 1308（关闭后不可再入库；审核期锁订单头复查拦截）
        $no = $this->createInbound($this->payload());
        $order = PurchaseOrder::find($this->orderId);
        $order->status = PurchaseOrder::STATUS_CLOSED;
        $order->closed_at = now();
        $order->save();
        $res = $this->approveInbound($no, false);
        $this->assertSame(1308, $res['code']);
    }

    public function test_from_order_prefill_returns_remaining(): void
    {
        // 正常路径：from-order 预填剩余量正确（未入库 → 剩余=订购数）
        $this->withToken($this->token)->getJson("/api/v1/purchase/inbounds/from-order/{$this->orderId}")
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.order_no', 'PO'.date('Ymd').'-001')
            ->assertJsonPath('data.items.0.product_code', 'MAT-001')
            ->assertJsonPath('data.items.0.quantity', '100.00')
            ->assertJsonPath('data.items.0.remaining_qty', '100.00')
            ->assertJsonPath('data.items.0.price', '500.00');
    }

    public function test_from_order_rejects_completed_order(): void
    {
        // 异常路径：已完成订单不可预填 → 1308
        $order = PurchaseOrder::find($this->orderId);
        foreach ($order->items as $item) {
            $item->received_qty = $item->quantity;
            $item->save();
        }
        $order->status = PurchaseOrder::STATUS_COMPLETED;
        $order->save();
        $this->withToken($this->token)->getJson("/api/v1/purchase/inbounds/from-order/{$this->orderId}")
            ->assertJsonPath('code', 1308);
    }

    public function test_update_and_destroy_draft_ok_approved_rejected_with_1309(): void
    {
        // 正常+异常路径：草稿可改可删；已审核不可改删 → 1309
        $no = $this->createInbound($this->payload());
        $id = PurchaseInbound::where('no', $no)->first()->id;
        $this->withToken($this->token)->putJson("/api/v1/purchase/inbounds/{$id}", $this->payload(['remark' => '改后']))
            ->assertJsonPath('code', 0);
        $this->withToken($this->token)->deleteJson("/api/v1/purchase/inbounds/{$id}")
            ->assertJsonPath('code', 0);
        $this->assertDatabaseMissing('purchase_inbounds', ['id' => $id]);

        $no2 = $this->createInbound($this->payload());
        $this->approveInbound($no2);
        $id2 = PurchaseInbound::where('no', $no2)->first()->id;
        $this->withToken($this->token)->putJson("/api/v1/purchase/inbounds/{$id2}", $this->payload())
            ->assertJsonPath('code', 1309);
        $this->withToken($this->token)->deleteJson("/api/v1/purchase/inbounds/{$id2}")
            ->assertJsonPath('code', 1309);
    }

    public function test_index_with_filters_and_labels(): void
    {
        // 正常路径：列表含供应商/仓库/库位名、来源订单单号、状态标签；状态筛选
        $no = $this->createInbound($this->payload());
        $this->approveInbound($no);
        $this->withToken($this->token)->getJson('/api/v1/purchase/inbounds')
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.items.0.supplier_name', '测试供应商')
            ->assertJsonPath('data.items.0.warehouse_name', '主仓')
            ->assertJsonPath('data.items.0.location_name', 'A-01')
            ->assertJsonPath('data.items.0.order_no', 'PO'.date('Ymd').'-001')
            ->assertJsonPath('data.items.0.status_label', '已审核');
        $this->withToken($this->token)->getJson('/api/v1/purchase/inbounds?status=0')
            ->assertJsonPath('data.total', 0);
        $this->withToken($this->token)->getJson('/api/v1/purchase/inbounds?keyword='.$no)
            ->assertJsonPath('data.total', 1);
    }

    public function test_show_returns_items_with_order_ref(): void
    {
        // 正常路径：详情含明细（商品名/数量/单价/金额/订单行引用）
        $no = $this->createInbound($this->payload());
        $id = PurchaseInbound::where('no', $no)->first()->id;
        $this->withToken($this->token)->getJson("/api/v1/purchase/inbounds/{$id}")
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.items.0.product_code', 'MAT-001')
            ->assertJsonPath('data.items.0.quantity', '60.00')
            ->assertJsonPath('data.items.0.amount', '30000.00')
            ->assertJsonPath('data.items.0.order_item_id', $this->matItemId);
    }

    public function test_standalone_inbound_movement_has_no_order_ref(): void
    {
        // 正常路径：独立入库审核后流水正常生成（无订单联动）
        $no = $this->createInbound($this->payload(['order_id' => null, 'items' => [
            ['product_id' => $this->mat->id, 'quantity' => 5, 'price' => 100],
        ]]));
        $this->approveInbound($no);
        $this->assertSame('105.00', InventoryBalance::where('product_id', $this->mat->id)->first()->quantity);
        $this->assertDatabaseHas('inventory_movements', [
            'product_id' => $this->mat->id, 'source_type' => 'purchase_inbound', 'source_no' => $no,
        ]);
    }

    public function test_inbounds_requires_permission(): void
    {
        // 异常路径：无 purchase.inbound.list 权限的角色被拒（403 JSON 信封）
        $role = Role::create(['name' => '普通', 'code' => 'plain']);
        $u = User::create(['name' => '普通', 'username' => 'plain', 'password' => 'admin123', 'status' => 1]);
        $u->roles()->sync([$role->id]);
        $token = $u->createToken('api')->plainTextToken;
        $this->withToken($token)->getJson('/api/v1/purchase/inbounds')->assertStatus(403);
    }
}
