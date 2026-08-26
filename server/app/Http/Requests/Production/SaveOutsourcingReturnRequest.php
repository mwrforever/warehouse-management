<?php

// 委外余料退回表单校验：退回组件行/数量/仓库库位格式校验

namespace App\Http\Requests\Production;

use Illuminate\Foundation\Http\FormRequest;

class SaveOutsourcingReturnRequest extends FormRequest
{
    public function authorize(): bool
    {
        // 权限由路由中间件 permission:production.outsource.update 控制，
        // 此处不再重复判断（避免与中间件口径漂移）
        return true;
    }

    /**
     * 载荷格式校验规则（422 仅格式层）
     *
     * 与原控制器内联 validatePayloadReturn 逐条等价迁移；组件归属/退回量超已发未退/
     * 当前状态不可退回等业务校验走 OutsourcingService 业务码（422），不在此处拦截。
     *
     * @return array<string, string> 字段名 => 规则串（Laravel 校验语法）
     */
    public function rules(): array
    {
        return [
            'items' => 'required|array|min:1',
            'items.*.item_id' => 'required|integer|exists:outsourcing_order_items,id',
            // 退回数量限两位小数（正则按字符串形态校验，拦截 1e2 科学计数）
            'items.*.quantity' => 'required|numeric|regex:/^\d+(\.\d{1,2})?$/',
            'warehouse_id' => 'required|integer|exists:warehouses,id',
            'location_id' => 'required|integer|exists:locations,id',
            'remark' => 'nullable|string|max:200',
        ];
    }
}
