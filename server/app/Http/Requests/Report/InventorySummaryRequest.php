<?php

// 库存报表查询参数校验：维度枚举与截止日期格式（仅格式层，业务聚合在 ReportService）

namespace App\Http\Requests\Report;

use Illuminate\Foundation\Http\FormRequest;

class InventorySummaryRequest extends FormRequest
{
    public function authorize(): bool
    {
        // 权限由路由中间件 permission:report.inventory 控制，此处不再重复判断（避免与中间件口径漂移）
        return true;
    }

    /**
     * 查询参数格式校验规则（422 仅格式层）
     *
     * 与原控制器内联 validate 逐条等价迁移；date_to 为 V1 预留字段，仅校验格式不参与过滤。
     *
     * @return array<string, array<int, string>> 字段名 => 规则数组（Laravel 校验语法）
     */
    public function rules(): array
    {
        return [
            'group_by' => ['sometimes', 'string', 'in:category,warehouse,type'],
            'date_to' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ];
    }
}
