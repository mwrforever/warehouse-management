<?php

// 销售出库单明细模型：出库数量/单价/行金额；order_item_id 关联订单行（独立出库为空）

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 销售出库单明细
 *
 * @property int $id
 * @property int $outbound_id
 * @property int $product_id
 * @property string $quantity
 * @property string $price
 * @property string $amount
 * @property int|null $order_item_id
 */
class SalesOutboundItem extends Model
{
    protected $fillable = ['outbound_id', 'product_id', 'quantity', 'price', 'amount', 'order_item_id'];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'price' => 'decimal:2',
            'amount' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<Product, $this> */
    // 明细商品
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<SalesOrderItem, $this> */
    // 来源订单行（独立出库为空）
    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(SalesOrderItem::class, 'order_item_id');
    }
}
