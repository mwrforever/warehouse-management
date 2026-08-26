<?php

// 委外回收品入库一致性（1529）+ 余料退回接口测试：分批回收推进、超量/回收品不一致拦截、
// 退回恢复库存/回写/关闭、超退与状态拦截（核心路径 100%）

namespace Tests\Feature;

use App\Models\InventoryBalance;
use App\Models\Location;
use App\Models\OutsourcingOrder;
use App\Models\OutsourcingReceipt;
use App\Models\OutsourcingReturn;
use App\Models\Process;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WorkOrderOperation;
use App\Services\InventoryService;
use Database\Seeders\DocumentNumberConfigSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\DagOrderFactory;
use Tests\TestCase;

class OutsourcingReturnTest extends TestCase
{
    use DagOrderFactory;
    use RefreshDatabase;

    private string $token;

    private User $admin;

    private Warehouse $wh;

    private Location $b01;

    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        // 编号规则配置种子（Spec 2）：单据号按配置生成 OS/OSR/ORT 等业务前缀
        $this->seed(DocumentNumberConfigSeeder::class);
        $role = Role::create(['name' => '管理员', 'code' => 'admin']);
        $this->admin = User::create(['name' => '管理员', 'username' => 'admin', 'password' => 'admin123', 'status' => 1]);
        $this->admin->roles()->sync([$role->id]);
        $this->token = $this->admin->createToken('api')->plainTextToken;

