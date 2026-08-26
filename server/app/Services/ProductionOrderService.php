<?php

// 生产工单领域服务：草稿创建/更新/删除、下达/开工/完工/关闭（状态机）、工序报工（D-1 写操作全部下沉）
// + BOM 展开（物料需求快照 + 工序序列生成）+ 完成率计算

namespace App\Services;

use App\Exceptions\ProductionException;
use App\Exceptions\RoutingException;
use App\Models\BomHeader;
use App\Models\DocumentSequence;
use App\Models\FinishedInbound;
use App\Models\InventoryBalance;
use App\Models\OperationReport;
use App\Models\OutsourcingOrder;
use App\Models\PickList;
use App\Models\Process;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\ReturnList;
use App\Models\RoutingHeader;
use App\Models\WorkOrderOperation;
use App\Models\WorkOrderOperationEdge;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 生产工单领域服务（D-1：控制器写操作全部下沉至此）
 *
 * 承接原控制器的工单写流程：草稿 CRUD、下达/开工/完工/关闭状态流转、工序报工，
 * 全部在 DB::transaction 内「锁行 → 复查状态 → 变更」执行。
 * 锁序铁律（与委外回收/报工/完工全局同序，防 ABBA 死锁环）：
 *  - 工单创建/更新/删除/下达/关闭：先锁工单行（或成品行）→ 明细 → 库存（只读）；
 *  - 开工/完工/报工：先锁工序行（id 升序全集）→ 再锁工单行（op→order 段全局同序，
 *    禁止先锁工单行再锁工序行，否则与并发报工/完工构成死锁环）。
 * 业务失败统一抛 ProductionException（业务码沿用原口径 1501~1512），由全局异常处理器
 * 渲染 {code, message, data} 信封，与原控制器 fail() 响应字节级等价。
 * 非线程安全：同一工单的并发写依赖数据库行锁串行化，勿在事务外组合调用多个写方法。
 */
class ProductionOrderService
{
    public function __construct(private DocumentSequenceService $sequenceService) {}

