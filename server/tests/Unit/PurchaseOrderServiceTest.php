<?php

// 采购订单服务单测（D-20）：金额 bcmath 精确到分 + 入库后订单状态重算语义
// lineAmount/calculateTotal 为纯计算直测；syncStatus 依赖 Eloquent 持久化
// （firstOrFail/items/save），按 RoutingServiceTest 惯例用 RefreshDatabase + sqlite 内存库直测

namespace Tests\Unit;

use App\Models\Category;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use App\Models\Unit;
use App\Services\PurchaseOrderService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PurchaseOrderServiceTest extends TestCase
{
    use RefreshDatabase;

    private PurchaseOrderService $service;

    private Supplier $supplier;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new PurchaseOrderService;
        // 主数据自建（sqlite 外键开启，不依赖种子）：分类/单位/商品/供应商
        $category = Category::create(['name' => '分类', 'code' => 'CAT-PO']);
        $unit = Unit::create(['name' => '个', 'code' => 'PCS-PO', 'status' => 1]);
        $this->supplier = Supplier::create(['name' => '供应商', 'code' => 'SUP-PO', 'status' => 1]);
        $this->product = Product::create([
            'name' => '原料', 'code' => 'RAW-PO', 'type' => 'raw_material',
            'category_id' => $category->id, 'unit_id' => $unit->id, 'status' => 1,
        ]);
    }

    /** 造订单辅助：指定初始状态（订单号唯一即可，金额由明细决定） */
    private function makeOrder(int $status): PurchaseOrder
    {
        return PurchaseOrder::create([
            'no' => 'PO-UT-'.uniqid(), 'supplier_id' => $this->supplier->id,
            'order_date' => now()->toDateString(), 'status' => $status, 'total_amount' => 0,
        ]);
    }

    /** 造订单行辅助：订购数量/单价（分）/已入库累计，行金额按数量×单价保持数据自洽 */
    private function makeItem(
        PurchaseOrder $order,
        string $quantity,
        string $price,
        string $receivedQty
    ): PurchaseOrderItem {
        return PurchaseOrderItem::create([
            'order_id' => $order->id, 'product_id' => $this->product->id,
            'quantity' => $quantity, 'price' => $price, 'received_qty' => $receivedQty,
            'amount' => $this->service->lineAmount($quantity, $price),
        ]);
    }

    #[Test]
    public function test_line_amount_multiplies_decimal_quantity_by_cent_price(): void
    {
        // 正常路径：数量 10.50 × 单价 1234 分 = 12957.00 分（2 位小数字符串，分单位禁浮点）
        $this->assertSame('12957.00', $this->service->lineAmount('10.50', '1234'));
    }

    #[Test]
    public function test_line_amount_keeps_exact_cent_precision(): void
    {
        // 边界路径：最小分位不丢精度——0.01 × 1 分 = 0.01；合法域内（数量 2 位小数 × 整数分单价）
        // 乘积至多 2 位小数，bcmul 截断与舍入无差异，金额精确到分无误差累积
        $this->assertSame('0.01', $this->service->lineAmount('0.01', '1'));
        $this->assertSame('382.50', $this->service->lineAmount('2.55', '150'));
    }

    #[Test]
    public function test_calculate_total_sums_lines_exact_to_cent(): void
    {
        // 正常路径：三行合计 12957.00 + 382.50 + 100.00 = 13439.50——bcadd 逐行累加，
        // 0.10 类数值（浮点下会漂移）合计仍精确到分
        $items = [
            ['quantity' => '10.50', 'price' => '1234'],
            ['quantity' => '2.55', 'price' => '150'],
            ['quantity' => '0.10', 'price' => '1000'],
        ];
        $this->assertSame('13439.50', $this->service->calculateTotal($items));
    }

    #[Test]
    public function test_calculate_total_empty_items_returns_zero(): void
    {
        // 边界路径：空明细合计为 '0'（初始累加值原样返回，对应无明细单据总额 0 的业务口径）
        $this->assertSame('0', $this->service->calculateTotal([]));
    }

    #[Test]
    public function test_sync_status_marks_completed_when_all_items_fully_received(): void
    {
        // 正常路径：已审核订单两行 received_qty 均等于订购数（2 位小数等值 bccomp 比较）→ 重算为已完成
        $order = $this->makeOrder(PurchaseOrder::STATUS_APPROVED);
        $this->makeItem($order, '10.00', '1234', '10.00');
        $this->makeItem($order, '2.55', '150', '2.55');

        $this->service->syncStatus($order->id);

        $this->assertSame(PurchaseOrder::STATUS_COMPLETED, $order->fresh()->status);
    }

    #[Test]
    public function test_sync_status_marks_partial_when_any_item_short(): void
    {
        // 正常路径：一行入满、一行未动 → 部分入库（任一行不足即不可判完成）
        $order = $this->makeOrder(PurchaseOrder::STATUS_APPROVED);
        $this->makeItem($order, '10.00', '1234', '10.00');
        $this->makeItem($order, '5.00', '150', '0.00');

        $this->service->syncStatus($order->id);

        $this->assertSame(PurchaseOrder::STATUS_PARTIAL, $order->fresh()->status);
    }

    #[Test]
    public function test_sync_status_advances_partial_to_completed_on_final_receipt(): void
    {
        // 正常路径：部分入库订单后续批次全部入满 → 已完成（重算可随入库批次前进，真实链路：
        // 第一批部分入库、第二批入满后由入库审核在事务内回写触发）
        $order = $this->makeOrder(PurchaseOrder::STATUS_PARTIAL);
        $this->makeItem($order, '10.00', '1234', '10.00');
        $this->makeItem($order, '5.00', '150', '5.00');

        $this->service->syncStatus($order->id);

        $this->assertSame(PurchaseOrder::STATUS_COMPLETED, $order->fresh()->status);
    }

    #[Test]
    public function test_sync_status_leaves_draft_and_closed_orders_untouched(): void
    {
        // 边界路径：重算仅作用于 已审核/部分入库——草稿未审核无入库语义、关闭单不可再动，
        // 即使全部行已入满，状态也不被扰动（防御性守卫，防止旁路改写非流转中状态）
        foreach ([PurchaseOrder::STATUS_DRAFT, PurchaseOrder::STATUS_CLOSED] as $frozen) {
            $order = $this->makeOrder($frozen);
            $this->makeItem($order, '10.00', '1234', '10.00');
            $this->service->syncStatus($order->id);
            $this->assertSame($frozen, $order->fresh()->status, "状态 {$frozen} 不应被重算扰动");
        }
    }

    #[Test]
    public function test_sync_status_null_order_id_is_noop(): void
    {
        // 边界路径：独立入库单无来源订单（order_id 为 null），上游以 null 调用时直接返回——
        // 不查库、不抛错，独立入库不应影响任何订单状态
        $this->expectNotToPerformAssertions();
        $this->service->syncStatus(null);
    }

    #[Test]
    public function test_sync_status_missing_order_throws_model_not_found(): void
    {
        // 异常路径：订单不存在（并发下已被删除等场景）——firstOrFail 抛 ModelNotFoundException，
        // 由全局异常处理器转 404，上游入库审核事务随之回滚（不得静默吞掉半途状态回写）
        try {
            $this->service->syncStatus(99999);
            $this->fail('应抛订单不存在异常');
        } catch (ModelNotFoundException $e) {
            $this->assertSame(PurchaseOrder::class, $e->getModel());
        }
    }
}