        $this->wh = Warehouse::create(['name' => '主仓', 'code' => 'WH01', 'status' => 1]);
        $this->b01 = Location::create(['warehouse_id' => $this->wh->id, 'name' => 'B-01', 'code' => 'B-01', 'status' => 1]);
        $this->supplier = Supplier::create(['name' => '测试供应商', 'code' => 'SUP-001', 'status' => 1]);
        // DagOrderFactory 依赖的基线字典（单位 pc / 工序 CUT）
        Unit::create(['name' => '个', 'code' => 'pc']);
        Process::create(['name' => '下料', 'code' => 'CUT', 'sort' => 1, 'status' => 1]);
    }

    // OP30 委外载荷（数量 6；items=节点输入材料折算应发：原料×2/半成品B×1 单位用量 → 12/6）
    private function payload(array $dag): array
    {
        ['order' => $order, 'ops' => $ops, 'raw' => $raw, 'semiB' => $semiB, 'unit' => $unit] = $dag;

        return [
            'order_id' => $order->id,
            'operation_id' => $ops['OP30']->id,
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->wh->id,
            'location_id' => $this->b01->id,
            'quantity' => 6,
            'items' => [
                ['material_id' => $raw->id, 'required_qty' => 12, 'unit_id' => $unit->id],
                ['material_id' => $semiB->id, 'required_qty' => 6, 'unit_id' => $unit->id],
            ],
        ];
    }

    // 组件基线注入（同仓同库位 B-01：原料 12 = 应发 12、半成品B 6 = 应发 6——发出后两组件归零）
    private function seedComponents(array $dag): void
    {
        ['raw' => $raw, 'semiB' => $semiB] = $dag;
        app(InventoryService::class)->apply([
            ['product_id' => $raw->id, 'warehouse_id' => $this->wh->id, 'location_id' => $this->b01->id,
                'direction' => 1, 'quantity' => 12, 'source_type' => 'purchase_inbound', 'source_id' => 0,
                'source_no' => 'SEED', 'remark' => '测试基线'],
            ['product_id' => $semiB->id, 'warehouse_id' => $this->wh->id, 'location_id' => $this->b01->id,
                'direction' => 1, 'quantity' => 6, 'source_type' => 'purchase_inbound', 'source_id' => 0,
                'source_no' => 'SEED', 'remark' => '测试基线'],
        ]);
    }

    // 经 API 建委外单（草稿）并返回单号
    private function createOutsourcing(array $payload): string
    {
        $res = $this->withToken($this->token)->postJson('/api/v1/production/outsourcings', $payload);
        $res->assertJsonPath('code', 0);

        return $res->json('data.no');
    }

    // 已发出委外单辅助：OP30 委外单（6）×组件基线 + 审核发出（组件按应发全额扣减归零，spec 5 §13.1）
    private function approvedOutsourcing(array $dag): OutsourcingOrder
    {
        $this->seedComponents($dag);
        $no = $this->createOutsourcing($this->payload($dag));
        $os = OutsourcingOrder::where('no', $no)->firstOrFail();
        $this->withToken($this->token)->postJson("/api/v1/production/outsourcings/{$os->id}/approve")
            ->assertJsonPath('code', 0);

        return $os;
    }

    // 对工序报合格数量并断言成功（DAG 推进用例的步进动作，同 OutsourcingDagTest）
    private function report(WorkOrderOperation $op, string $qty): void
    {
        $this->withToken($this->token)->postJson("/api/v1/production/operations/{$op->id}/reports", [
            'qualified_qty' => $qty,
        ])->assertJsonPath('code', 0);
    }

    // 组件余额读取（该委外仓位的余额行；无行=0，decimal 归一字符串——测试断言口径与实现 bcmath 一致）
    private function balanceOf(int $productId): string
    {
        $balance = InventoryBalance::where('product_id', $productId)->first();

        return $balance ? (string) $balance->quantity : '0';
    }

    // OUT-03：分批回收——回收品入半成品B（节点输出）库存、received_qty 累计、满量节点 DONE + 汇合点就绪推进
    // （注：Concerns/DagOrderFactory 实际 OP30 产出=半成品B，非早期设计稿「半成品C」，以工厂实际为准）
    public function test_receipt_partial_and_full_advances_node(): void
    {
        $dag = $this->dagOrder();
        ['ops' => $ops, 'raw' => $raw, 'semiB' => $semiB] = $dag;
        // 分支铺满：OP10 报满 → 三分支并行；OP20/OP40 报满 → 汇合点就绪（委外 OP30 未完成仍待开工）
        $this->report($ops['OP10'], '6');
        $this->report($ops['OP20'], '6');
        $this->report($ops['OP40'], '6');
        $this->assertSame(WorkOrderOperation::STATUS_PENDING, (int) $ops['OP50']->fresh()->status);
        $os = $this->approvedOutsourcing($dag);
        // 发出后两组件归零（原料/半成品B），回收品基线 0
        $this->assertSame('0.00', $this->balanceOf($raw->id));
        $this->assertSame('0.00', $this->balanceOf($semiB->id));
        // 第一批 3：回收品入半成品B 库存 0→3、received_qty 累计 3、状态未满（工序仍进行中）
        $this->withToken($this->token)->postJson("/api/v1/production/outsourcings/{$os->id}/receipts", [
            'quantity' => 3, 'warehouse_id' => $this->wh->id, 'location_id' => $this->b01->id,
        ])->assertJsonPath('code', 0);
        $this->assertSame('3.00', $this->balanceOf($semiB->id));
        // 回收只入回收品，原料不动
        $this->assertSame('0.00', $this->balanceOf($raw->id));
        $this->withToken($this->token)->getJson("/api/v1/production/outsourcings/{$os->id}")
            ->assertJsonPath('data.received_qty', '3.00');
        $this->assertSame(OutsourcingOrder::STATUS_APPROVED, (int) $os->fresh()->status);
        $this->assertSame(WorkOrderOperation::STATUS_RUNNING, (int) $ops['OP30']->fresh()->status);
        // 第二批 3 → 满量：委外单已回收 + 节点 DONE + 汇合点推进（全部前驱就绪）
        $this->withToken($this->token)->postJson("/api/v1/production/outsourcings/{$os->id}/receipts", [
            'quantity' => 3, 'warehouse_id' => $this->wh->id, 'location_id' => $this->b01->id,
        ])->assertJsonPath('code', 0);
        $this->assertSame('6.00', $this->balanceOf($semiB->id));
        $this->assertSame(OutsourcingOrder::STATUS_RECEIVED, (int) $os->fresh()->status);
        $this->assertSame(WorkOrderOperation::STATUS_DONE, (int) $ops['OP30']->fresh()->status);
        $this->assertSame(WorkOrderOperation::STATUS_RUNNING, (int) $ops['OP50']->fresh()->status);
        // 分批回收落库 2 条
        $this->assertDatabaseCount('outsourcing_receipts', 2);
    }

    // OUT-03：超量 1524 + 回收品不一致 1529（冒烟校验：提供 product_id 须等于节点输出）
    public function test_receipt_rejects_over_quantity_and_mismatch(): void
    {
        $dag = $this->dagOrder();
        ['semiB' => $semiB, 'semiC' => $semiC] = $dag;
        $os = $this->approvedOutsourcing($dag);
        // 传错误 product_id（半成品C ≠ 节点输出半成品B）→ 1529，无流水无回收单
        $this->withToken($this->token)->postJson("/api/v1/production/outsourcings/{$os->id}/receipts", [
            'quantity' => 2, 'product_id' => $semiC->id, 'warehouse_id' => $this->wh->id, 'location_id' => $this->b01->id,
        ])->assertJsonPath('code', 1529)
            ->assertJsonPath('message', '回收商品与委外工序产出不一致');
        $this->assertSame('0.00', $this->balanceOf($semiB->id));
        $this->assertDatabaseCount('outsourcing_receipts', 0);
        // 提供正确 product_id（=节点输出）→ 冒烟校验放行
        $this->withToken($this->token)->postJson("/api/v1/production/outsourcings/{$os->id}/receipts", [
            'quantity' => 2, 'product_id' => $semiB->id, 'warehouse_id' => $this->wh->id, 'location_id' => $this->b01->id,
        ])->assertJsonPath('code', 0);
        $this->assertSame('2.00', $this->balanceOf($semiB->id));
        // 再回收超量（累计 2+5 > 6）→ 1524 整体回滚
        $this->withToken($this->token)->postJson("/api/v1/production/outsourcings/{$os->id}/receipts", [
            'quantity' => 5, 'warehouse_id' => $this->wh->id, 'location_id' => $this->b01->id,
        ])->assertJsonPath('code', 1524)
            ->assertJsonPath('message', '回收数量超过委外数量');
        $this->assertSame('2.00', $this->balanceOf($semiB->id));
        $this->assertDatabaseCount('outsourcing_receipts', 1);
    }

    // OUT-05：余料退回——outsourcing_return 库存+、returned_qty 回写、全退后委外单已关闭
    public function test_return_restores_stock_and_closes(): void
    {
        $dag = $this->dagOrder();
        ['raw' => $raw, 'semiB' => $semiB] = $dag;
        $os = $this->approvedOutsourcing($dag);
        // 发出后组件归零（原料 12、半成品B 6）
        $this->assertSame('0.00', $this->balanceOf($raw->id));
        $this->assertSame('0.00', $this->balanceOf($semiB->id));
        $rawItem = $os->items()->where('material_id', $raw->id)->firstOrFail();
        $semiItem = $os->items()->where('material_id', $semiB->id)->firstOrFail();
        // 全退：两组件逐行退回 → 库存恢复 + returned_qty 回写 + 委外单自动关闭；单号 ORT 前缀
        $res = $this->withToken($this->token)->postJson("/api/v1/production/outsourcings/{$os->id}/returns", [
            'items' => [
                ['item_id' => $rawItem->id, 'quantity' => 12],
                ['item_id' => $semiItem->id, 'quantity' => 6],
            ],
            'warehouse_id' => $this->wh->id, 'location_id' => $this->b01->id, 'remark' => '退余料',
        ]);
        $res->assertJsonPath('code', 0)
            ->assertJsonPath('data.no', 'ORT'.date('YmdHi').'001');
        $this->assertMatchesRegularExpression('/^ORT\d{12}\d{3}$/', $res->json('data.no'));
        $this->assertSame('12.00', $this->balanceOf($raw->id));
        $this->assertSame('6.00', $this->balanceOf($semiB->id));
        // 退回单落库：创建即审核（status=1、returned_at 非空）；多行提交仅记首行（偏离记录③）
        $this->assertDatabaseHas('outsourcing_returns', [
            'outsourcing_id' => $os->id, 'material_id' => $raw->id, 'item_id' => $rawItem->id,
            'quantity' => '12.00', 'status' => OutsourcingReturn::STATUS_APPROVED,
        ]);
        $this->assertDatabaseCount('outsourcing_returns', 1);
        // 分量流水 outsourcing_return（每组件一条，source_no=退回单号、remark=余料退回）
        $this->assertDatabaseHas('inventory_movements', [
            'source_type' => 'outsourcing_return', 'source_no' => $res->json('data.no'),
            'product_id' => $raw->id, 'direction' => 1, 'quantity' => '12.00', 'balance_after' => '12.00',
            'remark' => '余料退回',
        ]);
        $this->assertDatabaseHas('inventory_movements', [
            'source_type' => 'outsourcing_return', 'source_no' => $res->json('data.no'),
            'product_id' => $semiB->id, 'direction' => 1, 'quantity' => '6.00', 'balance_after' => '6.00',
            'remark' => '余料退回',
        ]);
        // returned_qty 回写 = 应发（bcadd 累计）；全部组件退回完毕 → 委外单已关闭
        $this->assertSame('12.00', (string) $rawItem->fresh()->returned_qty);
        $this->assertSame('6.00', (string) $semiItem->fresh()->returned_qty);
        $this->assertSame(OutsourcingOrder::STATUS_CLOSED, (int) $os->fresh()->status);
        // 退回记录列表（按退回时间倒序；单行单据=首行物料）
        $this->withToken($this->token)->getJson("/api/v1/production/outsourcings/{$os->id}/returns")
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.items.0.no', 'ORT'.date('YmdHi').'001')
            ->assertJsonPath('data.items.0.material_name', '铝材')
            ->assertJsonPath('data.items.0.quantity', '12.00');
    }

    // PF-2 回归：先取号建单再写流水——回收/退回流水的 source_id/source_no 直接关联当次创建的
    // 回收单/退回单（与全项目「流水来源=承载单据本身」口径一致，如 purchase_inbound→入库单），
    // 不再有「空串占位 + UPDATE 回补」的中间态
    public function test_movements_source_linked_to_receipt_and_return_documents(): void
    {
        $dag = $this->dagOrder();
        ['raw' => $raw] = $dag;
        $os = $this->approvedOutsourcing($dag);
        // 全量回收：流水来源=当次回收单（source_id=回收单 id、source_no=回收单号）
        $receiptNo = $this->withToken($this->token)
            ->postJson("/api/v1/production/outsourcings/{$os->id}/receipts", [
                'quantity' => 6, 'warehouse_id' => $this->wh->id, 'location_id' => $this->b01->id,
            ])->assertJsonPath('code', 0)->json('data.no');
        $receipt = OutsourcingReceipt::where('no', $receiptNo)->firstOrFail();
        $this->assertDatabaseHas('inventory_movements', [
            'source_type' => 'outsourcing_in', 'source_id' => $receipt->id, 'source_no' => $receiptNo,
        ]);
        // 余料退回（原料 12）：流水来源=当次退回单（source_id=退回单 id、source_no=退回单号）
        $returnNo = $this->withToken($this->token)
            ->postJson("/api/v1/production/outsourcings/{$os->id}/returns", [
                'items' => [
                    ['item_id' => $os->items()->where('material_id', $raw->id)->firstOrFail()->id, 'quantity' => 12],
                ],
                'warehouse_id' => $this->wh->id, 'location_id' => $this->b01->id,
            ])->assertJsonPath('code', 0)->json('data.no');
        $return = OutsourcingReturn::where('no', $returnNo)->firstOrFail();
        $this->assertDatabaseHas('inventory_movements', [
            'source_type' => 'outsourcing_return', 'source_id' => $return->id, 'source_no' => $returnNo,
        ]);
    }

    // P-4 回归：全退判定复用事务早期已锁组件集合——修复早期锁定一次 + 全退判定 items()->get()
    // 再查一次，修复后 outsourcing_order_items 全程仅 1 次查询；全退自动关闭行为不变
    public function test_return_full_return_verdict_reuses_locked_items_single_query(): void
    {
        $dag = $this->dagOrder();
        ['raw' => $raw, 'semiB' => $semiB] = $dag;
        $os = $this->approvedOutsourcing($dag);
        $rawItem = $os->items()->where('material_id', $raw->id)->firstOrFail();
        $semiItem = $os->items()->where('material_id', $semiB->id)->firstOrFail();

        DB::enableQueryLog();
        $this->withToken($this->token)->postJson("/api/v1/production/outsourcings/{$os->id}/returns", [
            'items' => [
                ['item_id' => $rawItem->id, 'quantity' => 12],
                ['item_id' => $semiItem->id, 'quantity' => 6],
            ],
            'warehouse_id' => $this->wh->id, 'location_id' => $this->b01->id, 'remark' => '全退',
        ])->assertJsonPath('code', 0);
        $itemsQueries = collect(DB::getQueryLog())
            ->filter(fn (array $q) => str_contains($q['query'], 'from "outsourcing_order_items"')
                // 排除载荷校验 exists 规则的 count(*) 探查（每行载荷一次，与本修复无关）
                && ! str_contains($q['query'], 'count(*)'));
        DB::disableQueryLog();
        // 复用契约：组件行锁定（1 次）+ 全退判定复用已锁集合，items 表全程仅 1 次查询
        $this->assertCount(1, $itemsQueries);
        // 业务结果不变：全退后委外单自动关闭、returned_qty 回写
        $this->assertSame('12.00', (string) $rawItem->fresh()->returned_qty);
        $this->assertSame('6.00', (string) $semiItem->fresh()->returned_qty);
        $this->assertSame(OutsourcingOrder::STATUS_CLOSED, (int) $os->fresh()->status);
    }

    // OUT-05：超退拦截（422 已发未退）+ 草稿不可退回
    public function test_return_rejects_over_and_wrong_status(): void
    {
        $dag = $this->dagOrder();
        ['raw' => $raw, 'semiB' => $semiB] = $dag;
        // 先建草稿委外单（同节点已发出单存在时新建会被 1520 拦截，草稿用例先行）
        $draftNo = $this->createOutsourcing($this->payload($dag));
        $draft = OutsourcingOrder::where('no', $draftNo)->firstOrFail();
        // 草稿委外单不可退回 → 422（状态前置校验，草稿自身组件行作载荷）
        $this->withToken($this->token)->postJson("/api/v1/production/outsourcings/{$draft->id}/returns", [
            'items' => [['item_id' => $draft->items()->firstOrFail()->id, 'quantity' => 1]],
            'warehouse_id' => $this->wh->id, 'location_id' => $this->b01->id,
        ])->assertJsonPath('code', 422);
        $os = $this->approvedOutsourcing($dag);
        $rawItem = $os->items()->where('material_id', $raw->id)->firstOrFail();
        $semiItem = $os->items()->where('material_id', $semiB->id)->firstOrFail();
        // 超退：13 > 已发 12 → 422 整体回滚（余额不变、无退回单、状态不变）
        $this->withToken($this->token)->postJson("/api/v1/production/outsourcings/{$os->id}/returns", [
            'items' => [
                ['item_id' => $rawItem->id, 'quantity' => 13],
                ['item_id' => $semiItem->id, 'quantity' => 6],
            ],
            'warehouse_id' => $this->wh->id, 'location_id' => $this->b01->id,
        ])->assertJsonPath('code', 422)
            ->assertJsonPath('message', '退回数量超过已发未退数量');
        $this->assertSame('0.00', $this->balanceOf($raw->id));
        $this->assertDatabaseCount('outsourcing_returns', 0);
        $this->assertSame(OutsourcingOrder::STATUS_APPROVED, (int) $os->fresh()->status);
        // 部分退回原料 12（returned_qty 回写）→ 再退 1 超剩余（12−12=0）→ 422 累计口径拦截
        $this->withToken($this->token)->postJson("/api/v1/production/outsourcings/{$os->id}/returns", [
            'items' => [['item_id' => $rawItem->id, 'quantity' => 12]],
            'warehouse_id' => $this->wh->id, 'location_id' => $this->b01->id,
        ])->assertJsonPath('code', 0);
        $this->assertSame('12.00', $this->balanceOf($raw->id));
        $this->assertSame('12.00', (string) $rawItem->fresh()->returned_qty);
        $this->withToken($this->token)->postJson("/api/v1/production/outsourcings/{$os->id}/returns", [
            'items' => [['item_id' => $rawItem->id, 'quantity' => 1]],
            'warehouse_id' => $this->wh->id, 'location_id' => $this->b01->id,
        ])->assertJsonPath('code', 422)
            ->assertJsonPath('message', '退回数量超过已发未退数量');
        $this->assertDatabaseCount('outsourcing_returns', 1);
        // 半成品B 未退，委外单仍为已发出（未关闭）
        $this->assertSame(OutsourcingOrder::STATUS_APPROVED, (int) $os->fresh()->status);
    }

    // 修复轮 1：同一 item_id 提交两行 → 422「退回组件重复」（格式层查重，防逐行内存模型校验下
    // 累计退回超已发：无流水、returned_qty/余额不变、委外单不关闭）
    public function test_return_rejects_duplicate_item_lines(): void
    {
        $dag = $this->dagOrder();
        ['raw' => $raw, 'semiB' => $semiB] = $dag;
        $os = $this->approvedOutsourcing($dag);
        $rawItem = $os->items()->where('material_id', $raw->id)->firstOrFail();
        // 重复 item_id 两行各 12（已发 12）→ 422 整体拒绝（修复前两行各自 ≤ 剩余、累计 24 超已发）
        $this->withToken($this->token)->postJson("/api/v1/production/outsourcings/{$os->id}/returns", [
            'items' => [
                ['item_id' => $rawItem->id, 'quantity' => 12],
                ['item_id' => $rawItem->id, 'quantity' => 12],
            ],
            'warehouse_id' => $this->wh->id, 'location_id' => $this->b01->id,
        ])->assertJsonPath('code', 422)
            ->assertJsonPath('message', '退回组件重复');
        // 三连断言：余额不变 / 无退回单与流水 / returned_qty 未回写、委外单未关闭
        $this->assertSame('0.00', $this->balanceOf($raw->id));
        $this->assertSame('0.00', $this->balanceOf($semiB->id));
        $this->assertDatabaseCount('outsourcing_returns', 0);
        $this->assertDatabaseMissing('inventory_movements', ['source_type' => 'outsourcing_return']);
        $this->assertSame('0.00', (string) $rawItem->fresh()->returned_qty);
        $this->assertSame(OutsourcingOrder::STATUS_APPROVED, (int) $os->fresh()->status);
    }

    // OUT-08：已关闭委外单禁止回收/退回（关闭=全退后自动；两入口均 422，库存不再变动）
    public function test_closed_order_blocks_receipt_and_return(): void
    {
        $dag = $this->dagOrder();
        ['raw' => $raw, 'semiB' => $semiB] = $dag;
        $os = $this->approvedOutsourcing($dag);
        $rawItem = $os->items()->where('material_id', $raw->id)->firstOrFail();
        $semiItem = $os->items()->where('material_id', $semiB->id)->firstOrFail();
        // 全退 → 委外单自动关闭
        $this->withToken($this->token)->postJson("/api/v1/production/outsourcings/{$os->id}/returns", [
            'items' => [
                ['item_id' => $rawItem->id, 'quantity' => 12],
                ['item_id' => $semiItem->id, 'quantity' => 6],
            ],
            'warehouse_id' => $this->wh->id, 'location_id' => $this->b01->id,
        ])->assertJsonPath('code', 0);
        $this->assertSame(OutsourcingOrder::STATUS_CLOSED, (int) $os->fresh()->status);
        $this->assertSame('12.00', $this->balanceOf($raw->id));
        // 已关闭 → 回收被拒 422（防关闭后回灌），无流水无回收单
        $this->withToken($this->token)->postJson("/api/v1/production/outsourcings/{$os->id}/receipts", [
            'quantity' => 1, 'warehouse_id' => $this->wh->id, 'location_id' => $this->b01->id,
        ])->assertJsonPath('code', 422)
            ->assertJsonPath('message', '当前委外单不可回收');
        $this->assertDatabaseCount('outsourcing_receipts', 0);
        // 已关闭 → 退回被拒 422（防重复退回），库存不变
        $this->withToken($this->token)->postJson("/api/v1/production/outsourcings/{$os->id}/returns", [
            'items' => [['item_id' => $rawItem->id, 'quantity' => 1]],
            'warehouse_id' => $this->wh->id, 'location_id' => $this->b01->id,
        ])->assertJsonPath('code', 422)
            ->assertJsonPath('message', '当前委外单不可退回');
        $this->assertSame('12.00', $this->balanceOf($raw->id));
    }

    // 评审 F1：已关闭委外单（全量回收 + 全量退回自动关闭）后再 approve → 1523「该委外单已关闭」——
    // 修复前 STATUS_CLOSED 未被 approve 状态守卫拦截：工单状态仍 [RELEASED, PRODUCING] 通过、1520 复查
    // （排除自身 SUM=0+6≤6）通过 → 二次全额扣组件库存 + 状态被打回已审核
    public function test_closed_order_blocks_approve_with_1523(): void
    {
        $dag = $this->dagOrder();
        ['raw' => $raw, 'semiB' => $semiB] = $dag;
        $os = $this->approvedOutsourcing($dag);
        // 全量回收（6/6）→ 已回收；回收品=节点输出半成品B 回补库存
        $this->withToken($this->token)->postJson("/api/v1/production/outsourcings/{$os->id}/receipts", [
            'quantity' => 6, 'warehouse_id' => $this->wh->id, 'location_id' => $this->b01->id,
        ])->assertJsonPath('code', 0);
        $this->assertSame(OutsourcingOrder::STATUS_RECEIVED, (int) $os->fresh()->status);
        $this->assertSame('6.00', $this->balanceOf($semiB->id));
        // 全量退回（原料 12/半成品B 6）→ 自动关闭（组件库存全部恢复）
        $this->withToken($this->token)->postJson("/api/v1/production/outsourcings/{$os->id}/returns", [
            'items' => [
                ['item_id' => $os->items()->where('material_id', $raw->id)->firstOrFail()->id, 'quantity' => 12],
                ['item_id' => $os->items()->where('material_id', $semiB->id)->firstOrFail()->id, 'quantity' => 6],
            ],
            'warehouse_id' => $this->wh->id, 'location_id' => $this->b01->id,
        ])->assertJsonPath('code', 0);
        $this->assertSame(OutsourcingOrder::STATUS_CLOSED, (int) $os->fresh()->status);
        // 关闭后再 approve → 1523 拦截：状态仍已关闭、组件余额不变、无新增 outsourcing_out 流水
        $this->withToken($this->token)->postJson("/api/v1/production/outsourcings/{$os->id}/approve")
            ->assertJsonPath('code', 1523)
            ->assertJsonPath('message', '该委外单已关闭');
        $this->assertSame(OutsourcingOrder::STATUS_CLOSED, (int) $os->fresh()->status);
        $this->assertSame('12.00', $this->balanceOf($raw->id));
        $this->assertSame('12.00', $this->balanceOf($semiB->id));
        // 发出流水仅首次 2 条（原料/半成品B 各一），被拒 approve 不新增
        $this->assertSame(
            2,
            DB::table('inventory_movements')->where('source_type', 'outsourcing_out')
                ->where('source_id', $os->id)->count()
        );
    }

    // 评审 F2：零组件历史草稿（迁移前建单可能无 outsourcing_order_items 行）approve → 422 拒绝——
    // 修复前 $movements 为空跳过扣减直接置已审核（历史脏数据防线，同 1529 数据异常防御哲学）
    public function test_approve_rejects_legacy_draft_without_items_422(): void
    {
        $dag = $this->dagOrder();
        ['raw' => $raw] = $dag;
        // 组件基线注入（approve 被拒后余额须保持 12.00 不变）
        $this->seedComponents($dag);
        $no = $this->createOutsourcing($this->payload($dag));
        $os = OutsourcingOrder::where('no', $no)->firstOrFail();
        // 模拟迁移前旧草稿：删空组件行（新单载荷必带 items，历史行可能缺失）
        $os->items()->delete();
        $this->withToken($this->token)->postJson("/api/v1/production/outsourcings/{$os->id}/approve")
            ->assertJsonPath('code', 422)
            ->assertJsonPath('message', '委外单缺少发料组件，不可发出');
        // 三连断言：状态仍草稿、组件余额未动、无 outsourcing_out 流水
        $this->assertSame(OutsourcingOrder::STATUS_DRAFT, (int) $os->fresh()->status);
        $this->assertSame('12.00', $this->balanceOf($raw->id));
        $this->assertSame(
            0,
            DB::table('inventory_movements')->where('source_type', 'outsourcing_out')->count()
        );
    }
}
