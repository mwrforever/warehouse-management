<?php

// 编号规则编辑表单校验：前缀/日期格式/序列长度/启用/备注格式校验

namespace App\Http\Requests\Master;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveDocumentNumberConfigRequest extends FormRequest
{
    public function authorize(): bool
    {
        // 权限由路由中间件 permission:system.setting.update 控制，此处不再重复判断（避免与中间件口径漂移）
        return true;
    }

    /**
     * 载荷格式校验规则（422 仅格式层）
     *
     * 与原控制器内联 validate 逐条等价迁移；type 禁止修改（载荷不含 type，仅白名单字段可写）。
     *
     * @return array<string, string|array> 字段名 => 规则（字符串或规则数组，Laravel 校验语法）
     */
    public function rules(): array
    {
        return [
            'prefix' => 'required|string|max:10|regex:/^[A-Z]{2,4}$/',
            // date_format 允许空字符串（无日期段，如商品编码全局自增）：present 保证键存在但空串合法（required 会误拒）
            'date_format' => ['present', Rule::in(['', 'Ymd', 'YmdHi', 'YmdHis'])],
            'seq_length' => 'required|integer|between:1,10',
            'is_enabled' => 'required|boolean',
            'remark' => 'nullable|string|max:255',
        ];
    }
}
