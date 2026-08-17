<?php

// 领料单接口测试：CRUD/from-order 预填/审核扣库存/超领拦截/库存不足回滚/发料状态/幂等（核心路径 100%）

namespace Tests\Feature;

use App\Models\BomHeader;
use App\Models\Category;
use App\Models\InventoryBalance;
use App\Models\Location;
use App\Models\PickList;
use App\Models\Process;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\Role;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PickListTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    private User $admin;

    private Warehouse $wh;

    private Location $a01;

    private Product $mat;

    private Product $fin;

    private ProductionOrder $order;

    private int $materialId; // production_order_materials 行 id

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
        // 基线库存：MAT-001 @A-01=50（经统一引擎注入，满足余额=流水恒等式）
        app(InventoryService::class)->apply([
            ['product_id' => $this->mat->id, 'warehouse_id' => $this->wh->id, 'location_id' => $this->a01->id, 'direction' => 1, 'quantity' => 50, 'source_type' => 'purchase_inbound', 'source_id' => 0, 'source_no' => 'SEED', 'remark' => '测试基线'],
        ]);

        // 建单（FIN-002×10 → MAT-001 需求 20）→ 下达 → 开工
        $res = $this->withToken($this->token)->postJson('/api/v1/production/orders', [
            'product_id' => $this->fin->id, 'quantity' => 10, 'plan_date' => now()->toDateString(),
        ]);
        $res->assertJsonPath('code', 0);
        $this->order = ProductionOrder::where('no', $res->json('data.no'))->first();
        $this->withToken($this->token)->postJson("/api/v1/production/orders/{$this->order->id}/release")->assertJsonPath('code', 0);
        $this->withToken($this->token)->postJson("/api/v1/production/orders/{$this->order->id}/start")->assertJsonPath('code', 0);
        $this->materialId = $this->order->materials()->where('material_id', $this->mat->id)->first()->id;
    }

    // 组装领料单载荷（默认 MAT-001×20 全量）
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'order_id' => $this->order->id,
            'warehouse_id' => $this->wh->id,
            'location_id' => $this->a01->id,
            'items' => [
                ['product_id' => $this->mat->id, 'pick_qty' => 20],
            ],
        ], $overrides);
    }

    // 通过 API 建草稿领料单并返回单号
    private function createPick(array $payload): string
    {
        $res = $this->withToken($this->token)->postJson('/api/v1/production/picks', $payload);
        $res->assertJsonPath('code', 0);

        return $res->json('data.no');
    }

    public function test_store_creates_draft_with_no_and_snapshot(): void
    {
        // 正常路径：草稿创建成功，单号 PL{date}-001；明细需求快照 = BOM 展开值
        $no = $this->createPick($this->payload());
        $this->assertMatchesRegularExpression('/^PL\d{8}-001$/', $no);
        $pick = PickList::where('no', $no)->first();
        $this->assertSame(PickList::STATUS_DRAFT, $pick->status);
        $this->assertSame(PickList::ISSUE_NONE, $pick->issue_status);
        $this->assertSame('20.00', $pick->items()->first()->required_qty);
        $this->assertSame('20.00', $pick->items()->first()->pick_qty);
    }

    public function test_from_order_prefill_returns_remaining(): void
    {
        // 正常路径：from-order 预填剩余量（未领 → 剩余=需求）
        $this->withToken($this->token)->getJson("/api/v1/production/picks/from-order/{$this->order->id}")
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.order_no', 'MO'.date('Ymd').'-001')
            ->assertJsonPath('data.items.0.product_code', 'MAT-001')
            ->assertJsonPath('data.items.0.required_qty', '20.00')
            ->assertJsonPath('data.items.0.remaining_qty', '20.00');
    }

    public function test_store_rejects_over_remaining_with_1513(): void
    {
        // 异常路径：领料数量超需求剩余（25 > 20）→ 1513（草稿期即拦截）
        $this->withToken($this->token)->postJson('/api/v1/production/picks', $this->payload(['items' => [
            ['product_id' => $this->mat->id, 'pick_qty' => 25],
        ]]))
            ->assertJsonPath('code', 1513)
            ->assertJsonPath('message', '领料数量超过需求数量');
    }

    public function test_store_rejects_order_not_producing_with_1513(): void
    {
        // 异常路径（bug #2 回归）：spec §5.1 生产中→领料——草稿工单不可领料（store 同步收紧）
        $res = $this->withToken($this->token)->postJson('/api/v1/production/orders', [
            'product_id' => $this->fin->id, 'quantity' => 10, 'plan_date' => now()->toDateString(),
        ]);
        $draft = ProductionOrder::where('no', $res->json('data.no'))->first();
        $this->withToken($this->token)->postJson('/api/v1/production/picks', $this->payload(['order_id' => $draft->id]))
            ->assertJsonPath('code', 1513)
            ->assertJsonPath('message', '工单当前状态不可领料');
    }

    public function test_approve_rejects_order_not_producing_with_1516(): void
    {
        // 异常路径（bug #2 回归）：草稿期合法建单后工单被关闭 → 审核被拒 1516，库存无变动
        $no = $this->createPick($this->payload());
        $this->order->status = ProductionOrder::STATUS_CLOSED;
        $this->order->save();
        $pick = PickList::where('no', $no)->first();
        $this->withToken($this->token)->postJson("/api/v1/production/picks/{$pick->id}/approve")
            ->assertJsonPath('code', 1516)
            ->assertJsonPath('message', '工单当前状态不可领料');
        // 余额守恒：被拒审核不得扣减库存
        $this->assertSame('50.00', InventoryBalance::where('product_id', $this->mat->id)->first()->quantity);
    }

    public function test_store_rejects_material_not_in_order_with_1513(): void
    {
        // 异常路径：商品不在工单物料需求中 → 1513（需求剩余 0，超量自然拦截）
        $this->withToken($this->token)->postJson('/api/v1/production/picks', $this->payload(['items' => [
            ['product_id' => $this->fin->id, 'pick_qty' => 1],
        ]]))
            ->assertJsonPath('code', 1513);
    }

    public function test_store_rejects_empty_items_and_duplicates_with_422(): void
    {
        // 异常路径：明细为空/重复商品 → 422（格式层；spec 码段满）
        $this->withToken($this->token)->postJson('/api/v1/production/picks', $this->payload(['items' => []]))
            ->assertJsonPath('code', 422);
        $this->withToken($this->token)->postJson('/api/v1/production/picks', $this->payload(['items' => [
            ['product_id' => $this->mat->id, 'pick_qty' => 5],
            ['product_id' => $this->mat->id, 'pick_qty' => 5],
        ]]))
            ->assertJsonPath('code', 422);
    }

    public function test_store_rejects_non_positive_qty_with_422(): void
    {
        // 异常路径：领料数量 ≤ 0 → 422（格式层；spec 码段满）
        $this->withToken($this->token)->postJson('/api/v1/production/picks', $this->payload(['items' => [
            ['product_id' => $this->mat->id, 'pick_qty' => 0],
        ]]))
            ->assertJsonPath('code', 422);
    }

    public function test_store_rejects_missing_warehouse_or_location_with_422(): void
    {
        // 异常路径：仓库/库位缺失 → 422（格式层）
        $this->withToken($this->token)->postJson('/api/v1/production/picks', $this->payload(['warehouse_id' => null]))
            ->assertJsonPath('code', 422);
        $this->withToken($this->token)->postJson('/api/v1/production/picks', $this->payload(['location_id' => null]))
            ->assertJsonPath('code', 422);
    }

    public function test_approve_deducts_inventory_and_writes_movement(): void
    {
        // 核心不变式：审核后余额 50→30、pick 流水双写（direction=-1）、回写物料 issued_qty
        $no = $this->createPick($this->payload());
        $pick = PickList::where('no', $no)->first();
        $this->withToken($this->token)->postJson("/api/v1/production/picks/{$pick->id}/approve")
            ->assertJsonPath('code', 0);
        // 余额 50 → 30
        $balance = InventoryBalance::where('product_id', $this->mat->id)->first();
        $this->assertSame('30.00', $balance->quantity);
        $this->assertDatabaseHas('inventory_movements', [
            'product_id' => $this->mat->id, 'direction' => -1, 'quantity' => '20.00',
            'balance_after' => '30.00', 'source_type' => 'pick', 'source_no' => $no,
        ]);
        // 物料需求 issued_qty 回写 20
        $this->assertSame('20.00', $this->order->materials()->find($this->materialId)->issued_qty);
        // 领料单已审核 + 审核人/时间
        $pick->refresh();
        $this->assertSame(PickList::STATUS_APPROVED, $pick->status);
        $this->assertSame('管理员', $pick->operator);
        $this->assertNotNull($pick->approved_at);
    }

    public function test_approve_rejects_insufficient_balance_with_1515_rollback(): void
    {
        // 核心不变式（超卖拦截）：库存 50 但草稿期后库存被消耗 → 审核期复核拦截 1515，整体回滚
        $no = $this->createPick($this->payload(['items' => [
            ['product_id' => $this->mat->id, 'pick_qty' => 20],
        ]]));
        $pick = PickList::where('no', $no)->first();
        // 草稿创建后库存被消耗至 10（模拟并发消耗）
        $balance = InventoryBalance::where('product_id', $this->mat->id)->first();
        $balance->quantity = 10;
        $balance->save();
        $res = $this->withToken($this->token)->postJson("/api/v1/production/picks/{$pick->id}/approve");
        $res->assertJsonPath('code', 1515)
            ->assertJsonPath('message', '商品[MAT-001]库存不足');
        // 回滚验证：余额不变（10）、无流水、领料单仍草稿、issued_qty 未回写
        $this->assertSame('10.00', InventoryBalance::where('product_id', $this->mat->id)->first()->quantity);
        $this->assertDatabaseMissing('inventory_movements', ['source_no' => $no]);
        $this->assertSame(PickList::STATUS_DRAFT, $pick->refresh()->status);
        $this->assertSame('0.00', $this->order->materials()->find($this->materialId)->issued_qty);
    }

    public function test_approve_idempotent_with_1516(): void
    {
        // 核心不变式：重复审核 → 1516，库存不重复扣减
        $no = $this->createPick($this->payload());
        $pick = PickList::where('no', $no)->first();
        $this->withToken($this->token)->postJson("/api/v1/production/picks/{$pick->id}/approve")->assertJsonPath('code', 0);
        $this->withToken($this->token)->postJson("/api/v1/production/picks/{$pick->id}/approve")
            ->assertJsonPath('code', 1516)
            ->assertJsonPath('message', '该领料单已审核');
        $this->assertSame('30.00', InventoryBalance::where('product_id', $this->mat->id)->first()->quantity);
    }

    public function test_approve_rejects_when_remaining_shrunk(): void
    {
        // 核心不变式：两张草稿各 20 均在需求 20 时创建（草稿期合法）；先审第一张（已领 20 剩余 0）
        // → 审核第二张超量 1513 整体回滚（审核期锁物料需求行复核）
        $no1 = $this->createPick($this->payload());
        $no2 = $this->createPick($this->payload());
        $pick1 = PickList::where('no', $no1)->first();
        $pick2 = PickList::where('no', $no2)->first();
        $this->withToken($this->token)->postJson("/api/v1/production/picks/{$pick1->id}/approve")->assertJsonPath('code', 0);
        $this->withToken($this->token)->postJson("/api/v1/production/picks/{$pick2->id}/approve")
            ->assertJsonPath('code', 1513);
        // 回滚验证：余额仍 30（第二张未出）、第二张仍草稿
        $this->assertSame('30.00', InventoryBalance::where('product_id', $this->mat->id)->first()->quantity);
        $this->assertSame(PickList::STATUS_DRAFT, $pick2->refresh()->status);
    }

    public function test_approve_multiple_items_batch_locks_all_materials_and_balances(): void
    {
        // 正常路径（P1-2 批量预锁回归）：双物料领料——物料需求行/余额行各一次 whereIn 批量锁定，
        // 两商品同时扣减、issued_qty 各自回写（批量锁与逐行锁行为等价）
        $mat2 = Product::create(['name' => '测试钢板', 'code' => 'MAT-002', 'type' => 'raw_material',
            'category_id' => $this->mat->category_id, 'unit_id' => $this->mat->unit_id, 'status' => 1]);
        app(InventoryService::class)->apply([
            ['product_id' => $mat2->id, 'warehouse_id' => $this->wh->id, 'location_id' => $this->a01->id,
                'direction' => 1, 'quantity' => 30, 'source_type' => 'purchase_inbound', 'source_id' => 0,
                'source_no' => 'SEED-2', 'remark' => '测试基线'],
        ]);
        // 双物料启用 BOM（最新启用版，新单自动采用）→ 建单 → 下达 → 开工（MAT-001 需求 20、MAT-002 需求 20）
        $bom2 = BomHeader::create([
            'code' => 'BOM-002', 'product_id' => $this->fin->id, 'version' => 'v2',
            'quantity' => 1, 'status' => 1,
        ]);
        $bom2->items()->create(['material_id' => $this->mat->id, 'quantity' => 2, 'unit_id' => $this->mat->unit_id]);
        $bom2->items()->create(['material_id' => $mat2->id, 'quantity' => 2, 'unit_id' => $this->mat->unit_id]);
        $res = $this->withToken($this->token)->postJson('/api/v1/production/orders', [
            'product_id' => $this->fin->id, 'quantity' => 10, 'plan_date' => now()->toDateString(),
        ]);
        $res->assertJsonPath('code', 0);
        $order2 = ProductionOrder::where('no', $res->json('data.no'))->first();
        $this->withToken($this->token)->postJson("/api/v1/production/orders/{$order2->id}/release")->assertJsonPath('code', 0);
        $this->withToken($this->token)->postJson("/api/v1/production/orders/{$order2->id}/start")->assertJsonPath('code', 0);

        $no = $this->createPick($this->payload(['order_id' => $order2->id, 'items' => [
            ['product_id' => $this->mat->id, 'pick_qty' => 20],
            ['product_id' => $mat2->id, 'pick_qty' => 10],
        ]]));
        $pick = PickList::where('no', $no)->first();
        $this->withToken($this->token)->postJson("/api/v1/production/picks/{$pick->id}/approve")
            ->assertJsonPath('code', 0);
        // 余额：MAT-001 50-20=30、MAT-002 30-10=20；需求行 issued_qty 各自回写
        $this->assertSame('30.00', InventoryBalance::where('product_id', $this->mat->id)->first()->quantity);
        $this->assertSame('20.00', InventoryBalance::where('product_id', $mat2->id)->first()->quantity);
        $this->assertSame('20.00', $order2->materials()->where('material_id', $this->mat->id)->first()->issued_qty);
        $this->assertSame('10.00', $order2->materials()->where('material_id', $mat2->id)->first()->issued_qty);
    }

    public function test_issue_sets_all_issued_status(): void
    {
        // 正常路径：审核后发料 → issue_status 全部发料（V1 一次发完）；响应含状态文案
        $no = $this->createPick($this->payload());
        $pick = PickList::where('no', $no)->first();
        $this->withToken($this->token)->postJson("/api/v1/production/picks/{$pick->id}/approve")->assertJsonPath('code', 0);
        $res = $this->withToken($this->token)->postJson("/api/v1/production/picks/{$pick->id}/issue");
        $res->assertJsonPath('code', 0)
            ->assertJsonPath('data.issue_status', '全部发料');
        $pick->refresh();
        $this->assertSame(PickList::ISSUE_ALL, $pick->issue_status);
        // 明细行已发回写
        $this->assertSame('20.00', $pick->items()->first()->issued_qty);
    }

    public function test_issue_rejects_draft_with_422(): void
    {
        // 异常路径：未审核不可发料 → 422（格式层）
        $no = $this->createPick($this->payload());
        $pick = PickList::where('no', $no)->first();
        $this->withToken($this->token)->postJson("/api/v1/production/picks/{$pick->id}/issue")
            ->assertJsonPath('code', 422);
    }

    public function test_issue_idempotent_when_already_all_issued(): void
    {
        // 幂等路径：重复发料 → 返回当前状态、不重复写（判重锁行后复查，事务内幂等）
        $no = $this->createPick($this->payload());
        $pick = PickList::where('no', $no)->first();
        $this->withToken($this->token)->postJson("/api/v1/production/picks/{$pick->id}/approve")->assertJsonPath('code', 0);
        $this->withToken($this->token)->postJson("/api/v1/production/picks/{$pick->id}/issue")->assertJsonPath('code', 0);
        $this->withToken($this->token)->postJson("/api/v1/production/picks/{$pick->id}/issue")
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.issue_status', '全部发料');
        $pick->refresh();
        $this->assertSame(PickList::ISSUE_ALL, $pick->issue_status);
    }

    public function test_update_and_destroy_draft_ok_approved_rejected_with_1514(): void
    {
        // 正常+异常路径：草稿可改可删；已审核不可改删 → 1514
        $no = $this->createPick($this->payload());
        $id = PickList::where('no', $no)->first()->id;
        $this->withToken($this->token)->putJson("/api/v1/production/picks/{$id}", $this->payload(['remark' => '改后']))
            ->assertJsonPath('code', 0);
        $this->withToken($this->token)->deleteJson("/api/v1/production/picks/{$id}")
            ->assertJsonPath('code', 0);
        $this->assertDatabaseMissing('pick_lists', ['id' => $id]);

        $no2 = $this->createPick($this->payload());
        $pick2 = PickList::where('no', $no2)->first();
        $this->withToken($this->token)->postJson("/api/v1/production/picks/{$pick2->id}/approve")->assertJsonPath('code', 0);
        $this->withToken($this->token)->putJson("/api/v1/production/picks/{$pick2->id}", $this->payload())
            ->assertJsonPath('code', 1514);
        $this->withToken($this->token)->deleteJson("/api/v1/production/picks/{$pick2->id}")
            ->assertJsonPath('code', 1514);
    }

    public function test_index_with_filters_and_labels(): void
    {
        // 正常路径：列表含工单单号/仓库名/状态标签/发料标签；状态筛选
        $no = $this->createPick($this->payload());
        $pick = PickList::where('no', $no)->first();
        $this->withToken($this->token)->postJson("/api/v1/production/picks/{$pick->id}/approve")->assertJsonPath('code', 0);
        $this->withToken($this->token)->getJson('/api/v1/production/picks')
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.items.0.order_no', 'MO'.date('Ymd').'-001')
            ->assertJsonPath('data.items.0.warehouse_name', '主仓')
            ->assertJsonPath('data.items.0.status_label', '已审核')
            ->assertJsonPath('data.items.0.issue_status_label', '未发料');
        $this->withToken($this->token)->getJson('/api/v1/production/picks?status=0')
            ->assertJsonPath('data.total', 0);
    }

    public function test_picks_requires_pick_permission(): void
    {
        // 异常路径：无 production.pick.list 权限的角色被拒（403 JSON 信封）
        $role = Role::create(['name' => '普通', 'code' => 'plain']);
        $u = User::create(['name' => '普通', 'username' => 'plain', 'password' => 'admin123', 'status' => 1]);
        $u->roles()->sync([$role->id]);
        $token = $u->createToken('api')->plainTextToken;
        // 测试框架在同一 app 实例内缓存 auth guard 的已认证用户（setUp 已用管理员 token 请求过；
        // 真实 HTTP 每次请求独立容器不受影响），故先重置 guard，再以普通用户 token 验证无权限被拒
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/production/picks')->assertStatus(403);
    }
}
