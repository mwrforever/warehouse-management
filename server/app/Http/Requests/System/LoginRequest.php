<?php

// 登录表单校验：username/password 必填（格式层失败 422，业务失败 1001/1006 由 AuthService 抛出）

namespace App\Http\Requests\System;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // 登录为匿名入口，无 permission 中间件挂载（Auth 类接口属权限中间件豁免场景）
    }

    public function rules(): array
    {
        return [
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
        ];
    }
}
