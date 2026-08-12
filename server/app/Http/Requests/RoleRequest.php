<?php

// 角色表单校验：名称/编码必填、编码唯一（忽略自身）、权限数组

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 角色表单校验
 *
 * 覆盖新建与更新场景：名称/编码必填且限长，编码唯一（更新时忽略自身），
 * permission_ids 必须为数组且每个元素存在于 permissions 表。
 */
class RoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // 权限由路由中间件 permission:role.* 控制
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:50'],
            // 忽略自身：更新时编码不变也应通过唯一校验
            'code' => ['required', 'string', 'max:50', Rule::unique('roles', 'code')->ignore($this->route('role'))],
            'remark' => ['nullable', 'string'],
            'permission_ids' => ['array'],
            'permission_ids.*' => ['exists:permissions,id'],
        ];
    }
}
