<?php

// 工序报工表单校验：合格数必填、合格/不良/工时两位小数格式（负数值域/累计封顶归 Service）

namespace App\Http\Requests\Production;

use Illuminate\Foundation\Http\FormRequest;

class SaveOperationReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        // 权限由路由中间件 permission:production.order.report 控制，
        // 此处不再重复判断（避免与中间件口径漂移）
        return true;
    }

    /**
     * 载荷格式校验规则（422 仅格式层）
     *
     * 与原控制器内联 validatePayload 逐条等价迁移；合格/不良负数（值域 422、工时 1512）、
     * 累计超计划（1510/1511）、工序状态/委外拦截（1509）等业务校验下沉
     * ProductionOrderService::reportOperation 抛 ProductionException 全局渲染。
     */
    public function rules(): array
    {
        return [
            // 数量限两位小数（正则防科学计数法；负值形态放行到 Service 内业务码/422）
            'qualified_qty' => 'required|numeric|regex:/^-?\d+(\.\d{1,2})?$/',
            'defective_qty' => 'nullable|numeric|regex:/^-?\d+(\.\d{1,2})?$/',
            'hours' => 'nullable|numeric|regex:/^-?\d+(\.\d{1,2})?$/',
            'operator' => 'nullable|string|max:50',
            'remark' => 'nullable|string|max:200',
        ];
    }
}
