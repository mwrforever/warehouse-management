<?php

// 工艺路线服务：DAG 校验（环路/结构闭合/数量闭合）+ 拓扑排序纯逻辑；被 RoutingController 保存与工单展开复用

namespace App\Services;

use App\Exceptions\RoutingException;
use App\Models\DocumentSequence;
use App\Models\Product;
use App\Models\RoutingEdge;
use App\Models\RoutingHeader;
use App\Models\RoutingNode;
use App\Models\RoutingNodeMaterial;
use App\Support\DeletionGuard;
use Illuminate\Support\Facades\DB;

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

    /**
     * 保存（新建/更新）：载荷归一化+链接结构校验 → 被工单引用保护（1705）→
     * 事务内「锁成品行 → 启用唯一 1707 → DAG 校验 → 取号建头/改头 → 全量替换节点/材料/边」
     *
     * @param  array  $data  已过 SaveRoutingRequest 格式校验的原始载荷（nodes/edges 原始形态）
     *
     * @throws RoutingException 链接结构 422 / 被引用 1705 / 启用唯一 1707 / DAG 校验 1701~1704、1708~1710
     */
    public function persist(array $data, ?RoutingHeader $routing, DocumentSequenceService $sequenceService): RoutingHeader
    {
        // 载荷归一化 + 链接结构校验（422，原控制器 validatePayload 后半段下沉；格式 422 已提前至 FormRequest）
        $data = $this->normalizePayload($data);
        // 被工单引用（order.routing_id 关联）：仅可启停，禁止改结构（1705，读检查无需持锁）
        if ($routing && DeletionGuard::referenced('production_orders', 'routing_id', $routing->id)) {
            throw new RoutingException('工艺路线已被生产工单使用，仅可启用/停用', 1705);
        }

        return DB::transaction(function () use ($data, $routing, $sequenceService) {
            // 锁成品行串行化同成品并发启停（同 BOM 口径）
            Product::whereKey($data['product_id'])->lockForUpdate()->first();
            if (
                $data['status'] === RoutingHeader::STATUS_ENABLED
                && RoutingHeader::where('product_id', $data['product_id'])
                    ->where('status', RoutingHeader::STATUS_ENABLED)
                    ->when($routing, fn ($q) => $q->where('id', '!=', $routing->id))->exists()
            ) {
                throw new RoutingException('该成品已有启用版本的工艺路线', 1707);
            }

            // 商品预取（节点输出+全部材料 + 路线成品），DAG 校验一次遍历
            $productIds = [$data['product_id']];
            foreach ($data['nodes'] as $n) {
                $productIds[] = $n['output_product_id'];
                foreach ($n['materials'] as $m) {
                    $productIds[] = $m['material_id'];
                }
            }
            $products = Product::whereIn('id', array_unique($productIds))->get()->keyBy('id');
            $routingProduct = $products->get($data['product_id']);
            $this->validateAndTopoSort($data['nodes'], $data['edges'], $products->all(), $routingProduct, $data['quantity']);

            if ($routing) {
                $routing->update([
                    'product_id' => $data['product_id'], 'version' => $data['version'],
                    'quantity' => $data['quantity'], 'status' => $data['status'], 'remark' => $data['remark'],
                ]);
                // DAG 全量替换：节点删除级联材料/边（FK cascade）
                $routing->nodes()->delete();
            } else {
                $routing = $sequenceService->nextNoByConfig(
                    DocumentSequence::TYPE_RTG,
                    fn (string $code) => RoutingHeader::create([
                        'code' => $code, 'product_id' => $data['product_id'], 'version' => $data['version'],
                        'quantity' => $data['quantity'], 'status' => $data['status'], 'remark' => $data['remark'],
                    ]),
                    fn (string $prefix, string $dateKey) => ($no = RoutingHeader::where('code', 'like', $prefix.date('Ymd').'%')
                        ->orderByDesc('code')->value('code')) ? DocumentSequenceService::seqFromNo($no, $prefix, $dateKey) : 0,
                );
            }

            $nodeIdByNo = [];
            // 节点逐行 INSERT（需回填自增 id 供下方边映射，不可批量）；材料/边为无 id 依赖段改批量插入（P1-D-1）：
            // Relation::createMany 底层逐模型 save 仍是逐行 INSERT，故走查询构造器 insert 单语句多行落库，
            // 行内显式携带外键与时间戳（查询构造器不自动补 Eloquent 时间戳），与逐行 create 落库字节等价
            $now = now();
            foreach ($data['nodes'] as $n) {
                $node = $routing->nodes()->create([
                    'node_no' => $n['node_no'], 'process_id' => $n['process_id'], 'name' => $n['name'],
                    'output_product_id' => $n['output_product_id'], 'output_qty' => $n['output_qty'],
                    'is_outsourced' => $n['is_outsourced'], 'remark' => $n['remark'],
                ]);
                $nodeIdByNo[$n['node_no']] = $node->id;
                // 节点材料批量落库（每节点 1 条 INSERT，替代逐材料 N 条）
                if ($n['materials'] !== []) {
                    RoutingNodeMaterial::query()->insert(array_map(
                        fn (array $m) => [
                            'node_id' => $node->id,
                            'material_id' => $m['material_id'],
                            'qty_per_unit' => $m['qty_per_unit'],
                            'unit_id' => $m['unit_id'],
                            'created_at' => $now,
                            'updated_at' => $now,
                        ],
                        $n['materials'],
                    ));
                }
            }
            // 边批量落库（1 条 INSERT，替代逐边 N 条）
            if ($data['edges'] !== []) {
                RoutingEdge::query()->insert(array_map(
                    fn (array $e) => [
                        'routing_id' => $routing->id,
                        'from_node_id' => $nodeIdByNo[$e['from']],
                        'to_node_id' => $nodeIdByNo[$e['to']],
                        'created_at' => $now,
                        'updated_at' => $now,
                    ],
                    $data['edges'],
                ));
            }

            return $routing;
        }, 2);
    }

    /**
     * 删除：被工单引用不可删（1706）；头删除级联节点/材料/边（FK cascade）
     *
     * @throws RoutingException 被工单引用 1706
     */
    public function delete(RoutingHeader $routing): void
    {
        // 被工单引用保护（读检查无需持锁；原控制器 DeletionGuard 检查下沉）
        if (DeletionGuard::referenced('production_orders', 'routing_id', $routing->id)) {
            throw new RoutingException('工艺路线已被生产工单使用，不可删除', 1706);
        }
        $routing->delete();
    }

    /** 启用自动停用同成品其他版本（同 BOM toggle） */
    public function toggle(RoutingHeader $routing, int $status): void
    {
        DB::transaction(function () use ($routing, $status) {
            // 先锁成品行串行化同成品并发启停（B-103）：与 persist 写入路径同锁序，
            // 否则 toggle 与保存交错时各自的启用判断互看不到对方未提交的变更，可产生双启用版本
            Product::whereKey($routing->product_id)->lockForUpdate()->first();
            if ($status === RoutingHeader::STATUS_ENABLED) {
                RoutingHeader::where('product_id', $routing->product_id)->where('status', RoutingHeader::STATUS_ENABLED)
                    ->where('id', '!=', $routing->id)->update(['status' => RoutingHeader::STATUS_DISABLED]);
            }
            $routing->update(['status' => $status]);
        });
    }

    /** 完整 DAG 图（画布回显/查看），预加载消除关系懒加载 N+1 */
    public function graph(RoutingHeader $routing): array
    {
        // 一次预加载全部被消费的关系（含边端点节点）；下方直接消费已加载集合，禁止再发起关系查询
        $routing->load([
            'product', 'nodes.materials.material', 'nodes.materials.unit',
            'nodes.process', 'nodes.outputProduct', 'edges.fromNode', 'edges.toNode',
        ]);

        return [
            'routing' => [
                'id' => $routing->id, 'code' => $routing->code, 'product_id' => $routing->product_id,
                'product_name' => $routing->product?->name, 'version' => $routing->version,
                'quantity' => (float) $routing->quantity, 'status' => (int) $routing->status,
                'remark' => $routing->remark,
            ],
            // sortBy 为集合内存排序（等价原查询的 orderBy('id')），输出顺序不变
            'nodes' => $routing->nodes->sortBy('id')->map(fn (RoutingNode $n) => [
                'id' => $n->id, 'node_no' => $n->node_no, 'process_id' => $n->process_id,
                'process_name' => $n->process?->name, 'name' => $n->name,
                'output_product_id' => $n->output_product_id, 'output_product_name' => $n->outputProduct?->name,
                'output_qty' => (float) $n->output_qty, 'is_outsourced' => (int) $n->is_outsourced,
                'remark' => $n->remark,
                'materials' => $n->materials->map(fn (RoutingNodeMaterial $m) => [
                    'id' => $m->id, 'material_id' => $m->material_id, 'material_name' => $m->material?->name,
                    'qty_per_unit' => (float) $m->qty_per_unit, 'unit_id' => $m->unit_id, 'unit_name' => $m->unit?->name,
                ])->all(),
            ]),
            'edges' => $routing->edges->sortBy('id')->map(fn (RoutingEdge $e) => [
                'id' => $e->id, 'from_node_no' => $e->fromNode?->node_no, 'to_node_no' => $e->toNode?->node_no,
            ]),
        ];
    }

    /**
     * 载荷归一化 + 链接结构校验（原控制器 validatePayload 后半段下沉）
     *
     * 结构校验（均 422，格式层语义）：边端点必须存在于节点集 / 连线重复（同源多边合法，
     * 故不使用 distinct 规则）/ 同节点材料重复（唯一索引兜底前的友好报错）。
     * 归一化：数值字段转 int/string、缺省默认值、edges 的 from_node_no/to_node_no 键转 from/to。
     *
     * @param  array  $data  已过 SaveRoutingRequest 格式校验的原始载荷
     * @return array 归一化后载荷（persist 事务内各步消费的形态）
     *
     * @throws RoutingException 链接结构 422（连线端点不存在/连线重复/重复输入材料）
     */
    private function normalizePayload(array $data): array
    {
        // 边端点必须在节点集内 + 去重（重复边 422；同源多边合法）
        $nodeNos = array_column($data['nodes'], 'node_no');
        $seen = [];
        foreach ($data['edges'] ?? [] as $e) {
            if (! in_array($e['from_node_no'], $nodeNos, true) || ! in_array($e['to_node_no'], $nodeNos, true)) {
                throw new RoutingException('连线端点不存在于节点集中', 422);
            }
            $key = $e['from_node_no'].'>'.$e['to_node_no'];
            if (isset($seen[$key])) {
                throw new RoutingException('连线重复', 422);
            }
            $seen[$key] = true;
        }
        // 同节点材料去重（唯一索引兜底前的友好报错）
        foreach ($data['nodes'] as $n) {
            $ids = array_column($n['materials'] ?? [], 'material_id');
            if (count($ids) !== count(array_unique($ids))) {
                throw new RoutingException('工序['.$n['name'].']存在重复输入材料', 422);
            }
        }

        // 归一化默认值（与原控制器 validatePayload 逐条等价）
        return [
            'product_id' => (int) $data['product_id'],
            'version' => $data['version'],
            'quantity' => (string) ($data['quantity'] ?? 1),
            'status' => (int) ($data['status'] ?? RoutingHeader::STATUS_ENABLED),
            'remark' => $data['remark'] ?? null,
            'nodes' => array_map(fn ($n) => [
                'node_no' => $n['node_no'],
                'process_id' => (int) $n['process_id'],
                'name' => $n['name'],
                'output_product_id' => (int) $n['output_product_id'],
                'output_qty' => (string) ($n['output_qty'] ?? 1),
                'is_outsourced' => (int) ($n['is_outsourced'] ?? 0),
                'remark' => $n['remark'] ?? null,
                'materials' => array_map(fn ($m) => [
                    'material_id' => (int) $m['material_id'],
                    'qty_per_unit' => (string) $m['qty_per_unit'],
                    'unit_id' => (int) $m['unit_id'],
                ], $n['materials'] ?? []),
            ], $data['nodes']),
            'edges' => array_map(
                fn ($e) => ['from' => $e['from_node_no'], 'to' => $e['to_node_no']],
                $data['edges'] ?? [],
            ),
        ];
    }
}
