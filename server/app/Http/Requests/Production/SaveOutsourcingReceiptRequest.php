<?php

// 委外回收单创建表单校验：回收量/仓库库位/回收品冒烟字段格式校验

namespace App\Http\Requests\Production;

use Illuminate\Foundation\Http\FormRequest;

class SaveOutsourcingReceiptRequest extends FormRequest
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
     * 与原控制器内联 validatePayloadReceipt 逐条等价迁移；回收量非正走业务码 422、
     * 回收品一致性与状态冲突（1529/1524/1523）等业务校验走 OutsourcingService，不在此处拦截。
     * product_id 可空=冒烟校验（提供时须等于节点输出，业务码 1529 在事务内）。
     *
     * @return array<string, string> 字段名 => 规则串（Laravel 校验语法）
     */
    public function rules(): array
    {
        return [
            // 回收量限两位小数（正则按字符串形态校验，拦截 1e2 科学计数；允许负号形态，负值由业务层拦截 422）
            'quantity' => 'required|numeric|regex:/^-?\d+(\.\d{1,2})?$/',
            'product_id' => 'nullable|integer|exists:products,id',
            'warehouse_id' => 'required|integer|exists:warehouses,id',
            'location_id' => 'required|integer|exists:locations,id',
            'remark' => 'nullable|string|max:200',
        ];
    }
}
