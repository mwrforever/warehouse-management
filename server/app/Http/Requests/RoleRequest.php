<?php

// 角色表单校验：名称/编码必填、编码唯一（忽略自身）、admin 保留码拦截、权限数组

namespace App\Http\Requests;

use App\Models\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 角色表单校验
 *
 * 覆盖新建与更新场景：名称/编码必填且限长，编码唯一（更新时忽略自身），
 * permission_ids 必须为数组且每个元素存在于 permissions 表。
 * admin 为保留码：其他角色禁止占用（与 roles.code 唯一约束双重保险），
 * 内置管理员角色的编码锁定不可改出（防改名后重建 admin 角色架空删除保护与权限放行）。
 */
class RoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // 权限由路由中间件 permission:role.* 控制
    }

    public function rules(): array
    {
        $role = $this->route('role');
        // 忽略自身：更新时编码不变也应通过唯一校验
        $codeRules = ['required', 'string', 'max:50', Rule::unique('roles', 'code')->ignore($role)];
        if ($role instanceof Role && $role->code === 'admin') {
            // 内置管理员角色：编码锁定为 admin，防改名后重建 admin 角色架空保护
            $codeRules[] = Rule::in(['admin']);
        } else {
            // 新建/更新其他角色：禁止占用 admin 保留码（防伪造第二个管理员角色）
            $codeRules[] = Rule::notIn(['admin']);
        }

        return [
            'name' => ['required', 'string', 'max:50'],
            'code' => $codeRules,
            'remark' => ['nullable', 'string'],
            'permission_ids' => ['array'],
            'permission_ids.*' => ['exists:permissions,id'],
        ];
    }
}
