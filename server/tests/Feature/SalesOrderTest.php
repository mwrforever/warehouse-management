<?php

// 销售订单接口测试：CRUD/金额 bcmath/审核/关闭/状态机/available/出库记录/原料禁售（核心路径 100%）

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Role;
use App\Models\SalesOrder;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\DocumentNumberConfigSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SalesOrderTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    private User $admin;

    private Customer $customer;

    private Product $mat;

    private Product $semi;

    private Product $fin;

    protected function setUp(): void
    {
        parent::setUp();
        // 编号规则配置种子（Spec 2）：单据号按配置生成 CK/PO/MO 等业务前缀
        $this->seed(DocumentNumberConfigSeeder::class);
        $role = Role::create(['name' => '管理员', 'code' => 'admin']);
        $this->admin = User::create(['name' => '管理员', 'username' => 'admin', 'password' => 'admin123', 'status' => 1]);
        $this->admin->roles()->sync([$role->id]);
        $this->token = $this->admin->createToken('api')->plainTextToken;

        $this->customer = Customer::create(['name' => '测试客户', 'code' => 'CUS-001', 'status' => 1]);
        $cat = Category::create(['name' => '成品', 'parent_id' => 0]);
        $semiCat = Category::create(['name' => '半成品', 'parent_id' => 0]);
        $rawCat = Category::create(['name' => '原材料', 'parent_id' => 0]);
        $unit = Unit::create(['name' => '个', 'code' => 'pc']);
        $this->mat = Product::create(['name' => '测试铝材', 'code' => 'MAT-001', 'type' => 'raw_material', 'category_id' => $rawCat->id, 'unit_id' => $unit->id, 'status' => 1]);
        $this->semi = Product::create(['name' => '半成品A', 'code' => 'SEMI-001', 'type' => 'semi_finished', 'category_id' => $semiCat->id, 'unit_id' => $unit->id, 'status' => 1]);
        $this->fin = Product::create(['name' => '成品B', 'code' => 'FIN-002', 'type' => 'finished', 'category_id' => $cat->id, 'unit_id' => $unit->id, 'status' => 1]);
    }

    // 组装订单载荷（默认 2 行：FIN-002×10@100元、SEMI-001×5@20元）
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'customer_id' => $this->customer->id,
            'order_date' => now()->toDateString(),
            'items' => [
                ['product_id' => $this->fin->id, 'quantity' => 10, 'price' => 10000],
                ['product_id' => $this->semi->id, 'quantity' => 5, 'price' => 2000],
            ],
        ], $overrides);
    }

    // 通过 API 建草稿订单并返回单号
    private function createOrder(array $payload): string
    {
        $res = $this->withToken($this->token)->postJson('/api/v1/sales/orders', $payload);
        $res->assertJsonPath('code', 0);

        return $res->json('data.no');
    }

    // 通过 API 审核订单（返回 id）
    private function approveOrder(string $no): int
    {
        $list = $this->withToken($this->token)->getJson('/api/v1/sales/orders?keyword='.$no);
        $id = $list->json('data.items.0.id');
        $this->withToken($this->token)->postJson("/api/v1/sales/orders/{$id}/approve")->assertJsonPath('code', 0);

        return $id;
    }

    public function test_store_creates_draft_with_no_and_total(): void
    {
        // 正常路径：草稿创建成功，单号 SO{date}-001，金额=Σ数量×单价（分）
        $no = $this->createOrder($this->payload());
        $this->assertMatchesRegularExpression('/^SO\d{12}001$/', $no);
        $order = SalesOrder::where('no', $no)->first();
        $this->assertSame(SalesOrder::STATUS_DRAFT, $order->status);
        // 10×10000 + 5×2000 = 110000 分
        $this->assertSame('110000.00', $order->total_amount);
        $this->assertSame(2, $order->items()->count());
    }

    public function test_store_amount_precise_with_decimal_quantity(): void
    {
        // 边界路径：小数数量×单价 bcmath 精确（1.55×123=190.65 分，浮点会 190.6499...）
        $no = $this->createOrder($this->payload(['items' => [
            ['product_id' => $this->fin->id, 'quantity' => 1.55, 'price' => 123],
        ]]));
        $order = SalesOrder::where('no', $no)->first();
        $this->assertSame('190.65', $order->items()->first()->amount);
    }

    public function test_store_rejects_empty_items_with_1401(): void
    {
        // 异常路径：明细为空 → 1401（业务码）
        $this->withToken($this->token)->postJson('/api/v1/sales/orders', ['customer_id' => $this->customer->id, 'order_date' => now()->toDateString(), 'items' => []])
            ->assertJsonPath('code', 1401);
    }

    public function test_store_rejects_non_positive_quantity_with_422(): void
    {
        // 异常路径：数量 ≤ 0 → 422（值域校验；1401-1412 无空闲业务码位，盘点重复行 422 先例）
        $items = $this->payload()['items'];
        $items[0]['quantity'] = 0;
        $this->withToken($this->token)->postJson('/api/v1/sales/orders', ['customer_id' => $this->customer->id, 'order_date' => now()->toDateString(), 'items' => $items])
            ->assertJsonPath('code', 422);
    }

    public function test_store_rejects_negative_price_with_1411(): void
    {
        // 异常路径：负价格 → 1411（价格 0 允许：赠品场景）
        $items = $this->payload()['items'];
        $items[0]['price'] = -1;
        $this->withToken($this->token)->postJson('/api/v1/sales/orders', ['customer_id' => $this->customer->id, 'order_date' => now()->toDateString(), 'items' => $items])
            ->assertJsonPath('code', 1411);
    }

    public function test_store_rejects_raw_material_with_422(): void
    {
        // 异常路径：原料商品不可销售（SAL-10 后端防御校验；前端下拉已过滤，后端兜底）
        $items = $this->payload()['items'];
        $items[0]['product_id'] = $this->mat->id;
        $this->withToken($this->token)->postJson('/api/v1/sales/orders', ['customer_id' => $this->customer->id, 'order_date' => now()->toDateString(), 'items' => $items])
            ->assertJsonPath('code', 422);
    }

    public function test_store_rejects_duplicate_product_with_1412(): void
    {
        // 异常路径：同商品重复行 → 1412
        $items = $this->payload()['items'];
        $items[] = ['product_id' => $this->fin->id, 'quantity' => 1, 'price' => 1];
        $this->withToken($this->token)->postJson('/api/v1/sales/orders', ['customer_id' => $this->customer->id, 'order_date' => now()->toDateString(), 'items' => $items])
            ->assertJsonPath('code', 1412);
    }

    public function test_store_item_validation_queries_products_in_single_batch(): void
    {
        // 性能路径（B-105）：原料禁售校验须一次 whereIn 批量预取全部明细商品，
        // 禁止循环内逐行 Product::find（N 行明细 N 次查询的 N+1 形态；2 行明细断言仅 1 次商品行查询）
        DB::enableQueryLog();
        $this->withToken($this->token)->postJson('/api/v1/sales/orders', $this->payload())
            ->assertJsonPath('code', 0);
        $productSelects = collect(DB::getQueryLog())
            ->filter(fn ($q) => str_starts_with($q['query'], 'select * from "products"'));
        DB::disableQueryLog();
        $this->assertCount(1, $productSelects, '明细商品校验应一次批量查询完成，实际查询：'.$productSelects->pluck('query')->implode(' | '));
    }

    public function test_update_draft_recalculates_total(): void
    {
        // 正常路径：草稿可改，金额重算（10×10000 改为 12×10000 → 120000+10000=130000）
        $no = $this->createOrder($this->payload());
        $order = SalesOrder::where('no', $no)->first();
        $items = $this->payload()['items'];
        $items[0]['quantity'] = 12;
        $this->withToken($this->token)->putJson("/api/v1/sales/orders/{$order->id}", $this->payload(['items' => $items]))
            ->assertJsonPath('code', 0);
        $this->assertSame('130000.00', SalesOrder::where('no', $no)->first()->total_amount);
    }

    public function test_update_approved_rejected_with_1402(): void
    {
        // 异常路径：已审核订单不可修改 → 1402
        $no = $this->createOrder($this->payload());
        $id = $this->approveOrder($no);
        $this->withToken($this->token)->putJson("/api/v1/sales/orders/{$id}", $this->payload())
            ->assertJsonPath('code', 1402);
    }

    public function test_destroy_draft_ok_and_approved_rejected_with_1403(): void
    {
        // 正常+异常路径：草稿可删；已审核不可删 → 1403
        $no = $this->createOrder($this->payload());
        $draftId = SalesOrder::where('no', $no)->first()->id;
        $this->withToken($this->token)->deleteJson("/api/v1/sales/orders/{$draftId}")->assertJsonPath('code', 0);
        $this->assertDatabaseMissing('sales_orders', ['id' => $draftId]);

        $no2 = $this->createOrder($this->payload());
        $id2 = $this->approveOrder($no2);
        $this->withToken($this->token)->deleteJson("/api/v1/sales/orders/{$id2}")->assertJsonPath('code', 1403);
    }

    public function test_approve_marks_status_and_rejects_duplicate_with_1404(): void
    {
        // 正常+异常路径：审核成功（状态 1 + approved_at）；重复审核 → 1404
        $no = $this->createOrder($this->payload());
        $id = $this->approveOrder($no);
        $order = SalesOrder::find($id);
        $this->assertSame(SalesOrder::STATUS_APPROVED, $order->status);
        $this->assertNotNull($order->approved_at);
        $this->withToken($this->token)->postJson("/api/v1/sales/orders/{$id}/approve")
            ->assertJsonPath('code', 1404);
    }

    public function test_close_approved_order_and_reject_completed_with_1405(): void
    {
        // 正常+异常路径：已审核可关闭（状态 4）；已完成不可关闭 → 1405
        $no = $this->createOrder($this->payload());
        $id = $this->approveOrder($no);
        $this->withToken($this->token)->postJson("/api/v1/sales/orders/{$id}/close")->assertJsonPath('code', 0);
        $this->assertSame(SalesOrder::STATUS_CLOSED, SalesOrder::find($id)->status);
        $this->assertNotNull(SalesOrder::find($id)->closed_at);

        // 已完成订单：模拟出库回写状态为已完成后再关 → 1405
        $no2 = $this->createOrder($this->payload());
        $id2 = $this->approveOrder($no2);
        $order = SalesOrder::find($id2);
        foreach ($order->items as $item) {
            $item->shipped_qty = $item->quantity;
            $item->save();
        }
        $order->status = SalesOrder::STATUS_COMPLETED;
        $order->save();
        $this->withToken($this->token)->postJson("/api/v1/sales/orders/{$id2}/close")
            ->assertJsonPath('code', 1405);
    }

    public function test_close_draft_rejected_with_1405(): void
    {
        // 异常路径：草稿不可关闭（直接删除）→ 1405
        $no = $this->createOrder($this->payload());
        $id = SalesOrder::where('no', $no)->first()->id;
        $this->withToken($this->token)->postJson("/api/v1/sales/orders/{$id}/close")
            ->assertJsonPath('code', 1405);
    }

    public function test_index_with_filters_and_labels(): void
    {
        // 正常路径：列表含客户名/状态中文标签；keyword 与 status 筛选
        $no = $this->createOrder($this->payload());
        $this->approveOrder($no);
        $this->withToken($this->token)->getJson('/api/v1/sales/orders')
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.items.0.customer_name', '测试客户')
            ->assertJsonPath('data.items.0.status', 1)
            ->assertJsonPath('data.items.0.status_label', '已审核')
            ->assertJsonPath('data.items.0.total_amount', '110000.00');
        $this->withToken($this->token)->getJson('/api/v1/sales/orders?keyword=SO'.date('Ymd'))
            ->assertJsonPath('data.total', 1);
        $this->withToken($this->token)->getJson('/api/v1/sales/orders?status=0')
            ->assertJsonPath('data.total', 0);
    }

    public function test_show_returns_items_with_shipped_qty(): void
    {
        // 正常路径：详情含明细（商品名/订购数/已出库/单价/金额）
        $no = $this->createOrder($this->payload());
        $id = SalesOrder::where('no', $no)->first()->id;
        $this->withToken($this->token)->getJson("/api/v1/sales/orders/{$id}")
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.items.0.product_code', 'FIN-002')
            ->assertJsonPath('data.items.0.quantity', '10.00')
            ->assertJsonPath('data.items.0.shipped_qty', '0.00')
            ->assertJsonPath('data.items.0.amount', '100000.00');
    }

    public function test_available_only_lists_outboundable_orders(): void
    {
        // 正常路径：available 仅出 已审核/部分出库 且 未关闭 且有剩余量 的订单（从订单生成下拉数据源）
        $no = $this->createOrder($this->payload());
        $this->approveOrder($no);
        $no2 = $this->createOrder($this->payload());
        $id2 = $this->approveOrder($no2);
        $res = $this->withToken($this->token)->getJson('/api/v1/sales/orders/available');
        $this->assertSame(2, $res->json('data.total'));
        $this->assertSame($no2, $res->json('data.items.0.no'));
        // 关闭后不再出现（no2 关闭，available 只剩 no）
        $this->withToken($this->token)->postJson("/api/v1/sales/orders/{$id2}/close")->assertJsonPath('code', 0);
        $res2 = $this->withToken($this->token)->getJson('/api/v1/sales/orders/available');
        $this->assertSame(1, $res2->json('data.total'));
        $this->assertSame($no, $res2->json('data.items.0.no'));
    }

    public function test_orders_requires_sales_order_permission(): void
    {
        // 异常路径：无 sales.order.list 权限的角色被拒（403 JSON 信封）
        $role = Role::create(['name' => '普通', 'code' => 'plain']);
        $u = User::create(['name' => '普通', 'username' => 'plain', 'password' => 'admin123', 'status' => 1]);
        $u->roles()->sync([$role->id]);
        $token = $u->createToken('api')->plainTextToken;
        $this->withToken($token)->getJson('/api/v1/sales/orders')->assertStatus(403);
    }
}
