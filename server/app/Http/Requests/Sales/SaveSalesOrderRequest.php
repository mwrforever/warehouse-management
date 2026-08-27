<?php

// 销售订单保存（新建/更新共用）表单校验：客户/日期必填，明细商品存在、数量两位小数、单价分单位整数

namespace App\Http\Requests\Sales;

use Illuminate\Foundation\Http\FormRequest;

class SaveSalesOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        // 权限由路由中间件 permission:sales.order.create / sales.order.update 控制，
        // 此处不再重复判断（避免与中间件口径漂移）
        return true;
    }

    /**
     * 载荷格式校验规则（422 仅格式层）
     *
     * 与原控制器内联 validatePayload 逐条等价迁移；明细空数组/数量非正/负价/原料禁售/
     * 重复商品等业务冲突走 SalesOrderService 业务码（1401/422/1411/1412），不在此处拦截。
     *
     * @return array<string, string> 字段名 => 规则串（Laravel 校验语法）
     */
    public function rules(): array
    {
        return [
            'customer_id' => 'required|integer|exists:customers,id',
            'order_date' => 'required|date',
            'expected_date' => 'nullable|date',
            'remark' => 'nullable|string|max:200',
            // 注意：items 不加 required——空数组 [] 走 1401 业务码（422 仅拦缺失字段与类型错误）
            'items' => 'array',
            'items.*.product_id' => 'required|integer|exists:products,id',
            // 数量限两位小数（正则按字符串形态校验，拦截 1e2 科学计数法避免 bcmul ValueError；允许负号形态，负值由业务层拦截 422）；
            // 单价为分单位整数（R2：bigint 分列），integer 校验拦截小数分与科学计数法形态（负值仍由业务层拦截 1411）
            'items.*.quantity' => 'required|numeric|regex:/^-?\d+(\.\d{1,2})?$/',
            'items.*.price' => 'required|integer',
        ];
    }
}
