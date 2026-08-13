<?php

// 成品入库单明细模型：成品商品+数量（必须与工单产品一致 1526）

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 成品入库单明细
 *
 * @property int $id
 * @property int $finished_inbound_id
 * @property int $product_id
 * @property string $quantity
 */
class FinishedInboundItem extends Model
{
    protected $fillable = ['finished_inbound_id', 'product_id', 'quantity'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:2'];
    }

    /** @return BelongsTo<Product, $this> */
    // 成品商品
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
