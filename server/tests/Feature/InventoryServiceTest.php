<?php

// 库存引擎测试：核心不变式（事务双写/恒等式/超卖拒绝/首次入库建行/冗余同步）100% 覆盖

namespace Tests\Feature;

use App\Exceptions\InventoryException;
use App\Models\Category;
use App\Models\InventoryBalance;
use App\Models\InventoryMovement;
use App\Models\Location;
use App\Models\Product;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class InventoryServiceTest extends TestCase
{
    use RefreshDatabase;

    private InventoryService $svc;

    private Product $product;

    private Warehouse $warehouse;

    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = app(InventoryService::class);
        $cat = Category::create(['name' => '原材料', 'parent_id' => 0]);
        $unit = Unit::create(['name' => '个', 'code' => 'pc']);
        // 商品含安全上下限（验证冗余同步）
        $this->product = Product::create([
            'name' => '测试铝材', 'code' => 'MAT-001', 'type' => 'raw_material',
            'category_id' => $cat->id, 'unit_id' => $unit->id, 'safety_min' => 50, 'safety_max' => 500, 'status' => 1,
        ]);
        $this->warehouse = Warehouse::create(['name' => '主仓', 'code' => 'WH01', 'status' => 1]);
        $this->location = Location::create([
            'warehouse_id' => $this->warehouse->id,
            'name' => 'A-01',
            'code' => 'A-01',
            'status' => 1,
        ]);
    }

    // 组装单笔流水入参
    private function movement(array $overrides = []): array
    {
        return array_merge([
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouse->id,
            'location_id' => $this->location->id,
            'direction' => 1,
            'quantity' => 100,
            'source_type' => 'purchase_inbound',
            'source_id' => 1,
            'source_no' => 'PO20260812-001',
        ], $overrides);
    }

    public function test_inbound_writes_movement_and_updates_balance(): void
    {
        // 正常路径：入库在事务内双写（流水 + 余额）——核心不变式 1
        $this->svc->apply([$this->movement()]);
        $this->assertDatabaseHas('inventory_balances', ['product_id' => $this->product->id, 'quantity' => '100.00']);
        $this->assertDatabaseHas('inventory_movements', [
            'product_id' => $this->product->id, 'direction' => 1, 'quantity' => '100.00', 'balance_after' => '100.00',
            'source_type' => 'purchase_inbound', 'source_no' => 'PO20260812-001',
        ]);
    }

    public function test_outbound_decreases_balance(): void
    {
        // 正常路径：出库余额减少且流水快照正确
        $this->svc->apply([$this->movement()]);
        $this->svc->apply([$this->movement([
            'direction' => -1,
            'quantity' => 40,
            'source_type' => 'pick',
            'source_id' => 2,
            'source_no' => 'PL20260812-001',
        ])]);
        $this->assertDatabaseHas('inventory_balances', ['quantity' => '60.00']);
        $this->assertDatabaseHas('inventory_movements', [
            'direction' => -1,
            'quantity' => '40.00',
            'balance_after' => '60.00',
        ]);
    }

    public function test_outbound_exceeding_balance_rolls_back(): void
    {
        // 异常路径：出库超卖抛异常且事务回滚（无流水残留）——余额不允许为负
        $this->svc->apply([$this->movement(['quantity' => 50])]);
        try {
            $this->svc->apply([$this->movement([
                'direction' => -1,
                'quantity' => 60,
                'source_type' => 'sales_outbound',
                'source_id' => 2,
                'source_no' => 'SO20260812-001',
            ])]);
            $this->fail('出库超卖应抛出异常');
        } catch (InventoryException $e) {
            $this->assertStringContainsString('库存不足', $e->getMessage());
        }
        // 回滚验证：余额仍 50，流水仍只有入库那条
        $this->assertDatabaseHas('inventory_balances', ['quantity' => '50.00']);
        $this->assertSame(1, InventoryMovement::count());
    }

    public function test_balance_equals_sum_of_directions(): void
    {
        // 核心不变式 2：余额恒等于全部流水 direction*quantity 之和
        $this->svc->apply([$this->movement(['quantity' => 100])]);
        $this->svc->apply([$this->movement([
            'direction' => -1,
            'quantity' => 30,
            'source_type' => 'pick',
            'source_id' => 2,
            'source_no' => 'PL1',
        ])]);
        $this->svc->apply([$this->movement(['quantity' => 20, 'source_id' => 3, 'source_no' => 'PO2'])]);
        $sum = InventoryMovement::sum(DB::raw('direction * quantity'));
        $balance = InventoryBalance::first();
        $this->assertSame(90.0, (float) $balance->quantity);
        $this->assertSame(90.0, (float) $sum);
    }

    public function test_outbound_without_balance_row_rejected(): void
    {
        // 异常路径：余额行不存在直接出库被拒
        $this->expectException(InventoryException::class);
        $this->svc->apply([$this->movement([
            'direction' => -1,
            'quantity' => 5,
            'source_type' => 'sales_outbound',
            'source_id' => 1,
            'source_no' => 'SO1',
        ])]);
    }

    public function test_first_inbound_creates_row_then_updates(): void
    {
        // 正常路径：首次入库创建余额行，再次入库复用同一行
        $this->svc->apply([$this->movement(['quantity' => 10, 'source_no' => 'PO1'])]);
        $this->svc->apply([$this->movement(['quantity' => 5, 'source_id' => 2, 'source_no' => 'PO2'])]);
        $this->assertSame(1, InventoryBalance::count());
        $this->assertSame(15.0, (float) InventoryBalance::first()->quantity);
        $this->assertSame(2, InventoryMovement::count());
        $this->assertSame('15.00', InventoryBalance::first()->quantity);
    }

    public function test_safety_limits_synced_from_product(): void
    {
        // 正常路径：余额冗余上下限自商品同步
        $this->svc->apply([$this->movement()]);
        $balance = InventoryBalance::first();
        $this->assertSame('50.00', $balance->safety_min);
        $this->assertSame('500.00', $balance->safety_max);
    }

    public function test_multiple_movements_rollback_together(): void
    {
        // 异常路径：多笔一次提交，任一失败整体回滚（无部分成功）
        try {
            $this->svc->apply([
                $this->movement(['quantity' => 100, 'source_no' => 'PO1']),
                $this->movement([
                    'direction' => -1,
                    'quantity' => 999,
                    'source_type' => 'sales_outbound',
                    'source_id' => 2,
                    'source_no' => 'SO1',
                ]),
            ]);
            $this->fail('第二笔超卖应整体回滚');
        } catch (InventoryException $e) {
            // 预期异常
        }
        $this->assertSame(0, InventoryBalance::count());
        $this->assertSame(0, InventoryMovement::count());
    }

    public function test_operator_id_recorded_in_movement(): void
    {
        // 正常路径：操作人写入流水
        $user = User::create(['name' => '操作员', 'username' => 'op1', 'password' => 'admin123', 'status' => 1]);
        $this->svc->apply([$this->movement()], $user->id);
        $this->assertDatabaseHas('inventory_movements', ['operator_id' => $user->id]);
    }
}
