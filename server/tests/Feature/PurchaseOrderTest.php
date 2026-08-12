<?php

// 采购订单接口测试：CRUD/金额 bcmath/审核/关闭/状态机/available/入库记录（核心路径 100%）

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurchaseOrderTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    private User $admin;

    private Supplier $supplier;

    private Product $mat;

    private Product $semi;

    protected function setUp(): void
    {
        parent::setUp();
        $role = Role::create(['name' => '管理员', 'code' => 'admin']);
        $this->admin = User::create(['name' => '管理员', 'username' => 'admin', 'password' => 'admin123', 'status' => 1]);
        $this->admin->roles()->sync([$role->id]);
        $this->token = $this->admin->createToken('api')->plainTextToken;

        $this->supplier = Supplier::create(['name' => '测试供应商', 'code' => 'SUP-001', 'status' => 1]);
        $cat = Category::create(['name' => '原材料', 'parent_id' => 0]);
        $unit = Unit::create(['name' => '个', 'code' => 'pc']);
        $this->mat = Product::create(['name' => '测试铝材', 'code' => 'MAT-001', 'type' => 'raw_material', 'category_id' => $cat->id, 'unit_id' => $unit->id, 'status' => 1]);
        $this->semi = Product::create(['name' => '半成品A', 'code' => 'SEMI-001', 'type' => 'semi_finished', 'category_id' => $cat->id, 'unit_id' => $unit->id, 'status' => 1]);
    }

    // 组装订单载荷（默认 2 行：MAT-001×100@5元、SEMI-001×50@10元）
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'supplier_id' => $this->supplier->id,
            'order_date' => now()->toDateString(),
            'items' => [
                ['product_id' => $this->mat->id, 'quantity' => 100, 'price' => 500],
                ['product_id' => $this->semi->id, 'quantity' => 50, 'price' => 1000],
            ],
        ], $overrides);
    }

    // 通过 API 建草稿订单并返回单号
    private function createOrder(array $payload): string
    {
        $res = $this->withToken($this->token)->postJson('/api/v1/purchase/orders', $payload);
        $res->assertJsonPath('code', 0);

        return $res->json('data.no');
    }

    // 通过 API 审核订单（返回 id）
    private function approveOrder(string $no): int
    {
        $list = $this->withToken($this->token)->getJson('/api/v1/purchase/orders?keyword='.$no);
        $id = $list->json('data.items.0.id');
        $this->withToken($this->token)->postJson("/api/v1/purchase/orders/{$id}/approve")->assertJsonPath('code', 0);

        return $id;
    }

    public function test_store_creates_draft_with_no_and_total(): void
    {
        // 正常路径：草稿创建成功，单号 PO{date}-001，金额=Σ数量×单价（分）
        $no = $this->createOrder($this->payload());
        $this->assertMatchesRegularExpression('/^PO\d{8}-001$/', $no);
        $order = PurchaseOrder::where('no', $no)->first();
        $this->assertSame(PurchaseOrder::STATUS_DRAFT, $order->status);
        // 100×500 + 50×1000 = 100000 分
        $this->assertSame('100000.00', $order->total_amount);
        $this->assertSame(2, $order->items()->count());
    }

    public function test_store_amount_precise_with_decimal_quantity(): void
    {
        // 边界路径：小数数量×单价 bcmath 精确（1.55×123=190.65 分，浮点会 190.6499...）
        $no = $this->createOrder($this->payload(['items' => [
            ['product_id' => $this->mat->id, 'quantity' => 1.55, 'price' => 123],
        ]]));
        $order = PurchaseOrder::where('no', $no)->first();
        $this->assertSame('190.65', $order->items()->first()->amount);
    }

    public function test_store_rejects_empty_items_with_1301(): void
    {
        // 异常路径：明细为空 → 1301（业务码）
        $this->withToken($this->token)->postJson('/api/v1/purchase/orders', ['supplier_id' => $this->supplier->id, 'order_date' => now()->toDateString(), 'items' => []])
            ->assertJsonPath('code', 1301);
    }

    public function test_store_rejects_non_positive_quantity_with_1302(): void
    {
        // 异常路径：数量 ≤ 0 → 1302
        $items = $this->payload()['items'];
        $items[0]['quantity'] = 0;
        $this->withToken($this->token)->postJson('/api/v1/purchase/orders', ['supplier_id' => $this->supplier->id, 'order_date' => now()->toDateString(), 'items' => $items])
            ->assertJsonPath('code', 1302);
    }

    public function test_store_rejects_negative_price_with_1311(): void
    {
        // 异常路径：负价格 → 1311（价格 0 允许：赠品场景）
        $items = $this->payload()['items'];
        $items[0]['price'] = -1;
        $this->withToken($this->token)->postJson('/api/v1/purchase/orders', ['supplier_id' => $this->supplier->id, 'order_date' => now()->toDateString(), 'items' => $items])
            ->assertJsonPath('code', 1311);
    }

    public function test_store_accepts_zero_price_for_gift(): void
    {
        // 边界路径：价格 0 允许（赠品），金额 0 不报错
        $no = $this->createOrder($this->payload(['items' => [
            ['product_id' => $this->mat->id, 'quantity' => 10, 'price' => 0],
        ]]));
        $this->assertSame('0.00', PurchaseOrder::where('no', $no)->first()->total_amount);
    }

    public function test_store_rejects_duplicate_product_with_1312(): void
    {
        // 异常路径：同商品重复行 → 1312
        $items = $this->payload()['items'];
        $items[] = ['product_id' => $this->mat->id, 'quantity' => 1, 'price' => 1];
        $this->withToken($this->token)->postJson('/api/v1/purchase/orders', ['supplier_id' => $this->supplier->id, 'order_date' => now()->toDateString(), 'items' => $items])
            ->assertJsonPath('code', 1312);
    }

    public function test_update_draft_recalculates_total(): void
    {
        // 正常路径：草稿可改，金额重算（100×500 改为 120×500 → 60000+50000=110000）
        $no = $this->createOrder($this->payload());
        $order = PurchaseOrder::where('no', $no)->first();
        $items = $this->payload()['items'];
        $items[0]['quantity'] = 120;
        $this->withToken($this->token)->putJson("/api/v1/purchase/orders/{$order->id}", $this->payload(['items' => $items]))
            ->assertJsonPath('code', 0);
        $this->assertSame('110000.00', PurchaseOrder::where('no', $no)->first()->total_amount);
    }

    public function test_update_approved_rejected_with_1303(): void
    {
        // 异常路径：已审核订单不可修改 → 1303
        $no = $this->createOrder($this->payload());
        $id = $this->approveOrder($no);
        $this->withToken($this->token)->putJson("/api/v1/purchase/orders/{$id}", $this->payload())
            ->assertJsonPath('code', 1303);
    }

    public function test_destroy_draft_ok_and_approved_rejected_with_1304(): void
    {
        // 正常+异常路径：草稿可删；已审核不可删 → 1304
        $no = $this->createOrder($this->payload());

        $draftId = PurchaseOrder::where('no', $no)->first()->id;
        $this->withToken($this->token)->deleteJson("/api/v1/purchase/orders/{$draftId}")->assertJsonPath('code', 0);
        $this->assertDatabaseMissing('purchase_orders', ['id' => $draftId]);

        $no2 = $this->createOrder($this->payload());
        $id2 = $this->approveOrder($no2);
        $this->withToken($this->token)->deleteJson("/api/v1/purchase/orders/{$id2}")->assertJsonPath('code', 1304);
    }

    public function test_approve_marks_status_and_rejects_duplicate_with_1305(): void
    {
        // 正常+异常路径：审核成功（状态 1 + approved_at）；重复审核 → 1305
        $no = $this->createOrder($this->payload());
        $id = $this->approveOrder($no);
        $order = PurchaseOrder::find($id);
        $this->assertSame(PurchaseOrder::STATUS_APPROVED, $order->status);
        $this->assertNotNull($order->approved_at);
        $this->withToken($this->token)->postJson("/api/v1/purchase/orders/{$id}/approve")
            ->assertJsonPath('code', 1305);
    }

    public function test_close_approved_order_and_reject_completed_with_1306(): void
    {
        // 正常+异常路径：已审核可关闭（状态 4）；已完成不可关闭 → 1306
        $no = $this->createOrder($this->payload());
        $id = $this->approveOrder($no);
        $this->withToken($this->token)->postJson("/api/v1/purchase/orders/{$id}/close")->assertJsonPath('code', 0);
        $this->assertSame(PurchaseOrder::STATUS_CLOSED, PurchaseOrder::find($id)->status);
        $this->assertNotNull(PurchaseOrder::find($id)->closed_at);

        // 已完成订单：模拟入库回写状态为已完成后再关 → 1306
        $no2 = $this->createOrder($this->payload());
        $id2 = $this->approveOrder($no2);
        $order = PurchaseOrder::find($id2);
        foreach ($order->items as $item) {
            $item->received_qty = $item->quantity;
            $item->save();
        }
        $order->status = PurchaseOrder::STATUS_COMPLETED;
        $order->save();
        $this->withToken($this->token)->postJson("/api/v1/purchase/orders/{$id2}/close")
            ->assertJsonPath('code', 1306);
    }

    public function test_close_draft_rejected_with_1306(): void
    {
        // 异常路径：草稿不可关闭（直接删除）→ 1306
        $no = $this->createOrder($this->payload());
        $id = PurchaseOrder::where('no', $no)->first()->id;
        $this->withToken($this->token)->postJson("/api/v1/purchase/orders/{$id}/close")
            ->assertJsonPath('code', 1306);
    }

    public function test_index_with_filters_and_labels(): void
    {
        // 正常路径：列表含供应商名/状态中文标签；keyword 与 status 筛选
        $no = $this->createOrder($this->payload());
        $this->approveOrder($no);
        $this->withToken($this->token)->getJson('/api/v1/purchase/orders')
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.items.0.supplier_name', '测试供应商')
            ->assertJsonPath('data.items.0.status', 1)
            ->assertJsonPath('data.items.0.status_label', '已审核')
            ->assertJsonPath('data.items.0.total_amount', '100000.00');
        $this->withToken($this->token)->getJson('/api/v1/purchase/orders?keyword=PO'.date('Ymd'))
            ->assertJsonPath('data.total', 1);
        $this->withToken($this->token)->getJson('/api/v1/purchase/orders?status=0')
            ->assertJsonPath('data.total', 0);
    }

    public function test_show_returns_items_with_received_qty(): void
    {
        // 正常路径：详情含明细（商品名/订购数/已入库/单价/金额）
        $no = $this->createOrder($this->payload());
        $id = PurchaseOrder::where('no', $no)->first()->id;
        $this->withToken($this->token)->getJson("/api/v1/purchase/orders/{$id}")
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.items.0.product_code', 'MAT-001')
            ->assertJsonPath('data.items.0.quantity', '100.00')
            ->assertJsonPath('data.items.0.received_qty', '0.00')
            ->assertJsonPath('data.items.0.amount', '50000.00');
    }

    public function test_available_only_lists_approvable_orders(): void
    {
        // 正常路径：available 仅出 已审核/部分入库 且 未关闭 且有剩余量 的订单（从订单生成下拉数据源）
        $no = $this->createOrder($this->payload());
        $this->approveOrder($no);
        $no2 = $this->createOrder($this->payload());
        $id2 = PurchaseOrder::where('no', $no2)->first()->id;
        $res = $this->withToken($this->token)->getJson('/api/v1/purchase/orders/available');
        $this->assertSame(1, $res->json('data.total'));
        $this->assertSame($no, $res->json('data.items.0.no'));
        // 关闭后不再出现：先审核再关闭（草稿不可关闭 1306 由 close_draft 用例覆盖）
        $this->approveOrder($no2);
        $this->withToken($this->token)->postJson("/api/v1/purchase/orders/{$id2}/close")->assertJsonPath('code', 0);
        $res2 = $this->withToken($this->token)->getJson('/api/v1/purchase/orders/available');
        $this->assertSame(1, $res2->json('data.total'));
    }

    public function test_orders_requires_purchase_order_permission(): void
    {
        // 异常路径：无 purchase.order.list 权限的角色被拒（403 JSON 信封）
        $role = Role::create(['name' => '普通', 'code' => 'plain']);
        $u = User::create(['name' => '普通', 'username' => 'plain', 'password' => 'admin123', 'status' => 1]);
        $u->roles()->sync([$role->id]);
        $token = $u->createToken('api')->plainTextToken;
        $this->withToken($token)->getJson('/api/v1/purchase/orders')->assertStatus(403);
    }
}
