<?php

// 盘点账面预填（GET auto-books）表单校验：仓库必填且存在（盘点弹窗数据源）

namespace App\Http\Requests\Check;

use Illuminate\Foundation\Http\FormRequest;

class AutoBooksRequest extends FormRequest
{
    public function authorize(): bool
    {
        // 权限由路由中间件 permission:check.list 控制，此处不再重复判断（避免与中间件口径漂移）
        return true;
    }

    /**
     * 载荷格式校验规则（422 仅格式层）：仓库必填且存在
     *
     * 与原控制器内联 $request->validate 逐条等价迁移。
     *
     * @return array<string, string> 字段名 => 规则串（Laravel 校验语法）
     */
    public function rules(): array
    {
        return [
            'warehouse_id' => 'required|integer|exists:warehouses,id',
        ];
    }
}
