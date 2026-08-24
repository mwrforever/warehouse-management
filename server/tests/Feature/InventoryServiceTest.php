<?php

// 库存引擎测试：核心不变式（事务双写/恒等式/超卖拒绝/首次入库建行/冗余同步/锁序规范化）100% 覆盖

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

    public function test_decimal_accumulation_stays_exact_with_bcmath_not_float(): void
    {
        // D-3 等价性护栏：0.1 + 0.2 的 IEEE754 浮点和为 0.30000000000000004（MySQL decimal 列与
        // 模型 set cast 双重收敛后落库值正确）；bcmath 字符串化后同一累加必须构造性精确等于 0.30，
        // 且恰好充足（0.30 出 0.30）无浮点误差窗口——引擎口径切换前后该不变式不可破
        $this->svc->apply([$this->movement(['quantity' => '0.1', 'source_no' => 'PO1'])]);
        $this->svc->apply([$this->movement(['quantity' => '0.2', 'source_id' => 2, 'source_no' => 'PO2'])]);

        $this->assertDatabaseHas('inventory_balances', [
            'product_id' => $this->product->id,
            'quantity' => '0.30',
        ]);
        $this->assertDatabaseHas('inventory_movements', [
            'source_no' => 'PO2',
            'balance_after' => '0.30',
        ]);

        // 边界：构造性 0.30 余额恰好出库 0.30——bccomp 判充足性应放行且余额精确归零
        $this->svc->apply([$this->movement([
            'direction' => -1,
            'quantity' => '0.3',
            'source_type' => 'sales_outbound',
            'source_id' => 3,
            'source_no' => 'SO1',
        ])]);
        $this->assertDatabaseHas('inventory_balances', ['quantity' => '0.00']);
    }

    public function test_insufficient_message_renders_quantity_in_two_decimal_scale(): void
    {
        // D-3 口径契约：不足消息中的出库量统一两位小数（与当前余额的 decimal cast 口径一致）；
        // 浮点引擎渲染「出库 60」，bcmath 归一化后必须为「出库 60.00」
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
            $this->assertStringContainsString('出库 60.00', $e->getMessage());
        }
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

    public function test_apply_locks_rows_in_normalized_ascending_order_regardless_of_input_order(): void
    {
        // B-3 锁序规范化：乱序传入的 movements 必须按 (product_id, warehouse_id, location_id) 升序加锁——
        // 跨单据并发审核（如采购入库 [P1,P2] 与退料/盘点 [P2,P1] 同仓同位）乱序交叉持锁互等会成
        // InnoDB 死锁环（1213 回滚一方，败方 500）；本地 SQLite 无法复现行锁死锁语义，
        // 以「流水落库顺序 == 加锁顺序」间接验证（applyOne 先锁后写，自增 id 序即加锁序的落库投影）
        $mkProduct = fn (string $code) => Product::create([
            'name' => '锁序商品'.$code, 'code' => $code, 'type' => 'raw_material',
            'category_id' => $this->product->category_id, 'unit_id' => $this->product->unit_id, 'status' => 1,
        ]);
        $p2 = $mkProduct('MAT-002');
        $w2 = Warehouse::create(['name' => '副仓', 'code' => 'WH02', 'status' => 1]);
        $l2 = Location::create([
            'warehouse_id' => $this->warehouse->id, 'name' => 'A-02', 'code' => 'A-02', 'status' => 1,
        ]);
        $w2l1 = Location::create(['warehouse_id' => $w2->id, 'name' => 'B-01', 'code' => 'B-01', 'status' => 1]);
        // 四条流水覆盖三级排序键：P1 组内 warehouse 升序（W1 先于 W2）、W1 组内 location 升序（L1 先于 L2）、
        // 组间 product 升序（P1 全部先于 P2）
        $a = $this->movement(['quantity' => 10]); // (P1, W1, L1)
        $d = $this->movement(['location_id' => $l2->id, 'quantity' => 40]); // (P1, W1, L2)
        // (P1, W2, B-01)
        $c = $this->movement(['warehouse_id' => $w2->id, 'location_id' => $w2l1->id, 'quantity' => 30]);
        $b = $this->movement(['product_id' => $p2->id, 'quantity' => 20]); // (P2, W1, L1)
        // 完全逆序传入（与期望加锁序相反，最大化模拟乱序调用方）
        $this->svc->apply([$b, $c, $d, $a]);
        // 断言：流水 id 升序（即加锁序）为三元组字典升序 [A, D, C, B]
        $key = fn (array $m) => $m['product_id'].':'.$m['warehouse_id'].':'.$m['location_id'];
        $this->assertSame(
            [$key($a), $key($d), $key($c), $key($b)],
            InventoryMovement::query()->orderBy('id')->get()
                ->map(fn (InventoryMovement $m) => $m->product_id.':'.$m->warehouse_id.':'.$m->location_id)
                ->all()
        );
        // 排序只改变处理序不改变业务结果：四行余额数量各自正确（防明细串行错位）
        $this->assertSame(4, InventoryBalance::count());
        foreach ([[$a, '10.00'], [$d, '40.00'], [$c, '30.00'], [$b, '20.00']] as [$m, $qty]) {
            $this->assertDatabaseHas('inventory_balances', [
                'product_id' => $m['product_id'],
                'warehouse_id' => $m['warehouse_id'],
                'location_id' => $m['location_id'],
                'quantity' => $qty,
            ]);
        }
    }
}
