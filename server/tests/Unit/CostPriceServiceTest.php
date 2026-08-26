<?php

// 成本价估算服务单测（D-20 + R2）：「每商品最近一次已审核采购入库单价」全项目唯一口径（分单位整数）
//
// 可测性说明：build() 为 private 且无异常分支（无数据即空 map），无法也不应直调——
// 经公有 latestPriceMap() 覆盖（缓存未命中路径即执行 build，测试环境 CACHE_STORE=array
// 每用例全新缓存）；依赖 Eloquent 查询（whereHas 过滤审核状态 + 明细表全序扫描），
// 按 RoutingServiceTest 惯例用 RefreshDatabase + sqlite 内存库直测。
// 注意：build 的排序键是明细表 created_at/id（复合索引全序），非入库单头时间，用例须控制明细行时间戳。

namespace Tests\Unit;

use App\Models\Category;
use App\Models\Location;
use App\Models\Product;
use App\Models\PurchaseInbound;
use App\Models\PurchaseInboundItem;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\Warehouse;
use App\Services\CostPriceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CostPriceServiceTest extends TestCase
{
    use RefreshDatabase;

    private CostPriceService $service;

    private Supplier $supplier;

    private Warehouse $warehouse;

    private Location $location;

    private Product $matA;

    private Product $matB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new CostPriceService;
        // 主数据自建（sqlite 外键开启，不依赖种子）：分类/单位/两商品/供应商/仓库/库位
        $category = Category::create(['name' => '原材料', 'code' => 'CAT-CP']);
        $unit = Unit::create(['name' => '个', 'code' => 'PCS-CP', 'status' => 1]);
        $makeProduct = fn (string $code) => Product::create([
            'name' => $code, 'code' => $code, 'type' => 'raw_material',
            'category_id' => $category->id, 'unit_id' => $unit->id, 'status' => 1,
        ]);
        $this->matA = $makeProduct('MAT-CP-A');
        $this->matB = $makeProduct('MAT-CP-B');
        $this->supplier = Supplier::create(['name' => '供应商', 'code' => 'SUP-CP', 'status' => 1]);
        $this->warehouse = Warehouse::create(['name' => '主仓', 'code' => 'WH-CP', 'status' => 1]);
        $this->location = Location::create([
            'warehouse_id' => $this->warehouse->id, 'name' => 'A-01', 'code' => 'LOC-CP', 'status' => 1,
        ]);
    }

    /** 造入库单头辅助：指定审核状态（明细时间戳才是成本价新旧依据，单头时间不参与） */
    private function makeInbound(string $no, int $status): PurchaseInbound
    {
        return PurchaseInbound::create([
            'no' => $no, 'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id, 'location_id' => $this->location->id,
            'status' => $status, 'total_amount' => 0,
        ]);
    }

    /** 造入库明细辅助：单价（分单位整数）+ 可选明细行创建时间（成本价排序键：created_at/id） */
    private function makeItem(
        PurchaseInbound $inbound,
        Product $product,
        int $price,
        ?string $createdAt = null
    ): PurchaseInboundItem {
        $item = PurchaseInboundItem::create([
            'inbound_id' => $inbound->id, 'product_id' => $product->id,
            'quantity' => 1, 'price' => $price, 'amount' => $price,
        ]);
        if ($createdAt !== null) {
            // fillable 不含 created_at → 属性赋值后 save（DashboardServiceTest 同惯例）
            $item->created_at = $createdAt;
            $item->save();
        }

        return $item;
    }

    #[Test]
    public function test_latest_price_map_keeps_latest_approved_price_per_product(): void
    {
        // 正常路径：商品 A 先后两张已审核入库单（昨日 150 分 → 今日 250 分）→ 取更晚的 250 分；
        // 商品 B 仅一张 → 各自生效；price 列 bigint + integer cast，map 值为分单位整数
        $first = $this->makeInbound('PI-CP-001', PurchaseInbound::STATUS_APPROVED);
        $this->makeItem($first, $this->matA, '150', now()->subDay()->toDateTimeString());
        $second = $this->makeInbound('PI-CP-002', PurchaseInbound::STATUS_APPROVED);
        $this->makeItem($second, $this->matA, '250', now()->toDateTimeString());
        $this->makeItem($second, $this->matB, '333', now()->toDateTimeString());

        $map = $this->service->latestPriceMap();

        $this->assertSame(250, $map[$this->matA->id]);
        $this->assertSame(333, $map[$this->matB->id]);
    }

    #[Test]
    public function test_latest_price_map_excludes_draft_inbound_price(): void
    {
        // 边界路径（bug #7 口径回归）：草稿入库单 store 即写明细且可改删——即使比已审核单更新，
        // 其单价也不得参与成本价（否则草稿改删导致估算金额跳变），仍取已审核的 150 分
        $approved = $this->makeInbound('PI-CP-001', PurchaseInbound::STATUS_APPROVED);
        $this->makeItem($approved, $this->matA, '150', now()->subDay()->toDateTimeString());
        $draft = $this->makeInbound('PI-CP-002', PurchaseInbound::STATUS_DRAFT);
        $this->makeItem($draft, $this->matA, '999', now()->toDateTimeString());

        $map = $this->service->latestPriceMap();

        $this->assertSame(150, $map[$this->matA->id]);
    }

    #[Test]
    public function test_latest_price_map_same_timestamp_breaks_tie_by_id(): void
    {
        // 边界路径：同一秒两张已审核单（明细 created_at 相同）→ 按明细 id 升序末条生效
        // （后入库者覆盖，与索引全序 (product_id, created_at, id) 的决胜语义一致）
        $ts = now()->setTime(10, 0, 0)->toDateTimeString();
        $first = $this->makeInbound('PI-CP-001', PurchaseInbound::STATUS_APPROVED);
        $this->makeItem($first, $this->matA, '150', $ts);
        $second = $this->makeInbound('PI-CP-002', PurchaseInbound::STATUS_APPROVED);
        $this->makeItem($second, $this->matA, '250', $ts);

        $map = $this->service->latestPriceMap();

        $this->assertSame(250, $map[$this->matA->id]);
    }

    #[Test]
    public function test_latest_price_map_empty_without_approved_inbounds(): void
    {
        // 边界路径：空库与仅草稿入库两种场景均返回空 map（调用方 isset 判定 → 商品无成本价，
        // 对应仪表盘总值 null 而非 ¥0 的展示口径）；flush 后强制走 build 重建仍为空
        $this->assertSame([], $this->service->latestPriceMap());

        $draft = $this->makeInbound('PI-CP-001', PurchaseInbound::STATUS_DRAFT);
        $this->makeItem($draft, $this->matA, '150', now()->toDateTimeString());
        $this->service->flush();

        $this->assertSame([], $this->service->latestPriceMap());
    }

    #[Test]
    public function test_latest_price_map_serves_cache_until_flush(): void
    {
        // 缓存失效契约：审核是价格集合唯一变化点——首次调用建缓存（150 分）后直插更晚的
        // 已审核明细（模拟绕过服务层的数据变更），未 flush 前读缓存得旧价；flush 后重建
        // 反映新价（真实链路：采购入库单 approve 成功路径调用 flush）
        $first = $this->makeInbound('PI-CP-001', PurchaseInbound::STATUS_APPROVED);
        $this->makeItem($first, $this->matA, '150', now()->subDay()->toDateTimeString());
        $this->assertSame(150, $this->service->latestPriceMap()[$this->matA->id]);

        $second = $this->makeInbound('PI-CP-002', PurchaseInbound::STATUS_APPROVED);
        $this->makeItem($second, $this->matA, '250', now()->toDateTimeString());

        // 未失效：仍读缓存旧价（150 分）——证明缓存生效而非每次全量扫描
        $this->assertSame(150, $this->service->latestPriceMap()[$this->matA->id]);

        // 失效后重建：250 分成为最新价
        $this->service->flush();
        $this->assertSame(250, $this->service->latestPriceMap()[$this->matA->id]);
    }
}
