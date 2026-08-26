<?php

// 重置密码表单校验：密码必填且满足强度（至少 8 位 + 字母 + 数字），弱密码走格式层 422

namespace App\Http\Requests\System;

use Illuminate\Foundation\Http\FormRequest;

class ResetPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // 权限由路由中间件 permission:user.update 控制
    }

    public function rules(): array
    {
        return [
            'password' => ['required', 'string', 'min:8', 'regex:/[A-Za-z]/', 'regex:/[0-9]/'],
        ];
    }
}
