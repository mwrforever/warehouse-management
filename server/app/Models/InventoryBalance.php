<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 库存余额模型：按 商品×仓库×库位 唯一；quantity 为流水求和结果，禁止旁路修改
 *
 * @property string $quantity 余额数量（decimal:2 cast 运行时为两位小数字符串；
 *                            显式标注纠正 larastan 将 decimal cast 推断为 float 的失真——D-3 全链路 bcmath 字符串口径）
 */
class InventoryBalance extends Model
{
    protected $fillable = ['product_id', 'warehouse_id', 'location_id', 'quantity', 'safety_min', 'safety_max'];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'safety_min' => 'decimal:2',
            'safety_max' => 'decimal:2',
        ];
    }
}
