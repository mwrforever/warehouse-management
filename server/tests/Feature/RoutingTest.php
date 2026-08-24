<?php

// 工艺路线 Feature 测试：CRUD/DAG 校验拦截/启停唯一/引用保护（RTG-01/02/03/04/09）

namespace Tests\Feature;

use App\Models\BomHeader;
use App\Models\Category;
use App\Models\Process;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\Role;
use App\Models\RoutingHeader;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\DocumentNumberConfigSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class RoutingTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    private Product $finished;

    private Product $semi;

    private Product $semiA;

    private Product $semiB;

    private Product $semiC;

    private Product $raw;

    private int $processId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DocumentNumberConfigSeeder::class);
        $this->seed(RbacSeeder::class);
        // admin 角色由 RbacSeeder 创建并挂全量权限；建用户绑定后 EnsurePermission 直通
        // （RbacSeeder 的 admin 角色标识列为 code，name 为「管理员」，故按 code 取）
        $role = Role::where('code', 'admin')->firstOrFail();
        $user = User::create(['username' => 'adminrt', 'name' => '路由管理员', 'password' => bcrypt('admin123'), 'status' => 1]);
        $user->roles()->attach($role->id);
        $this->token = $user->createToken('api')->plainTextToken;

        $category = Category::create(['name' => '分类']);
        $unit = Unit::create(['name' => '个', 'code' => 'PCS-RTF', 'status' => 1]);
        $this->raw = Product::create(['name' => '铝材', 'code' => 'RAW-RTF', 'type' => 'raw_material', 'category_id' => $category->id, 'unit_id' => $unit->id, 'status' => 1]);
        // 三分支各自产出不同半成品（数量闭合为逐节点校验：同品种多产一耗无法闭合，故分支半成品必须互异）
        $this->semi = Product::create(['name' => '支架', 'code' => 'SEMI-RTF', 'type' => 'semi_finished', 'category_id' => $category->id, 'unit_id' => $unit->id, 'status' => 1]);
        $this->semiA = Product::create(['name' => '支架A', 'code' => 'SEMIA-RTF', 'type' => 'semi_finished', 'category_id' => $category->id, 'unit_id' => $unit->id, 'status' => 1]);
        $this->semiB = Product::create(['name' => '支架B', 'code' => 'SEMIB-RTF', 'type' => 'semi_finished', 'category_id' => $category->id, 'unit_id' => $unit->id, 'status' => 1]);
        $this->semiC = Product::create(['name' => '支架C', 'code' => 'SEMIC-RTF', 'type' => 'semi_finished', 'category_id' => $category->id, 'unit_id' => $unit->id, 'status' => 1]);
        $this->finished = Product::create(['name' => '机柜', 'code' => 'FIN-RTF', 'type' => 'finished', 'category_id' => $category->id, 'unit_id' => $unit->id, 'status' => 1]);
        $this->processId = Process::create(['name' => '下料', 'code' => 'PROC-A', 'sort' => 1, 'status' => 1])->id;
        Process::create(['name' => '冲压', 'code' => 'PROC-B', 'sort' => 2, 'status' => 1]);
        Process::create(['name' => '焊接', 'code' => 'PROC-C', 'sort' => 3, 'status' => 1]);
    }

    /** 合法并行 DAG：OP10(原料→支架) → OP20/OP30/OP40(三分支各耗 1/3 支架、各产分支半成品) → OP50(汇合成成品) */
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
                    ['material_id' => $this->raw->id, 'qty_per_unit' => 3, 'unit_id' => 1],
                ]],
                ['node_no' => 'OP20', 'process_id' => $this->processId, 'name' => '冲压', 'output_product_id' => $this->semiA->id, 'output_qty' => 2, 'is_outsourced' => 0, 'remark' => null, 'materials' => [
                    ['material_id' => $this->semi->id, 'qty_per_unit' => 1, 'unit_id' => 1],
                ]],
                ['node_no' => 'OP30', 'process_id' => $this->processId, 'name' => '焊接', 'output_product_id' => $this->semiB->id, 'output_qty' => 2, 'is_outsourced' => 0, 'remark' => null, 'materials' => [
                    ['material_id' => $this->semi->id, 'qty_per_unit' => 1, 'unit_id' => 1],
                ]],
                ['node_no' => 'OP40', 'process_id' => $this->processId, 'name' => '组装', 'output_product_id' => $this->semiC->id, 'output_qty' => 2, 'is_outsourced' => 0, 'remark' => null, 'materials' => [
                    ['material_id' => $this->semi->id, 'qty_per_unit' => 1, 'unit_id' => 1],
                ]],
                ['node_no' => 'OP50', 'process_id' => $this->processId, 'name' => '质检', 'output_product_id' => $this->finished->id, 'output_qty' => 1, 'is_outsourced' => 0, 'remark' => null, 'materials' => [
                    ['material_id' => $this->semiA->id, 'qty_per_unit' => 2, 'unit_id' => 1],
                    ['material_id' => $this->semiB->id, 'qty_per_unit' => 2, 'unit_id' => 1],
                    ['material_id' => $this->semiC->id, 'qty_per_unit' => 2, 'unit_id' => 1],
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

    private function postRouting(array $payload): TestResponse
    {
        return $this->withToken($this->token)->postJson('/api/v1/routings', $payload);
    }

    public function test_routing_store_saves_dag(): void
    {
        $res = $this->postRouting($this->routingPayload());
        $res->assertJsonPath('code', 0);
        $routing = RoutingHeader::where('id', $res->json('data.id'))->firstOrFail();
        $this->assertSame(5, $routing->nodes()->count());
        $this->assertSame(6, $routing->edges()->count());
        // 编号自动生成 RTG 前缀
        $this->assertMatchesRegularExpression('/^RTG\d{12}\d{3}$/', $routing->code);
        // 节点材料落库（OP10 输入原料 3）
        $op10 = $routing->nodes()->where('node_no', 'OP10')->first();
        $this->assertSame(1, $op10->materials()->count());
        $this->assertSame('3.00', (string) $op10->materials()->first()->qty_per_unit);
    }

    public function test_routing_graph_returns_full_dag(): void
    {
        $res = $this->postRouting($this->routingPayload());
        $id = $res->json('data.id');
        $graph = $this->withToken($this->token)->getJson("/api/v1/routings/{$id}/graph");
        $graph->assertJsonPath('code', 0)
            ->assertJsonPath('data.routing.id', $id)
            ->assertJsonCount(5, 'data.nodes')
            ->assertJsonCount(6, 'data.edges');
        $op10 = collect($graph->json('data.nodes'))->firstWhere('node_no', 'OP10');
        $this->assertSame('下料', $op10['name']);
        $this->assertNotEmpty($op10['materials']);
    }

    public function test_routing_rejects_cycle_1701(): void
    {
        $payload = $this->routingPayload();
        // 追加 OP50→OP10 构成环
        $payload['edges'][] = ['from_node_no' => 'OP50', 'to_node_no' => 'OP10'];
        $this->postRouting($payload)
            ->assertJsonPath('code', 1701)
            ->assertJsonPath('message', '工艺路线存在工序环路');
        $this->assertDatabaseCount('routing_headers', 0);
    }

    public function test_routing_rejects_open_chain_1702(): void
    {
        $payload = $this->routingPayload();
        // 断开 OP10→OP30 后，OP30 输入的支架无前驱产出 → 来源缺失 1702
        $payload['edges'] = array_values(array_filter($payload['edges'], fn ($e) => ! ($e['from_node_no'] === 'OP10' && $e['to_node_no'] === 'OP30')));
        $this->postRouting($payload)->assertJsonPath('code', 1702);
    }

    public function test_routing_rejects_unconsumed_1703(): void
    {
        $payload = $this->routingPayload();
        // OP20 产出半成品但把它的输出改到无人消耗的结构：OP50 只耗原料
        foreach ($payload['nodes'] as &$n) {
            if ($n['node_no'] === 'OP50') {
                $n['materials'] = [['material_id' => $this->raw->id, 'qty_per_unit' => 1, 'unit_id' => 1]];
            }
        }
        unset($n);
        $this->postRouting($payload)->assertJsonPath('code', 1703);
    }

    public function test_routing_rejects_qty_mismatch_1704(): void
    {
        $payload = $this->routingPayload();
        // OP10 产出 3，三分支合计只耗 2（改 OP20 用量 0）
        foreach ($payload['nodes'] as &$n) {
            if ($n['node_no'] === 'OP20') {
                $n['materials'][0]['qty_per_unit'] = 0.01;
            }
        }
        unset($n);
        $this->postRouting($payload)->assertJsonPath('code', 1704);
    }

    public function test_routing_update_replaces_dag(): void
    {
        $id = $this->postRouting($this->routingPayload(['status' => 0]))->json('data.id');
        $payload = $this->routingPayload(['status' => 0, 'version' => 'v2']);
        $payload['nodes'][0]['remark'] = '改';
        $this->withToken($this->token)->putJson("/api/v1/routings/{$id}", $payload)->assertJsonPath('code', 0);
        $this->assertSame('v2', RoutingHeader::find($id)->version);
        // DAG 全量替换：节点 remark 已更新
        $this->assertSame('改', RoutingHeader::find($id)->nodes()->where('node_no', 'OP10')->first()->remark);
    }

    public function test_routing_enable_unique_and_toggle(): void
    {
        // 已有启用 v1 → 再建启用 v2 报 1707；停用 v1 后可启用 v2；toggle 启用自动停用旧版
        $id1 = $this->postRouting($this->routingPayload())->json('data.id');
        $this->postRouting($this->routingPayload(['version' => 'v2']))->assertJsonPath('code', 1707);
        $this->withToken($this->token)->putJson("/api/v1/routings/{$id1}/toggle", ['status' => 0])->assertJsonPath('code', 0);
        $id2 = $this->postRouting($this->routingPayload(['version' => 'v2']))->json('data.id');
        $this->withToken($this->token)->putJson("/api/v1/routings/{$id2}/toggle", ['status' => 1])->assertJsonPath('code', 0);
        $this->assertSame(1, RoutingHeader::where('product_id', $this->finished->id)->where('status', 1)->count());
    }

    public function test_routing_toggle_enable_auto_disables_other_enabled_version(): void
    {
        // 正常路径：v1 仍启用时直接 toggle 启用 v2 → v1 被自动停用（同成品启用唯一不变式，B-103；
        // 上一用例先手工停 v1 再启 v2，未覆盖「启用时自动停用其他启用版本」分支）
        $id1 = $this->postRouting($this->routingPayload())->json('data.id');
        $id2 = $this->postRouting($this->routingPayload(['version' => 'v2', 'status' => 0]))->json('data.id');
        $this->withToken($this->token)->putJson("/api/v1/routings/{$id2}/toggle", ['status' => 1])->assertJsonPath('code', 0);
        $this->assertSame(0, RoutingHeader::find($id1)->status);
        $this->assertSame(1, RoutingHeader::find($id2)->status);
        // 不变式收口断言：同成品启用版本恒为 1
        $this->assertSame(1, RoutingHeader::where('product_id', $this->finished->id)->where('status', 1)->count());
    }

    public function test_routing_deletion_guard(): void
    {
        $id = $this->postRouting($this->routingPayload(['status' => 0]))->json('data.id');
        // 未引用：可删（级联清节点/边/材料）
        $this->withToken($this->token)->deleteJson("/api/v1/routings/{$id}")->assertJsonPath('code', 0);
        $this->assertDatabaseCount('routing_nodes', 0);

        // 被工单引用：改结构 1705、删除 1706
        $id2 = $this->postRouting($this->routingPayload(['status' => 0]))->json('data.id');
        // bom_id 外键须指向真实 BOM（直接硬编码 1 会 FK 失败）
        $bom = BomHeader::create(['code' => 'BOM-REF-1', 'product_id' => $this->finished->id, 'version' => 'v1', 'quantity' => 1, 'status' => 1]);
        ProductionOrder::create([
            'no' => 'MO-REF-1', 'product_id' => $this->finished->id, 'quantity' => 10,
            'plan_date' => now()->toDateString(), 'bom_id' => $bom->id, 'routing_id' => $id2,
            'status' => 0, 'completed_qty' => 0,
        ]);
        $this->withToken($this->token)->putJson("/api/v1/routings/{$id2}", $this->routingPayload())->assertJsonPath('code', 1705);
        $this->withToken($this->token)->deleteJson("/api/v1/routings/{$id2}")->assertJsonPath('code', 1706);
        // 引用后仅可启停
        $this->withToken($this->token)->putJson("/api/v1/routings/{$id2}/toggle", ['status' => 1])->assertJsonPath('code', 0);
    }

    public function test_routing_list_filters(): void
    {
        $this->postRouting($this->routingPayload());
        $this->withToken($this->token)->getJson('/api/v1/routings?product_id='.$this->finished->id)
            ->assertJsonPath('code', 0)->assertJsonCount(1, 'data.items');
        $this->withToken($this->token)->getJson('/api/v1/routings?status=0')
            ->assertJsonPath('code', 0)->assertJsonCount(0, 'data.items');
    }

    public function test_routing_permission_denied_for_plain_user(): void
    {
        // 无 routing.create 权限的普通用户：中间件直接 403
        $plain = Role::create(['name' => 'plain', 'code' => 'plain', 'remark' => '无路由权限']);
        $user = User::create(['username' => 'plainrt', 'name' => '普通', 'password' => bcrypt('plain123'), 'status' => 1]);
        $user->roles()->attach($plain->id);
        $token = $user->createToken('api')->plainTextToken;
        $this->withToken($token)->postJson('/api/v1/routings', $this->routingPayload())->assertStatus(403);
    }
}
