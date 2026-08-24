<?php

// 工艺路线服务：DAG 校验（环路/结构闭合/数量闭合）+ 拓扑排序纯逻辑；被 RoutingController 保存与工单展开复用

namespace App\Services;

use App\Exceptions\RoutingException;
use App\Models\Product;

class RoutingService
{
    /**
     * 校验 DAG 并返回拓扑序（保存时调用）
     *
     * 校验链：1708 路线成品类型 → 1710 输入类型格式 → 1701 环路 → 1702 输入来源 →
     * 1703 半成品消耗/终点输出 → 1704 数量闭合（产出 vs 直接后继消耗合计，×基准折算）
     *
     * @param  array<int, array{node_no:string, process_id:int, name:string, output_product_id:int, output_qty:string, is_outsourced:int, materials:array<int, array{material_id:int, qty_per_unit:string, unit_id:int}>}>  $nodes
     * @param  array<int, array{from:string, to:string}>  $edges  node_no 对
     * @param  array<int, Product>  $products  涉及商品 id=>模型（控制器 whereIn 预取）
     * @param  string  $baseQty  基准产出（decimal 字符串）
     * @return array<int, string> 拓扑序 node_no 列表
     *
     * @throws RoutingException 1701/1702/1703/1704/1708/1709/1710
     */
    public function validateAndTopoSort(array $nodes, array $edges, array $products, Product $routingProduct, string $baseQty): array
    {
        if ($routingProduct->type !== 'finished') {
            throw new RoutingException('工艺路线关联商品必须是成品', 1708);
        }

        // 直接前驱/后继邻接表（材料流与依赖流一致：只认直接边）
        $preds = $succs = [];
        foreach ($edges as $e) {
            $succs[$e['from']][] = $e['to'];
            $preds[$e['to']][] = $e['from'];
        }

        $order = $this->topoSort($nodes, $edges);

        $byNo = collect($nodes)->keyBy('node_no');
        foreach ($nodes as $n) {
            $output = $products[$n['output_product_id']] ?? null;
            if (! $output) {
                throw new RoutingException('工序['.$n['name'].']的输出商品不存在', 1709);
            }

            // 输入材料校验：类型 1710 + 来源 1702（原料或直接前驱的输出半成品）
            foreach ($n['materials'] as $m) {
                $material = $products[$m['material_id']] ?? null;
                if (! $material) {
                    throw new RoutingException('工序['.$n['name'].']的输入材料不存在', 1710);
                }
                if ($material->type === 'finished') {
                    throw new RoutingException('工序['.$n['name'].']的输入材料必须是原料或半成品', 1710);
                }
                if ($material->type === 'semi_finished') {
                    $hasSource = false;
                    foreach ($preds[$n['node_no']] ?? [] as $pno) {
                        $pred = $byNo->get($pno);
                        if ($pred && (int) $pred['output_product_id'] === (int) $m['material_id']) {
                            $hasSource = true;
                            break;
                        }
                    }
                    if (! $hasSource) {
                        throw new RoutingException('工序['.$n['name'].']的输入/输出未闭合：材料['.$material->name.']无产出来源', 1702);
                    }
                }
            }

            // 输出校验 1703/1709：半成品必须被直接后继消耗；成品只允许出现在终点且必须等于路线成品
            $isEnd = empty($succs[$n['node_no']]);
            if ($output->type === 'semi_finished') {
                $consumed = false;
                foreach ($succs[$n['node_no']] ?? [] as $sno) {
                    $succ = $byNo->get($sno);
                    if ($succ && collect($succ['materials'])->contains(fn ($m) => (int) $m['material_id'] === (int) $n['output_product_id'])) {
                        $consumed = true;
                        break;
                    }
                }
                if (! $consumed) {
                    throw new RoutingException('半成品['.$output->name.']未被任何后继工序消耗', 1703);
                }
            } elseif ($output->type === 'finished') {
                if (! $isEnd || (int) $n['output_product_id'] !== (int) $routingProduct->id) {
                    throw new RoutingException('工序['.$n['name'].']的输出必须是半成品，或终点工序输出路线成品', 1709);
                }
            } else {
                throw new RoutingException('工序['.$n['name'].']的输出必须是半成品或成品', 1709);
            }
        }

        // 数量闭合 1704：每个半成品产出（output_qty×基准）= 直接后继消耗合计（Σ qty_per_unit×基准）
        foreach ($nodes as $n) {
            $output = $products[$n['output_product_id']];
            if ($output->type !== 'semi_finished') {
                continue;
            }
            $production = bcmul((string) $n['output_qty'], $baseQty, 4);
            $consumption = '0';
            foreach ($succs[$n['node_no']] ?? [] as $sno) {
                $succ = $byNo->get($sno);
                if (! $succ) {
                    continue;
                }
                foreach ($succ['materials'] as $m) {
                    if ((int) $m['material_id'] === (int) $n['output_product_id']) {
                        $consumption = bcadd($consumption, bcmul((string) $m['qty_per_unit'], $baseQty, 4), 4);
                    }
                }
            }
            if (bccomp($production, $consumption, 4) !== 0) {
                throw new RoutingException('工序['.$n['name'].']投入产出数量对不上账', 1704);
            }
        }

        return $order;
    }

    /**
     * 拓扑排序（Kahn，队列按节点入表序保证确定性；工单展开 seq 分配复用）
     *
     * @param  array<int, array{node_no:string, ...}>  $nodes
     * @param  array<int, array{from:string, to:string}>  $edges
     * @return array<int, string> 拓扑序 node_no 列表
     *
     * @throws RoutingException 1701 存在环路（含自环）
     */
    public function topoSort(array $nodes, array $edges): array
    {
        $indeg = [];
        $succs = [];
        foreach ($nodes as $n) {
            $indeg[$n['node_no']] = 0;
            $succs[$n['node_no']] = [];
        }
        foreach ($edges as $e) {
            // 自环 from==to 直接判环
            if ($e['from'] === $e['to']) {
                throw new RoutingException('工艺路线存在工序环路', 1701);
            }
            $succs[$e['from']][] = $e['to'];
            $indeg[$e['to']]++;
        }
        $queue = [];
        foreach ($nodes as $n) {
            if ($indeg[$n['node_no']] === 0) {
                $queue[] = $n['node_no'];
            }
        }
        $order = [];
        while ($queue !== []) {
            $no = array_shift($queue);
            $order[] = $no;
            foreach ($succs[$no] as $next) {
                if (--$indeg[$next] === 0) {
                    $queue[] = $next;
                }
            }
        }
        if (count($order) !== count($nodes)) {
            throw new RoutingException('工艺路线存在工序环路', 1701);
        }

        return $order;
    }
}
