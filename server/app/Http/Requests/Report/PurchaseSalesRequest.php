<?php

// 采购销售汇总查询参数校验：日期闭区间必填、粒度枚举（仅格式层）

namespace App\Http\Requests\Report;

use Illuminate\Foundation\Http\FormRequest;

class PurchaseSalesRequest extends FormRequest
{
    public function authorize(): bool
    {
        // 权限由路由中间件 permission:report.purchase_sales 控制，此处不再重复判断（避免与中间件口径漂移）
        return true;
    }

    /**
     * 查询参数格式校验规则（422 仅格式层）
     *
     * 与原控制器内联 validate 逐条等价迁移；
     * 倒置区间属业务层校验，保留在控制器走 1601 业务码，不在此拦截。
     *
     * @return array<string, array<int, string>> 字段名 => 规则数组（Laravel 校验语法）
     */
    public function rules(): array
    {
        return [
            'date_from' => ['required', 'date_format:Y-m-d'],
            'date_to' => ['required', 'date_format:Y-m-d'],
            'granularity' => ['sometimes', 'string', 'in:day,month'],
        ];
    }
}
