<?php

// 库位保存（新建/更新共用）表单校验：名称/编码（全局唯一，更新排除自身）/状态格式校验

namespace App\Http\Requests\Master;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveLocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        // 权限由路由中间件 permission:warehouse.create / permission:warehouse.update 控制，
        // 此处不再重复判断（避免与中间件口径漂移）
        return true;
    }

    /**
     * 载荷格式校验规则（422 格式层，含编码全局唯一 unique 规则）
     *
     * 与原控制器内联 validate 逐条等价迁移：新建时无自身可排除（路由无 location 绑定），
     * 更新时经路由绑定 location 排除自身，规则语义与 'unique:locations,code,{id}' 一致。
     *
     * @return array<string, string|array> 字段名 => 规则（字符串或规则数组，Laravel 校验语法）
     */
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:50',
            // 编码全局唯一：重复 422 格式层（既有口径不变；unique 规则属格式层，业务码无需引入）
            'code' => [
                'required', 'string', 'max:50',
                Rule::unique('locations', 'code')->ignore($this->route('location')),
            ],
            'status' => 'nullable|in:0,1',
        ];
    }
}
