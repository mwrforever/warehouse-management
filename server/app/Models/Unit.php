<?php

// 计量单位模型：商品/BOM 明细共用

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * 计量单位模型
 *
 * 商品/BOM 明细共用（code 全局唯一），被商品/BOM 引用时删除受限。
 *
 * @property int $id
 * @property string $name
 * @property string $code
 * @property int $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Unit extends Model
{
    protected $fillable = ['name', 'code', 'status'];

    protected function casts(): array
    {
        return ['status' => 'integer'];
    }
}
