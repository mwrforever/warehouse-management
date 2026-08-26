<?php

// 仓库保存（新建/更新共用）表单校验：名称/编码/地址/负责人/状态格式校验

namespace App\Http\Requests\Master;

use Illuminate\Foundation\Http\FormRequest;

class SaveWarehouseRequest extends FormRequest
{
    public function authorize(): bool
    {
        // 权限由路由中间件 permission:warehouse.create / permission:warehouse.update 控制，
        // 此处不再重复判断（避免与中间件口径漂移）
        return true;
    }

    /**
     * 载荷格式校验规则（422 仅格式层）
     *
     * 与原控制器内联 validate 逐条等价迁移；编码唯一（1105）属业务冲突，走 WarehouseService
     * 业务码，不在此处拦截。
     *
     * @return array<string, string> 字段名 => 规则串（Laravel 校验语法）
     */
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:50',
            'code' => 'required|string|max:20',
            'address' => 'nullable|string',
            'manager' => 'nullable|string|max:50',
            'status' => 'nullable|in:0,1',
        ];
    }
}
