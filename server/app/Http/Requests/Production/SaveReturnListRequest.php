<?php

// 退料单保存（新建/更新共用）表单校验：工单/来源领料单/仓库/库位/明细行格式校验

namespace App\Http\Requests\Production;

use Illuminate\Foundation\Http\FormRequest;

class SaveReturnListRequest extends FormRequest
{
    public function authorize(): bool
    {
        // 权限由路由中间件 permission:production.return.create / production.return.update 控制，
        // 此处不再重复判断（避免与中间件口径漂移）
        return true;
    }

    /**
     * 载荷格式校验规则（422 仅格式层）
     *
     * 与原控制器内联 validatePayload 逐条等价迁移；明细空/数量≤0/重复商品/仓库库位缺失、
     * 领料单归属（422）、超已领总量（1517）与工单状态不可退料（1517）等业务冲突
     * 走 ReturnListService 业务码（422/1517），不在此处拦截。
     *
     * @return array<string, string> 字段名 => 规则串（Laravel 校验语法）
     */
    public function rules(): array
    {
        return [
            'order_id' => 'required|integer|exists:production_orders,id',
            'pick_id' => 'nullable|integer|exists:pick_lists,id',
            // 仓库/库位可空：缺失走业务码 422（仓库与库位不能为空），不在此处拦截
            'warehouse_id' => 'nullable|integer|exists:warehouses,id',
            'location_id' => 'nullable|integer|exists:locations,id',
            'remark' => 'nullable|string|max:200',
            // 注意：items 不加 required——空数组 [] 走 422 业务码（422 仅拦缺失字段与类型错误）
            'items' => 'array',
            'items.*.product_id' => 'required|integer|exists:products,id',
            // 数量限两位小数（正则防科学计数法；负值形态放行到业务层 422）
            'items.*.quantity' => 'required|numeric|regex:/^-?\d+(\.\d{1,2})?$/',
        ];
    }
}
