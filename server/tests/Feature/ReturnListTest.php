<?php

// 退料单接口测试：CRUD/审核冲销（库存+/已领-）/超退拦截/幂等（核心路径 100%）

namespace Tests\Feature;

use App\Models\BomHeader;
use App\Models\Category;
use App\Models\InventoryBalance;
use App\Models\Location;
use App\Models\PickList;
use App\Models\Process;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\ReturnList;
use App\Models\Role;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReturnListTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    private User $admin;

    private Warehouse $wh;

    private Location $a01;

    private Product $mat;

    private Product $fin;

    private ProductionOrder $order;

    private PickList $pick;

    private int $materialId;

    protected function setUp(): void
    {
        parent::setUp();
        $role = Role::create(['name' => '管理员', 'code' => 'admin']);
        $this->admin = User::create(['name' => '管理员', 'username' => 'admin', 'password' => 'admin123', 'status' => 1]);
        $this->admin->roles()->sync([$role->id]);
        $this->token = $this->admin->createToken('api')->plainTextToken;

        $this->wh = Warehouse::create(['name' => '主仓', 'code' => 'WH01', 'status' => 1]);
        $this->a01 = Location::create(['warehouse_id' => $this->wh->id, 'name' => 'A-01', 'code' => 'A-01', 'status' => 1]);
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
        // 基线库存 MAT-001 @A-01=30（领料 20 后余 10，退料 2 后 12）
        app(InventoryService::class)->apply([
            ['product_id' => $this->mat->id, 'warehouse_id' => $this->wh->id, 'location_id' => $this->a01->id, 'direction' => 1, 'quantity' => 30, 'source_type' => 'purchase_inbound', 'source_id' => 0, 'source_no' => 'SEED', 'remark' => '测试基线'],
        ]);

        // 建单（FIN-002×10 → MAT-001 需求 20）→ 下达 → 开工 → 领料审核（已领 20）
        $res = $this->withToken($this->token)->postJson('/api/v1/production/orders', [
            'product_id' => $this->fin->id, 'quantity' => 10, 'plan_date' => now()->toDateString(),
        ]);
        $res->assertJsonPath('code', 0);
        $this->order = ProductionOrder::where('no', $res->json('data.no'))->first();
        $this->withToken($this->token)->postJson("/api/v1/production/orders/{$this->order->id}/release")->assertJsonPath('code', 0);
        $this->withToken($this->token)->postJson("/api/v1/production/orders/{$this->order->id}/start")->assertJsonPath('code', 0);
        $pickRes = $this->withToken($this->token)->postJson('/api/v1/production/picks', [
            'order_id' => $this->order->id, 'warehouse_id' => $this->wh->id, 'location_id' => $this->a01->id,
            'items' => [['product_id' => $this->mat->id, 'pick_qty' => 20]],
        ]);
        $pickRes->assertJsonPath('code', 0);
        $pick = PickList::where('no', $pickRes->json('data.no'))->first();
        $this->withToken($this->token)->postJson("/api/v1/production/picks/{$pick->id}/approve")->assertJsonPath('code', 0);
        $this->pick = $pick;
        $this->materialId = $this->order->materials()->where('material_id', $this->mat->id)->first()->id;
        // 已领 20、库存 30-20=10
    }

    // 组装退料单载荷（默认 MAT-001×2）
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'order_id' => $this->order->id,
            'warehouse_id' => $this->wh->id,
            'location_id' => $this->a01->id,
            'items' => [
                ['product_id' => $this->mat->id, 'quantity' => 2],
            ],
        ], $overrides);
    }

    // 通过 API 建草稿退料单并返回单号
    private function createReturn(array $payload): string
    {
        $res = $this->withToken($this->token)->postJson('/api/v1/production/returns', $payload);
        $res->assertJsonPath('code', 0);

        return $res->json('data.no');
    }

    public function test_store_creates_draft_with_no(): void
    {
        // 正常路径：草稿创建成功，单号 RL{date}-001
        $no = $this->createReturn($this->payload());
        $this->assertMatchesRegularExpression('/^RL\d{8}-001$/', $no);
        $return = ReturnList::where('no', $no)->first();
        $this->assertSame(ReturnList::STATUS_DRAFT, $return->status);
        $this->assertSame('2.00', $return->items()->first()->quantity);
    }

    public function test_store_rejects_over_issued_with_1517(): void
    {
        // 异常路径：退料数量超已领总量（25 > 20）→ 1517（草稿期即拦截）
        $this->withToken($this->token)->postJson('/api/v1/production/returns', $this->payload(['items' => [
            ['product_id' => $this->mat->id, 'quantity' => 25],
        ]]))
            ->assertJsonPath('code', 1517)
            ->assertJsonPath('message', '退料数量超过已领数量');
    }

    public function test_store_rejects_order_not_producing_with_1517(): void
    {
        // 异常路径（bug #2 回归）：spec §5.1 生产中→退料——草稿工单不可退料（store 同步收紧）
        $res = $this->withToken($this->token)->postJson('/api/v1/production/orders', [
            'product_id' => $this->fin->id, 'quantity' => 10, 'plan_date' => now()->toDateString(),
        ]);
        $draft = ProductionOrder::where('no', $res->json('data.no'))->first();
        $this->withToken($this->token)->postJson('/api/v1/production/returns', $this->payload(['order_id' => $draft->id]))
            ->assertJsonPath('code', 1517)
            ->assertJsonPath('message', '工单当前状态不可退料');
    }

    public function test_approve_rejects_order_not_producing_with_1519(): void
    {
        // 异常路径（bug #2 回归）：草稿期合法建单后工单被关闭 → 审核被拒 1519，库存/已领均无变动
        $no = $this->createReturn($this->payload());
        $this->order->status = ProductionOrder::STATUS_CLOSED;
        $this->order->save();
        $return = ReturnList::where('no', $no)->first();
        $this->withToken($this->token)->postJson("/api/v1/production/returns/{$return->id}/approve")
            ->assertJsonPath('code', 1519)
            ->assertJsonPath('message', '工单当前状态不可退料');
        // 被拒审核不得冲销：库存仍为 10、已领仍为 20
        $this->assertSame('10.00', InventoryBalance::where('product_id', $this->mat->id)->first()->quantity);
        $this->assertSame('20.00', $this->order->materials()->where('material_id', $this->mat->id)->first()->issued_qty);
    }

    public function test_store_rejects_material_not_issued_with_1517(): void
    {
        // 异常路径：商品从未领过（已领 0）→ 1517（超已领自然拦截）
        $this->withToken($this->token)->postJson('/api/v1/production/returns', $this->payload(['items' => [
            ['product_id' => $this->fin->id, 'quantity' => 1],
        ]]))
            ->assertJsonPath('code', 1517);
    }

    public function test_store_rejects_empty_items_non_positive_and_duplicates_with_422(): void
    {
        // 异常路径：明细为空/数量≤0/重复商品 → 422（格式层；spec 码段满）
        $this->withToken($this->token)->postJson('/api/v1/production/returns', $this->payload(['items' => []]))
            ->assertJsonPath('code', 422);
        $this->withToken($this->token)->postJson('/api/v1/production/returns', $this->payload(['items' => [
            ['product_id' => $this->mat->id, 'quantity' => 0],
        ]]))
            ->assertJsonPath('code', 422);
        $this->withToken($this->token)->postJson('/api/v1/production/returns', $this->payload(['items' => [
            ['product_id' => $this->mat->id, 'quantity' => 1],
            ['product_id' => $this->mat->id, 'quantity' => 1],
        ]]))
            ->assertJsonPath('code', 422);
    }

    public function test_store_rejects_missing_warehouse_or_location_with_422(): void
    {
        // 异常路径：仓库/库位缺失 → 422（格式层）
        $this->withToken($this->token)->postJson('/api/v1/production/returns', $this->payload(['warehouse_id' => null]))
            ->assertJsonPath('code', 422);
        $this->withToken($this->token)->postJson('/api/v1/production/returns', $this->payload(['location_id' => null]))
            ->assertJsonPath('code', 422);
    }

    public function test_store_pick_id_must_belong_to_same_order_with_422(): void
    {
        // 异常路径：pick_id 属于其他工单的领料单 → 422（防跨工单挂单，追溯语义错乱）
        // 建第二工单（FIN-002×5 → MAT-001 需求 10）并下达开工（领料要求生产中，同 set
        // Up 口径），领料后挂到第一工单的退料单上
        $res = $this->withToken($this->token)->postJson('/api/v1/production/orders', [
            'product_id' => $this->fin->id, 'quantity' => 5, 'plan_date' => now()->toDateString(),
        ]);
        $order2 = ProductionOrder::where('no', $res->json('data.no'))->first();
        $this->withToken($this->token)->postJson("/api/v1/production/orders/{$order2->id}/release")->assertJsonPath('code', 0);
        $this->withToken($this->token)->postJson("/api/v1/production/orders/{$order2->id}/start")->assertJsonPath('code', 0);
        $pickRes = $this->withToken($this->token)->postJson('/api/v1/production/picks', [
            'order_id' => $order2->id, 'warehouse_id' => $this->wh->id, 'location_id' => $this->a01->id,
            'items' => [['product_id' => $this->mat->id, 'pick_qty' => 5]],
        ]);
        $pick2 = PickList::where('no', $pickRes->json('data.no'))->first();
        $this->withToken($this->token)->postJson('/api/v1/production/returns', $this->payload(['pick_id' => $pick2->id]))
            ->assertJsonPath('code', 422)
            ->assertJsonPath('message', '领料单不属于该工单');
        // 正常路径：本工单领料单正确归属 → 放行
        $this->withToken($this->token)->postJson('/api/v1/production/returns', $this->payload(['pick_id' => $this->pick->id]))
            ->assertJsonPath('code', 0);
    }

    public function test_approve_credits_inventory_and_writes_movement(): void
    {
        // 核心不变式：审核后余额 10→12、return 流水双写（direction=+1）、物料 issued_qty 20→18（冲销）
        $no = $this->createReturn($this->payload());
        $return = ReturnList::where('no', $no)->first();
        $this->withToken($this->token)->postJson("/api/v1/production/returns/{$return->id}/approve")
            ->assertJsonPath('code', 0);
        $balance = InventoryBalance::where('product_id', $this->mat->id)->first();
        $this->assertSame('12.00', $balance->quantity);
        $this->assertDatabaseHas('inventory_movements', [
            'product_id' => $this->mat->id, 'direction' => 1, 'quantity' => '2.00',
            'balance_after' => '12.00', 'source_type' => 'return', 'source_no' => $no,
        ]);
        // 已领冲销 20 → 18
        $this->assertSame('18.00', $this->order->materials()->find($this->materialId)->issued_qty);
        $return->refresh();
        $this->assertSame(ReturnList::STATUS_APPROVED, $return->status);
        $this->assertSame('管理员', $return->operator);
        $this->assertNotNull($return->approved_at);
    }

    public function test_approve_rejects_when_issued_shrunk_with_1517_rollback(): void
    {
        // 核心不变式：草稿建后已领被冲销（20→4），审核期锁行复核 1517 整体回滚
        $no = $this->createReturn($this->payload(['items' => [
            ['product_id' => $this->mat->id, 'quantity' => 5],
        ]]));
        $return = ReturnList::where('no', $no)->first();
        // 模拟并发冲销：另一张退料单已审 16（已领 20→4）
        $pm = $this->order->materials()->find($this->materialId);
        $pm->issued_qty = 4;
        $pm->save();
        $res = $this->withToken($this->token)->postJson("/api/v1/production/returns/{$return->id}/approve");
        $res->assertJsonPath('code', 1517)
            ->assertJsonPath('message', '退料数量超过已领数量');
        // 回滚验证：余额不变（10）、无流水、退料单仍草稿
        $this->assertSame('10.00', InventoryBalance::where('product_id', $this->mat->id)->first()->quantity);
        $this->assertDatabaseMissing('inventory_movements', ['source_no' => $no]);
        $this->assertSame(ReturnList::STATUS_DRAFT, $return->refresh()->status);
    }

    public function test_approve_idempotent_with_1519(): void
    {
        // 核心不变式：重复审核 → 1519，库存不重复变动
        $no = $this->createReturn($this->payload());
        $return = ReturnList::where('no', $no)->first();
        $this->withToken($this->token)->postJson("/api/v1/production/returns/{$return->id}/approve")->assertJsonPath('code', 0);
        $this->withToken($this->token)->postJson("/api/v1/production/returns/{$return->id}/approve")
            ->assertJsonPath('code', 1519)
            ->assertJsonPath('message', '该退料单已审核');
        $this->assertSame('12.00', InventoryBalance::where('product_id', $this->mat->id)->first()->quantity);
    }

    public function test_update_and_destroy_draft_ok_approved_rejected_with_1518(): void
    {
        // 正常+异常路径：草稿可改可删；已审核不可改删 → 1518
        $no = $this->createReturn($this->payload());
        $id = ReturnList::where('no', $no)->first()->id;
        $this->withToken($this->token)->putJson("/api/v1/production/returns/{$id}", $this->payload(['remark' => '改后']))
            ->assertJsonPath('code', 0);
        $this->withToken($this->token)->deleteJson("/api/v1/production/returns/{$id}")
            ->assertJsonPath('code', 0);
        $this->assertDatabaseMissing('return_lists', ['id' => $id]);

        $no2 = $this->createReturn($this->payload());
        $return2 = ReturnList::where('no', $no2)->first();
        $this->withToken($this->token)->postJson("/api/v1/production/returns/{$return2->id}/approve")->assertJsonPath('code', 0);
        $this->withToken($this->token)->putJson("/api/v1/production/returns/{$return2->id}", $this->payload())
            ->assertJsonPath('code', 1518);
        $this->withToken($this->token)->deleteJson("/api/v1/production/returns/{$return2->id}")
            ->assertJsonPath('code', 1518);
    }

    public function test_index_with_labels_and_returns_requires_permission(): void
    {
        // 正常路径：列表含工单单号/状态标签
        $no = $this->createReturn($this->payload());
        $return = ReturnList::where('no', $no)->first();
        $this->withToken($this->token)->postJson("/api/v1/production/returns/{$return->id}/approve")->assertJsonPath('code', 0);
        $this->withToken($this->token)->getJson('/api/v1/production/returns')
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.items.0.order_no', 'MO'.date('Ymd').'-001')
            ->assertJsonPath('data.items.0.status_label', '已审核');
        // 异常路径：无 production.return.list 权限的角色被拒（403 JSON 信封）
        $role = Role::create(['name' => '普通', 'code' => 'plain']);
        $u = User::create(['name' => '普通', 'username' => 'plain', 'password' => 'admin123', 'status' => 1]);
        $u->roles()->sync([$role->id]);
        $token = $u->createToken('api')->plainTextToken;
        // 测试框架在同一 app 实例内缓存 auth guard 的已认证用户（本方法前序请求均以管理员 token 发起；
        // 真实 HTTP 每次请求独立容器不受影响），故先重置 guard，再以普通用户 token 验证无权限被拒
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/production/returns')->assertStatus(403);
    }
}
