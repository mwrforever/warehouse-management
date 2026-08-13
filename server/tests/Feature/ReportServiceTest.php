<?php

// 报表聚合服务测试（A：库存报表/出入库汇总）：聚合口径=数据一致性核心路径 100% 覆盖
// 数据一律测试内自建（不依赖 InventorySeeder 数值），插外键数据先建真实主数据（sqlite 外键开启）

namespace Tests\Feature;

use App\Models\BomHeader;
use App\Models\Category;
use App\Models\Customer;
use App\Models\InventoryBalance;
use App\Models\InventoryMovement;
use App\Models\Location;
use App\Models\OperationReport;
use App\Models\PickList;
use App\Models\PickListItem;
use App\Models\Process;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\PurchaseInbound;
use App\Models\PurchaseInboundItem;
use App\Models\ReturnList;
use App\Models\ReturnListItem;
use App\Models\SalesOutbound;
use App\Models\SalesOutboundItem;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\Warehouse;
use App\Models\WorkOrderOperation;
use App\Services\ReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ReportServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // 基础主数据：分类/单位/仓库/库位/供应商（商品与余额由各用例自建）
        $this->seed();
        // 清空 InventorySeeder 基线余额/流水：聚合断言数值必须完全由用例自建数据决定（不依赖种子数值）
        InventoryMovement::query()->delete();
        InventoryBalance::query()->delete();
        $this->service = app(ReportService::class);
        $this->warehouse = Warehouse::where('code', 'WH01')->first();
        $this->location = Location::where('code', 'A-01')->first();
    }

    private ReportService $service;

    private Warehouse $warehouse;

    private Location $location;

    // 商品自建辅助（unit/category 用种子既有主数据）
    private function makeProduct(string $code, string $type, string $categoryName, string $name = ''): Product
    {
        return Product::create([
            'name' => $name ?: $code, 'code' => $code, 'type' => $type,
            'category_id' => Category::where('name', $categoryName)->first()->id,
            'unit_id' => Unit::where('code', 'pc')->first()->id,
            'safety_min' => 0, 'safety_max' => 0, 'status' => 1,
        ]);
    }

    // 余额行自建辅助（聚合只读不写库存，直插即可；balance_unique 唯一索引=商品×仓库×库位，
    // 同商品多行必须落不同库位——location 参数缺省 A-01）
    private function makeBalance(Product $p, string $quantity, ?Location $location = null): void
    {
        InventoryBalance::create([
            'product_id' => $p->id, 'warehouse_id' => $this->warehouse->id,
            'location_id' => ($location ?? $this->location)->id, 'quantity' => $quantity,
            'safety_min' => 0, 'safety_max' => 0,
        ]);
    }

    // —— inventorySummary 用例 ——

    public function test_inventory_summary_groups_by_category_with_quantity_and_count(): void
    {
        // 正常路径：按分类分组——数量求和、商品种类去重
        $raw = $this->makeProduct('MAT-A', 'raw_material', '原材料');
        $raw2 = $this->makeProduct('MAT-B', 'raw_material', '原材料');
        $fin = $this->makeProduct('FIN-A', 'finished', '成品');
        $this->makeBalance($raw, '10.00');
        // 同商品第二行余额必须落不同库位（balance_unique 唯一索引；种子有 A-01/B-01 两库位）
        $this->makeBalance($raw, '5.50', Location::where('code', 'B-01')->first());
        $this->makeBalance($raw2, '20.00');
        $this->makeBalance($fin, '3.00');

        $res = $this->service->inventorySummary('category');

        $byName = collect($res['items'])->keyBy('group_name');
        // 原材料 = MAT-A(10+5.5) + MAT-B(20)（brief 期望值 15.50/18.50 漏算 MAT-B 的 20.00，按“余额行求和”口径修正）
        $this->assertSame('35.50', $byName['原材料']['quantity_total']);
        $this->assertSame(2, $byName['原材料']['product_count']);
        $this->assertSame('3.00', $byName['成品']['quantity_total']);
        $this->assertSame(1, $byName['成品']['product_count']);
        // total 全局汇总：数量=全部余额和、种类=全部商品去重
        $this->assertSame('38.50', $res['total']['quantity_total']);
        $this->assertSame(3, $res['total']['product_count']);
        $this->assertFalse($res['truncated']);
        // 无成本价数据 → 金额为空（无成本时仅数量）
        $this->assertNull($res['total']['amount_total']);
        $this->assertNull($byName['原材料']['amount_total']);
    }

    public function test_inventory_summary_groups_by_warehouse_and_type(): void
    {
        // 正常路径：按仓库/按类型维度分组（type 输出中文标签）
        $raw = $this->makeProduct('MAT-A', 'raw_material', '原材料');
        $fin = $this->makeProduct('FIN-A', 'finished', '成品');
        $this->makeBalance($raw, '10.00');
        $this->makeBalance($fin, '3.00');

        $wh = $this->service->inventorySummary('warehouse');
        $this->assertSame('13.00', $wh['items'][0]['quantity_total']);
        $this->assertSame('主仓', $wh['items'][0]['group_name']);

        $type = $this->service->inventorySummary('type');
        $byName = collect($type['items'])->keyBy('group_name');
        $this->assertSame('10.00', $byName['原料']['quantity_total']);
        $this->assertSame('3.00', $byName['成品']['quantity_total']);
    }

    public function test_inventory_summary_amount_uses_latest_purchase_price(): void
    {
        // 正常路径：金额=余额×最近一次采购入库单价（分）÷100 元；取最近一条单价
        $raw = $this->makeProduct('MAT-A', 'raw_material', '原材料');
        $this->makeBalance($raw, '2.00');
        // 种子无供应商 → 自建（supplier_id 外键）
        $sup = Supplier::create(['name' => '供应商A', 'code' => 'SUP-A', 'status' => 1]);
        $inbound = PurchaseInbound::create([
            'no' => 'PI20260813-001', 'supplier_id' => $sup->id,
            'warehouse_id' => $this->warehouse->id, 'location_id' => $this->location->id,
            'status' => 1, 'total_amount' => 0, 'inbound_at' => now()->toDateTimeString(),
        ]);
        // 旧单价 100 分（先建，created_at 较早）
        PurchaseInboundItem::create([
            'inbound_id' => $inbound->id, 'product_id' => $raw->id,
            'quantity' => 1, 'price' => 100, 'amount' => 100,
        ]);
        // 新单价 150 分（后建，created_at 较晚 → 生效；显式时间戳不在 fillable 白名单，forceCreate 写入）
        PurchaseInboundItem::forceCreate([
            'inbound_id' => $inbound->id, 'product_id' => $raw->id,
            'quantity' => 1, 'price' => 150, 'amount' => 150,
            'created_at' => now()->addSecond(), 'updated_at' => now()->addSecond(),
        ]);

        $res = $this->service->inventorySummary('category');
        // 2 × 150 分 = 300 分 = 3.00 元
        $this->assertSame('3.00', $res['items'][0]['amount_total']);
        $this->assertSame('3.00', $res['total']['amount_total']);
    }

    public function test_inventory_summary_amount_null_when_no_purchase_price(): void
    {
        // 边界路径：有采购单但无对应商品单价 → 金额为空、仅数量
        $raw = $this->makeProduct('MAT-A', 'raw_material', '原材料');
        $this->makeBalance($raw, '7.00');

        $res = $this->service->inventorySummary('category');
        $this->assertSame('7.00', $res['items'][0]['quantity_total']);
        $this->assertNull($res['items'][0]['amount_total']);
    }

    public function test_inventory_summary_empty_when_no_balances(): void
    {
        // 边界路径：无任何余额 → items 空、total 全 0
        $res = $this->service->inventorySummary('category');
        $this->assertSame([], $res['items']);
        $this->assertSame('0', $res['total']['quantity_total']);
        $this->assertSame(0, $res['total']['product_count']);
    }

    public function test_inventory_summary_ignores_date_to_in_v1(): void
    {
        // 边界路径：date_to V1 仅预留（无历史快照），不参与过滤——传任何日期结果一致
        $raw = $this->makeProduct('MAT-A', 'raw_material', '原材料');
        $this->makeBalance($raw, '10.00');
        $a = $this->service->inventorySummary('category', '2026-01-01');
        $b = $this->service->inventorySummary('category', null);
        $this->assertSame($a, $b);
    }

    // —— movementsSummary 用例 ——

    // 流水自建辅助（方向/数量/时间；聚合只读，直插即可；created_at 显式指定回填历史时间，
    // updated_at 不在 fillable 白名单由模型自动维护——聚合仅读 created_at）
    private function makeMovement(Product $p, int $direction, string $quantity, string $datetime, string $sourceType = 'purchase_inbound'): void
    {
        InventoryMovement::create([
            'product_id' => $p->id, 'warehouse_id' => $this->warehouse->id,
            'location_id' => $this->location->id, 'direction' => $direction,
            'quantity' => $quantity, 'balance_after' => $quantity,
            'source_type' => $sourceType, 'source_id' => 1, 'source_no' => 'PI20260813-001',
            'created_at' => $datetime,
        ]);
    }

    public function test_movements_summary_day_granularity_sums_by_direction(): void
    {
        // 正常路径：日粒度——同日同方向求和、方向分列、条数计数
        $p = $this->makeProduct('MAT-A', 'raw_material', '原材料');
        $this->makeMovement($p, 1, '10.00', '2026-08-10 08:00:00');
        $this->makeMovement($p, 1, '5.50', '2026-08-10 09:00:00');
        $this->makeMovement($p, -1, '3.00', '2026-08-10 10:00:00');
        $this->makeMovement($p, 1, '2.00', '2026-08-11 08:00:00');

        $res = $this->service->movementsSummary('2026-08-10', '2026-08-11', 'day');

        $this->assertCount(2, $res['items']);
        $this->assertSame('2026-08-10', $res['items'][0]['period']);
        $this->assertSame('15.50', $res['items'][0]['inbound_qty']);
        $this->assertSame('3.00', $res['items'][0]['outbound_qty']);
        $this->assertSame(2, $res['items'][0]['inbound_count']);
        $this->assertSame(1, $res['items'][0]['outbound_count']);
        $this->assertSame('2026-08-11', $res['items'][1]['period']);
        // totals = 全区间求和
        $this->assertSame('17.50', $res['totals']['inbound_qty']);
        $this->assertSame('3.00', $res['totals']['outbound_qty']);
        $this->assertSame(3, $res['totals']['inbound_count']);
        $this->assertFalse($res['truncated']);
    }

    public function test_movements_summary_month_granularity_and_closed_interval(): void
    {
        // 边界路径：月粒度聚合 + 闭区间（8/31 含、9/1 不含——跨月边界正确性）
        $p = $this->makeProduct('MAT-A', 'raw_material', '原材料');
        $this->makeMovement($p, 1, '10.00', '2026-08-01 08:00:00');
        $this->makeMovement($p, 1, '20.00', '2026-08-31 23:59:59'); // 闭区间上界含
        $this->makeMovement($p, 1, '99.00', '2026-09-01 00:00:00'); // 区间外不含
        $this->makeMovement($p, -1, '5.00', '2026-08-15 08:00:00');

        $res = $this->service->movementsSummary('2026-08-01', '2026-08-31', 'month');

        $this->assertCount(1, $res['items']);
        $this->assertSame('2026-08', $res['items'][0]['period']);
        $this->assertSame('30.00', $res['items'][0]['inbound_qty']);
        $this->assertSame('5.00', $res['items'][0]['outbound_qty']);
        $this->assertSame('30.00', $res['totals']['inbound_qty']);
    }

    public function test_movements_summary_filters_by_source_type(): void
    {
        // 正常路径：source_type 可空筛选（未传=全部）
        $p = $this->makeProduct('MAT-A', 'raw_material', '原材料');
        $this->makeMovement($p, 1, '10.00', '2026-08-10 08:00:00', 'pick');
        $this->makeMovement($p, -1, '2.00', '2026-08-10 09:00:00', 'return');

        $all = $this->service->movementsSummary('2026-08-10', '2026-08-10', 'day');
        $this->assertSame('10.00', $all['totals']['inbound_qty']);
        $this->assertSame('2.00', $all['totals']['outbound_qty']);

        $pick = $this->service->movementsSummary('2026-08-10', '2026-08-10', 'day', 'pick');
        $this->assertSame('10.00', $pick['totals']['inbound_qty']);
        $this->assertSame('0', $pick['totals']['outbound_qty']);
    }

    public function test_movements_summary_empty_range_returns_empty_items_and_zero_totals(): void
    {
        // 边界路径：无数据区间 → items 空、totals 全 0（不补零——E2E TC-RPT-05 锁定空态）
        $res = $this->service->movementsSummary('2099-01-01', '2099-01-31', 'day');
        $this->assertSame([], $res['items']);
        $this->assertSame('0', $res['totals']['inbound_qty']);
        $this->assertSame('0', $res['totals']['outbound_qty']);
        $this->assertSame(0, $res['totals']['inbound_count']);
        $this->assertSame(0, $res['totals']['outbound_count']);
    }

    public function test_movements_summary_truncates_over_500_rows(): void
    {
        // 边界路径：>500 周期截断前 500 + truncated 标记（用 501 个不同日期构造）
        $p = $this->makeProduct('MAT-A', 'raw_material', '原材料');
        $day = Carbon::create(2025, 1, 1);
        for ($i = 0; $i < 501; $i++) {
            $this->makeMovement($p, 1, '1.00', $day->copy()->addDays($i)->toDateTimeString());
        }
        $res = $this->service->movementsSummary('2025-01-01', '2026-12-31', 'day');
        $this->assertTrue($res['truncated']);
        $this->assertCount(500, $res['items']);
        // totals 仍为全区间真实合计（截断只作用于 items 展示）
        $this->assertSame('501.00', $res['totals']['inbound_qty']);
    }

    // —— production 用例 ——

    // 工单+报工+领退料自建辅助（聚合口径核对数据源；sqlite 外键开启 → bom_id/operation_id 必须先建真实主数据）
    private function makeOrder(Product $fin, string $no, string $quantity, string $planDate, int $status = 3, string $completed = '0'): ProductionOrder
    {
        // 工单必须挂真实 BOM 头（bom_id 外键；种子不建 BOM）
        $bom = BomHeader::create([
            'code' => 'BOM-'.$no, 'product_id' => $fin->id, 'version' => 'v1',
            'quantity' => 1, 'status' => 1,
        ]);

        return ProductionOrder::create([
            'no' => $no, 'product_id' => $fin->id, 'quantity' => $quantity,
            'plan_date' => $planDate, 'bom_id' => $bom->id, 'status' => $status,
            'completed_qty' => $completed, 'created_by' => 1,
        ]);
    }

    private function makeReport(ProductionOrder $o, string $qualified, string $defective, string $hours): void
    {
        // 报工必须挂工单工序（operation_id 外键）：懒建 seq=1 工序（process_id 用种子工序）
        $op = WorkOrderOperation::firstOrCreate(
            ['order_id' => $o->id, 'seq' => 1],
            ['process_id' => Process::first()->id, 'status' => 1, 'qualified_qty' => 0, 'defective_qty' => 0, 'hours' => 0],
        );
        OperationReport::create([
            'operation_id' => $op->id, 'order_id' => $o->id,
            'qualified_qty' => $qualified, 'defective_qty' => $defective,
            'hours' => $hours, 'report_time' => now()->toDateTimeString(),
        ]);
    }

    private function makePick(ProductionOrder $o, Product $mat, string $qty, string $no = 'PL20260813-001'): void
    {
        // 已审核领料：领料行 pick_qty 即审核时写流水的数量（口径=E2E 流水核对）
        $pick = PickList::create([
            'no' => $no, 'order_id' => $o->id, 'status' => 1, 'issue_status' => 2,
            'warehouse_id' => $this->warehouse->id, 'location_id' => $this->location->id,
            'approved_at' => now()->toDateTimeString(),
        ]);
        PickListItem::create([
            'pick_id' => $pick->id, 'product_id' => $mat->id,
            'required_qty' => $qty, 'pick_qty' => $qty, 'issued_qty' => $qty,
        ]);
    }

    private function makeReturn(ProductionOrder $o, Product $mat, string $qty, string $no = 'RL20260813-001'): void
    {
        $ret = ReturnList::create([
            'no' => $no, 'order_id' => $o->id, 'status' => 1,
            'warehouse_id' => $this->warehouse->id, 'location_id' => $this->location->id,
            'approved_at' => now()->toDateTimeString(),
        ]);
        ReturnListItem::create([
            'return_id' => $ret->id, 'product_id' => $mat->id, 'quantity' => $qty,
        ]);
    }

    public function test_production_rates_hours_and_material_used(): void
    {
        // 正常路径：达成率/良率/工时/物料耗用四指标与源数据精确一致
        $fin = $this->makeProduct('FIN-A', 'finished', '成品');
        $mat = $this->makeProduct('MAT-A', 'raw_material', '原材料');
        $o = $this->makeOrder($fin, 'MO20260813-001', '10', '2026-08-13', 3, '10');
        $this->makeReport($o, '8', '2', '3.00');   // 合格 8 不良 2 工时 3
        $this->makeReport($o, '2', '0', '2.00');   // 合格累计 10 工时累计 5
        $this->makePick($o, $mat, '20.00');
        $this->makeReturn($o, $mat, '2.00');       // 耗用 = 20 - 2 = 18

        $res = $this->service->production('2026-08-01', '2026-08-31');

        $this->assertCount(1, $res['items']);
        $row = $res['items'][0];
        $this->assertSame('MO20260813-001', $row['order_no']);
        $this->assertSame('FIN-A', $row['product_code']);
        $this->assertSame('10', $row['quantity']);
        $this->assertSame('10', $row['completed_qty']);
        $this->assertSame('100.00', $row['achievement_rate']);
        $this->assertSame('10', $row['qualified_qty']);
        $this->assertSame('2', $row['defective_qty']);
        $this->assertSame('83.33', $row['yield_rate']); // 10/(10+2)=83.33%（2 位小数）
        $this->assertSame('5.00', $row['total_hours']);
        $this->assertCount(1, $row['material_used']);
        $this->assertSame('MAT-A', $row['material_used'][0]['material_code']);
        $this->assertSame('18.00', $row['material_used'][0]['used_qty']);
        $this->assertSame('个', $row['material_used'][0]['unit']);
        $this->assertFalse($res['truncated']);
    }

    public function test_production_preserves_fractional_quantities_two_decimals(): void
    {
        // 边界路径：数量/合格/不良小数位保持 2 位一致（仅剥离 '.00' 尾零，不破坏 1 位小数的尾零）
        $fin = $this->makeProduct('FIN-A', 'finished', '成品');
        $o = $this->makeOrder($fin, 'MO20260813-004', '10.50', '2026-08-13', 3, '10.50');
        $this->makeReport($o, '8.50', '1.50', '1.50'); // 合格 8.5 不良 1.5 工时 1.5

        $res = $this->service->production('2026-08-01', '2026-08-31');
        $row = $res['items'][0];

        $this->assertSame('10.50', $row['quantity']);
        $this->assertSame('10.50', $row['completed_qty']);
        $this->assertSame('8.50', $row['qualified_qty']);
        $this->assertSame('1.50', $row['defective_qty']);
        $this->assertSame('1.50', $row['total_hours']);
        $this->assertSame('85.00', $row['yield_rate']); // 8.5/(8.5+1.5)=85.00%
        $this->assertSame('100.00', $row['achievement_rate']);
    }

    public function test_production_yield_is_100_when_no_defective(): void
    {
        // 边界路径：无不良（含无报工记录）→ 良率 100.00（spec RPT-04）
        $fin = $this->makeProduct('FIN-A', 'finished', '成品');
        $o1 = $this->makeOrder($fin, 'MO20260813-001', '10', '2026-08-13');
        $o2 = $this->makeOrder($fin, 'MO20260813-002', '10', '2026-08-13');
        $this->makeReport($o2, '5', '0', '1.00'); // 合格无不良

        $res = $this->service->production('2026-08-01', '2026-08-31');
        $byNo = collect($res['items'])->keyBy('order_no');
        $this->assertSame('100.00', $byNo['MO20260813-001']['yield_rate']); // 无任何报工
        $this->assertSame('0.00', $byNo['MO20260813-001']['achievement_rate']); // 完工 0 → 达成率 0
        $this->assertSame('100.00', $byNo['MO20260813-002']['yield_rate']);
    }

    public function test_production_filters_by_plan_date_and_product(): void
    {
        // 边界路径：plan_date 闭区间窗口 + product_id 筛选
        $fin = $this->makeProduct('FIN-A', 'finished', '成品');
        $fin2 = $this->makeProduct('FIN-B', 'finished', '成品');
        $this->makeOrder($fin, 'MO20260813-001', '10', '2026-07-31'); // 窗口外
        $this->makeOrder($fin, 'MO20260813-002', '10', '2026-08-01'); // 窗口内
        $this->makeOrder($fin2, 'MO20260813-003', '10', '2026-08-15'); // 窗口内另一成品

        $all = $this->service->production('2026-08-01', '2026-08-31');
        $this->assertCount(2, $all['items']);

        $filtered = $this->service->production('2026-08-01', '2026-08-31', $fin2->id);
        $this->assertCount(1, $filtered['items']);
        $this->assertSame('MO20260813-003', $filtered['items'][0]['order_no']);
    }

    public function test_production_totals_sum_full_window_independent_of_items_truncation(): void
    {
        // 正常路径：totals 对窗口内全部工单求和（KPI 口径=全区间，先于 items 截断计算——截断安全）
        $fin = $this->makeProduct('FIN-A', 'finished', '成品');
        $o1 = $this->makeOrder($fin, 'MO20260813-001', '10', '2026-08-13', 3, '8');
        $o2 = $this->makeOrder($fin, 'MO20260813-002', '20.50', '2026-08-14', 3, '20.50');
        $this->makeReport($o1, '7', '1', '2.00');   // 合格 7 不良 1
        $this->makeReport($o2, '18', '2', '3.00');  // 合格 18 不良 2

        $res = $this->service->production('2026-08-01', '2026-08-31');

        $this->assertSame(2, $res['totals']['order_count']);
        $this->assertSame('30.50', $res['totals']['total_plan']); // 10 + 20.50
        $this->assertSame('28.50', $res['totals']['total_completed']); // 8 + 20.50
        $this->assertSame('25', $res['totals']['total_qualified']); // 7 + 18（剥离 '.00' 尾零）
        $this->assertSame('3', $res['totals']['total_defective']); // 1 + 2
        $this->assertFalse($res['truncated']);
    }

    public function test_production_excludes_draft_documents_from_material_used(): void
    {
        // 边界路径：草稿领料不参与耗用（仅已审核单据；审核才写流水）
        $fin = $this->makeProduct('FIN-A', 'finished', '成品');
        $mat = $this->makeProduct('MAT-A', 'raw_material', '原材料');
        $o = $this->makeOrder($fin, 'MO20260813-001', '10', '2026-08-13');
        $this->makePick($o, $mat, '20.00');
        // 草稿领料单（status=0）：不计入耗用
        $draft = PickList::create([
            'no' => 'PL20260813-999', 'order_id' => $o->id, 'status' => 0, 'issue_status' => 0,
            'warehouse_id' => $this->warehouse->id, 'location_id' => $this->location->id,
        ]);
        PickListItem::create([
            'pick_id' => $draft->id, 'product_id' => $mat->id,
            'required_qty' => '5.00', 'pick_qty' => '5.00', 'issued_qty' => '0',
        ]);

        $res = $this->service->production('2026-08-01', '2026-08-31');
        $this->assertSame('20.00', $res['items'][0]['material_used'][0]['used_qty']);
    }

    // —— purchaseSales 用例 ——

    public function test_purchase_sales_month_sums_approved_documents_and_converts_to_yuan(): void
    {
        // 正常路径：已审核单据金额合计（分→元 2 位字符串）、数量合计、按审核时间分桶；草稿不计
        // 种子无供应商 → 自建（supplier_id 外键）
        $sup = Supplier::create(['name' => '供应商A', 'code' => 'SUP-A', 'status' => 1]);
        $p = $this->makeProduct('MAT-A', 'raw_material', '原材料');
        $cust = Customer::create([
            'name' => '客户A', 'code' => 'CUST-A', 'status' => 1,
        ]);
        // 已审核采购入库：金额 12345 分 = 123.45 元（8 月）
        $in1 = PurchaseInbound::create([
            'no' => 'PI20260813-001', 'supplier_id' => $sup->id,
            'warehouse_id' => $this->warehouse->id, 'location_id' => $this->location->id,
            'status' => 1, 'total_amount' => 12345, 'inbound_at' => '2026-08-10 10:00:00',
        ]);
        PurchaseInboundItem::create([
            'inbound_id' => $in1->id, 'product_id' => $p->id,
            'quantity' => 10, 'price' => 1000, 'amount' => 10000,
        ]);
        // 草稿采购入库：不计入
        PurchaseInbound::create([
            'no' => 'PI20260813-002', 'supplier_id' => $sup->id,
            'warehouse_id' => $this->warehouse->id, 'location_id' => $this->location->id,
            'status' => 0, 'total_amount' => 99999,
        ]);
        // 已审核销售出库：金额 5000 分 = 50.00 元（8 月）
        $out = SalesOutbound::create([
            'no' => 'SOUT20260813-001', 'customer_id' => $cust->id,
            'warehouse_id' => $this->warehouse->id, 'location_id' => $this->location->id,
            'status' => 1, 'total_amount' => 5000, 'outbound_at' => '2026-08-20 10:00:00',
        ]);
        SalesOutboundItem::create([
            'outbound_id' => $out->id, 'product_id' => $p->id,
            'quantity' => 4, 'price' => 1250, 'amount' => 5000,
        ]);

        $res = $this->service->purchaseSales('2026-08-01', '2026-08-31', 'month');

        $this->assertCount(1, $res['items']);
        $row = $res['items'][0];
        $this->assertSame('2026-08', $row['period']);
        $this->assertSame('123.45', $row['purchase_amount']);
        $this->assertSame('50.00', $row['sales_amount']);
        $this->assertSame('10.00', $row['purchase_qty']);
        $this->assertSame('4.00', $row['sales_qty']);
        $this->assertSame('123.45', $res['totals']['purchase_amount']);
        $this->assertSame('50.00', $res['totals']['sales_amount']);
        $this->assertFalse($res['truncated']);
    }

    public function test_purchase_sales_day_granularity_and_closed_interval(): void
    {
        // 边界路径：日粒度拆分 + 审核时间闭区间（跨月边界：8/31 含、9/1 不含）
        $sup = Supplier::create(['name' => '供应商A', 'code' => 'SUP-A', 'status' => 1]);
        PurchaseInbound::create([
            'no' => 'PI20260813-001', 'supplier_id' => $sup->id,
            'warehouse_id' => $this->warehouse->id, 'location_id' => $this->location->id,
            'status' => 1, 'total_amount' => 10000, 'inbound_at' => '2026-08-31 23:59:59',
        ]);
        PurchaseInbound::create([
            'no' => 'PI20260813-002', 'supplier_id' => $sup->id,
            'warehouse_id' => $this->warehouse->id, 'location_id' => $this->location->id,
            'status' => 1, 'total_amount' => 99900, 'inbound_at' => '2026-09-01 00:00:00',
        ]);

        $res = $this->service->purchaseSales('2026-08-01', '2026-08-31', 'day');

        $this->assertCount(1, $res['items']);
        $this->assertSame('2026-08-31', $res['items'][0]['period']);
        $this->assertSame('100.00', $res['totals']['purchase_amount']);
        $this->assertSame('0', $res['totals']['sales_amount']);
        $this->assertSame('0', $res['totals']['sales_qty']);
    }

    public function test_purchase_sales_empty_range_returns_empty_items_and_zero_totals(): void
    {
        // 边界路径：无数据区间 → items 空、totals 全 0
        $res = $this->service->purchaseSales('2099-01-01', '2099-01-31', 'day');
        $this->assertSame([], $res['items']);
        $this->assertSame('0', $res['totals']['purchase_amount']);
        $this->assertSame('0', $res['totals']['sales_amount']);
    }
}
