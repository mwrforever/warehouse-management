<?php

// 生产工单保存（新建/更新共用）表单校验：成品/计划日期/计划数量格式校验；bom_id 仅格式透传（后端以启用版本为准）

namespace App\Http\Requests\Production;

use Illuminate\Foundation\Http\FormRequest;

class SaveProductionOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        // 权限由路由中间件 permission:production.order.create / production.order.update 控制，
        // 此处不再重复判断（避免与中间件口径漂移）
        return true;
    }

    /**
     * 载荷格式校验规则（422 仅格式层）
     *
     * 与原控制器内联 validatePayload 逐条等价迁移；数量 ≤ 0 / 非草稿状态 / BOM 缺失等
     * 业务冲突走 ProductionOrderService 业务码（1501/1502/1503/1504），不在此处拦截。
     * bom_id 不做存在性校验：请求携带的 bom_id 一律忽略，以启用版本为准（后端权威）。
     *
     * @return array<string, string> 字段名 => 规则串（Laravel 校验语法）
     */
    public function rules(): array
    {
        return [
            'product_id' => 'required|integer|exists:products,id',
            // 数量限两位小数（正则按字符串形态校验，拦截 1e2 科学计数法避免 bcmul ValueError；允许负号形态，负值由业务层拦截 1502）
            'quantity' => 'required|numeric|regex:/^-?\d+(\.\d{1,2})?$/',
            'plan_date' => 'required|date',
            'bom_id' => 'nullable|integer',
            'remark' => 'nullable|string|max:200',
        ];
    }
}
