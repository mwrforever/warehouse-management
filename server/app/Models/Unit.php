<?php
// 计量单位模型：商品/BOM 明细共用
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Unit extends Model
{
    protected $fillable = ['name', 'code', 'status'];

    protected function casts(): array
    {
        return ['status' => 'integer'];
    }
}
