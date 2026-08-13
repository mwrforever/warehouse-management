<?php

// 销售订单明细模型：订购数/已出库累计/行金额；shipped_qty 仅出库审核回写，禁止旁路修改

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 销售订单明细
 *
 * @property int $id
 * @property int $order_id
 * @property int $product_id
 * @property string $quantity
 * @property string $price
 * @property string $shipped_qty
 * @property string $amount
 */
class SalesOrderItem extends Model
{
    protected $fillable = ['order_id', 'product_id', 'quantity', 'price', 'shipped_qty', 'amount'];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'price' => 'decimal:2',
            'shipped_qty' => 'decimal:2',
            'amount' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<Product, $this> */
    // 明细商品
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
