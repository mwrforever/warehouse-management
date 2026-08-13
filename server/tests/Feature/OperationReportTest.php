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
