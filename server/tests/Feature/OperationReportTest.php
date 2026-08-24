<?php

// 工序报工接口测试：报工校验/累计边界/自动流转/记录列表/并发安全（核心路径 100%）

namespace Tests\Feature;

use App\Models\BomHeader;
use App\Models\Category;
use App\Models\Process;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\Role;
use App\Models\Unit;
use App\Models\User;
use App\Models\WorkOrderOperation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class OperationReportTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    private User $admin;

    private ProductionOrder $order;

    private array $ops = []; // [seq => WorkOrderOperation]

    protected function setUp(): void
    {
        parent::setUp();
        $role = Role::create(['name' => '管理员', 'code' => 'admin']);
        $this->admin = User::create(['name' => '管理员', 'username' => 'admin', 'password' => 'admin123', 'status' => 1]);
        $this->admin->roles()->sync([$role->id]);
        $this->token = $this->admin->createToken('api')->plainTextToken;

        $cat = Category::create(['name' => '成品', 'parent_id' => 0]);
        $unit = Unit::create(['name' => '个', 'code' => 'pc']);
        $fin = Product::create(['name' => '成品B', 'code' => 'FIN-002', 'type' => 'finished', 'category_id' => $cat->id, 'unit_id' => $unit->id, 'status' => 1]);
        $rawCat = Category::create(['name' => '原材料', 'parent_id' => 0]);
        Product::create(['name' => '测试铝材', 'code' => 'MAT-001', 'type' => 'raw_material', 'category_id' => $rawCat->id, 'unit_id' => $unit->id, 'status' => 1]);
        $bom = BomHeader::create(['code' => 'BOM-001', 'product_id' => $fin->id, 'version' => 'v1', 'quantity' => 1, 'status' => 1]);
        $bom->items()->create(['material_id' => 2, 'quantity' => 2, 'unit_id' => $unit->id]);
        // 3 个启用工序
        foreach ([['下料', 'CUT', 1], ['组装', 'ASSY', 2], ['质检', 'QC', 3]] as [$name, $code, $sort]) {
            Process::create(['name' => $name, 'code' => $code, 'sort' => $sort, 'status' => 1]);
        }
        // 建单 → 下达 → 开工（首工序进行中）
        $res = $this->withToken($this->token)->postJson('/api/v1/production/orders', [
            'product_id' => $fin->id, 'quantity' => 10, 'plan_date' => now()->toDateString(),
        ]);
        $res->assertJsonPath('code', 0);
        $this->order = ProductionOrder::where('no', $res->json('data.no'))->first();
        $this->withToken($this->token)->postJson("/api/v1/production/orders/{$this->order->id}/release")->assertJsonPath('code', 0);
        $this->withToken($this->token)->postJson("/api/v1/production/orders/{$this->order->id}/start")->assertJsonPath('code', 0);
        foreach ($this->order->operations()->orderBy('seq')->get() as $op) {
            $this->ops[$op->seq] = $op;
        }
    }

    // 组装报工载荷
    private function reportPayload(array $overrides = []): array
    {
        return array_merge([
            'qualified_qty' => 10,
            'defective_qty' => 0,
            'hours' => 2.5,
            'operator' => '张三',
            'remark' => '正常报工',
        ], $overrides);
    }

    /**
     * DAG 工单辅助：造钻石路线工单（OP10→OP20/OP30/OP40→OP50，OP30 委外）并下达开工
     *
     * 独立物料族与 setUp 线性工单（成品B，routing_id=null）隔离互不影响：
     * OP10 产半成品A×3（耗原料×3），三分支各耗 A×1、各产互异半成品 B/C/D×2，
     * OP50 汇合耗 B/C/D 各×2 产成品；工单计划 6（节点报满 6 即完成）。
     * 返回 ['order' => 工单, 'ops' => 按 node_no 键控的工序映射（开工后刷新态）]
     */
    private function dagOrder(): array
    {
        // 钻石 DAG 物料族：原料 + OP10 半成品A + 分支互异半成品 B/C/D + 成品
        $cat = Category::create(['name' => 'DAG 物料']);
        $unitId = Unit::where('code', 'pc')->firstOrFail()->id;
        $processId = Process::where('code', 'CUT')->firstOrFail()->id;
        $raw = Product::create(['name' => '铝材', 'code' => 'RAW-DAG', 'type' => 'raw_material', 'category_id' => $cat->id, 'unit_id' => $unitId, 'status' => 1]);
        $semiA = Product::create(['name' => '半成品A', 'code' => 'SEMI-DA', 'type' => 'semi_finished', 'category_id' => $cat->id, 'unit_id' => $unitId, 'status' => 1]);
        $semiB = Product::create(['name' => '半成品B', 'code' => 'SEMI-DB', 'type' => 'semi_finished', 'category_id' => $cat->id, 'unit_id' => $unitId, 'status' => 1]);
        $semiC = Product::create(['name' => '半成品C', 'code' => 'SEMI-DC', 'type' => 'semi_finished', 'category_id' => $cat->id, 'unit_id' => $unitId, 'status' => 1]);
        $semiD = Product::create(['name' => '半成品D', 'code' => 'SEMI-DD', 'type' => 'semi_finished', 'category_id' => $cat->id, 'unit_id' => $unitId, 'status' => 1]);
        $fin = Product::create(['name' => '机柜DAG', 'code' => 'FIN-DAG', 'type' => 'finished', 'category_id' => $cat->id, 'unit_id' => $unitId, 'status' => 1]);
        // 成品启用 BOM：原料×3 + 半成品A×1（工单创建前置，与路线数量口径一致）
        $bom = BomHeader::create(['code' => 'BOM-DAG-1', 'product_id' => $fin->id, 'version' => 'v1', 'quantity' => 1, 'status' => 1]);
        $bom->items()->createMany([
            ['material_id' => $raw->id, 'quantity' => 3, 'unit_id' => $unitId],
            ['material_id' => $semiA->id, 'quantity' => 1, 'unit_id' => $unitId],
        ]);

        // 启用钻石路线（OP30 委外）：下达后工单按 DAG 展开快照节点/边
        $this->withToken($this->token)->postJson('/api/v1/routings', [
            'product_id' => $fin->id, 'version' => 'v1', 'quantity' => 3, 'status' => 1, 'remark' => null,
            'nodes' => [
                ['node_no' => 'OP10', 'process_id' => $processId, 'name' => '下料', 'output_product_id' => $semiA->id, 'output_qty' => 3, 'is_outsourced' => 0, 'remark' => null, 'materials' => [
                    ['material_id' => $raw->id, 'qty_per_unit' => 3, 'unit_id' => $unitId],
                ]],
                ['node_no' => 'OP20', 'process_id' => $processId, 'name' => '冲压', 'output_product_id' => $semiB->id, 'output_qty' => 2, 'is_outsourced' => 0, 'remark' => null, 'materials' => [
                    ['material_id' => $semiA->id, 'qty_per_unit' => 1, 'unit_id' => $unitId],
                ]],
                ['node_no' => 'OP30', 'process_id' => $processId, 'name' => '焊接', 'output_product_id' => $semiC->id, 'output_qty' => 2, 'is_outsourced' => 1, 'remark' => null, 'materials' => [
                    ['material_id' => $semiA->id, 'qty_per_unit' => 1, 'unit_id' => $unitId],
                ]],
                ['node_no' => 'OP40', 'process_id' => $processId, 'name' => '组装', 'output_product_id' => $semiD->id, 'output_qty' => 2, 'is_outsourced' => 0, 'remark' => null, 'materials' => [
                    ['material_id' => $semiA->id, 'qty_per_unit' => 1, 'unit_id' => $unitId],
                ]],
                ['node_no' => 'OP50', 'process_id' => $processId, 'name' => '质检', 'output_product_id' => $fin->id, 'output_qty' => 1, 'is_outsourced' => 0, 'remark' => null, 'materials' => [
                    ['material_id' => $semiB->id, 'qty_per_unit' => 2, 'unit_id' => $unitId],
                    ['material_id' => $semiC->id, 'qty_per_unit' => 2, 'unit_id' => $unitId],
                    ['material_id' => $semiD->id, 'qty_per_unit' => 2, 'unit_id' => $unitId],
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
        ])->assertJsonPath('code', 0);

        // 建单（计划 6）→ 下达 → 开工（入度 0 的 OP10 置进行中）
        $res = $this->withToken($this->token)->postJson('/api/v1/production/orders', [
            'product_id' => $fin->id, 'quantity' => 6, 'plan_date' => now()->toDateString(),
        ]);
        $res->assertJsonPath('code', 0);
        $order = ProductionOrder::where('id', $res->json('data.id'))->firstOrFail();
        $this->withToken($this->token)->postJson("/api/v1/production/orders/{$order->id}/release")->assertJsonPath('code', 0);
        $this->withToken($this->token)->postJson("/api/v1/production/orders/{$order->id}/start")->assertJsonPath('code', 0);

        // 按 node_no 键控的工序映射（开工后已刷新，直接承载起点状态断言）
        return ['order' => $order, 'ops' => $order->operations()->get()->keyBy('node_no')];
    }

    /** 提交报工载荷并返回响应（成功与异常断言共用出口） */
    private function postReport(WorkOrderOperation $op, array $payload): TestResponse
    {
        return $this->withToken($this->token)->postJson("/api/v1/production/operations/{$op->id}/reports", $payload);
    }

    /** 对工序报合格数量并断言成功（DAG 推进用例的步进动作） */
    private function report(WorkOrderOperation $op, string $qty): void
    {
        $this->postReport($op, ['qualified_qty' => $qty])->assertJsonPath('code', 0);
    }

    public function test_report_success_and_auto_advance(): void
    {
        // 正常路径：报工成功（累计+记录落库）；合格累计=计划 → 本工序完成 + 下一工序进行中
        $op1 = $this->ops[1];
        $this->withToken($this->token)->postJson("/api/v1/production/operations/{$op1->id}/reports", $this->reportPayload())
            ->assertJsonPath('code', 0);
        $op1->refresh();
        $this->assertSame('10.00', $op1->qualified_qty);
        $this->assertSame('2.50', $op1->hours);
        $this->assertSame(WorkOrderOperation::STATUS_DONE, $op1->status);
        $op2 = $this->ops[2]->refresh();
        $this->assertSame(WorkOrderOperation::STATUS_RUNNING, $op2->status);
        // 报工记录落库
        $this->assertDatabaseHas('operation_reports', [
            'operation_id' => $op1->id, 'qualified_qty' => '10.00', 'defective_qty' => '0.00',
            'hours' => '2.50', 'operator' => '张三',
        ]);
    }

    public function test_report_partial_keeps_running(): void
    {
        // 边界路径：累计合格 < 计划 → 本工序仍进行中，下一工序不动
        $op1 = $this->ops[1];
        $this->withToken($this->token)->postJson("/api/v1/production/operations/{$op1->id}/reports", $this->reportPayload(['qualified_qty' => 4]))
            ->assertJsonPath('code', 0);
        $op1->refresh();
        $this->assertSame('4.00', $op1->qualified_qty);
        $this->assertSame(WorkOrderOperation::STATUS_RUNNING, $op1->status);
        $this->assertSame(WorkOrderOperation::STATUS_PENDING, $this->ops[2]->refresh()->status);
        // 再报 6 达标 → 完成 + 推进
        $this->withToken($this->token)->postJson("/api/v1/production/operations/{$op1->id}/reports", $this->reportPayload(['qualified_qty' => 6]))
            ->assertJsonPath('code', 0);
        $this->assertSame(WorkOrderOperation::STATUS_DONE, $op1->refresh()->status);
        $this->assertSame(WorkOrderOperation::STATUS_RUNNING, $this->ops[2]->refresh()->status);
        // 累计合格 = 4 + 6 = 10
        $this->assertSame('10.00', $op1->qualified_qty);
    }

    public function test_report_last_operation_completion_no_next(): void
    {
        // 边界路径：末工序报工达标 → 完成且无下一工序可推进（工单可完工）
        $op1 = $this->ops[1];
        $this->withToken($this->token)->postJson("/api/v1/production/operations/{$op1->id}/reports", $this->reportPayload())->assertJsonPath('code', 0);
        $op2 = $this->ops[2]->refresh();
        $this->withToken($this->token)->postJson("/api/v1/production/operations/{$op2->id}/reports", $this->reportPayload())->assertJsonPath('code', 0);
        $op3 = $this->ops[3]->refresh();
        $this->withToken($this->token)->postJson("/api/v1/production/operations/{$op3->id}/reports", $this->reportPayload())->assertJsonPath('code', 0);
        $op3->refresh();
        $this->assertSame(WorkOrderOperation::STATUS_DONE, $op3->status);
        $this->assertSame('10.00', $op3->qualified_qty);
    }

    public function test_report_rejects_non_running_operation_with_1509(): void
    {
        // 异常路径：已完成工序再报工 → 1509（待开工/已完成均不可报）
        $op1 = $this->ops[1];
        $this->withToken($this->token)->postJson("/api/v1/production/operations/{$op1->id}/reports", $this->reportPayload())->assertJsonPath('code', 0);
        $this->withToken($this->token)->postJson("/api/v1/production/operations/{$op1->id}/reports", $this->reportPayload())
            ->assertJsonPath('code', 1509)
            ->assertJsonPath('message', '该工序当前不可报工');
        // 待开工工序（质检）直接报工 → 1509
        $op3 = $this->ops[3];
        $this->withToken($this->token)->postJson("/api/v1/production/operations/{$op3->id}/reports", $this->reportPayload())
            ->assertJsonPath('code', 1509);
    }

    public function test_report_rejects_qualified_over_plan_with_1510(): void
    {
        // 异常路径：合格数超过计划数 → 1510（累计语义：已报 4 + 本次 8 = 12 > 10）
        $op1 = $this->ops[1];
        $this->withToken($this->token)->postJson("/api/v1/production/operations/{$op1->id}/reports", $this->reportPayload(['qualified_qty' => 11]))
            ->assertJsonPath('code', 1510)
            ->assertJsonPath('message', '合格数不能超过工单计划数量');
        // 累计场景：先报 4 再报 8（累计 12 > 10）→ 1510
        $this->withToken($this->token)->postJson("/api/v1/production/operations/{$op1->id}/reports", $this->reportPayload(['qualified_qty' => 4]))->assertJsonPath('code', 0);
        $this->withToken($this->token)->postJson("/api/v1/production/operations/{$op1->id}/reports", $this->reportPayload(['qualified_qty' => 8]))
            ->assertJsonPath('code', 1510);
    }

    public function test_report_rejects_qualified_plus_defective_over_plan_with_1511(): void
    {
        // 异常路径：合格+不良合计超计划 → 1511（8+5=13 > 10）
        $op1 = $this->ops[1];
        $this->withToken($this->token)->postJson("/api/v1/production/operations/{$op1->id}/reports", $this->reportPayload(['qualified_qty' => 8, 'defective_qty' => 5]))
            ->assertJsonPath('code', 1511)
            ->assertJsonPath('message', '合格数与不良数合计不能超过工单计划数量');
    }

    public function test_report_rejects_negative_hours_with_1512(): void
    {
        // 异常路径：工时负数 → 1512（业务码，spec 明确）
        $op1 = $this->ops[1];
        $this->withToken($this->token)->postJson("/api/v1/production/operations/{$op1->id}/reports", $this->reportPayload(['hours' => -1]))
            ->assertJsonPath('code', 1512);
    }

    public function test_report_rejects_negative_qualified_with_422(): void
    {
        // 异常路径：合格数为负 → 422（值域；spec 码段满，镜像采购/销售负值 422 先例）
        $op1 = $this->ops[1];
        $this->withToken($this->token)->postJson("/api/v1/production/operations/{$op1->id}/reports", $this->reportPayload(['qualified_qty' => -1]))
            ->assertJsonPath('code', 422);
        // 不良数为负同样 422
        $this->withToken($this->token)->postJson("/api/v1/production/operations/{$op1->id}/reports", $this->reportPayload(['defective_qty' => -2]))
            ->assertJsonPath('code', 422);
    }

    public function test_report_accumulates_defective_and_hours(): void
    {
        // 正常路径：不良数与工时累计（良率统计口径）
        $op1 = $this->ops[1];
        $this->withToken($this->token)->postJson("/api/v1/production/operations/{$op1->id}/reports", $this->reportPayload(['qualified_qty' => 4, 'defective_qty' => 1, 'hours' => 1.5]))
            ->assertJsonPath('code', 0);
        $this->withToken($this->token)->postJson("/api/v1/production/operations/{$op1->id}/reports", $this->reportPayload(['qualified_qty' => 5, 'defective_qty' => 1, 'hours' => 2]))
            ->assertJsonPath('code', 0);
        $op1->refresh();
        $this->assertSame('9.00', $op1->qualified_qty);
        $this->assertSame('2.00', $op1->defective_qty);
        $this->assertSame('3.50', $op1->hours);
    }

    public function test_reports_index_lists_records(): void
    {
        // 正常路径：报工记录列表（该工序全部记录，含操作人/时间）
        $op1 = $this->ops[1];
        $this->withToken($this->token)->postJson("/api/v1/production/operations/{$op1->id}/reports", $this->reportPayload())->assertJsonPath('code', 0);
        $this->withToken($this->token)->getJson("/api/v1/production/operations/{$op1->id}/reports")
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.items.0.qualified_qty', '10.00')
            ->assertJsonPath('data.items.0.operator', '张三');
    }

    public function test_report_missing_defective_qty_defaults_to_zero(): void
    {
        // 边界路径：defective_qty 漏传（nullable 字段，镜像 E2E TC-PRD-04 载荷「合格 10、工时 0.5」）
        // → 归一化为 0 不 500；累计合格达标 → 本工序完成，不良/工时累计 0/0.5
        $op1 = $this->ops[1];
        $this->withToken($this->token)->postJson("/api/v1/production/operations/{$op1->id}/reports", [
            'qualified_qty' => 10,
            'hours' => 0.5,
        ])->assertJsonPath('code', 0);
        $op1->refresh();
        $this->assertSame(WorkOrderOperation::STATUS_DONE, $op1->status);
        $this->assertSame('0.00', $op1->defective_qty);
        $this->assertSame('0.50', $op1->hours);
        // 报工记录同样按 0/0.5 落库（操作人回退到当前登录用户名）
        $this->assertDatabaseHas('operation_reports', [
            'operation_id' => $op1->id, 'qualified_qty' => '10.00', 'defective_qty' => '0.00',
            'hours' => '0.50', 'operator' => '管理员',
        ]);
    }

    // RTG-07：并行分支报工推进——节点完成后仅「全部前驱已完成」的直接后继置进行中
    public function test_report_advances_dag_parallel(): void
    {
        ['order' => $order, 'ops' => $ops] = $this->dagOrder();
        // 开工：仅入度 0 的 OP10 进行中，其余待开工
        $this->assertSame(1, (int) $ops['OP10']->fresh()->status);
        $this->assertSame(0, (int) $ops['OP20']->fresh()->status);

        // OP10 报满 → 三分支同时进行中（并行）
        $this->report($ops['OP10'], '6');
        $this->assertSame(2, (int) $ops['OP10']->fresh()->status);
        foreach (['OP20', 'OP30', 'OP40'] as $no) {
            $this->assertSame(1, (int) $ops[$no]->fresh()->status, "节点 {$no} 应进行中");
        }
        $this->assertSame(0, (int) $ops['OP50']->fresh()->status);

        // OP20/OP40 完成、OP30（委外）未完成 → 汇合点 OP50 仍待开工（前驱全完成才推进）
        $this->report($ops['OP20'], '6');
        $this->report($ops['OP40'], '6');
        $this->assertSame(0, (int) $ops['OP50']->fresh()->status);
    }

    // RTG-07：委外节点不可报工（只能经委外单回收完成）
    public function test_report_rejects_outsourced_node(): void
    {
        ['order' => $order, 'ops' => $ops] = $this->dagOrder();
        $this->report($ops['OP10'], '6'); // OP30 随之分叉置进行中
        $this->postReport($ops['OP30'], ['qualified_qty' => 1])
            ->assertJsonPath('code', 1509)
            ->assertJsonPath('message', '委外工序不可报工，经委外单回收完成');
    }

    public function test_reports_requires_report_permission(): void
    {
        // 异常路径：无 production.report.list 权限的角色被拒（403 JSON 信封）
        $role = Role::create(['name' => '普通', 'code' => 'plain']);
        $u = User::create(['name' => '普通', 'username' => 'plain', 'password' => 'admin123', 'status' => 1]);
        $u->roles()->sync([$role->id]);
        $token = $u->createToken('api')->plainTextToken;
        $op1 = $this->ops[1];
        // 测试框架在同一 app 实例内缓存 auth guard 的已认证用户（setUp 已用管理员 token 请求过；
        // 真实 HTTP 每次请求独立容器不受影响），故先重置 guard，再以普通用户 token 验证无权限被拒
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson("/api/v1/production/operations/{$op1->id}/reports")->assertStatus(403);
    }
}
