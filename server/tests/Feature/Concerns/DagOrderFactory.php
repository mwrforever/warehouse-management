<?php

// DAG 工单公共造数 trait（委外重构 OutsourcingTest/OutsourcingDagTest/OutsourcingReturnTest 共用）：
// 钻石路线（OP10→OP20/OP30/OP40→OP50，OP30 焊接委外）建单/下达/开工。
// OP30 委外节点输入=原料×2 + 半成品B×1、产出=半成品B——半成品B 为委外加工本体（发出时随原料扣出、
// 回收时加工后入库）；材料流全部经过 RoutingService 1701/1702/1703/1704 DAG 校验（半成品输入须有直接前驱产出、
// 半成品产出须被直接后继消耗且数量闭合）；工单计划 6，供组件发料/回收/退回用例断言与基线注入

namespace Tests\Feature\Concerns;

use App\Models\BomHeader;
use App\Models\Category;
use App\Models\Process;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\Unit;

trait DagOrderFactory
{
    /**
     * DAG 工单辅助：造钻石路线工单（OP10→OP20/OP30/OP40→OP50，OP30 委外）并下达开工
     *
     * OP10 下料产半成品B×3（耗原料×3）；OP20/OP40 分支各耗半成品B×1 产互异半成品 C/D；
     * OP30 焊接（委外）耗 原料×2 + 半成品B×1 产半成品B×2（半成品B=委外加工本体，OP10 为其输入来源、
     * OP50 为其后继消耗方，数量闭合通过 DAG 校验）；OP50 质检汇合耗 B×2/C×2/D×2 产成品 FIN-DAG；
     * 工单计划 6，开工后入度 0 的 OP10 置进行中（其余待开工）。
     * 返回 ['order' => 工单, 'ops' => 按 node_no 键控的工序映射（开工后刷新态）,
     *        'raw'/'semiB'/'semiC'/'semiD'/'fin' => 物料行, 'unit' => 计量单位]
     */
    public function dagOrder(): array
    {
        // 钻石 DAG 物料族：原料 + 半成品B（兼作委外加工本体）+ 分支互异半成品 C/D + 成品
        $cat = Category::create(['name' => 'DAG 物料']);
        $unit = Unit::where('code', 'pc')->firstOrFail();
        $processId = Process::where('code', 'CUT')->firstOrFail()->id;
        $raw = Product::create(['name' => '铝材', 'code' => 'RAW-DAG', 'type' => 'raw_material', 'category_id' => $cat->id, 'unit_id' => $unit->id, 'status' => 1]);
        $semiB = Product::create(['name' => '半成品B', 'code' => 'SEMI-DB', 'type' => 'semi_finished', 'category_id' => $cat->id, 'unit_id' => $unit->id, 'status' => 1]);
        $semiC = Product::create(['name' => '半成品C', 'code' => 'SEMI-DC', 'type' => 'semi_finished', 'category_id' => $cat->id, 'unit_id' => $unit->id, 'status' => 1]);
        $semiD = Product::create(['name' => '半成品D', 'code' => 'SEMI-DD', 'type' => 'semi_finished', 'category_id' => $cat->id, 'unit_id' => $unit->id, 'status' => 1]);
        $fin = Product::create(['name' => '机柜DAG', 'code' => 'FIN-DAG', 'type' => 'finished', 'category_id' => $cat->id, 'unit_id' => $unit->id, 'status' => 1]);
        // 成品启用 BOM：原料×3 + 半成品B×1（工单创建前置，与路线数量口径一致）
        $bom = BomHeader::create(['code' => 'BOM-DAG-1', 'product_id' => $fin->id, 'version' => 'v1', 'quantity' => 1, 'status' => 1]);
        $bom->items()->createMany([
            ['material_id' => $raw->id, 'quantity' => 3, 'unit_id' => $unit->id],
            ['material_id' => $semiB->id, 'quantity' => 1, 'unit_id' => $unit->id],
        ]);

        // 启用钻石路线（OP30 委外）：下达后工单按 DAG 展开快照节点/边
        $this->withToken($this->token)->postJson('/api/v1/routings', [
            'product_id' => $fin->id, 'version' => 'v1', 'quantity' => 3, 'status' => 1, 'remark' => null,
            'nodes' => [
                ['node_no' => 'OP10', 'process_id' => $processId, 'name' => '下料', 'output_product_id' => $semiB->id, 'output_qty' => 3, 'is_outsourced' => 0, 'remark' => null, 'materials' => [
                    ['material_id' => $raw->id, 'qty_per_unit' => 3, 'unit_id' => $unit->id],
                ]],
                ['node_no' => 'OP20', 'process_id' => $processId, 'name' => '冲压', 'output_product_id' => $semiC->id, 'output_qty' => 2, 'is_outsourced' => 0, 'remark' => null, 'materials' => [
                    ['material_id' => $semiB->id, 'qty_per_unit' => 1, 'unit_id' => $unit->id],
                ]],
                // 委外节点：输入=原料×2 + 半成品B×1（半成品B 为加工本体，来源 OP10），产出=加工后半成品B×2
                ['node_no' => 'OP30', 'process_id' => $processId, 'name' => '焊接', 'output_product_id' => $semiB->id,
                    'output_qty' => 2, 'is_outsourced' => 1, 'remark' => null, 'materials' => [
                        ['material_id' => $raw->id, 'qty_per_unit' => 2, 'unit_id' => $unit->id],
                        ['material_id' => $semiB->id, 'qty_per_unit' => 1, 'unit_id' => $unit->id],
                    ]],
                ['node_no' => 'OP40', 'process_id' => $processId, 'name' => '组装', 'output_product_id' => $semiD->id,
                    'output_qty' => 2, 'is_outsourced' => 0, 'remark' => null, 'materials' => [
                        ['material_id' => $semiB->id, 'qty_per_unit' => 1, 'unit_id' => $unit->id],
                    ]],
                ['node_no' => 'OP50', 'process_id' => $processId, 'name' => '质检', 'output_product_id' => $fin->id,
                    'output_qty' => 1, 'is_outsourced' => 0, 'remark' => null, 'materials' => [
                        ['material_id' => $semiB->id, 'qty_per_unit' => 2, 'unit_id' => $unit->id],
                        ['material_id' => $semiC->id, 'qty_per_unit' => 2, 'unit_id' => $unit->id],
                        ['material_id' => $semiD->id, 'qty_per_unit' => 2, 'unit_id' => $unit->id],
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
        $this->withToken($this->token)
            ->postJson("/api/v1/production/orders/{$order->id}/release")
            ->assertJsonPath('code', 0);
        $this->withToken($this->token)
            ->postJson("/api/v1/production/orders/{$order->id}/start")
            ->assertJsonPath('code', 0);

        // 按 node_no 键控的工序映射（开工后已刷新，直接承载起点状态断言）
        return [
            'order' => $order,
            'ops' => $order->operations()->get()->keyBy('node_no'),
            'raw' => $raw, 'semiB' => $semiB,
            'semiC' => $semiC, 'semiD' => $semiD, 'fin' => $fin,
            'unit' => $unit,
        ];
    }
}
