<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    // 用户字段白名单：账号信息、状态与最后登录时间（密码单独走 setter）
    protected $fillable = ['name', 'username', 'email', 'password', 'status', 'last_login_at'];

    // 密码统一 bcrypt 加密存储；last_login_at 转 Carbon 供资源层格式化
    protected function casts(): array
    {
        return ['password' => 'hashed', 'status' => 'integer', 'last_login_at' => 'datetime'];
    }

    // 用户可挂多个角色（RBAC 多对多）
    public function roles(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Role::class);
    }

    // 合并所有角色的权限 code 集合（去重）
    public function permissions(): \Illuminate\Support\Collection
    {
        return $this->roles()->with('permissions')->get()
            ->pluck('permissions')->flatten()->pluck('code')->unique()->values();
    }
}
