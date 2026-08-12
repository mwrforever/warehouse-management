<?php

// 权限模型：RBAC 叶子节点，按 group 分组

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * 权限模型
 *
 * RBAC 叶子节点，按 group 分组（如 系统管理），
 * 与 Role 多对多关联（permission_role 中间表）。
 *
 * @property int $id
 * @property string $name
 * @property string $code
 * @property string $group
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Permission extends Model
{
    protected $fillable = ['name', 'code', 'group'];
}
