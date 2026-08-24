<?php

// RoutingService DAG 校验单测：环路/结构闭合/数量闭合/类型校验（Task Routing-2，纯逻辑直调）

namespace Tests\Unit;

use App\Exceptions\RoutingException;
use App\Models\Category;
use App\Models\Product;
use App\Models\Unit;
use App\Services\RoutingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RoutingServiceTest extends TestCase
{
    use RefreshDatabase;

    private RoutingService $service;

    private Product $finished;

    private Product $semi;

    private Product $raw;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new RoutingService;
        $category = Category::create(['name' => '分类', 'code' => 'CAT-RS']);
        $unit = Unit::create(['name' => '个', 'code' => 'PCS-RS', 'status' => 1]);
        $this->finished = Product::create(['name' => '成品', 'code' => 'FIN-RS', 'type' => 'finished', 'category_id' => $category->id, 'unit_id' => $unit->id, 'status' => 1]);
        $this->semi = Product::create(['name' => '半成品', 'code' => 'SEMI-RS', 'type' => 'semi_finished', 'category_id' => $category->id, 'unit_id' => $unit->id, 'status' => 1]);
        $this->raw = Product::create(['name' => '原料', 'code' => 'RAW-RS', 'type' => 'raw_material', 'category_id' => $category->id, 'unit_id' => $unit->id, 'status' => 1]);
    }

    /** 造节点数组辅助：默认产出半成品，输入原料 */
    private function node(string $no, int $outputId, string $outputQty = '1', array $materials = []): array
    {
        return ['node_no' => $no, 'process_id' => 1, 'name' => '工序'.$no, 'output_product_id' => $outputId,
            'output_qty' => $outputQty, 'is_outsourced' => 0, 'materials' => $materials];
    }

    private function mat(int $id, string $qty = '1'): array
    {
        return ['material_id' => $id, 'qty_per_unit' => $qty, 'unit_id' => 1];
    }

    private function products(): array
    {
        return [$this->finished->id => $this->finished, $this->semi->id => $this->semi, $this->raw->id => $this->raw];
    }

    #[Test]
    public function test_valid_linear_routing_returns_topo_order(): void
    {
        // A(原料→半成品) → B(半成品→成品)
        $nodes = [
            $this->node('OP10', $this->semi->id, '2', [$this->mat($this->raw->id, '2')]),
            $this->node('OP20', $this->finished->id, '1', [$this->mat($this->semi->id, '2')]),
        ];
        $edges = [['from' => 'OP10', 'to' => 'OP20']];
        $order = $this->service->validateAndTopoSort($nodes, $edges, $this->products(), $this->finished, '1');
        $this->assertSame(['OP10', 'OP20'], $order);
    }

    #[Test]
    public function test_rejects_cycle_1701(): void
    {
        $nodes = [
            $this->node('OP10', $this->semi->id, '1', []),
            $this->node('OP20', $this->finished->id, '1', [$this->mat($this->semi->id, '1')]),
        ];
        $edges = [['from' => 'OP10', 'to' => 'OP20'], ['from' => 'OP20', 'to' => 'OP10']];
        try {
            $this->service->validateAndTopoSort($nodes, $edges, $this->products(), $this->finished, '1');
            $this->fail('应抛环路异常');
        } catch (RoutingException $e) {
            $this->assertSame(1701, $e->getCode());
            $this->assertSame('工艺路线存在工序环路', $e->getMessage());
        }
    }

    #[Test]
    public function test_rejects_material_without_source_1702(): void
    {
        // B 直接消耗半成品但无前驱产出它
        $nodes = [$this->node('OP10', $this->finished->id, '1', [$this->mat($this->semi->id, '1')])];
        try {
            $this->service->validateAndTopoSort($nodes, [], $this->products(), $this->finished, '1');
            $this->fail('应抛来源异常');
        } catch (RoutingException $e) {
            $this->assertSame(1702, $e->getCode());
            $this->assertSame('工序[工序OP10]的输入/输出未闭合：材料[半成品]无产出来源', $e->getMessage());
        }
    }

    #[Test]
    public function test_rejects_unconsumed_semi_1703(): void
    {
        // 中间节点产出半成品但后继不消耗（后继只消耗原料）
        $nodes = [
            $this->node('OP10', $this->semi->id, '1', []),
            $this->node('OP20', $this->finished->id, '1', [$this->mat($this->raw->id, '1')]),
        ];
        $edges = [['from' => 'OP10', 'to' => 'OP20']];
        try {
            $this->service->validateAndTopoSort($nodes, $edges, $this->products(), $this->finished, '1');
            $this->fail('应抛未消耗异常');
        } catch (RoutingException $e) {
            $this->assertSame(1703, $e->getCode());
        }
    }

    #[Test]
    public function test_rejects_semi_output_on_end_node_1703(): void
    {
        // 终点节点输出半成品（半成品无后继可消耗）
        $nodes = [$this->node('OP10', $this->semi->id, '1', [])];
        try {
            $this->service->validateAndTopoSort($nodes, [], $this->products(), $this->finished, '1');
            $this->fail('应抛未消耗异常');
        } catch (RoutingException $e) {
            $this->assertSame(1703, $e->getCode());
        }
    }

    #[Test]
    public function test_rejects_qty_mismatch_1704(): void
    {
        // 上游产出 2 半成品，下游只消耗 1
        $nodes = [
            $this->node('OP10', $this->semi->id, '2', []),
            $this->node('OP20', $this->finished->id, '1', [$this->mat($this->semi->id, '1')]),
        ];
        $edges = [['from' => 'OP10', 'to' => 'OP20']];
        try {
            $this->service->validateAndTopoSort($nodes, $edges, $this->products(), $this->finished, '1');
            $this->fail('应抛数量不闭合异常');
        } catch (RoutingException $e) {
            $this->assertSame(1704, $e->getCode());
            $this->assertSame('工序[工序OP10]投入产出数量对不上账', $e->getMessage());
        }
    }

    #[Test]
    public function test_split_flow_consumption_sums_up(): void
    {
        // 一产两耗：上游产 4，两个后继各耗 2，数量闭合
        $nodes = [
            $this->node('OP10', $this->semi->id, '4', []),
            $this->node('OP20', $this->finished->id, '1', [$this->mat($this->raw->id, '1')]),
            $this->node('OP30', $this->finished->id, '1', [$this->mat($this->raw->id, '1')]),
        ];
        // OP20/OP30 各消耗 2 个半成品（并行分支各领一半）
        $nodes[1]['materials'][] = $this->mat($this->semi->id, '2');
        $nodes[2]['materials'][] = $this->mat($this->semi->id, '2');
        $edges = [['from' => 'OP10', 'to' => 'OP20'], ['from' => 'OP10', 'to' => 'OP30']];
        $order = $this->service->validateAndTopoSort($nodes, $edges, $this->products(), $this->finished, '1');
        $this->assertSame(['OP10', 'OP20', 'OP30'], $order);
    }

    #[Test]
    public function test_rejects_routing_product_not_finished_1708(): void
    {
        $nodes = [$this->node('OP10', $this->finished->id, '1', [])];
        try {
            $this->service->validateAndTopoSort($nodes, [], [$this->semi->id => $this->semi], $this->semi, '1');
            $this->fail('应抛成品类型异常');
        } catch (RoutingException $e) {
            $this->assertSame(1708, $e->getCode());
        }
    }

    #[Test]
    public function test_rejects_node_output_finished_on_non_end_node_1709(): void
    {
        // 非终点节点输出成品
        $nodes = [
            $this->node('OP10', $this->finished->id, '1', []),
            $this->node('OP20', $this->finished->id, '1', [$this->mat($this->raw->id, '1')]),
        ];
        $edges = [['from' => 'OP10', 'to' => 'OP20']];
        try {
            $this->service->validateAndTopoSort($nodes, $edges, $this->products(), $this->finished, '1');
            $this->fail('应抛输出类型异常');
        } catch (RoutingException $e) {
            $this->assertSame(1709, $e->getCode());
        }
    }

    #[Test]
    public function test_rejects_node_output_wrong_finished_1709(): void
    {
        // 终点输出成品但与路线成品不一致（用另一个成品冒充）
        $other = Product::create(['name' => '别的成品', 'code' => 'FIN-OTHER', 'type' => 'finished', 'category_id' => $this->finished->category_id, 'unit_id' => $this->finished->unit_id, 'status' => 1]);
        $nodes = [$this->node('OP10', $other->id, '1', [])];
        try {
            $this->service->validateAndTopoSort($nodes, [], [$other->id => $other, $this->finished->id => $this->finished], $this->finished, '1');
            $this->fail('应抛输出类型异常');
        } catch (RoutingException $e) {
            $this->assertSame(1709, $e->getCode());
        }
    }

    #[Test]
    public function test_rejects_finished_as_input_material_1710(): void
    {
        $nodes = [$this->node('OP10', $this->finished->id, '1', [$this->mat($this->finished->id, '1')])];
        try {
            $this->service->validateAndTopoSort($nodes, [], $this->products(), $this->finished, '1');
            $this->fail('应抛输入类型异常');
        } catch (RoutingException $e) {
            $this->assertSame(1710, $e->getCode());
        }
    }
}