    /**
     * 新建草稿：事务内「锁成品行 → 校验启用 BOM（1501）→ 单号持久序列 → BOM 展开快照物料需求与工序序列」
     *
     * 数量 ≤ 0 → 1502（业务码，生产 spec 明确）；请求携带 bom_id 忽略，一律以启用版本为准。
     * 无启用工艺路线时回退旧逻辑（全量启用工序线性快照），告警文案经引用参数带出供前端提示（RTG-06）。
     * 事务第 2 参数为死锁(1213)重试次数：序列行首建间隙锁死锁败方整体回滚后重跑闭包重新取号+重新展开，幂等安全。
     *
     * @param  array  $data  已过 SaveProductionOrderRequest 格式校验的载荷（product_id/quantity/plan_date/bom_id/remark）
     * @param  string|null  $routingWarning  引用带出回退告警文案（无启用工艺路线时赋值，供控制器响应携带）
     * @return ProductionOrder 新建的工单模型（含单号/id，供控制器回显）
     *
     * @throws ProductionException 数量≤0 1502 / 无启用 BOM 1501
     */
    public function create(array $data, ?string &$routingWarning = null): ProductionOrder
    {
        // 数量 ≤ 0 走业务码 1502（生产 spec 明确，与采购/销售 422 不同）；bccomp 判正（D-3 铁律禁浮点比较）
        if (bccomp((string) $data['quantity'], '0', 2) <= 0) {
            throw new ProductionException('数量必须大于 0', 1502);
        }

        $order = DB::transaction(function () use ($data, &$routingWarning) {
            // 锁成品行：与 BOM 启用切换并发时串行化（1501 判定读一致）
            $product = Product::whereKey($data['product_id'])->lockForUpdate()->firstOrFail();
            // 启用版本唯一（BOM 模块不变式），按 id 倒序取最新启用版
            $bom = BomHeader::where('product_id', $product->id)
                ->where('status', BomHeader::STATUS_ENABLED)->orderByDesc('id')->first();
            if (! $bom) {
                throw new ProductionException('该成品没有启用版本的 BOM', 1501);
            }
            $expansion = $this->expandBom($product, (string) $data['quantity'], $bom);

            // 取启用工艺路线（同成品启用唯一，同 BOM 口径）：有→DAG 展开；无→旧逻辑全量工序快照 + 告警（RTG-06）
            $routing = RoutingHeader::where('product_id', $product->id)
                ->where('status', RoutingHeader::STATUS_ENABLED)->orderByDesc('id')->first();
            if ($routing) {
                $rex = $this->expandRouting($routing);
            } else {
                // 无启用工艺路线：沿用旧逻辑（全量启用工序线性快照）并记告警日志
                Log::warning('工单创建：成品无启用工艺路线，回退全量工序快照', ['product_id' => $product->id]);
                $rex = null;
                $routingWarning = '该成品无启用工艺路线，已按全量启用工序展开';
            }

            $order = $this->sequenceService->nextNoByConfig(
                DocumentSequence::TYPE_MO,
                fn (string $no) => ProductionOrder::create([
                    'no' => $no,
                    'product_id' => $data['product_id'],
                    'quantity' => $data['quantity'],
                    'plan_date' => $data['plan_date'],
                    'bom_id' => $bom->id,
                    // 路线快照锚定：null=旧逻辑展开（存量单不回写）
                    'routing_id' => $routing?->id,
                    'status' => ProductionOrder::STATUS_DRAFT,
                    'completed_qty' => 0,
                    'created_by' => auth()->id(),
                    'remark' => $data['remark'] ?? null,
                ]),
                // legacyMax 只取当日最大单号一行（orderByDesc+value 单查，P1-5：同日前缀字典序=序号序）
                fn (string $prefix, string $dateKey) => ($no = ProductionOrder::where('no', 'like', $prefix.date('Ymd').'%')
                    ->orderByDesc('no')->value('no')) ? DocumentSequenceService::seqFromNo($no, $prefix, $dateKey) : 0,
            );
            // BOM 展开结果快照：物料需求（order_id+material_id 唯一）+ 工序序列（order_id+seq 唯一）
            $order->materials()->createMany(array_map(fn ($m) => [
                'material_id' => $m['material_id'],
                'required_qty' => $m['required_qty'],
                'issued_qty' => 0,
                // 物料归属工序节点：仅唯一消费节点时落 node_no（多节点共用按总量领料；无路线恒 null）
                'node_no' => $rex['nodeOwners'][$m['material_id']] ?? null,
            ], $expansion['materials']));
            if ($rex) {
                // DAG 工序快照：node_no/输出产品/委外标记随节点落到工序行
                $createdOps = $order->operations()->createMany(array_map(fn ($op) => [
                    'process_id' => $op['process_id'],
                    'seq' => $op['seq'],
                    'node_no' => $op['node_no'],
                    'output_product_id' => $op['output_product_id'],
                    'is_outsourced' => $op['is_outsourced'],
                    'status' => WorkOrderOperation::STATUS_PENDING,
                    'qualified_qty' => 0,
                    'defective_qty' => 0,
                    'hours' => 0,
                ], $rex['operations']));
                // 边快照：node_no → 工序 id 映射后落边表（依赖边随快照固化，后续路线改版不影响本单）
                $opIdByNo = [];
                foreach ($createdOps as $i => $op) {
                    $opIdByNo[$rex['operations'][$i]['node_no']] = $op->id;
                }
                $order->edges()->createMany(array_map(fn ($e) => [
                    'from_operation_id' => $opIdByNo[$e['from']],
                    'to_operation_id' => $opIdByNo[$e['to']],
                ], $rex['edges']));
            } else {
                // 旧逻辑：全量启用工序线性快照（无 node_no/输出产品/委外标记）
                $order->operations()->createMany(array_map(fn ($op) => [
                    'process_id' => $op['process_id'],
                    'seq' => $op['seq'],
                    'status' => WorkOrderOperation::STATUS_PENDING,
                    'qualified_qty' => 0,
                    'defective_qty' => 0,
                    'hours' => 0,
                ], $expansion['operations']));
            }

            return $order;
        }, 2);

        // 单据创建审计日志（事务提交后记）：单号 + 成品 + 计划数量（decimal 原值）+ 操作人
        Log::info('生产工单创建成功', [
            'no' => $order->no, 'product_id' => $order->product_id,
            'quantity' => $order->quantity, 'created_by' => auth()->id(),
        ]);

        return $order;
    }

