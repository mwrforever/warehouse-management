<?php
// 权限模型：RBAC 叶子节点，按 group 分组
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Permission extends Model
{
    protected $fillable = ['name', 'code', 'group'];
}
