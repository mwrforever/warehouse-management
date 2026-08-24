<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Laravel\Sanctum\HasApiTokens;

/**
 * 用户模型
 *
 * 系统登录账号（Authenticatable + Sanctum 令牌认证），
 * username 唯一登录名，email 可空；与 Role 多对多（RBAC）；
 * last_login_at 为 datetime cast（Carbon），供资源层格式化输出。
 *
 * @property int $id
 * @property string $name
 * @property string $username
 * @property string|null $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property int $status
 * @property Carbon|null $last_login_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property \Illuminate\Database\Eloquent\Collection<int, Role> $roles
 */
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    use HasApiTokens;

    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use Notifiable;

    /** 账号状态：0禁用 1启用（禁用后登录被拒，见 AuthController） */
    public const STATUS_DISABLED = 0;

    public const STATUS_ENABLED = 1;

    // 用户字段白名单：账号信息、状态与最后登录时间（密码单独走 setter）
    protected $fillable = ['name', 'username', 'email', 'password', 'status', 'last_login_at'];

    // 密码统一 bcrypt 加密存储；last_login_at 转 Carbon 供资源层格式化
    protected function casts(): array
    {
        return ['password' => 'hashed', 'status' => 'integer', 'last_login_at' => 'datetime'];
    }

    // 用户可挂多个角色（RBAC 多对多）
    /** @return \Illuminate\Database\Eloquent\Relations\BelongsToMany<\App\Models\Role, *, *, *> */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class);
    }

    // 合并所有角色的权限 code 集合（去重）
    public function permissions(): Collection
    {
        return $this->roles()->with('permissions')->get()
            ->pluck('permissions')->flatten()->pluck('code')->unique()->values();
    }
}