    /**
     * 更新草稿：仅草稿（1503）；被委外单引用不可改（1504）；物料快照/工序序列全量重建（BOM 展开）；事务内锁行复查防并发
     *
     * @param  ProductionOrder  $order  路由绑定的工单模型（草稿状态才可改）
     * @param  array  $data  已过 SaveProductionOrderRequest 格式校验的载荷
     *
     * @throws ProductionException 非草稿 1503 / 数量≤0 1502 / 委外引用 1504 / 无启用 BOM 1501
     */
    public function update(ProductionOrder $order, array $data): void
    {
        // 状态前置校验（不进事务，快速失败）：非草稿直接拒绝
        if ($order->status !== ProductionOrder::STATUS_DRAFT) {
            throw new ProductionException('已下达工单不可修改', 1503);
        }
        // 数量 ≤ 0 走业务码 1502（与 create 同口径）；bccomp 判正（D-3 铁律禁浮点比较）
        if (bccomp((string) $data['quantity'], '0', 2) <= 0) {
            throw new ProductionException('数量必须大于 0', 1502);
        }

        DB::transaction(function () use ($order, $data) {
            // 锁工单行复查状态：与下达并发时防止改到正在下达的单（幂等 1503）
            $locked = ProductionOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== ProductionOrder::STATUS_DRAFT) {
                throw new ProductionException('已下达工单不可修改', 1503);
            }
            // 防外键卡死（B-1）：草稿工单工序被委外单引用（operation_id RESTRICT）时全删重建工序
            // 行会撞外键抛 QueryException 500——比照 destroy 引用检查同口径拒绝（1504 族）；
            // 用户可先删除草稿委外单再编辑工单（自愈路径）
            if (OutsourcingOrder::where('order_id', $locked->id)->exists()) {
                throw new ProductionException('工单已被委外单使用，不可修改', 1504);
            }
            // 锁成品行 + 取启用 BOM（与 create 同口径）
            Product::whereKey($data['product_id'])->lockForUpdate()->firstOrFail();
            $bom = BomHeader::where('product_id', $data['product_id'])
                ->where('status', BomHeader::STATUS_ENABLED)->orderByDesc('id')->first();
            if (! $bom) {
                throw new ProductionException('该成品没有启用版本的 BOM', 1501);
            }
            $expansion = $this->expandBom($locked->product, (string) $data['quantity'], $bom);

            // 取启用工艺路线（与 create 同口径，成品可改故随新成品重取）：有→DAG 展开重建；无→旧逻辑线性快照 + 告警
            $routing = RoutingHeader::where('product_id', $data['product_id'])
                ->where('status', RoutingHeader::STATUS_ENABLED)->orderByDesc('id')->first();
            if ($routing) {
                $rex = $this->expandRouting($routing);
            } else {
                // 无启用工艺路线：沿用旧逻辑重建并记告警日志（更新无响应文案，行为与 create 回退一致）
                Log::warning(
                    '工单更新：成品无启用工艺路线，回退全量工序快照',
                    ['product_id' => $data['product_id'], 'order_id' => $locked->id],
                );
                $rex = null;
            }

            $locked->update([
                'product_id' => $data['product_id'],
                'quantity' => $data['quantity'],
                'plan_date' => $data['plan_date'],
                'bom_id' => $bom->id,
                // 路线快照随重建重挂（成品变更后锚定新成品的启用路线，无则回退 null）
                'routing_id' => $routing?->id,
                'remark' => $data['remark'] ?? $locked->remark,
            ]);
            // 物料快照/工序序列全量重建（草稿工单无流水引用，直接重建）
            // 重建前按 material_id 快照既有已领量：历史缺陷期草稿单可能已产生领料，
            // 清零会导致剩余量恢复全量 → 原料库存重复扣减、退料「≤已领」防线失效（防数据丢失）
            $issuedByMaterial = $locked->materials()->get()->keyBy('material_id');
            $locked->materials()->delete();
            $locked->materials()->createMany(array_map(fn ($m) => [
                'material_id' => $m['material_id'],
                'required_qty' => $m['required_qty'],
                // 回填既有已领量（仅重算需求数量；该物料不再出现在新 BOM 时其已领记录随快照行一并移除）
                // ?? 左值天然 null 安全（缺失时回退 0），nullsafe 显式多余故用 ->
                'issued_qty' => (string) ($issuedByMaterial->get($m['material_id'])->issued_qty ?? '0'),
                // 物料归属工序节点（仅唯一消费节点时落 node_no；无路线恒 null）
                'node_no' => $rex['nodeOwners'][$m['material_id']] ?? null,
            ], $expansion['materials']));
            // 工序重建前先删旧行（边表 FK 级联随删，无需显式清）
            $locked->operations()->delete();
            if ($rex) {
                // DAG 工序快照 + 边快照重建（同 create）
                $createdOps = $locked->operations()->createMany(array_map(fn ($op) => [
                    'process_id' => $op['process_id'],
                    'seq' => $op['seq'],
                    'node_no' => $op['node_no'],
                    'output_product_id' => $op['output_product_id'],
                    'is_outsourced' => $op['is_outsourced'],
                    'status' => WorkOrderOperation::STATUS_PENDING,
                    'qualified_qty' => 0,
                    'defective_qty' => 0,
                    'hours' => 0,
                ], $rex['operations']));
                $opIdByNo = [];
                foreach ($createdOps as $i => $op) {
                    $opIdByNo[$rex['operations'][$i]['node_no']] = $op->id;
                }
                $locked->edges()->createMany(array_map(fn ($e) => [
                    'from_operation_id' => $opIdByNo[$e['from']],
                    'to_operation_id' => $opIdByNo[$e['to']],
                ], $rex['edges']));
            } else {
                // 旧逻辑：全量启用工序线性快照
                $locked->operations()->createMany(array_map(fn ($op) => [
                    'process_id' => $op['process_id'],
                    'seq' => $op['seq'],
                    'status' => WorkOrderOperation::STATUS_PENDING,
                    'qualified_qty' => 0,
                    'defective_qty' => 0,
                    'hours' => 0,
                ], $expansion['operations']));
            }
        });
    }

    /**
     * 删除草稿：仅草稿（1504）；被生产单据引用不可删；事务内锁行复查防并发
     *
     * @param  ProductionOrder  $order  路由绑定的工单模型
     *
     * @throws ProductionException 非草稿/被生产单据引用 1504
     */
    public function delete(ProductionOrder $order): void
    {
        // 状态前置校验（不进事务，快速失败）：非草稿直接拒绝
        if ($order->status !== ProductionOrder::STATUS_DRAFT) {
            throw new ProductionException('已下达工单不可删除', 1504);
        }
        DB::transaction(function () use ($order) {
            // 锁工单行复查状态（幂等 1504）
            $locked = ProductionOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== ProductionOrder::STATUS_DRAFT) {
                throw new ProductionException('已下达工单不可删除', 1504);
            }
            // 防孤儿单据：草稿工单已被领料/退料/委外/成品入库单引用 → 拒绝删除（1504 同族）
            $referenced = PickList::where('order_id', $locked->id)->exists()
                || ReturnList::where('order_id', $locked->id)->exists()
                || OutsourcingOrder::where('order_id', $locked->id)->exists()
                || FinishedInbound::where('order_id', $locked->id)->exists();
            if ($referenced) {
                throw new ProductionException('工单已被生产单据使用，不可删除', 1504);
            }
            $locked->delete();
        });

        // 单据删除审计日志（事务提交后记）：内存模型仍持有单号，可用于追溯
        Log::info('生产工单草稿删除', ['no' => $order->no, 'operator' => auth()->id()]);
    }

    /**
     * 下达（草稿→已下达）：重复/非草稿 1505；物料库存不足仅返回 warnings 不阻断（缺料由领料环节控制）
     *
     * 事务内锁工单行复查状态防并发；warnings 读全局库存快照（Σ 全仓余额，只读不锁）。
     *
     * @param  ProductionOrder  $order  路由绑定的工单模型
     * @return array{warnings: array<int, array{material_name:string, material_code:?string,
     *          required:string, stock:string}>} 缺料警告列表（无缺料为空数组，供控制器回显）
     *
     * @throws ProductionException 已下达/非草稿 1505
     */
    public function release(ProductionOrder $order): array
    {
        // 事务内锁工单行复查状态防并发；warnings 在闭包内收集后透出
        $warnings = DB::transaction(function () use ($order) {
            // 锁工单行：同一工单重复下达在此判重（幂等 1505）
            $locked = ProductionOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === ProductionOrder::STATUS_RELEASED) {
                throw new ProductionException('工单已下达', 1505);
            }
            if ($locked->status !== ProductionOrder::STATUS_DRAFT) {
                throw new ProductionException('当前状态不可下达', 1505);
            }
            // 缺料警告：全仓余额汇总 vs 需求（bcadd 归一防浮点；只读快照，允许下达）
            // 物料一次预取（with('material')）+ 余额 SUM 下推 SQL groupBy——跨仓余额行在库端
            // 归并为每物料一行（SUM 标准 SQL 无方言差异），消除整表余额行传输与 PHP 侧跨仓累加
            $materials = $locked->materials()->with('material')->get();
            $stockRows = InventoryBalance::query()
                ->whereIn('product_id', $materials->pluck('material_id'))
                ->selectRaw('product_id, SUM(quantity) as total')
                ->groupBy('product_id')
                ->get()
                ->keyBy('product_id');
            $warnings = [];
            foreach ($materials as $m) {
                // SUM 跨库形态归一（SQLite int/float、MySQL decimal 字符串）→ 2 位小数字符串
                $stock = bcadd((string) ($stockRows->get($m->material_id)?->getAttribute('total') ?? '0'), '0', 2);
                if (bccomp($stock, (string) $m->required_qty, 2) < 0) {
                    $warnings[] = [
                        'material_name' => $m->material->name ?? ('#'.$m->material_id),
                        'material_code' => $m->material?->code,
                        'required' => $m->required_qty,
                        'stock' => $stock,
                    ];
                }
            }
            $locked->status = ProductionOrder::STATUS_RELEASED;
            $locked->released_at = now();
            $locked->save();

            return $warnings;
        });

        // 状态变更审计日志（事务提交后记）：下达后工单可开工/领料，缺料仅警告不阻断
        Log::info('生产工单下达', [
            'no' => $order->no, 'shortage_count' => count($warnings), 'operator' => auth()->id(),
        ]);

        return ['warnings' => $warnings];
    }

    /**
     * 开工（已下达→生产中）：DAG 工单（routing_id 非空）全部入度 0（无入边）节点置进行中
     * ——并行起点同时开工；无路线工单沿用首工序（seq 最小）置进行中。重复/非已下达 1506。
     *
     * 锁序 起点工序→order：与委外回收（outsourcing→op→order）/报工（全部工序 id 升序→order）/
     * 完工（全工序→order）在 op→order 段全局同序，消除「开工 vs 末批回收」并发 ABBA 死锁环
     * （委外工序可为 seq1/入度 0，系统无校验禁止）。
     *
     * @param  ProductionOrder  $order  路由绑定的工单模型（已下达状态才可开工）
     *
     * @throws ProductionException 非已下达 1506
     */
    public function start(ProductionOrder $order): void
    {
        DB::transaction(function () use ($order) {
            // 先取工单判定模式再锁工序行：routing_id 为下达时快照锚定、此后不可变，无锁读无错判窗口
            $isDag = ProductionOrder::whereKey($order->id)->value('routing_id') !== null;
            if ($isDag) {
                // DAG：锁全部入度 0（无入边）工序行（升序）——开工即并行起点全部进行中
                $startOps = WorkOrderOperation::where('order_id', $order->id)
                    ->whereNotIn('id', WorkOrderOperationEdge::select('to_operation_id')->where('order_id', $order->id))
                    ->orderBy('id')->lockForUpdate()->get();
            } else {
                // 旧逻辑：锁首工序（seq 最小；行可能不存在 → collect 包裹 null 由循环兜底）
                $startOps = collect([WorkOrderOperation::where('order_id', $order->id)
                    ->orderBy('seq')->lockForUpdate()->first()]);
            }
            // 锁工单行复查状态（幂等 1506；失败回滚释放锁，行为与锁后校验等价）
            $locked = ProductionOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== ProductionOrder::STATUS_RELEASED) {
                throw new ProductionException('当前状态不可开工', 1506);
            }
            $locked->status = ProductionOrder::STATUS_PRODUCING;
            $locked->save();
            // 起点工序置进行中（行已提前锁定，直接更新不重复加锁）
            foreach ($startOps as $op) {
                if ($op && $op->status === WorkOrderOperation::STATUS_PENDING) {
                    $op->status = WorkOrderOperation::STATUS_RUNNING;
                    $op->save();
                }
            }
        });

        // 状态变更审计日志（事务提交后记）
        Log::info('生产工单开工', ['no' => $order->no, 'operator' => auth()->id()]);
    }

    /**
     * 完工（生产中→已完成）：双前置校验——所有工序已完成（1507）+ 至少一次成品入库 completed_qty>0（1508）
     *
     * 锁序 op→order：先锁全部工序行（升序），再锁工单行——与报工（全部工序 id 升序→order）/开工（起点工序→order）
     * 全局同序；若先锁 order 再锁工序行会引入 order→op 反序，与并发报工构成 ABBA 死锁环。
     *
     * @param  ProductionOrder  $order  路由绑定的工单模型（生产中状态才可完工）
     *
     * @throws ProductionException 非生产中/存在未完成工序 1507 / 无成品入库 1508
     */
    public function complete(ProductionOrder $order): void
    {
        DB::transaction(function () use ($order) {
            // 锁全部工序行（升序）：工序状态改为锁后一致读——与并发报工末批提交串行化
            // （此前为无锁一致性读，窗口内可能读到「全部 DONE」的同时末笔报工在途，方向安全但读不可靠）
            $operations = WorkOrderOperation::where('order_id', $order->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            // 锁工单行复查状态（幂等 1507）
            $locked = ProductionOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== ProductionOrder::STATUS_PRODUCING) {
                throw new ProductionException('当前状态不可完工', 1507);
            }
            // 前置 1：所有工序必须已完成（直接用已锁工序行判定，存在待开工/进行中 → 1507）
            $hasUndone = $operations->contains(
                fn (WorkOrderOperation $op) => $op->status !== WorkOrderOperation::STATUS_DONE,
            );
            if ($hasUndone) {
                throw new ProductionException('存在未完成工序，无法完工', 1507);
            }
            // 前置 2：至少一次成品入库（completed_qty > 0，bcmath 比较）
            if (bccomp((string) $locked->completed_qty, '0', 2) <= 0) {
                throw new ProductionException('无成品入库，无法完工', 1508);
            }
            $locked->status = ProductionOrder::STATUS_COMPLETED;
            $locked->completed_at = now();
            $locked->save();
        });

        // 状态变更审计日志（事务提交后记）：完工为工单终态前置（关闭前必须完工），含入库累计数量
        Log::info('生产工单完工', ['no' => $order->no, 'completed_qty' => $order->completed_qty, 'operator' => auth()->id()]);
    }

    /**
     * 关闭（已完成→关闭）：非已完成拒绝 1505「当前状态不可关闭」（spec 码段满，复用 1505，与 1405/1306 语义对齐）
     *
     * @param  ProductionOrder  $order  路由绑定的工单模型（已完成状态才可关闭）
     *
     * @throws ProductionException 非已完成 1505
     */
    public function close(ProductionOrder $order): void
    {
        DB::transaction(function () use ($order) {
            // 锁工单行复查状态（幂等 1505 关闭族）
            $locked = ProductionOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== ProductionOrder::STATUS_COMPLETED) {
                throw new ProductionException('当前状态不可关闭', 1505);
            }
            $locked->status = ProductionOrder::STATUS_CLOSED;
            $locked->closed_at = now();
            $locked->save();
        });

        // 状态变更审计日志（事务提交后记）：关闭为工单生命周期终态，不可逆
        Log::info('生产工单关闭', ['no' => $order->no, 'operator' => auth()->id()]);
    }

    /**
     * 工序报工：仅工序进行中可报（1509）；委外节点不可报工（1509，经委外单回收完成）；
     * 累计校验防虚报——合格累计+本次 > 计划数 → 1510、合格不良累计+本次 > 计划数 → 1511；
     * 工时负数 → 1512；合格/不良负数 → 422（值域）。
     *
     * 流转：累计合格 ≥ 计划数 → 本工序自动完成；DAG 工单（routing_id 非空）直接后继中
     * 「全部前驱已完成」的待开工节点并行置进行中（并行分支独立推进），
     * 无路线工单仍按 seq 升序单后继推进（旧逻辑行为不变）。
     * 事务内锁定同单全部工序行：并发报工同一工单在此串行化，累计值与后继就绪判定一致。
     * 锁序 全部工序（id 升序，含本工序）→ order：与完工（全工序→order）完全同序、
     * 与开工（起点工序 id 升序→order）在行级单调同向——若先锁报工工序再锁其余工序，
     * 并行分支上 report(opX)/report(opY)/complete() 的获取序列非单调会构成 ABBA 死锁环，
     * 且 DB::transaction 默认不重试死锁败方（500），故必须全集升序单条获取。
     *
     * @param  WorkOrderOperation  $operation  路由绑定的工序行模型（仅进行中且非委外可报）
     * @param  array  $data  已过 SaveOperationReportRequest 格式校验的载荷（合格/不良/工时/操作人/备注）
     *
     * @throws ProductionException 值域 422/1512 / 不可报工 1509 / 累计超计划 1510/1511；
     *                             ModelNotFoundException 仅并发删除窗口内防御性到达（404 语义）
     */
    public function reportOperation(WorkOrderOperation $operation, array $data): void
    {
        // 归一化可空字段：defective_qty/hours 漏传时 validated 数据不含该 key（nullable 规则），
        // 缺省按 0 处理，避免 Undefined array key / bcadd 空串 ValueError（E2E TC-PRD-04 载荷即漏传 defective_qty）
        $defective = $data['defective_qty'] ?? 0;
        $hours = $data['hours'] ?? 0;
        // 合格/不良负数走 422 值域（spec 码段满；工时负数有专属码 1512 走业务码）；
        // 判负走 bccomp（D-3 铁律：禁浮点参与数量比较；正则已保证入参为两位小数十进制）
        if (bccomp((string) $data['qualified_qty'], '0', 2) < 0 || bccomp((string) $defective, '0', 2) < 0) {
            throw new ProductionException('合格数与不良数不能为负数', 422);
        }
        if (bccomp((string) $hours, '0', 2) < 0) {
            throw new ProductionException('工时不能为负数', 1512);
        }

        DB::transaction(function () use ($operation, $data, $defective, $hours) {
            // 锁全部同单工序行（id 升序，含本工序，单条语句获取）：与 complete() 完全同序，
            // 并发报工/完工在行级全序上单调获取锁（DAG 后继就绪判定需读其它前驱状态，
            // 全集一次锁定）；禁止「先 whereKey 预锁本工序再锁其余」——非单调序列在并行
            // 分支并发报工时会成死锁环（评审 Important 发现）
            $allOps = WorkOrderOperation::where('order_id', $operation->order_id)
                ->orderBy('id')->lockForUpdate()->get();
            // 本工序行从已锁全集取：路由绑定保证存在，仅并发删除窗口内防御性到达，
            // 抛框架同款 ModelNotFound → 404（与 firstOrFail 语义一致）
            $op = $allOps->find($operation->id);
            if (! $op) {
                throw (new ModelNotFoundException)->setModel(WorkOrderOperation::class, [$operation->id]);
            }
            if ($op->status !== WorkOrderOperation::STATUS_RUNNING) {
                throw new ProductionException('该工序当前不可报工', 1509);
            }
            if ((int) $op->is_outsourced === 1) {
                // 委外节点不可报工：进度只能经委外单回收回写（RTG-07 / OUT-06）
                throw new ProductionException('委外工序不可报工，经委外单回收完成', 1509);
            }
            // 锁工单行：计划数快照（与工单状态流转并发一致）
            $order = ProductionOrder::whereKey($op->order_id)->lockForUpdate()->firstOrFail();
            // 累计语义：已报合格 + 本次合格 ≤ 计划数（防并发虚报）
            $qualifiedSum = bcadd((string) $op->qualified_qty, (string) $data['qualified_qty'], 2);
            if (bccomp($qualifiedSum, (string) $order->quantity, 2) > 0) {
                throw new ProductionException('合格数不能超过工单计划数量', 1510);
            }
            // 累计语义：合格累计 + 本次不良 ≤ 计划数（不良仅按本次报工计入封顶，跨次不良不叠加；
            // 与验收测试「不良数与工时累计」口径一致，避免累计合格+累计不良双重封顶误伤正常报工）
            $defectSum = bcadd((string) $op->defective_qty, (string) $defective, 2);
            $totalSum = bcadd($qualifiedSum, (string) $defective, 2);
            if (bccomp($totalSum, (string) $order->quantity, 2) > 0) {
                throw new ProductionException('合格数与不良数合计不能超过工单计划数量', 1511);
            }

            // 累计回写（bcmath）
            $op->qualified_qty = $qualifiedSum;
            $op->defective_qty = $defectSum;
            $op->hours = bcadd((string) $op->hours, (string) $hours, 2);

            // 自动流转：累计合格 ≥ 计划数 → 本节点完成；后继推进按 DAG/线性分流
            if (bccomp($op->qualified_qty, (string) $order->quantity, 2) >= 0) {
                $op->status = WorkOrderOperation::STATUS_DONE;

                if ($order->routing_id) {
                    // DAG 推进：直接后继中「全部前驱已完成」的待开工节点置进行中（并行分支独立推进）。
                    // 边一次取出内存建邻接、前驱状态用已锁定的工序全集判定（§4.2.2 禁循环内查询）
                    $edges = WorkOrderOperationEdge::where('order_id', $order->id)->get();
                    // 已完成集合：全集中已 DONE 的行 + 本节点（本轮即将落 DONE，对后继就绪判定等效已完成）
                    $doneIds = [$op->id => true];
                    foreach ($allOps as $s) {
                        if ($s->status === WorkOrderOperation::STATUS_DONE) {
                            $doneIds[$s->id] = true;
                        }
                    }
                    $byId = $allOps->keyBy('id');
                    $predsByTo = $edges->groupBy('to_operation_id');
                    foreach ($edges->where('from_operation_id', $op->id) as $edge) {
                        $succ = $byId->get($edge->to_operation_id);
                        if (! $succ || $succ->status !== WorkOrderOperation::STATUS_PENDING) {
                            continue;
                        }
                        // 后继就绪判定：全部前驱均在已完成集合（空前驱不会出现——本节点即其前驱）
                        $allPredsDone = ($predsByTo->get($edge->to_operation_id) ?? collect())
                            ->every(fn (WorkOrderOperationEdge $e) => isset($doneIds[$e->from_operation_id]));
                        if ($allPredsDone) {
                            $succ->status = WorkOrderOperation::STATUS_RUNNING;
                            $succ->save();
                        }
                    }
                } else {
                    // 旧逻辑：下一工序（seq 升序单后继）进行中（行已随全集锁定，直接更新不重复加锁；
                    // 本工序自身 seq 不大于自身，天然被过滤）
                    $next = $allOps->filter(fn (WorkOrderOperation $s) => $s->seq > $op->seq)
                        ->sortBy('seq')->first();
                    if ($next && $next->status === WorkOrderOperation::STATUS_PENDING) {
                        $next->status = WorkOrderOperation::STATUS_RUNNING;
                        $next->save();
                    }
                }
            }
            $op->save();

            // 报工记录（只增不改，统计口径来源）
            OperationReport::create([
                'operation_id' => $op->id,
                'order_id' => $order->id,
                'operator' => $data['operator'] ?? auth()->user()->name ?? null,
                'qualified_qty' => $data['qualified_qty'],
                'defective_qty' => $defective,
                'hours' => $hours,
                'report_time' => now(),
                'remark' => $data['remark'] ?? null,
            ]);
        });

        // 生产进度审计日志（事务提交后记）：合格/不良数量为 decimal 原值，报工是产量统计口径来源
        Log::info('工序报工成功', [
            'operation_id' => $operation->id, 'order_id' => $operation->order_id,
            'qualified_qty' => $data['qualified_qty'], 'defective_qty' => $defective,
            'operator' => auth()->id(),
        ]);
    }

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
