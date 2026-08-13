<?php

// 报表聚合服务测试（A：库存报表/出入库汇总）：聚合口径=数据一致性核心路径 100% 覆盖
// 数据一律测试内自建（不依赖 InventorySeeder 数值），插外键数据先建真实主数据（sqlite 外键开启）

namespace Tests\Feature;

use App\Models\Category;
use App\Models\InventoryBalance;
use App\Models\InventoryMovement;
use App\Models\Location;
use App\Models\Product;
use App\Models\PurchaseInbound;
use App\Models\PurchaseInboundItem;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\Warehouse;
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
}
