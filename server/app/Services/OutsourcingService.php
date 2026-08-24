<?php

// 委外服务：从工序节点预填组装（节点输入材料×单位用量 + 回收品 + 剩余委外量）与委外载荷组件校验（bcmath 权威）

namespace App\Services;

use App\Exceptions\ProductionException;
use App\Models\InventoryBalance;
use App\Models\OutsourcingOrder;
use App\Models\RoutingHeader;
use App\Models\RoutingNode;
use App\Models\WorkOrderOperation;

class OutsourcingService
{
    /**
     * 从工序节点组装委外预填数据
     * 取数链：工序行 node_no → production_orders.routing_id → routing_nodes.materials（单位用量）
     * 组件库存=Σ全仓余额（读快照）；剩余可委外量=工单数量−Σ非草稿委外单（bcmath）
     *
     * @throws ProductionException 422（工单无路线/节点非委外/节点已完成/节点缺失）
     */
    public function fromOperation(int $operationId): array
    {
        $op = WorkOrderOperation::with('process')->findOrFail($operationId);
        $order = $op->order()->firstOrFail();
        if (! $order->routing_id || ! $op->node_no) {
            throw new ProductionException('该工单没有工艺路线，不可委外', 422);
        }
        if ((int) $op->is_outsourced !== 1) {
            throw new ProductionException('该工序不是委外工序', 422);
        }
        if ((int) $op->status === WorkOrderOperation::STATUS_DONE) {
            throw new ProductionException('该工序已完成，不可委外', 422);
        }
        $routing = RoutingHeader::with('nodes.materials.material', 'nodes.materials.unit')->findOrFail($order->routing_id);
        $node = $routing->nodes->firstWhere('node_no', $op->node_no);
        if (! $node) {
            throw new ProductionException('工艺路线节点不存在', 422);
        }

        // 已委外量：同节点全部非草稿委外单合计（SQL SUM 归一 bcmath）
        $outsourced = bcadd((string) OutsourcingOrder::where('operation_id', $operationId)
            ->where('status', '!=', OutsourcingOrder::STATUS_DRAFT)->sum('quantity'), '0', 2);
        $plan = (string) $order->quantity;

        // 组件库存：Σ全仓余额（每组件一行，SUM 形态归一——跨库返回形态不一统一 bcmath）
        $stockRows = InventoryBalance::query()
            ->whereIn('product_id', $node->materials->pluck('material_id'))
            ->selectRaw('product_id, SUM(quantity) as total')->groupBy('product_id')->get()->keyBy('product_id');

        return [
            'operation_id' => $op->id, 'node_no' => $op->node_no, 'process_name' => $op->process?->name,
            'order_id' => $order->id, 'order_no' => $order->no,
            'plan_qty' => $plan, 'outsourced_qty' => $outsourced,
            'remaining_qty' => bcsub($plan, $outsourced, 2),
            'output_product_id' => $node->output_product_id,
            'output_product_name' => $node->outputProduct?->name,
            'items' => $node->materials->map(fn ($m) => [
                'material_id' => $m->material_id, 'material_name' => $m->material?->name,
                'material_code' => $m->material?->code, 'qty_per_unit' => (string) $m->qty_per_unit,
                'unit_id' => $m->unit_id, 'unit_name' => $m->unit?->name,
                'stock' => bcadd((string) ($stockRows->get($m->material_id)?->getAttribute('total') ?? '0'), '0', 2),
            ])->values(),
        ];
    }

    /**
     * 取工序对应的工艺路线节点（store/update 用，逻辑同 fromOperation 的取数段）
     * 无路线/无 node_no → 422「该工单没有工艺路线，不可委外」：仅 is_outsourced=1 的路线节点可委外
     * （spec §5 成品口径收敛后与 fromOperation 语义一致）；有路线但路由头/节点缺失属数据异常：
     * 显式 422，禁止静默降级
     *
     * @throws ProductionException 422（无路线/无 node_no/路由头或节点缺失）/ 数据异常（工序/工单不存在）
     */
    public function routingNodeForOperation(int $operationId): RoutingNode
    {
        $op = WorkOrderOperation::findOrFail($operationId);
        $order = $op->order()->firstOrFail();
        if (! $order->routing_id || ! $op->node_no) {
            throw new ProductionException('该工单没有工艺路线，不可委外', 422);
        }
        $routing = RoutingHeader::with('nodes.materials.material', 'nodes.materials.unit')->find($order->routing_id);
        if (! $routing) {
            throw new ProductionException('工艺路线节点不存在', 422);
        }
        $node = $routing->nodes->firstWhere('node_no', $op->node_no);
        if (! ($node instanceof RoutingNode)) {
            throw new ProductionException('工艺路线节点不存在', 422);
        }

        return $node;
    }

    /**
     * 校验组件载荷：应发 > 0 且 ≤ 单位用量×委外量（后端权威，bcmath 4 位中间精度）
     *
     * @param  array<int, array{material_id:int, required_qty:string, unit_id:int}>  $items
     * @return array<int, array{material_id:int, required_qty:string, unit_id:int}>
     *
     * @throws ProductionException 422（空组件/非节点材料/应发非正/超折算上限/重复物料）
     */
    public function validateItems(array $items, RoutingNode $node, string $quantity): array
    {
        if ($items === []) {
            throw new ProductionException('至少需要一个发料组件', 422);
        }
        $seen = [];
        foreach ($items as $i) {
            $mat = $node->materials->firstWhere('material_id', (int) $i['material_id']);
            if (! $mat) {
                throw new ProductionException('发料组件不在该节点输入材料清单中', 422);
            }
            if (bccomp((string) $i['required_qty'], '0', 2) <= 0) {
                throw new ProductionException('应发数量必须大于 0', 422);
            }
            $cap = bcmul((string) $mat->qty_per_unit, $quantity, 4);
            if (bccomp((string) $i['required_qty'], $cap, 2) > 0) {
                throw new ProductionException('应发数量超过单位用量折算上限', 422);
            }
            if (isset($seen[$i['material_id']])) {
                throw new ProductionException('发料组件重复', 422);
            }
            $seen[$i['material_id']] = true;
        }

        return $items;
    }
}
