<?php

// 仪表盘聚合服务测试：聚合口径=数据一致性核心路径 100% 覆盖
// 数据一律测试内自建（不依赖 InventorySeeder 数值），插外键数据先建真实主数据（sqlite 外键开启）

namespace Tests\Feature;

use App\Models\BomHeader;
use App\Models\Category;
use App\Models\InventoryBalance;
use App\Models\InventoryCheck;
use App\Models\InventoryMovement;
use App\Models\Location;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\PurchaseInbound;
use App\Models\PurchaseInboundItem;
use App\Models\PurchaseOrder;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\DashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        // 清空 InventorySeeder 基线余额/流水/基线商品（同 ReportServiceTest 惯例）：
        // 聚合断言数值必须完全由用例自建数据决定（不依赖种子数值）；
        // 商品后删（余额/流水外键 restrictOnDelete，必须先清引用行）
        InventoryMovement::query()->delete();
        InventoryBalance::query()->delete();
        Product::whereIn('code', ['MAT-001', 'SEMI-001', 'FIN-002'])->delete();
        $this->service = app(DashboardService::class);
        $this->warehouse = Warehouse::where('code', 'WH01')->first();
        $this->location = Location::where('code', 'A-01')->first();
        $this->admin = User::where('username', 'admin')->first();
    }

    private DashboardService $service;

    private Warehouse $warehouse;

    private Location $location;

    private User $admin;

    // 商品自建辅助（unit/category 用种子既有主数据）
    private function makeProduct(string $code, string $type, string $categoryName = '原材料', int $safetyMin = 0, int $safetyMax = 0): Product
    {
        return Product::create([
            'name' => $code, 'code' => $code, 'type' => $type,
            'category_id' => Category::where('name', $categoryName)->first()->id,
            'unit_id' => Unit::where('code', 'pc')->first()->id,
            'safety_min' => $safetyMin, 'safety_max' => $safetyMax, 'status' => 1,
        ]);
    }

    // 余额行自建辅助（聚合只读不写库存，直插即可）
    private function makeBalance(Product $p, string $quantity): void
    {
        InventoryBalance::create([
            'product_id' => $p->id, 'warehouse_id' => $this->warehouse->id,
            'location_id' => $this->location->id, 'quantity' => $quantity,
            'safety_min' => 0, 'safety_max' => 0,
        ]);
    }

    // 流水自建辅助（方向/数量/时间；聚合只读，直插即可）
    private function makeMovement(Product $p, int $direction, string $quantity, string $datetime): void
    {
        InventoryMovement::create([
            'product_id' => $p->id, 'warehouse_id' => $this->warehouse->id,
            'location_id' => $this->location->id, 'direction' => $direction,
            'quantity' => $quantity, 'balance_after' => $quantity,
            'source_type' => 'purchase_inbound', 'source_id' => 1, 'source_no' => 'PI20260813-001',
            'created_at' => $datetime, 'updated_at' => $datetime,
        ]);
    }

    // 工单自建辅助（bom_id 外键必须先建真实 BOM 头）
    private function makeOrder(string $no, int $status): ProductionOrder
    {
        $fin = $this->makeProduct('FIN-'.$no, 'finished', '成品');
        $bom = BomHeader::create([
            'code' => 'BOM-'.$no, 'product_id' => $fin->id, 'version' => 'v1',
            'quantity' => 1, 'status' => 1,
        ]);

        return ProductionOrder::create([
            'no' => $no, 'product_id' => $fin->id, 'quantity' => '10',
            'plan_date' => now()->toDateString(), 'bom_id' => $bom->id, 'status' => $status,
            'completed_qty' => '0', 'created_by' => 1,
        ]);
    }

    // 带指定权限的用户（角色 + 权限独立自建，验证权限过滤语义）
    private function makeUserWithPermissions(array $codes): User
    {
        $role = Role::create(['code' => 'ROLE-'.uniqid(), 'name' => '测试角色', 'remark' => '']);
        $role->permissions()->sync(Permission::whereIn('code', $codes)->pluck('id'));

        // 建用户后显式挂角色（Model::__call 会将 tap 转发到 Eloquent Builder，故不用 ->tap 链式写法）
        $user = User::create([
            'name' => '权限测试用户', 'username' => 'perm_'.uniqid(), 'email' => uniqid().'@php-design.local',
            'password' => 'Test@12345', 'status' => 1,
        ]);
        $user->roles()->sync([$role->id]);

        return $user;
    }

    public function test_summary_aggregates_inventory_qty_value_and_today_movements(): void
    {
        // 正常路径：库存总量=余额和；总值=Σ(余额×最近采购单价)转元；今日出入库=今日流水方向 Σ
        $p = $this->makeProduct('MAT-A', 'raw_material');
        $this->makeBalance($p, '10.00');
        // 种子无供应商 → 自建（supplier_id 外键）
        $sup = Supplier::create(['name' => '供应商A', 'code' => 'SUP-A', 'status' => 1]);
        $inbound = PurchaseInbound::create([
            'no' => 'PI20260813-001', 'supplier_id' => $sup->id,
            'warehouse_id' => $this->warehouse->id, 'location_id' => $this->location->id,
            'status' => 1, 'total_amount' => 0, 'inbound_at' => now()->toDateTimeString(),
        ]);
        PurchaseInboundItem::create([
            'inbound_id' => $inbound->id, 'product_id' => $p->id,
            'quantity' => 1, 'price' => 150, 'amount' => 150,
        ]);
        // 今日流水：入 5、出 3；昨日流水不计入
        $this->makeMovement($p, 1, '5.00', now()->toDateTimeString());
        $this->makeMovement($p, -1, '3.00', now()->toDateTimeString());
        $this->makeMovement($p, 1, '99.00', now()->subDay()->toDateTimeString());

        $res = $this->service->summary($this->admin);

        $this->assertSame('10.00', $res['inventory_total_qty']);
        $this->assertSame('15.00', $res['inventory_value']); // 10 × 150 分 = 15.00 元
        $this->assertSame('5.00', $res['today_inbound_qty']);
        $this->assertSame('3.00', $res['today_outbound_qty']);
    }

    public function test_summary_value_null_without_cost_price(): void
    {
        // 边界路径：无采购单价 → 总值 null（仅数量，不显示 ¥0）
        $p = $this->makeProduct('MAT-A', 'raw_material');
        $this->makeBalance($p, '10.00');

        $res = $this->service->summary($this->admin);

        $this->assertSame('10.00', $res['inventory_total_qty']);
        $this->assertNull($res['inventory_value']);
    }

    public function test_summary_pending_count_is_permission_filtered(): void
    {
        // 正常路径：待审核数按用户审核权限过滤（无采购权限 → 采购草稿不计）
        $sup = Supplier::create(['name' => '供应商A', 'code' => 'SUP-A', 'status' => 1]);
        PurchaseOrder::create([
            'no' => 'PO20260813-001', 'supplier_id' => $sup->id,
            'order_date' => now()->toDateString(), 'status' => PurchaseOrder::STATUS_DRAFT,
            'total_amount' => 100,
        ]);

        // admin 全权限：计入
        $this->assertSame(1, $this->service->summary($this->admin)['pending_approvals']);
        // 仅盘点审核权限用户：采购草稿不计入 → 0
        $limited = $this->makeUserWithPermissions(['check.update']);
        $this->assertSame(0, $this->service->summary($limited)['pending_approvals']);
    }

    public function test_summary_work_order_running_and_alert_count(): void
    {
        // 正常路径：生产中工单数 + 低库存预警数（草稿工单不计；高库存不计——spec §7）
        $this->makeOrder('MO20260813-001', ProductionOrder::STATUS_PRODUCING);
        $this->makeOrder('MO20260813-002', ProductionOrder::STATUS_DRAFT);
        // 低库存：低于下限（预警）；高库存：高于上限（不计入仪表盘）
        $low = $this->makeProduct('MAT-A', 'raw_material', '原材料', 10, 0);
        $this->makeBalance($low, '3.00');
        $high = $this->makeProduct('MAT-B', 'raw_material', '原材料', 0, 5);
        $this->makeBalance($high, '20.00');

        $res = $this->service->summary($this->admin);

        $this->assertSame(1, $res['work_order_running']);
        $this->assertSame(1, $res['alert_count']);
    }

    public function test_pending_approvals_lists_permission_filtered_drafts_sorted_desc(): void
    {
        // 正常路径：列表含模块/类型/单号/时间/路由；创建时间倒序；无权限类型不出现
        $sup = Supplier::create(['name' => '供应商A', 'code' => 'SUP-A', 'status' => 1]);
        $po1 = PurchaseOrder::create([
            'no' => 'PO20260813-001', 'supplier_id' => $sup->id,
            'order_date' => now()->toDateString(), 'status' => PurchaseOrder::STATUS_DRAFT,
            'total_amount' => 100,
        ]);
        $po1->created_at = now()->subMinute(); // fillable 不保证含时间戳 → 属性赋值后 save
        $po1->save();
        PurchaseOrder::create([
            'no' => 'PO20260813-002', 'supplier_id' => $sup->id,
            'order_date' => now()->toDateString(), 'status' => PurchaseOrder::STATUS_DRAFT,
            'total_amount' => 100,
        ]);
        $check = InventoryCheck::create([
            'no' => 'CK20260813-001', 'warehouse_id' => $this->warehouse->id,
            'status' => InventoryCheck::STATUS_DRAFT,
        ]);
        $check->created_at = now()->addMinute();
        $check->save();

        $res = $this->service->pendingApprovals($this->admin);

        $this->assertCount(3, $res['items']);
        $this->assertSame('CK20260813-001', $res['items'][0]['no']); // 最新在前
        $this->assertSame('库存', $res['items'][0]['module']);
        $this->assertSame('盘点单', $res['items'][0]['type']);
        $this->assertSame('/inventory/checks', $res['items'][0]['url']);
        $this->assertSame('PO20260813-002', $res['items'][1]['no']);
        $this->assertSame('采购', $res['items'][1]['module']);
        $this->assertSame('订单', $res['items'][1]['type']);
        $this->assertSame('/purchase/orders', $res['items'][1]['url']);
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/',
            $res['items'][0]['created_at']
        );

        // 权限过滤：仅盘点审核权限用户只看到盘点草稿
        $limited = $this->makeUserWithPermissions(['check.update']);
        $filtered = $this->service->pendingApprovals($limited);
        $this->assertCount(1, $filtered['items']);
        $this->assertSame('CK20260813-001', $filtered['items'][0]['no']);
    }

    public function test_pending_approvals_excludes_draft_production_orders(): void
    {
        // 边界路径：工单草稿不入待审核（流转动作是「下达」而非「审核」）
        $this->makeOrder('MO20260813-001', ProductionOrder::STATUS_DRAFT);

        $res = $this->service->pendingApprovals($this->admin);

        $this->assertSame([], $res['items']);
    }

    public function test_pending_approvals_limits_to_20_newest(): void
    {
        // 边界路径：跨类型超过 20 条 → 仅返回最新 20 条（最新在前）
        $sup = Supplier::create(['name' => '供应商A', 'code' => 'SUP-A', 'status' => 1]);
        $base = now()->startOfDay();
        for ($i = 1; $i <= 25; $i++) {
            $po = PurchaseOrder::create([
                'no' => 'PO20260813-'.str_pad((string) $i, 3, '0', STR_PAD_LEFT),
                'supplier_id' => $sup->id,
                'order_date' => now()->toDateString(), 'status' => PurchaseOrder::STATUS_DRAFT,
                'total_amount' => 100,
            ]);
            $po->created_at = $base->copy()->addMinutes($i);
            $po->save();
        }

        $res = $this->service->pendingApprovals($this->admin);

        $this->assertCount(20, $res['items']);
        // 第 25 张单在最前，第 1 张（最早）被截断
        $this->assertSame('PO20260813-025', $res['items'][0]['no']);
        $this->assertSame('PO20260813-006', $res['items'][19]['no']);
    }

    public function test_work_order_progress_lists_producing_and_completed_with_progress(): void
    {
        // 正常路径：仅生产中/已完成工单；进度=完工/计划×100（2 位字符串）；状态标签正确
        $this->makeOrder('MO20260813-001', ProductionOrder::STATUS_PRODUCING);
        $done = $this->makeOrder('MO20260813-002', ProductionOrder::STATUS_COMPLETED);
        $done->completed_qty = '2.5';
        $done->save();
        $this->makeOrder('MO20260813-003', ProductionOrder::STATUS_DRAFT);    // 不入
        $this->makeOrder('MO20260813-004', ProductionOrder::STATUS_RELEASED); // 不入
        $this->makeOrder('MO20260813-005', ProductionOrder::STATUS_CLOSED);   // 不入

        $res = $this->service->workOrderProgress();

        $this->assertCount(2, $res['items']);
        $byNo = collect($res['items'])->keyBy('no');
        $this->assertSame('0.00', $byNo['MO20260813-001']['progress']);
        $this->assertSame(2, $byNo['MO20260813-001']['status']);
        $this->assertSame('生产中', $byNo['MO20260813-001']['status_label']);
        $this->assertSame('25.00', $byNo['MO20260813-002']['progress']); // 2.5/10
        $this->assertSame(3, $byNo['MO20260813-002']['status']);
        $this->assertSame('已完成', $byNo['MO20260813-002']['status_label']);
        $this->assertSame('FIN-MO20260813-002', $byNo['MO20260813-002']['product_name']);
    }

    public function test_work_order_progress_limits_10_by_updated_at_desc(): void
    {
        // 边界路径：超过 10 条 → 最新更新在前，取前 10
        for ($i = 1; $i <= 12; $i++) {
            $o = $this->makeOrder('MO20260813-'.str_pad((string) $i, 3, '0', STR_PAD_LEFT), ProductionOrder::STATUS_PRODUCING);
            $o->updated_at = now()->addMinutes($i);
            $o->save();
        }

        $res = $this->service->workOrderProgress();

        $this->assertCount(10, $res['items']);
        $this->assertSame('MO20260813-012', $res['items'][0]['no']);
    }

    public function test_work_order_progress_zero_quantity_defends_zero(): void
    {
        // 边界路径：计划数量 0（防御）→ 进度 0.00 不除零
        $o = $this->makeOrder('MO20260813-001', ProductionOrder::STATUS_PRODUCING);
        $o->quantity = '0';
        $o->save();

        $res = $this->service->workOrderProgress();

        $this->assertSame('0.00', $res['items'][0]['progress']);
    }

    public function test_alerts_lists_low_stock_only_top_10(): void
    {
        // 正常路径：仅低库存（低于下限）；高库存不计；按商品排序前 10
        for ($i = 1; $i <= 12; $i++) {
            $p = $this->makeProduct('MAT-'.str_pad((string) $i, 3, '0', STR_PAD_LEFT), 'raw_material', '原材料', 10, 0);
            $this->makeBalance($p, '3.00');
        }
        $high = $this->makeProduct('MAT-HIGH', 'raw_material', '原材料', 0, 5);
        $this->makeBalance($high, '20.00');

        $res = $this->service->alerts();

        $this->assertCount(10, $res['items']);
        $this->assertSame('MAT-001', $res['items'][0]['product_code']); // 商品 id 升序前 10
        $this->assertSame('主仓', $res['items'][0]['warehouse_name']);
        $this->assertSame('3.00', $res['items'][0]['quantity']);
        $this->assertSame(10, $res['items'][0]['safety_min']);
        foreach ($res['items'] as $row) {
            $this->assertNotSame('MAT-HIGH', $row['product_code']);
        }
    }

    public function test_alerts_empty_when_no_low_stock(): void
    {
        // 边界路径：无低库存（高于下限 / 下限 0=不预警该侧）→ items 空
        $p = $this->makeProduct('MAT-A', 'raw_material', '原材料', 10, 0);
        $this->makeBalance($p, '50.00');

        $res = $this->service->alerts();

        $this->assertSame([], $res['items']);
    }
}
