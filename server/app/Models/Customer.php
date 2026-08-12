<?php
// 客户模型：销售模块引用；被销售单据引用时不可删除
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    protected $fillable = ['name', 'code', 'contact', 'phone', 'address', 'remark', 'status'];

    protected function casts(): array
    {
        return ['status' => 'integer'];
    }
}
