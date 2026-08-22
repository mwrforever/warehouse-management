<?php

// 销售出库单接口测试：CRUD/from-order 预填/审核扣库存/防超卖回滚/并发/幂等/状态联动/客户一致性（核心路径 100%）

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Customer;
use App\Models\InventoryBalance;
use App\Models\Location;
use App\Models\Product;
use App\Models\Role;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use App\Models\SalesOutbound;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\InventoryService;
use Database\Seeders\DocumentNumberConfigSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalesOutboundTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    private Customer $customer;

    private Warehouse $wh;

    private Location $b01;

    private Location $a01;

    private Product $mat;

    private Product $semi;

    private Product $fin;

    private int $orderId;

    private int $finItemId;

    private int $semiItemId;

    protected function setUp(): void
    {
        parent::setUp();
        // 编号规则配置种子（Spec 2）：单据号按配置生成 CK/PO/MO 等业务前缀
        $this->seed(DocumentNumberConfigSeeder::class);
        $role = Role::create(['name' => '管理员', 'code' => 'admin']);
        $u = User::create(['name' => '管理员', 'username' => 'admin', 'password' => 'admin123', 'status' => 1]);
        $u->roles()->sync([$role->id]);
        $this->token = $u->createToken('api')->plainTextToken;

        $this->customer = Customer::create(['name' => '测试客户', 'code' => 'CUS-001', 'status' => 1]);
        $this->wh = Warehouse::create(['name' => '主仓', 'code' => 'WH01', 'status' => 1]);
        $this->a01 = Location::create(['warehouse_id' => $this->wh->id, 'name' => 'A-01', 'code' => 'A-01', 'status' => 1]);
        $this->b01 = Location::create(['warehouse_id' => $this->wh->id, 'name' => 'B-01', 'code' => 'B-01', 'status' => 1]);
        $cat = Category::create(['name' => '成品', 'parent_id' => 0]);
        $semiCat = Category::create(['name' => '半成品', 'parent_id' => 0]);
        $rawCat = Category::create(['name' => '原材料', 'parent_id' => 0]);
        $unit = Unit::create(['name' => '个', 'code' => 'pc']);
        $this->mat = Product::create(['name' => '测试铝材', 'code' => 'MAT-001', 'type' => 'raw_material', 'category_id' => $rawCat->id, 'unit_id' => $unit->id, 'status' => 1]);
        $this->semi = Product::create(['name' => '半成品A', 'code' => 'SEMI-001', 'type' => 'semi_finished', 'category_id' => $semiCat->id, 'unit_id' => $unit->id, 'status' => 1]);
        $this->fin = Product::create(['name' => '成品B', 'code' => 'FIN-002', 'type' => 'finished', 'category_id' => $cat->id, 'unit_id' => $unit->id, 'status' => 1]);

        // 基线库存：FIN-002 @B-01=100、SEMI-001 @B-01=40（经统一引擎注入，满足余额=流水恒等式；
        // 注：出库单整单只落一个库位 B-01，库存须在出库库位下，否则审核期 1409 拦截——E2E 同源）
        app(InventoryService::class)->apply([
            ['product_id' => $this->fin->id, 'warehouse_id' => $this->wh->id, 'location_id' => $this->b01->id, 'direction' => 1, 'quantity' => 100, 'source_type' => 'purchase_inbound', 'source_id' => 0, 'source_no' => 'SEED', 'remark' => '测试基线'],
            ['product_id' => $this->semi->id, 'warehouse_id' => $this->wh->id, 'location_id' => $this->b01->id, 'direction' => 1, 'quantity' => 40, 'source_type' => 'purchase_inbound', 'source_id' => 0, 'source_no' => 'SEED', 'remark' => '测试基线'],
        ]);

        // 已审核订单：FIN-002×10@100元 + SEMI-001×5@20元（草稿 → 审核）
        $res = $this->withToken($this->token)->postJson('/api/v1/sales/orders', [
            'customer_id' => $this->customer->id,
            'order_date' => now()->toDateString(),
            'items' => [
                ['product_id' => $this->fin->id, 'quantity' => 10, 'price' => 10000],
                ['product_id' => $this->semi->id, 'quantity' => 5, 'price' => 2000],
            ],
        ]);
        $res->assertJsonPath('code', 0);
        $orderNo = $res->json('data.no');
        $order = SalesOrder::where('no', $orderNo)->first();
        $this->orderId = $order->id;
        $this->finItemId = $order->items()->where('product_id', $this->fin->id)->first()->id;
        $this->semiItemId = $order->items()->where('product_id', $this->semi->id)->first()->id;
        $this->withToken($this->token)->postJson("/api/v1/sales/orders/{$this->orderId}/approve")->assertJsonPath('code', 0);
    }

    // 组装出库单载荷（默认 2 行关联订单：FIN-002×6、SEMI-001×5）
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->wh->id,
            'location_id' => $this->b01->id,
            'order_id' => $this->orderId,
            'items' => [
                ['product_id' => $this->fin->id, 'quantity' => 6, 'price' => 10000, 'order_item_id' => $this->finItemId],
                ['product_id' => $this->semi->id, 'quantity' => 5, 'price' => 2000, 'order_item_id' => $this->semiItemId],
            ],
        ], $overrides);
    }

    // 通过 API 建草稿出库单并返回单号
    private function createOutbound(array $payload): string
    {
        $res = $this->withToken($this->token)->postJson('/api/v1/sales/outbounds', $payload);
        $res->assertJsonPath('code', 0);

        return $res->json('data.no');
    }

    // 通过 API 审核出库单（成功时返回响应数组，失败时返回原始响应）
    private function approveOutbound(string $no, bool $assertOk = true): array
    {
        $outbound = SalesOutbound::where('no', $no)->firstOrFail();
        $res = $this->withToken($this->token)->postJson("/api/v1/sales/outbounds/{$outbound->id}/approve");
        if ($assertOk) {
            $res->assertJsonPath('code', 0);
        }

        return $res->json();
    }

    public function test_store_creates_draft_with_no_and_total(): void
    {
        // 正常路径：草稿创建成功，单号 SOUT{date}-001，金额=Σ数量×单价（分）
        $no = $this->createOutbound($this->payload());
        $this->assertMatchesRegularExpression('/^ST\d{12}001$/', $no);
        $outbound = SalesOutbound::where('no', $no)->first();
        $this->assertSame(SalesOutbound::STATUS_DRAFT, $outbound->status);
        // 6×10000 + 5×2000 = 70000 分
        $this->assertSame('70000.00', $outbound->total_amount);
        $this->assertSame(2, $outbound->items()->count());
    }

    public function test_store_rejects_missing_warehouse_or_location_with_1406(): void
    {
        // 异常路径：仓库/库位缺失 → 1406（业务码，非 422）
        $this->withToken($this->token)->postJson('/api/v1/sales/outbounds', $this->payload(['warehouse_id' => null]))
            ->assertJsonPath('code', 1406);
        $this->withToken($this->token)->postJson('/api/v1/sales/outbounds', $this->payload(['location_id' => null]))
            ->assertJsonPath('code', 1406);
    }

    public function test_store_rejects_empty_items_with_1401(): void
    {
        // 异常路径：明细为空 → 1401
        $this->withToken($this->token)->postJson('/api/v1/sales/outbounds', $this->payload(['items' => []]))
            ->assertJsonPath('code', 1401);
    }

    public function test_store_rejects_over_remaining_with_1407(): void
    {
        // 异常路径：出库数量超订单行剩余（11 > 10）→ 1407（草稿期即拦截）
        $this->withToken($this->token)->postJson('/api/v1/sales/outbounds', $this->payload(['items' => [
            ['product_id' => $this->fin->id, 'quantity' => 11, 'price' => 10000, 'order_item_id' => $this->finItemId],
        ]]))
            ->assertJsonPath('code', 1407);
    }

    public function test_store_rejects_mismatched_customer_with_1407(): void
    {
        // 异常路径：出库单客户与来源订单不一致 → 1407（防跨客户挂单，镜像采购供应商一致性 I3）
        $other = Customer::create(['name' => '其他客户', 'code' => 'CUS-002', 'status' => 1]);
        $this->withToken($this->token)->postJson('/api/v1/sales/outbounds', $this->payload(['customer_id' => $other->id]))
            ->assertJsonPath('code', 1407);
    }

    public function test_store_rejects_raw_material_with_422(): void
    {
        // 异常路径：原料不可出库（SAL-10 后端防御校验）
        $this->withToken($this->token)->postJson('/api/v1/sales/outbounds', $this->payload(['items' => [
            ['product_id' => $this->mat->id, 'quantity' => 1, 'price' => 100],
        ]]))
            ->assertJsonPath('code', 422);
    }

    public function test_store_rejects_duplicate_product_with_1412(): void
    {
        // 异常路径：同商品+同订单行重复 → 1412
        $items = $this->payload()['items'];
        $items[] = ['product_id' => $this->fin->id, 'quantity' => 1, 'price' => 10000, 'order_item_id' => $this->finItemId];
        $this->withToken($this->token)->postJson('/api/v1/sales/outbounds', $this->payload(['items' => $items]))
            ->assertJsonPath('code', 1412);
    }

    public function test_store_standalone_outbound_without_order(): void
    {
        // 正常路径：独立出库（无 order_id/order_item_id）可建可审
        $no = $this->createOutbound($this->payload(['order_id' => null, 'location_id' => $this->b01->id, 'items' => [
            ['product_id' => $this->fin->id, 'quantity' => 3, 'price' => 10000],
        ]]));
        $outbound = SalesOutbound::where('no', $no)->first();
        $this->assertNull($outbound->order_id);
        // 审核成功扣库存（余额 100 → 97）
        $this->approveOutbound($no);
        $this->assertSame('97.00', InventoryBalance::where('product_id', $this->fin->id)->first()->quantity);
    }

    public function test_from_order_prefill_returns_remaining(): void
    {
        // 正常路径：from-order 预填剩余量正确（未出库 → 剩余=订购数）
        $this->withToken($this->token)->getJson("/api/v1/sales/outbounds/from-order/{$this->orderId}")
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.order_no', 'SO'.date('YmdHi').'001')
            ->assertJsonPath('data.customer_id', $this->customer->id)
            ->assertJsonPath('data.items.0.product_code', 'FIN-002')
            ->assertJsonPath('data.items.0.quantity', '10.00')
            ->assertJsonPath('data.items.0.remaining_qty', '10.00')
            ->assertJsonPath('data.items.0.price', '10000.00');
    }

    public function test_from_order_rejects_completed_order(): void
    {
        // 异常路径：已完成订单不可预填 → 1407
        $order = SalesOrder::find($this->orderId);
        foreach ($order->items as $item) {
            $item->shipped_qty = $item->quantity;
            $item->save();
        }
        $order->status = SalesOrder::STATUS_COMPLETED;
        $order->save();
        $this->withToken($this->token)->getJson("/api/v1/sales/outbounds/from-order/{$this->orderId}")
            ->assertJsonPath('code', 1407);
    }

    public function test_approve_deducts_inventory_and_writes_movement(): void
    {
        // 核心不变式：审核后余额-6、sales_outbound 流水双写（direction=-1）、balance_after 正确、回写 shipped_qty
        $no = $this->createOutbound($this->payload());
        $res = $this->approveOutbound($no);
        $this->assertSame($no, $res['data']['no']);
        // 余额 100 → 94，流水单号 SOUT、方向 -
        $balance = InventoryBalance::where('product_id', $this->fin->id)->first();
        $this->assertSame('94.00', $balance->quantity);
        $this->assertDatabaseHas('inventory_movements', [
            'product_id' => $this->fin->id, 'direction' => -1, 'quantity' => '6.00',
            'balance_after' => '94.00', 'source_type' => 'sales_outbound', 'source_no' => $no,
        ]);
        // 订单行 shipped_qty 回写 6，订单状态 → 部分出库（SEMI-001 仍剩 5）
        $this->assertSame('6.00', SalesOrderItem::find($this->finItemId)->shipped_qty);
        $this->assertSame(SalesOrder::STATUS_PARTIAL, SalesOrder::find($this->orderId)->status);
        // 出库单已审核 + 审核人/时间
        $outbound = SalesOutbound::where('no', $no)->first();
        $this->assertSame(SalesOutbound::STATUS_APPROVED, $outbound->status);
        $this->assertSame('管理员', $outbound->operator);
        $this->assertNotNull($outbound->outbound_at);
    }

    public function test_approve_completes_order_when_all_shipped(): void
    {
        // 核心不变式：全部出完 → 订单「已完成」
        $no = $this->createOutbound($this->payload(['items' => [
            ['product_id' => $this->fin->id, 'quantity' => 10, 'price' => 10000, 'order_item_id' => $this->finItemId],
            ['product_id' => $this->semi->id, 'quantity' => 5, 'price' => 2000, 'order_item_id' => $this->semiItemId],
        ]]));
        $this->approveOutbound($no);
        $this->assertSame(SalesOrder::STATUS_COMPLETED, SalesOrder::find($this->orderId)->status);
    }

    public function test_approve_idempotent_with_1410(): void
    {
        // 核心不变式：重复审核 → 1410，库存不重复扣减
        $no = $this->createOutbound($this->payload());
        $this->approveOutbound($no);
        $res = $this->approveOutbound($no, false);
        $this->assertSame(1410, $res['code']);
        $this->assertSame('94.00', InventoryBalance::where('product_id', $this->fin->id)->first()->quantity);
    }

    public function test_approve_rejects_when_order_remaining_shrunk(): void
    {
        // 核心不变式：两张草稿各 6 均在剩余 10 时创建（草稿期合法）；先审第一张（消耗 6 剩余 4）
        // → 审核第二张超量 1407 整体回滚（草稿期校验拦不住审核前的并发消耗，靠审核期锁行复核）
        $no1 = $this->createOutbound($this->payload());
        $no2 = $this->createOutbound($this->payload());
        $this->approveOutbound($no1);
        $res = $this->approveOutbound($no2, false);
        $this->assertSame(1407, $res['code']);
        // 回滚验证：余额仍 94（第二张未出），第二张仍草稿，订单行未回写
        $this->assertSame('94.00', InventoryBalance::where('product_id', $this->fin->id)->first()->quantity);
        $this->assertSame(SalesOutbound::STATUS_DRAFT, SalesOutbound::where('no', $no2)->first()->status);
        $this->assertSame('6.00', SalesOrderItem::find($this->finItemId)->shipped_qty);
    }

    public function test_approve_rejects_insufficient_balance_with_1409_rollback(): void
    {
        // 核心不变式（超卖拦截）：独立出库 150 > 余额 100 → 1409（消息含商品名与当前库存快照），整体回滚
        $no = $this->createOutbound($this->payload([
            'order_id' => null,
            'items' => [['product_id' => $this->fin->id, 'quantity' => 150, 'price' => 10000]],
        ]));
        $res = $this->approveOutbound($no, false);
        $this->assertSame(1409, $res['code']);
        $this->assertSame('商品[成品B]库存不足，当前库存 100', $res['message']);
        // 回滚验证：余额不变、无流水、出库单仍草稿
        $this->assertSame('100.00', InventoryBalance::where('product_id', $this->fin->id)->first()->quantity);
        $this->assertDatabaseMissing('inventory_movements', ['source_no' => $no]);
        $this->assertSame(SalesOutbound::STATUS_DRAFT, SalesOutbound::where('no', $no)->first()->status);
    }

    public function test_approve_allows_draining_balance_to_zero(): void
    {
        // 边界路径：出库量=当前余额（100）→ 允许出库，余额变 0（允许 0 不允许负）
        $no = $this->createOutbound($this->payload([
            'order_id' => null,
            'items' => [['product_id' => $this->fin->id, 'quantity' => 100, 'price' => 10000]],
        ]));
        $this->approveOutbound($no);
        $this->assertSame('0.00', InventoryBalance::where('product_id', $this->fin->id)->first()->quantity);
        // 余额 0 再出 1 → 1409 精确提示当前库存 0
        $no2 = $this->createOutbound($this->payload([
            'order_id' => null,
            'items' => [['product_id' => $this->fin->id, 'quantity' => 1, 'price' => 10000]],
        ]));
        $res = $this->approveOutbound($no2, false);
        $this->assertSame(1409, $res['code']);
        $this->assertSame('商品[成品B]库存不足，当前库存 0', $res['message']);
    }

    public function test_approve_rejects_when_order_closed(): void
    {
        // 异常路径：草稿创建后订单被关闭，审核 → 1407（关闭后不可再出库；审核期锁订单头复查拦截）
        $no = $this->createOutbound($this->payload());
        $order = SalesOrder::find($this->orderId);
        $order->status = SalesOrder::STATUS_CLOSED;
        $order->closed_at = now();
        $order->save();
        $res = $this->approveOutbound($no, false);
        $this->assertSame(1407, $res['code']);
    }

    public function test_update_and_destroy_draft_ok_approved_rejected_with_1408(): void
    {
        // 正常+异常路径：草稿可改可删；已审核不可改删 → 1408
        $no = $this->createOutbound($this->payload());
        $id = SalesOutbound::where('no', $no)->first()->id;
        $this->withToken($this->token)->putJson("/api/v1/sales/outbounds/{$id}", $this->payload(['remark' => '改后']))
            ->assertJsonPath('code', 0);
        $this->withToken($this->token)->deleteJson("/api/v1/sales/outbounds/{$id}")
            ->assertJsonPath('code', 0);
        $this->assertDatabaseMissing('sales_outbounds', ['id' => $id]);

        $no2 = $this->createOutbound($this->payload());
        $this->approveOutbound($no2);
        $id2 = SalesOutbound::where('no', $no2)->first()->id;
        $this->withToken($this->token)->putJson("/api/v1/sales/outbounds/{$id2}", $this->payload())
            ->assertJsonPath('code', 1408);
        $this->withToken($this->token)->deleteJson("/api/v1/sales/outbounds/{$id2}")
            ->assertJsonPath('code', 1408);
    }

    public function test_index_with_filters_and_labels(): void
    {
        // 正常路径：列表含客户/仓库/库位名、来源订单单号、状态标签；状态筛选
        $no = $this->createOutbound($this->payload());
        $this->approveOutbound($no);
        $this->withToken($this->token)->getJson('/api/v1/sales/outbounds')
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.items.0.customer_name', '测试客户')
            ->assertJsonPath('data.items.0.warehouse_name', '主仓')
            ->assertJsonPath('data.items.0.location_name', 'B-01')
            ->assertJsonPath('data.items.0.order_no', 'SO'.date('YmdHi').'001')
            ->assertJsonPath('data.items.0.status_label', '已审核');
        $this->withToken($this->token)->getJson('/api/v1/sales/outbounds?status=0')
            ->assertJsonPath('data.total', 0);
    }

    public function test_today_summary_counts_approved_quantities(): void
    {
        // 正常路径：当日已审核出库量按商品汇总（列表页汇总行数据源；草稿不计入）
        $no = $this->createOutbound($this->payload());
        $this->approveOutbound($no);
        // 草稿不计入
        $this->createOutbound($this->payload(['order_id' => null, 'location_id' => $this->b01->id, 'items' => [
            ['product_id' => $this->fin->id, 'quantity' => 3, 'price' => 10000],
        ]]));
        $res = $this->withToken($this->token)->getJson('/api/v1/sales/outbounds/today-summary')
            ->assertJsonPath('code', 0);
        $items = $res->json('data.items');
        // 仅一条已审核汇总：FIN-002 出 6（SEMI-001 5 也在同一张已审核单内 → 两条）
        $this->assertSame(2, count($items));
        $fin = collect($items)->firstWhere('product_code', 'FIN-002');
        $this->assertSame('6.00', $fin['quantity']);
    }

    public function test_outbounds_requires_sales_outbound_permission(): void
    {
        // 异常路径：无 sales.outbound.list 权限的角色被拒（403 JSON 信封）
        $role = Role::create(['name' => '普通', 'code' => 'plain']);
        $u = User::create(['name' => '普通', 'username' => 'plain', 'password' => 'admin123', 'status' => 1]);
        $u->roles()->sync([$role->id]);
        $token = $u->createToken('api')->plainTextToken;
        // 测试框架在同一 app 实例内缓存 auth guard 的已认证用户（setUp 已用管理员 token 请求过；
        // 真实 HTTP 每次请求独立容器不受影响），故先重置 guard，再以普通用户 token 验证无权限被拒
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/sales/outbounds')->assertStatus(403);
    }
}
