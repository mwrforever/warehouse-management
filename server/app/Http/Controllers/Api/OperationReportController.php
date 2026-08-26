<?php

// 工序报工控制器：报工薄壳（写流程下沉 ProductionOrderService）+ 报工记录列表

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Production\SaveOperationReportRequest;
use App\Models\OperationReport;
use App\Models\WorkOrderOperation;
use App\Services\ProductionOrderService;
use App\Support\ApiResponse;

class OperationReportController extends Controller
{
    use ApiResponse;

    public function __construct(private ProductionOrderService $orderService) {}

    /**
     * 报工：格式校验 422 → Service（值域 422/1512、状态/委外 1509、累计 1510/1511、
     * 事务内全集锁工序行防并发虚报 + 自动流转，锁序与完工/开工全局同序）
     */
    public function store(SaveOperationReportRequest $request, WorkOrderOperation $operation)
    {
        $this->orderService->reportOperation($operation, $request->validated());

        return $this->ok();
    }

    /** 报工记录列表：该工序全部报工记录（按报工时间倒序） */
    public function index(WorkOrderOperation $operation)
    {
        // 经模型查询构建器分页（EloquentBuilder 泛型保留，供 map 闭包参数类型解析；
        // 关系分页 larastan 无法解析闭包类型）
        $rows = OperationReport::where('operation_id', $operation->id)
            ->orderByDesc('reported_at')
            ->paginate(max(1, min(100, (int) request('per_page', 10))));

        return $this->ok([
            'items' => $rows->map(fn (OperationReport $r) => [
                'id' => $r->id,
                'operator' => $r->operator,
                'qualified_qty' => $r->qualified_qty,
                'defective_qty' => $r->defective_qty,
                'hours' => $r->hours,
                // reported_at 为非空 datetime 列（cast 后为 Carbon，直接取字符串）
                'reported_at' => $r->reported_at->toDateTimeString(),
                'remark' => $r->remark,
            ]),
            'total' => $rows->total(), 'page' => $rows->currentPage(), 'per_page' => $rows->perPage(),
        ]);
    }
}
