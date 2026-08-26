<?php

// 商品保存（新建/更新共用）表单校验：名称/编码/类型/分类/单位/条码/安全库存/状态格式校验

namespace App\Http\Requests\Master;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        // 权限由路由中间件 permission:product.create / permission:product.update 控制，
        // 此处不再重复判断（避免与中间件口径漂移）
        return true;
    }

    /**
     * 载荷格式校验规则（422 仅格式层）
     *
     * 与原控制器内联 validate 逐条等价迁移；编码/条码唯一（1114/1115）与安全库存区间
     * （1122）属业务冲突，走 ProductService 业务码，不在此处拦截。
     *
     * @return array<string, string|array> 字段名 => 规则（字符串或规则数组，Laravel 校验语法）
     */
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:100',
            // Spec 2：新建编码留空则自动生成（type=prd 配置驱动）；更新时编码必填；手填仍唯一校验 1114
            'code' => [($this->isMethod('PUT') ? 'required' : 'nullable'), 'string', 'max:50'],
            'type' => ['required', Rule::in(['raw_material', 'semi_finished', 'finished'])],
            'category_id' => 'required|exists:categories,id',
            'unit_id' => 'required|exists:units,id',
            'spec' => 'nullable|string|max:100',
            // 条码字符集限制可打印 ASCII（\x20-\x7E）：CODE128 仅支持 ASCII，防中文/emoji 录入导致前端条码渲染崩溃
            'barcode' => 'nullable|string|max:50|regex:/^[\x20-\x7E]*$/',
            // 安全库存限两位小数（正则防科学计数法，bccomp 比较的字符串安全前提，D-3；负值由 min:0 拦截）
            'safety_min' => 'nullable|numeric|min:0|regex:/^\d+(\.\d{1,2})?$/',
            'safety_max' => 'nullable|numeric|min:0|regex:/^\d+(\.\d{1,2})?$/',
            'status' => 'nullable|in:0,1',
            'remark' => 'nullable|string',
        ];
    }
}
