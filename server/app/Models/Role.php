<?php
// 角色模型：RBAC 中间实体，关联权限与用户
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Role extends Model
{
    protected $fillable = ['name', 'code', 'remark'];

    // 角色下挂权限（多对多）
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class);
    }

    // 拥有该角色的用户（多对多）
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }
}
