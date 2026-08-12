<?php

// 角色模型：RBAC 中间实体，关联权限与用户

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * 角色模型
 *
 * RBAC 中间实体，关联权限（多对多）与用户（多对多）；
 * admin 编码角色为内置管理员保护对象（删除/修改受限）。
 *
 * @property int $id
 * @property string $name
 * @property string $code
 * @property string|null $remark
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Role extends Model
{
    protected $fillable = ['name', 'code', 'remark'];

    // 角色下挂权限（多对多）
    /** @return \Illuminate\Database\Eloquent\Relations\BelongsToMany<\App\Models\Permission, *, *, *> */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class);
    }

    // 拥有该角色的用户（多对多）
    /** @return \Illuminate\Database\Eloquent\Relations\BelongsToMany<\App\Models\User, *, *, *> */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }
}
