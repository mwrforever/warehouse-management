<?php

// 用户资源：对外输出的用户数据结构（含角色与权限，供前端守卫使用）

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 用户资源：对外输出的用户数据结构（含角色与权限，供前端守卫使用）
 *
 * @mixin User
 */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'username' => $this->username,
            'email' => $this->email,
            'status' => $this->status,
            'last_login_at' => $this->last_login_at?->toDateTimeString(),
            'roles' => $this->roles->map(fn ($r) => ['id' => $r->id, 'name' => $r->name]),
            'permissions' => $this->permissions(),
        ];
    }
}
