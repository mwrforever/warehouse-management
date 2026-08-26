<?php

// 盘点单保存（新建/更新共用）表单校验：仓库必填、明细非空、商品/库位存在、实盘数两位小数

namespace App\Http\Requests\Check;

use Illuminate\Foundation\Http\FormRequest;

class SaveCheckRequest extends FormRequest
{
    public function authorize(): bool
    {
        // 权限由路由中间件 permission:check.create / check.update 控制，
        // 此处不再重复判断（避免与中间件口径漂移）
        return true;
    }

    /**
     * 载荷格式校验规则（422 仅格式层）
     *
     * 与原控制器内联 validatePayload 逐条等价迁移；明细重复行/负实盘/无余额商品等业务冲突
     * 走 CheckService 业务码（422/1201/1205），不在此处拦截。
     *
     * @return array<string, string> 字段名 => 规则串（Laravel 校验语法）
     */
    public function rules(): array
    {
        return [
            'warehouse_id' => 'required|integer|exists:warehouses,id',
            'remark' => 'nullable|string|max:200',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|integer|exists:products,id',
            'items.*.location_id' => 'required|integer|exists:locations,id',
            // 数量限两位小数（正则防科学计数法；负值形态放行到方法内业务码 1201）——
            // bccomp 判负的字符串安全前提（D-3），与采购/销售等其余单据口径一致
            'items.*.actual_qty' => 'required|numeric|regex:/^-?\d+(\.\d{1,2})?$/',
        ];
    }
}
