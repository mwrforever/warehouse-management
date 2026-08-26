<?php

// 客户保存（新建/更新共用）表单校验：名称/编码/联系人/电话/地址/备注/状态格式校验

namespace App\Http\Requests\Master;

use Illuminate\Foundation\Http\FormRequest;

class SaveCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        // 权限由路由中间件 permission:customer.create / permission:customer.update 控制，
        // 此处不再重复判断（避免与中间件口径漂移）
        return true;
    }

    /**
     * 载荷格式校验规则（422 仅格式层）
     *
     * 与原控制器内联 validate 逐条等价迁移；编码唯一（1110）属业务冲突，走 CustomerService
     * 业务码，不在此处拦截。
     *
     * @return array<string, string> 字段名 => 规则串（Laravel 校验语法）
     */
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:100',
            'code' => 'required|string|max:50',
            'contact' => 'nullable|string|max:50',
            'phone' => 'nullable|string|max:30',
            'address' => 'nullable|string',
            'remark' => 'nullable|string',
            'status' => 'nullable|in:0,1',
        ];
    }
}
