<?php

// 库存余额模型：按 商品×仓库×库位 唯一；quantity 为流水求和结果，禁止旁路修改

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

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
