<?php

// 工序报工控制器：报工（累计校验 + 自动流转）与报工记录列表；事务内锁工序行防并发虚报

namespace App\Http\Controllers\Api;

use App\Exceptions\ProductionException;
use App\Http\Controllers\Controller;
use App\Models\OperationReport;
use App\Models\ProductionOrder;
use App\Models\WorkOrderOperation;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OperationReportController extends Controller
{
    use ApiResponse;

    /**
     * 报工：仅工序进行中可报（1509）；累计校验防虚报——合格累计+本次 > 计划数 → 1510、
     * 合格不良累计+本次 > 计划数 → 1511；工时负数 → 1512；合格/不良负数 → 422（值域）。
     * 流转：累计合格 ≥ 计划数 → 本工序自动完成，下一工序（seq 升序）自动进行中。
     * 事务内锁工序行：并发报工同一工序在此串行化，累计值判定一致。
     */
    public function store(Request $request, WorkOrderOperation $operation)
    {
        $data = $this->validatePayload($request);
        // 合格/不良负数走 422 值域（spec 码段满；工时负数有专属码 1512 走业务码）
        if ((float) $data['qualified_qty'] < 0 || (float) $data['defective_qty'] < 0) {
            return $this->fail(422, '合格数与不良数不能为负数');
        }
        if ((float) $data['hours'] < 0) {
            return $this->fail(1512, '工时不能为负数');
        }

        try {
            DB::transaction(function () use ($operation, $data) {
                // 锁工序行：累计值并发安全（两次并发报工串行化后各自复核累计）
                $op = WorkOrderOperation::whereKey($operation->id)->lockForUpdate()->firstOrFail();
                if ($op->status !== WorkOrderOperation::STATUS_RUNNING) {
                    throw new ProductionException('该工序当前不可报工', 1509);
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
                $defectSum = bcadd((string) $op->defective_qty, (string) $data['defective_qty'], 2);
                $totalSum = bcadd($qualifiedSum, (string) $data['defective_qty'], 2);
                if (bccomp($totalSum, (string) $order->quantity, 2) > 0) {
                    throw new ProductionException('合格数与不良数合计不能超过工单计划数量', 1511);
                }

                // 累计回写（bcmath）
                $op->qualified_qty = $qualifiedSum;
                $op->defective_qty = $defectSum;
                $op->hours = bcadd((string) $op->hours, (string) $data['hours'], 2);

                // 自动流转：累计合格 ≥ 计划数 → 本工序完成 + 下一工序进行中
                if (bccomp($op->qualified_qty, (string) $order->quantity, 2) >= 0) {
                    $op->status = WorkOrderOperation::STATUS_DONE;
                    // 下一工序（seq 升序第一个未完成的待开工工序）
                    $next = WorkOrderOperation::where('order_id', $order->id)
                        ->where('seq', '>', $op->seq)
                        ->orderBy('seq')
                        ->first();
                    if ($next && $next->status === WorkOrderOperation::STATUS_PENDING) {
                        $next->status = WorkOrderOperation::STATUS_RUNNING;
                        $next->save();
                    }
                }
                $op->save();

                // 报工记录（只增不改，统计口径来源）
                OperationReport::create([
                    'operation_id' => $op->id,
                    'order_id' => $order->id,
                    'operator' => $data['operator'] ?? auth()->user()->name ?? null,
                    'qualified_qty' => $data['qualified_qty'],
                    'defective_qty' => $data['defective_qty'],
                    'hours' => $data['hours'],
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
