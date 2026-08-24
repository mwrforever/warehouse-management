<?php

// 工序报工控制器：报工（累计校验 + 自动流转）与报工记录列表；事务内锁工序行防并发虚报

namespace App\Http\Controllers\Api;

use App\Exceptions\ProductionException;
use App\Http\Controllers\Controller;
use App\Models\OperationReport;
use App\Models\ProductionOrder;
use App\Models\WorkOrderOperation;
use App\Models\WorkOrderOperationEdge;
use App\Support\ApiResponse;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OperationReportController extends Controller
{
    use ApiResponse;

    /**
     * 报工：仅工序进行中可报（1509）；委外节点不可报工（1509，经委外单回收完成）；
     * 累计校验防虚报——合格累计+本次 > 计划数 → 1510、合格不良累计+本次 > 计划数 → 1511；
     * 工时负数 → 1512；合格/不良负数 → 422（值域）。
     * 流转：累计合格 ≥ 计划数 → 本工序自动完成；DAG 工单（routing_id 非空）直接后继中
     * 「全部前驱已完成」的待开工节点并行置进行中（并行分支独立推进），
     * 无路线工单仍按 seq 升序单后继推进（旧逻辑行为不变）。
     * 事务内锁定同单全部工序行：并发报工同一工单在此串行化，累计值与后继就绪判定一致。
     * 锁序 全部工序（id 升序，含本工序）→ order：与完工（全工序→order）完全同序、
     * 与开工（起点工序 id 升序→order）在行级单调同向——若先锁报工工序再锁其余工序，
     * 并行分支上 report(opX)/report(opY)/complete() 的获取序列非单调会构成 ABBA 死锁环，
     * 且 DB::transaction 默认不重试死锁败方（500），故必须全集升序单条获取。
     */
    public function store(Request $request, WorkOrderOperation $operation)
    {
        $data = $this->validatePayload($request);
        // 归一化可空字段：defective_qty/hours 漏传时 validated 数据不含该 key（nullable 规则），
        // 缺省按 0 处理，避免 Undefined array key / bcadd 空串 ValueError（E2E TC-PRD-04 载荷即漏传 defective_qty）
        $defective = $data['defective_qty'] ?? 0;
        $hours = $data['hours'] ?? 0;
        // 合格/不良负数走 422 值域（spec 码段满；工时负数有专属码 1512 走业务码）
        if ((float) $data['qualified_qty'] < 0 || (float) $defective < 0) {
            return $this->fail(422, '合格数与不良数不能为负数');
        }
        if ((float) $hours < 0) {
            return $this->fail(1512, '工时不能为负数');
        }

        try {
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
        } catch (ProductionException $e) {
            // 1509 不可报工 / 1510 合格超计划 / 1511 合计超计划
            return $this->fail($e->getCode() ?: 1509, $e->getMessage());
        }

        return $this->ok();
    }

    /** 报工记录列表：该工序全部报工记录（按报工时间倒序） */
    public function index(WorkOrderOperation $operation)
    {
        // 经模型查询构建器分页（EloquentBuilder 泛型保留，供 map 闭包参数类型解析；
        // 关系分页 larastan 无法解析闭包类型）
        $rows = OperationReport::where('operation_id', $operation->id)
            ->orderByDesc('report_time')
            ->paginate(max(1, min(100, (int) request('per_page', 10))));

        return $this->ok([
            'items' => $rows->map(fn (OperationReport $r) => [
                'id' => $r->id,
                'operator' => $r->operator,
                'qualified_qty' => $r->qualified_qty,
                'defective_qty' => $r->defective_qty,
                'hours' => $r->hours,
                // report_time 为非空 datetime 列（cast 后为 Carbon，直接取字符串）
                'report_time' => $r->report_time->toDateTimeString(),
                'remark' => $r->remark,
            ]),
            'total' => $rows->total(), 'page' => $rows->currentPage(), 'per_page' => $rows->perPage(),
        ]);
    }

    // 载荷格式校验（422 仅格式层）；负数值域/业务码在方法内检查
    private function validatePayload(Request $request): array
    {
        return $request->validate([
            // 数量限两位小数（正则防科学计数法；负值形态放行到方法内业务码/422）
            'qualified_qty' => 'required|numeric|regex:/^-?\d+(\.\d{1,2})?$/',
            'defective_qty' => 'nullable|numeric|regex:/^-?\d+(\.\d{1,2})?$/',
            'hours' => 'nullable|numeric|regex:/^-?\d+(\.\d{1,2})?$/',
            'operator' => 'nullable|string|max:50',
            'remark' => 'nullable|string|max:200',
        ]);
    }
}
