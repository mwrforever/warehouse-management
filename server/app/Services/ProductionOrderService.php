<?php

// 生产工单服务：BOM 展开（物料需求快照 + 工序序列生成）+ 完成率计算

namespace App\Services;

use App\Exceptions\RoutingException;
use App\Models\BomHeader;
use App\Models\Process;
use App\Models\Product;
use App\Models\RoutingHeader;
use App\Models\WorkOrderOperation;

class ProductionOrderService
{
    /**
     * BOM 展开：物料需求快照 + 工序序列（供工单创建/更新时调用）
     *
     * @param  Product  $product  工单成品
     * @param  string  $quantity  工单计划数量（decimal 字符串）
     * @param  BomHeader  $bom  启用版本 BOM（调用方已锁定）
     * @return array{materials: array<int, array{material_id:int, required_qty:string}>, operations: array<int, array{process_id:int, seq:int}>}
     */
    public function expandBom(Product $product, string $quantity, BomHeader $bom): array
    {
        // 物料需求 = 计划数量 ÷ 基准产出 × 用量（bcmath 4 位中间精度防误差，最终 2 位）
        $materials = $bom->items()->get()->map(fn ($i) => [
            'material_id' => $i->material_id,
            'required_qty' => bcmul(bcdiv($quantity, (string) $bom->quantity, 4), (string) $i->quantity, 2),
        ])->values()->all();

        // 工序序列 = 全部启用工序按 sort 升序（V1 设计：BOM 头无工序字段，全量启用工序进入工单）
        $seq = 0;
        $operations = Process::query()
            ->where('status', Process::STATUS_ENABLED)
            ->orderBy('sort')
            ->orderBy('id')
            ->get()
            ->map(function (Process $p) use (&$seq) {
                // 按遍历顺序自增 seq（引用捕获：箭头函数按值捕获会导致 seq 恒为 1）
                return [
                    'process_id' => $p->id,
                    'seq' => ++$seq,
                ];
            })->values()->all();

        return compact('materials', 'operations');
    }

    /**
     * 工艺路线展开：工序 DAG 快照 + 物料节点归属（供工单创建/更新调用，替代无路线时的线性工序展开）
     *
     * 工序行按拓扑序分配 seq（展示序，前端步骤条与画布共用）；
     * 物料归属规则：仅被唯一节点消耗的 BOM 材料落 node_no（领料定位到节点），
     * 多节点共用材料不归属（null=按工单总量领料，防重复归属歧义）。
     *
     * @param  RoutingHeader  $routing  启用版本工艺路线（调用方已按成品锁定取用）
     * @return array{operations: array<int, array{process_id:int, seq:int, node_no:string, output_product_id:?int, is_outsourced:int}>, edges: array<int, array{from:string, to:string}>, nodeOwners: array<int, string>}
     *
     * @throws RoutingException 1701（防御性：保存时已校验无环，此处拓扑复跑兜底存量脏数据）
     */
    public function expandRouting(RoutingHeader $routing): array
    {
        // 节点带材料预加载一次取出（归属统计在集合上完成，禁止循环内懒加载）
        $nodes = $routing->nodes()->with('materials')->orderBy('id')->get();
        $edges = $routing->edges()->get();

        // 边 id 对转 node_no 对（拓扑排序入参形态）
        $nodeArr = $nodes->map(fn ($n) => ['node_no' => $n->node_no])->all();
        $edgeArr = $edges->map(fn ($e) => [
            'from' => $nodes->firstWhere('id', $e->from_node_id)->node_no,
            'to' => $nodes->firstWhere('id', $e->to_node_id)->node_no,
        ])->all();
        // RoutingService 经容器解析避免构造耦合；拓扑序确定性（队列按节点入表序）
        $order = app(RoutingService::class)->topoSort($nodeArr, $edgeArr);

        $seqByNo = array_flip($order); // node_no => 拓扑序（0 起）
        $operations = $nodes->map(fn ($n) => [
            'process_id' => $n->process_id,
            'seq' => $seqByNo[$n->node_no] + 1,
            'node_no' => $n->node_no,
            'output_product_id' => $n->output_product_id,
            'is_outsourced' => (int) $n->is_outsourced,
        ])->all();

        // 物料节点归属：计数被消耗的节点数，>1 的共用材料剔除（按总量领料）
        $ownerCount = [];
        $nodeOwners = [];
        foreach ($nodes as $n) {
            foreach ($n->materials as $m) {
                $ownerCount[$m->material_id] = ($ownerCount[$m->material_id] ?? 0) + 1;
                $nodeOwners[$m->material_id] = $n->node_no;
            }
        }
        foreach ($ownerCount as $materialId => $count) {
            if ($count > 1) {
                unset($nodeOwners[$materialId]);
            }
        }

        return ['operations' => $operations, 'edges' => $edgeArr, 'nodeOwners' => $nodeOwners];
    }

    /**
     * 完成率（%）：completed ÷ quantity × 100，保留 1 位小数（列表进度条展示）
     *
     * @param  string  $completed  累计完工数量
     * @param  string  $quantity  计划数量
     * @return float 完成率（0-100，如 50.0）
     */
    public function progress(string $completed, string $quantity): float
    {
        if (bccomp($quantity, '0', 2) <= 0) {
            return 0.0;
        }

        // 上限钳制 100：completed 超计划（异常/防御数据）时展示 100 而非超 100 失真
        return min(100.0, (float) bcmul(bcdiv($completed, $quantity, 4), '100', 1));
    }

    /** 工序状态中文标签（详情/列表展示，防御未知状态） */
    public function operationStatusLabel(int $status): string
    {
        return WorkOrderOperation::STATUS_LABELS[$status] ?? '未知';
    }
}
