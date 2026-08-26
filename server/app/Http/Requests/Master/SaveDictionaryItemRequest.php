<?php

// 字典项保存（新建/更新共用）表单校验：标签/值/排序/状态格式校验

namespace App\Http\Requests\Master;

use Illuminate\Foundation\Http\FormRequest;

class SaveDictionaryItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        // 权限由路由中间件 permission:dictionary.create / permission:dictionary.update 控制，
        // 此处不再重复判断（避免与中间件口径漂移）
        return true;
    }

    /**
     * 载荷格式校验规则（422 仅格式层）
     *
     * 与原控制器内联 validate 逐条等价迁移（全部为格式层规则，无业务冲突下沉）。
     *
     * @return array<string, string> 字段名 => 规则串（Laravel 校验语法）
     */
    public function rules(): array
    {
        return [
            'label' => 'required|string|max:50',
            'value' => 'required|string|max:50',
            'sort' => 'integer',
            'status' => 'in:0,1',
        ];
    }
}
