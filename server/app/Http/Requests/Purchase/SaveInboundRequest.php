<?php

// 采购入库单保存（新建/更新共用）表单校验：供应商必填，仓库/库位可空（业务码 1307 拦截），明细同订单口径

namespace App\Http\Requests\Purchase;

use Illuminate\Foundation\Http\FormRequest;

class SaveInboundRequest extends FormRequest
{
    public function authorize(): bool
    {
        // 权限由路由中间件 permission:purchase.inbound.create / purchase.inbound.update 控制，
        // 此处不再重复判断（避免与中间件口径漂移）
        return true;
    }

    /**
     * 载荷格式校验规则（422 仅格式层）
     *
     * 与原控制器内联 validatePayload 逐条等价迁移；仓库/库位缺失走业务码 1307，
     * 明细空/数量非法/负价/重复/订单行冲突走 PurchaseInboundService 业务码
     * （1301/1302/1311/1312/1308），不在此处拦截。
     *
     * @return array<string, string> 字段名 => 规则串（Laravel 校验语法）
     */
    public function rules(): array
    {
        return [
            'supplier_id' => 'required|integer|exists:suppliers,id',
            'warehouse_id' => 'nullable|integer|exists:warehouses,id',
            'location_id' => 'nullable|integer|exists:locations,id',
            'order_id' => 'nullable|integer|exists:purchase_orders,id',
            'remark' => 'nullable|string|max:200',
            // 注意：items 不加 required——空数组 [] 走 1301 业务码（422 仅拦缺失字段与类型错误）
            'items' => 'array',
            'items.*.product_id' => 'required|integer|exists:products,id',
            // 数量限两位小数（正则按字符串形态校验，拦截 1e2 科学计数法避免 bcmul ValueError；允许负号形态，负值由业务层拦截 1302）；
            // 单价为分单位整数（R2：bigint 分列），integer 校验拦截小数分与科学计数法形态（负值仍由业务层拦截 1311）
            'items.*.quantity' => 'required|numeric|regex:/^-?\d+(\.\d{1,2})?$/',
            'items.*.price' => 'required|integer',
            'items.*.order_item_id' => 'nullable|integer|exists:purchase_order_items,id',
        ];
    }
}
