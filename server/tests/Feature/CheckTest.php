<?php

// 盘点单接口测试：CRUD/校验/审核（盘盈盘亏/幂等/并发 1206）/账面预填（核心路径 100%）

namespace Tests\Feature;

use App\Models\Category;
use App\Models\InventoryBalance;
use App\Models\InventoryCheck;
use App\Models\InventoryMovement;
use App\Models\Location;
use App\Models\Product;
use App\Models\Role;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CheckTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    private User $admin;

    private Warehouse $wh;

    private Location $a01;

    private Location $b01;

    private Product $mat;

    private Product $semi;

    private Product $fin;

    protected function setUp(): void
    {
        parent::setUp();
        // admin 角色（check.* 全量放行）
        $role = Role::create(['name' => '管理员', 'code' => 'admin']);
        $this->admin = User::create(['name' => '管理员', 'username' => 'admin', 'password' => 'admin123', 'status' => 1]);
        $this->admin->roles()->sync([$role->id]);
        $this->token = $this->admin->createToken('api')->plainTextToken;

        // 主仓 + A-01/B-01 库位 + 3 商品（MAT-001=100、SEMI-001=30、FIN-002=20）
        $this->wh = Warehouse::create(['name' => '主仓', 'code' => 'WH01', 'status' => 1]);
        $this->a01 = Location::create([
            'warehouse_id' => $this->wh->id,
            'name' => 'A-01',
            'code' => 'A-01',
            'status' => 1,
        ]);
        $this->b01 = Location::create([
            'warehouse_id' => $this->wh->id,
            'name' => 'B-01',
            'code' => 'B-01',
            'status' => 1,
        ]);
        $cat = Category::create(['name' => '原材料', 'parent_id' => 0]);
        $unit = Unit::create(['name' => '个', 'code' => 'pc']);
        $this->mat = Product::create([
            'name' => '测试铝材',
            'code' => 'MAT-001',
            'type' => 'raw_material',
            'category_id' => $cat->id,
            'unit_id' => $unit->id,
            'status' => 1,
        ]);
        $this->semi = Product::create([
            'name' => '半成品A',
            'code' => 'SEMI-001',
            'type' => 'semi_finished',
            'category_id' => $cat->id,
            'unit_id' => $unit->id,
            'status' => 1,
        ]);
        $this->fin = Product::create([
            'name' => '成品B',
            'code' => 'FIN-002',
            'type' => 'finished',
            'category_id' => $cat->id,
            'unit_id' => $unit->id,
            'status' => 1,
        ]);
        $this->balance($this->mat, $this->a01, 100);
        $this->balance($this->semi, $this->a01, 30);
        $this->balance($this->fin, $this->b01, 20);
    }

    // 直接建余额行（账面快照）
    private function balance(Product $p, Location $l, float $qty): void
    {
        InventoryBalance::create([
            'product_id' => $p->id, 'warehouse_id' => $this->wh->id, 'location_id' => $l->id,
            'quantity' => $qty, 'safety_min' => 0, 'safety_max' => 0,
        ]);
    }

    // 组装盘点单载荷（默认 3 行全量）
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'warehouse_id' => $this->wh->id,
            'remark' => '月度盘点',
            'items' => [
                ['product_id' => $this->mat->id, 'location_id' => $this->a01->id, 'actual_qty' => 100],
                ['product_id' => $this->semi->id, 'location_id' => $this->a01->id, 'actual_qty' => 30],
                ['product_id' => $this->fin->id, 'location_id' => $this->b01->id, 'actual_qty' => 20],
            ],
        ], $overrides);
    }

    // 通过 API 建草稿单并返回单号
    private function createCheck(array $payload): string
    {
        $res = $this->withToken($this->token)->postJson('/api/v1/checks', $payload);
        $res->assertJsonPath('code', 0);

        return $res->json('data.no');
    }

    public function test_store_creates_draft_with_no_and_auto_book(): void
    {
        // 正常路径：草稿创建成功，单号 CK{date}-001，账面数自动带出
        $no = $this->createCheck($this->payload());
        $this->assertMatchesRegularExpression('/^CK\d{8}-001$/', $no);
        $check = InventoryCheck::where('no', $no)->first();
        $this->assertSame(InventoryCheck::STATUS_DRAFT, $check->status);
        $this->assertDatabaseHas('inventory_check_items', [
            'check_id' => $check->id,
            'product_id' => $this->mat->id,
            'book_qty' => '100.00',
            'actual_qty' => '100.00',
        ]);
    }

    public function test_store_uses_next_sequence_when_no_collides(): void
    {
        // 边界路径：CK{date}-001 已被占用（历史遗留行）时，新单自动换号 -002
        // 注：撞号重试分支现已兼容 MySQL 1062 与 SQLite 19，本用例在 sqlite 下即覆盖「占号冲突 → 换号重试」路径
        InventoryCheck::create([
            'no' => 'CK'.date('Ymd').'-001',
            'warehouse_id' => $this->wh->id,
            'status' => InventoryCheck::STATUS_DRAFT,
        ]);
        $no = $this->createCheck($this->payload());
        $this->assertMatchesRegularExpression('/^CK\d{8}-002$/', $no);
    }

    public function test_store_sequence_initializes_from_legacy_numbers(): void
    {
        // 回归（审查修复）：老库无序列记录但已有当日历史 CK 单号段时，新单不得从 -001 起步撞历史单
        // 缺陷背景：序列行首次初始化 seq=0，若历史已有 -001/-002/-003，新单逐号碰撞、
        // 3 次重试耗尽直接 500；初始化须衔接既有号段最大值 → 新单取 -004 且不复用缺失号段
        foreach (['-001', '-002', '-003'] as $suffix) {
            InventoryCheck::create([
                'no' => 'CK'.date('Ymd').$suffix,
                'warehouse_id' => $this->wh->id,
                'status' => InventoryCheck::STATUS_DRAFT,
            ]);
        }
        $no = $this->createCheck($this->payload());
        $this->assertSame(sprintf('CK%s-004', date('Ymd')), $no);
        // 序列行已初始化：再建一单继续 -005（持久序列单调不回退）
        $no2 = $this->createCheck($this->payload());
        $this->assertSame(sprintf('CK%s-005', date('Ymd')), $no2);
    }

    public function test_store_sequence_does_not_regress_after_delete(): void
    {
        // 回归（缺陷修复）：删除中间号段单据后，新单号不回退
        // 缺陷背景：旧实现按当日存量 count+1 生成号段，删除 CK-001 后 count 回落，
        // 新单复用仍存在的 CK-002 → 唯一索引冲突 500（E2E TC-INV-08 实测暴露）
        // 修复后序号来自持久序列 document_sequences，与存量行数解耦，删除不回退
        $no1 = $this->createCheck($this->payload());
        $this->assertMatchesRegularExpression('/^CK\d{8}-001$/', $no1);
        $no2 = $this->createCheck($this->payload());
        $this->assertMatchesRegularExpression('/^CK\d{8}-002$/', $no2);
        // 删除 -001 草稿后，新单必须为 -003（不得复用仍存在的 -002）
        $check1 = InventoryCheck::where('no', $no1)->firstOrFail();
        $this->withToken($this->token)->deleteJson("/api/v1/checks/{$check1->id}")->assertJsonPath('code', 0);
        $no3 = $this->createCheck($this->payload());
        $this->assertSame(sprintf('CK%s-003', date('Ymd')), $no3);
        // 持久序列按日单调：同日继续取号 -004
        $no4 = $this->createCheck($this->payload());
        $this->assertSame(sprintf('CK%s-004', date('Ymd')), $no4);
    }

    public function test_store_rejects_negative_actual_with_1201(): void
    {
        // 异常路径：实盘数为负 → 1201
        $items = $this->payload()['items'];
        $items[0]['actual_qty'] = -5;
        $this->withToken($this->token)->postJson('/api/v1/checks', ['warehouse_id' => $this->wh->id, 'items' => $items])
            ->assertJsonPath('code', 1201);
    }

    public function test_store_rejects_product_without_balance_with_1205(): void
    {
        // 异常路径：该仓库无余额的商品不可录盘 → 1205
        $items = $this->payload()['items'];
        // FIN-002 在 A-01 无余额（余额在 B-01）
        $items[] = ['product_id' => $this->fin->id, 'location_id' => $this->a01->id, 'actual_qty' => 1];
        $this->withToken($this->token)->postJson('/api/v1/checks', ['warehouse_id' => $this->wh->id, 'items' => $items])
            ->assertJsonPath('code', 1205);
    }

    public function test_store_rejects_empty_items_with_422(): void
    {
        // 异常路径：明细为空 → 422 格式层校验
        $this->withToken($this->token)->postJson('/api/v1/checks', ['warehouse_id' => $this->wh->id, 'items' => []])
            ->assertStatus(422);
    }

    public function test_update_draft_and_reject_approved_with_1202(): void
    {
        // 正常路径：草稿可改（items 全量替换）
        $no = $this->createCheck($this->payload());
        $check = InventoryCheck::where('no', $no)->first();
        $items = $this->payload()['items'];
        $items[0]['actual_qty'] = 105;
        $this->withToken($this->token)
            ->putJson("/api/v1/checks/{$check->id}", [
                'warehouse_id' => $this->wh->id,
                'remark' => '改后',
                'items' => $items,
            ])
            ->assertJsonPath('code', 0);
        $this->assertDatabaseHas('inventory_check_items', [
            'check_id' => $check->id,
            'product_id' => $this->mat->id,
            'actual_qty' => '105.00',
        ]);
        // 异常路径：已审核不可改 → 1202
        $check->update(['status' => InventoryCheck::STATUS_APPROVED]);
        $this->withToken($this->token)
            ->putJson("/api/v1/checks/{$check->id}", ['warehouse_id' => $this->wh->id, 'items' => $items])
            ->assertJsonPath('code', 1202);
    }

    public function test_destroy_draft_and_reject_approved_with_1203(): void
    {
        // 正常路径：草稿可删
        $no = $this->createCheck($this->payload());
        $check = InventoryCheck::where('no', $no)->first();
        $this->withToken($this->token)->deleteJson("/api/v1/checks/{$check->id}")->assertJsonPath('code', 0);
        $this->assertDatabaseMissing('inventory_checks', ['id' => $check->id]);
        // 异常路径：已审核不可删 → 1203
        $no2 = $this->createCheck($this->payload());
        $check2 = InventoryCheck::where('no', $no2)->first();
        $check2->update(['status' => InventoryCheck::STATUS_APPROVED]);
        $this->withToken($this->token)->deleteJson("/api/v1/checks/{$check2->id}")->assertJsonPath('code', 1203);
        $this->assertDatabaseHas('inventory_checks', ['id' => $check2->id]);
    }

    public function test_auto_books_returns_balance_rows_per_location(): void
    {
        // 正常路径：按商品×库位返回该仓库有余额的行（账面数=当前余额）
        $this->withToken($this->token)->getJson('/api/v1/checks/auto-books?warehouse_id='.$this->wh->id)
            ->assertJsonPath('code', 0)
            ->assertJsonCount(3, 'data.items')
            ->assertJsonPath('data.items.0.product_code', 'MAT-001')
            ->assertJsonPath('data.items.0.book_qty', '100.00');
    }

    public function test_approve_gain_creates_check_in_movement(): void
    {
        // 核心不变式 3（盘盈）：diff=+5 → check_in 流水 + 余额 105 + diff_qty 落库
        $no = $this->createCheck($this->payload(['items' => [
            ['product_id' => $this->mat->id, 'location_id' => $this->a01->id, 'actual_qty' => 105],
        ]]));
        $check = InventoryCheck::where('no', $no)->first();
        $this->withToken($this->token)->postJson("/api/v1/checks/{$check->id}/approve")
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.changed_items', 1)
            ->assertJsonPath('data.increased', 5)
            ->assertJsonPath('data.decreased', 0)
            ->assertJsonPath('data.increased_items', 1)
            ->assertJsonPath('data.decreased_items', 0);
        // 余额 +5、check_in 流水（来源=盘点单号、快照 105）
        $this->assertDatabaseHas('inventory_balances', ['product_id' => $this->mat->id, 'quantity' => '105.00']);
        $this->assertDatabaseHas('inventory_movements', [
            'product_id' => $this->mat->id, 'direction' => 1, 'quantity' => '5.00', 'balance_after' => '105.00',
            'source_type' => 'check_in', 'source_no' => $no,
        ]);
        $this->assertDatabaseHas('inventory_check_items', [
            'check_id' => $check->id,
            'product_id' => $this->mat->id,
            'diff_qty' => '5.00',
        ]);
        // 单据状态 + 审核人
        $this->assertDatabaseHas('inventory_checks', [
            'id' => $check->id,
            'status' => InventoryCheck::STATUS_APPROVED,
            'checker' => '管理员',
        ]);
        $this->assertNotNull(InventoryCheck::find($check->id)->check_time);
    }

    public function test_approve_loss_creates_check_out_movement(): void
    {
        // 核心不变式 3（盘亏）：diff=-2 → check_out 流水 + 余额 28
        $no = $this->createCheck($this->payload(['items' => [
            ['product_id' => $this->semi->id, 'location_id' => $this->a01->id, 'actual_qty' => 28],
        ]]));
        $check = InventoryCheck::where('no', $no)->first();
        $this->withToken($this->token)->postJson("/api/v1/checks/{$check->id}/approve")
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.changed_items', 1)
            ->assertJsonPath('data.increased', 0)
            ->assertJsonPath('data.decreased', 2)
            ->assertJsonPath('data.increased_items', 0)
            ->assertJsonPath('data.decreased_items', 1);
        $this->assertDatabaseHas('inventory_balances', ['product_id' => $this->semi->id, 'quantity' => '28.00']);
        $this->assertDatabaseHas('inventory_movements', [
            'product_id' => $this->semi->id, 'direction' => -1, 'quantity' => '2.00', 'balance_after' => '28.00',
            'source_type' => 'check_out', 'source_no' => $no,
        ]);
    }

    public function test_approve_zero_diff_generates_no_movement(): void
    {
        // 边界路径：实盘=账面（diff=0）不生成流水，changed_items=0
        $no = $this->createCheck($this->payload());
        $check = InventoryCheck::where('no', $no)->first();
        $this->withToken($this->token)->postJson("/api/v1/checks/{$check->id}/approve")
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.changed_items', 0);
        $this->assertSame(0, InventoryMovement::count());
        $this->assertSame(100.0, (float) InventoryBalance::where('product_id', $this->mat->id)->value('quantity'));
    }

    public function test_approve_is_idempotent_with_1204(): void
    {
        // 核心不变式 4（幂等）：重复审核被拒 1204，余额不二次变动
        $no = $this->createCheck($this->payload(['items' => [
            ['product_id' => $this->mat->id, 'location_id' => $this->a01->id, 'actual_qty' => 105],
        ]]));
        $check = InventoryCheck::where('no', $no)->first();
        $this->withToken($this->token)->postJson("/api/v1/checks/{$check->id}/approve")->assertJsonPath('code', 0);
        $this->withToken($this->token)->postJson("/api/v1/checks/{$check->id}/approve")
            ->assertJsonPath('code', 1204)
            ->assertJsonPath('message', '该盘点单已审核');
        $this->assertSame(105.0, (float) InventoryBalance::where('product_id', $this->mat->id)->value('quantity'));
        $this->assertSame(1, InventoryMovement::count());
    }

    public function test_approve_conflict_after_concurrent_change_rolls_back_with_1206(): void
    {
        // 核心不变式 4（并发）：同商品先被其他盘点单审核（余额已变）→ 后审者 1206 整体回滚
        $noA = $this->createCheck($this->payload(['items' => [
            ['product_id' => $this->mat->id, 'location_id' => $this->a01->id, 'actual_qty' => 105],
        ]]));
        // 单据 B 仍以账面 100 录入（快照旧值）
        $noB = $this->createCheck($this->payload(['items' => [
            ['product_id' => $this->mat->id, 'location_id' => $this->a01->id, 'actual_qty' => 90],
        ]]));
        $a = InventoryCheck::where('no', $noA)->first();
        $b = InventoryCheck::where('no', $noB)->first();
        // 先审 A：余额 100 → 105
        $this->withToken($this->token)->postJson("/api/v1/checks/{$a->id}/approve")->assertJsonPath('code', 0);
        // 再审 B：账面快照 100 ≠ 当前余额 105 → 1206 回滚
        $this->withToken($this->token)->postJson("/api/v1/checks/{$b->id}/approve")
            ->assertJsonPath('code', 1206)
            ->assertJsonPath('message', '库存已变动，请重新盘点');
        $this->assertSame(105.0, (float) InventoryBalance::where('product_id', $this->mat->id)->value('quantity'));
        $this->assertSame(InventoryCheck::STATUS_DRAFT, InventoryCheck::find($b->id)->status);
        $this->assertSame(1, InventoryMovement::count());
    }

    public function test_show_returns_items_with_diff(): void
    {
        // 正常路径：详情含明细与差异
        $no = $this->createCheck($this->payload(['items' => [
            ['product_id' => $this->mat->id, 'location_id' => $this->a01->id, 'actual_qty' => 105],
        ]]));
        $check = InventoryCheck::where('no', $no)->first();
        $this->withToken($this->token)->getJson("/api/v1/checks/{$check->id}")
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.no', $no)
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.book_qty', '100.00')
            ->assertJsonPath('data.items.0.actual_qty', '105.00');
    }

    public function test_approve_requires_check_update_permission(): void
    {
        // 异常路径：无 check.update 权限 → 403
        $plain = Role::create(['name' => '普通', 'code' => 'plain']);
        $u = User::create(['name' => '普通', 'username' => 'plain', 'password' => 'admin123', 'status' => 1]);
        $u->roles()->sync([$plain->id]);
        $no = $this->createCheck($this->payload());
        $check = InventoryCheck::where('no', $no)->first();
        // 测试框架在同一 app 实例内缓存 auth guard 的已认证用户（真实 HTTP 每次请求独立容器不受影响），
        // 故先重置 guard，再以普通用户 token 请求，验证无 check.update 权限被拒
        $this->app['auth']->forgetGuards();
        $this->withToken($u->createToken('api')->plainTextToken)
            ->postJson("/api/v1/checks/{$check->id}/approve")
            ->assertStatus(403);
    }

    public function test_store_rejects_duplicate_product_location_with_422(): void
    {
        // 异常路径：同商品×库位 出现两次 → 422（防扫码/粘贴重复行）
        $items = $this->payload()['items'];
        $items[] = ['product_id' => $this->mat->id, 'location_id' => $this->a01->id, 'actual_qty' => 99];
        $this->withToken($this->token)->postJson('/api/v1/checks', ['warehouse_id' => $this->wh->id, 'items' => $items])
            ->assertJsonPath('code', 422);
    }
}
