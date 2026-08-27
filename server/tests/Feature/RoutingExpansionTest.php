<?php

// 工单工艺路线展开 Feature 测试：DAG 快照展开（RTG-05）/无路线回退兼容（RTG-06）/详情 graph 字段

namespace Tests\Feature;

use App\Models\BomHeader;
use App\Models\Category;
use App\Models\Process;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\Role;
use App\Models\Unit;
use App\Models\User;
use App\Models\WorkOrderOperationEdge;
use Database\Seeders\DocumentNumberConfigSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class RoutingExpansionTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    private Product $finished;

    private Product $semi;

    private Product $semiA;

    private Product $semiB;

    private Product $semiC;

    private Product $raw;

    private int $unitId;

    private int $processId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DocumentNumberConfigSeeder::class);
        $this->seed(RbacSeeder::class);
        // admin 角色由 RbacSeeder 创建并挂全量权限；角色标识列为 code（name 为「管理员」），按 code 取
        $role = Role::where('code', 'admin')->firstOrFail();
        $user = User::create(['username' => 'adminex', 'name' => '展开管理员', 'password' => bcrypt('admin123'), 'status' => 1]);
        $user->roles()->attach($role->id);
        $this->token = $user->createToken('api')->plainTextToken;

        $category = Category::create(['name' => '分类']);
        $unit = Unit::create(['name' => '个', 'code' => 'PCS-EXP', 'status' => 1]);
        $this->unitId = $unit->id;
        $this->raw = Product::create(['name' => '铝材', 'code' => 'RAW-EXP', 'type' => 'raw_material', 'category_id' => $category->id, 'unit_id' => $unit->id, 'status' => 1]);
        // OP10 产出的半成品（被三个分支节点消耗 → 物料归属 node_no=null 的反例主角）
        $this->semi = Product::create(['name' => '支架', 'code' => 'SEMI-EXP', 'type' => 'semi_finished', 'category_id' => $category->id, 'unit_id' => $unit->id, 'status' => 1]);
        // 三分支各自产出互异半成品（数量闭合为逐节点校验：同品种多产一耗无法闭合，故分支半成品必须互异）
        $this->semiA = Product::create(['name' => '支架A', 'code' => 'SEMIA-EXP', 'type' => 'semi_finished', 'category_id' => $category->id, 'unit_id' => $unit->id, 'status' => 1]);
        $this->semiB = Product::create(['name' => '支架B', 'code' => 'SEMIB-EXP', 'type' => 'semi_finished', 'category_id' => $category->id, 'unit_id' => $unit->id, 'status' => 1]);
        $this->semiC = Product::create(['name' => '支架C', 'code' => 'SEMIC-EXP', 'type' => 'semi_finished', 'category_id' => $category->id, 'unit_id' => $unit->id, 'status' => 1]);
        $this->finished = Product::create(['name' => '机柜', 'code' => 'FIN-EXP', 'type' => 'finished', 'category_id' => $category->id, 'unit_id' => $unit->id, 'status' => 1]);
        // 2 个启用工序：无路线回退用例断言线性快照 seq 1/2（路线节点全部共用首工序）
        $this->processId = Process::create(['name' => '下料', 'code' => 'PROC-EX-A', 'sort' => 1, 'status' => 1])->id;
        Process::create(['name' => '焊接', 'code' => 'PROC-EX-B', 'sort' => 2, 'status' => 1]);

        // 启用 BOM（工单创建前置）：成品 = 铝材×3 + 支架×1（基准产出 1）——expandBom 物料快照来源
        $bom = BomHeader::create(['code' => 'BOM-EXP-1', 'product_id' => $this->finished->id, 'version' => 'v1', 'quantity' => 1, 'status' => 1]);
        $bom->items()->createMany([
            ['material_id' => $this->raw->id, 'quantity' => 3, 'unit_id' => $unit->id],
            ['material_id' => $this->semi->id, 'quantity' => 1, 'unit_id' => $unit->id],
        ]);
    }

    /** 合法并行 DAG：OP10(原料→支架×3) → OP20/OP30/OP40(三分支各耗 1 支架、各产互异半成品×2) → OP50(汇合耗三分支半成品各×2 产成品) */
    private function routingPayload(array $overrides = []): array
    {
        $payload = [
            'product_id' => $this->finished->id,
            'version' => 'v1',
            'quantity' => 3,
            'status' => 1,
            'remark' => null,
            'nodes' => [
                ['node_no' => 'OP10', 'process_id' => $this->processId, 'name' => '下料', 'output_product_id' => $this->semi->id, 'output_qty' => 3, 'is_outsourced' => 0, 'remark' => null, 'materials' => [
                    ['material_id' => $this->raw->id, 'qty_per_unit' => 3, 'unit_id' => $this->unitId],
                ]],
                ['node_no' => 'OP20', 'process_id' => $this->processId, 'name' => '冲压', 'output_product_id' => $this->semiA->id, 'output_qty' => 2, 'is_outsourced' => 0, 'remark' => null, 'materials' => [
                    ['material_id' => $this->semi->id, 'qty_per_unit' => 1, 'unit_id' => $this->unitId],
                ]],
                ['node_no' => 'OP30', 'process_id' => $this->processId, 'name' => '焊接', 'output_product_id' => $this->semiB->id, 'output_qty' => 2, 'is_outsourced' => 0, 'remark' => null, 'materials' => [
                    ['material_id' => $this->semi->id, 'qty_per_unit' => 1, 'unit_id' => $this->unitId],
                ]],
                ['node_no' => 'OP40', 'process_id' => $this->processId, 'name' => '组装', 'output_product_id' => $this->semiC->id, 'output_qty' => 2, 'is_outsourced' => 0, 'remark' => null, 'materials' => [
                    ['material_id' => $this->semi->id, 'qty_per_unit' => 1, 'unit_id' => $this->unitId],
                ]],
                ['node_no' => 'OP50', 'process_id' => $this->processId, 'name' => '质检', 'output_product_id' => $this->finished->id, 'output_qty' => 1, 'is_outsourced' => 0, 'remark' => null, 'materials' => [
                    ['material_id' => $this->semiA->id, 'qty_per_unit' => 2, 'unit_id' => $this->unitId],
                    ['material_id' => $this->semiB->id, 'qty_per_unit' => 2, 'unit_id' => $this->unitId],
                    ['material_id' => $this->semiC->id, 'qty_per_unit' => 2, 'unit_id' => $this->unitId],
                ]],
            ],
            'edges' => [
                ['from_node_no' => 'OP10', 'to_node_no' => 'OP20'],
                ['from_node_no' => 'OP10', 'to_node_no' => 'OP30'],
                ['from_node_no' => 'OP10', 'to_node_no' => 'OP40'],
                ['from_node_no' => 'OP20', 'to_node_no' => 'OP50'],
                ['from_node_no' => 'OP30', 'to_node_no' => 'OP50'],
                ['from_node_no' => 'OP40', 'to_node_no' => 'OP50'],
            ],
        ];

        return array_merge($payload, $overrides);
    }

    /** 通过 API 建工单（默认机柜×6 计划今天），返回响应（各用例按需断言） */
    private function createOrderViaApi(array $overrides = []): TestResponse
    {
        return $this->withToken($this->token)->postJson('/api/v1/production/orders', array_merge([
            'product_id' => $this->finished->id,
            'quantity' => 6,
            'plan_date' => now()->toDateString(),
        ], $overrides));
    }

    // RTG-05：工单按 DAG 展开——节点/边/物料归属正确，routing_id 快照落库
    public function test_order_expands_routing_dag(): void
    {
        // 启用工艺路线（OP30 标记委外，验证 is_outsourced 随快照落到工序行）
        $payload = $this->routingPayload();
        foreach ($payload['nodes'] as &$n) {
            if ($n['node_no'] === 'OP30') {
                $n['is_outsourced'] = 1;
            }
        }
        unset($n);
        $routingId = $this->withToken($this->token)->postJson('/api/v1/routings', $payload)->json('data.id');

        $res = $this->createOrderViaApi();
        $res->assertJsonPath('code', 0);
        $order = ProductionOrder::where('id', $res->json('data.id'))->firstOrFail();
        // DAG 工单锚定路线快照；正常展开不带 routing_warning 键
        $this->assertSame($routingId, $order->routing_id);
        $this->assertArrayNotHasKey('routing_warning', $res->json('data'));

        // 5 工序快照：node_no/output_product/is_outsourced 带到工序行，seq 为拓扑序（OP10→OP20/30/40→OP50）
        $ops = $order->operations()->orderBy('seq')->get();
        $this->assertSame(5, $ops->count());
        $this->assertSame('OP10', $ops[0]->node_no);
        $this->assertEquals($this->semi->id, $ops[0]->output_product_id);
        $this->assertSame(1, (int) $ops[2]->is_outsourced); // OP30 委外标记（拓扑序第 3 位，0 基下标 2）
        $this->assertSame('OP50', $ops[4]->node_no);
        // 边快照：6 条依赖边按 operation id 映射落边表
        $this->assertSame(6, WorkOrderOperationEdge::where('order_id', $order->id)->count());
        // 物料归属：BOM 铝材仅 OP10 消耗 → node_no=OP10；支架被 OP20/30/40 三节点消耗 → null（按总量领料）
        $materials = $order->materials()->get();
        $this->assertSame('OP10', $materials->firstWhere('material_id', $this->raw->id)->node_no);
        $this->assertNull($materials->firstWhere('material_id', $this->semi->id)->node_no);
    }

    // RTG-06：无工艺路线回退旧逻辑（全量启用工序线性快照）+ routing_warning
    public function test_order_without_routing_falls_back(): void
    {
        // setUp 未建任何路线 → 无启用路线存在；Log mock 须在请求前挂载才能拦截事务内告警
        \Log::shouldReceive('warning')->atLeast()->once();
        // 创建成功路径的 info 审计日志（D-14）与本用例断言无关，Mockery 窄 mock 下须显式放行
        \Log::shouldReceive('info');

        $res = $this->createOrderViaApi();
        $res->assertJsonPath('code', 0)
            ->assertJsonPath('data.routing_warning', '该成品无启用工艺路线，已按全量启用工序展开');
        $order = ProductionOrder::where('id', $res->json('data.id'))->firstOrFail();
        $this->assertNull($order->routing_id);
        // 旧逻辑：2 个启用工序线性 seq 1/2，无 DAG 边
        $this->assertSame(2, $order->operations()->count());
        $this->assertSame([1, 2], $order->operations()->orderBy('seq')->pluck('seq')->all());
        $this->assertSame(0, WorkOrderOperationEdge::where('order_id', $order->id)->count());
        // 旧工单详情：graph=null（前端隐藏工序网络 tab）
        $detail = $this->withToken($this->token)->getJson("/api/v1/production/orders/{$order->id}");
        $detail->assertJsonPath('code', 0)->assertJsonPath('data.graph', null);
    }

    // 详情 graph 字段：DAG 工单带 nodes/edges 与 operations 前驱结构（Task 8 前端画布消费）
    public function test_order_detail_graph_field(): void
    {
        $routingId = $this->withToken($this->token)->postJson('/api/v1/routings', $this->routingPayload())->json('data.id');
        $orderId = $this->createOrderViaApi()->json('data.id');

        $res = $this->withToken($this->token)->getJson("/api/v1/production/orders/{$orderId}");
        $res->assertJsonPath('code', 0)
            ->assertJsonPath('data.routing_id', $routingId)
            ->assertJsonCount(5, 'data.graph.nodes')
            ->assertJsonCount(6, 'data.graph.edges')
            ->assertJsonPath('data.operations.0.node_no', 'OP10')
            ->assertJsonPath('data.operations.0.output_product_name', '支架')
            ->assertJsonPath('data.graph.nodes.0.status_label', '待开工');

        // 前驱结构：OP20 的 predecessors 仅含 OP10（id/节点号/工序名）
        $op20 = collect($res->json('data.operations'))->firstWhere('node_no', 'OP20');
        $this->assertCount(1, $op20['predecessors']);
        $this->assertSame('OP10', $op20['predecessors'][0]['node_no']);
        $this->assertSame('下料', $op20['predecessors'][0]['process_name']);
        $this->assertNotNull($op20['predecessors'][0]['id']);
        $this->assertSame(0, $op20['is_outsourced']);
        $this->assertSame($this->semiA->id, $op20['output_product_id']);

        // 边端点带节点号（画布连线按 node_no 定位）
        $edge = collect($res->json('data.graph.edges'))
            ->first(fn (array $e) => $e['from_node_no'] === 'OP10' && $e['to_node_no'] === 'OP20');
        $this->assertNotNull($edge);
        $this->assertNotNull($edge['from_operation_id']);
        $this->assertNotNull($edge['to_operation_id']);
    }
}
