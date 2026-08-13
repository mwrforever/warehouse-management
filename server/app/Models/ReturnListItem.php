<?php

// 退料单明细模型：退料商品+数量（冲销对象为工单物料已领量，不绑定领料行）

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 退料单明细
 *
 * @property int $id
 * @property int $return_id
 * @property int $product_id
 * @property string $quantity
 */
class ReturnListItem extends Model
{
    protected $fillable = ['return_id', 'product_id', 'quantity'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:2'];
    }

    /** @return BelongsTo<Product, $this> */
    // 物料商品
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
