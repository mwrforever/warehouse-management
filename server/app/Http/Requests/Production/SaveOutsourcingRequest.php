<?php

// 委外单保存（新建/更新共用）表单校验：工单/工序/供应商/仓库库位/委外量/发料组件格式校验

namespace App\Http\Requests\Production;

use Illuminate\Foundation\Http\FormRequest;

class SaveOutsourcingRequest extends FormRequest
{
    public function authorize(): bool
    {
        // 权限由路由中间件 permission:production.outsource.create / production.outsource.update 控制，
        // 此处不再重复判断（避免与中间件口径漂移）
        return true;
    }

    /**
     * 载荷格式校验规则（422 仅格式层）
     *
     * 与原控制器内联 validatePayload 逐条等价迁移；委外量非正/供应商缺失/仓库库位缺失/
     * 工序归属/工单状态（1523）/剩余量（1520）等业务冲突走 OutsourcingService
     * 业务码（422/1520/1523），不在此处拦截。
     *
     * @return array<string, string> 字段名 => 规则串（Laravel 校验语法）
     */
    public function rules(): array
    {
        return [
            'order_id' => 'required|integer|exists:production_orders,id',
            'operation_id' => 'required|integer|exists:work_order_operations,id',
            // 供应商/仓库库位可空：缺失走业务码 422（供应商不能为空/仓库与库位不能为空），不在此处拦截
            'supplier_id' => 'nullable|integer|exists:suppliers,id',
            'warehouse_id' => 'nullable|integer|exists:warehouses,id',
            'location_id' => 'nullable|integer|exists:locations,id',
            // 委外量限两位小数（正则按字符串形态校验，拦截 1e2 科学计数；允许负号形态，负值由业务层拦截 422）
            'quantity' => 'required|numeric|regex:/^-?\d+(\.\d{1,2})?$/',
            'remark' => 'nullable|string|max:200',
            // 发料组件必填（载荷重构后草稿必带组件）；数量限两位小数，正则按字符串形态校验拦 1e2 科学计数
            'items' => 'required|array|min:1',
            'items.*.material_id' => 'required|integer|exists:products,id',
            'items.*.required_qty' => 'required|numeric|regex:/^\d+(\.\d{1,2})?$/',
            'items.*.unit_id' => 'required|integer|exists:units,id',
        ];
    }
}
