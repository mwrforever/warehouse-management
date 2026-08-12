<?php
// 供应商模型：采购模块引用；被采购单据引用时不可删除
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Supplier extends Model
{
    protected $fillable = ['name', 'code', 'contact', 'phone', 'address', 'remark', 'status'];

    protected function casts(): array
    {
        return ['status' => 'integer'];
    }
}
