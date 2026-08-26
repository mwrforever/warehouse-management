<?php

// 编号规则预览表单校验：前缀/日期格式/序列长度格式校验（不落库、不占号）

namespace App\Http\Requests\Master;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PreviewDocumentNumberConfigRequest extends FormRequest
{
    public function authorize(): bool
    {
        // 权限由路由中间件 permission:system.setting.list 控制，此处不再重复判断（避免与中间件口径漂移）
        return true;
    }

    /**
     * 载荷格式校验规则（422 仅格式层）
     *
     * 与原控制器内联 validate 逐条等价迁移（与编辑规则共用 prefix/date_format/seq_length 三段规则）。
     *
     * @return array<string, string|array> 字段名 => 规则（字符串或规则数组，Laravel 校验语法）
     */
    public function rules(): array
    {
        return [
            'prefix' => 'required|string|max:10|regex:/^[A-Z]{2,4}$/',
            // 同上：date_format 空串是合法规则（无日期段）；ConvertEmptyStringsToNull 会转 null，归一化回 '' 由控制器复用 update 方式
            'date_format' => ['present', Rule::in(['', 'Ymd', 'YmdHi', 'YmdHis'])],
            'seq_length' => 'required|integer|between:1,10',
        ];
    }
}
