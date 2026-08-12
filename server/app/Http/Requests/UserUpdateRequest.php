<?php

// 更新用户表单校验：用户名唯一（排除自身）、密码可选、角色数组

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UserUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // 权限由路由中间件 permission:user.update 控制
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:50'],
            // 排除自身：更新时用户名不变也应通过唯一校验
            'username' => [
                'required', 'string', 'max:50',
                Rule::unique('users', 'username')->ignore($this->route('user')),
            ],
            'password' => ['nullable', 'string', 'min:8', 'regex:/[A-Za-z]/', 'regex:/[0-9]/'],
            'email' => ['nullable', 'email'],
            'status' => ['required', 'in:0,1'],
            'role_ids' => ['array'],
            'role_ids.*' => ['exists:roles,id'],
        ];
    }
}
