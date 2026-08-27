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
 * @property int $price 单价（分单位整数）
 * @property string $shipped_qty
 * @property int $amount 行金额（分单位整数，half-up 取整）
 */
class SalesOrderItem extends Model
{
    protected $fillable = ['order_id', 'product_id', 'quantity', 'price', 'shipped_qty', 'amount'];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'price' => 'integer',
            'shipped_qty' => 'decimal:2',
            'amount' => 'integer',
        ];
    }

    /** @return BelongsTo<Product, $this> */
    // 明细商品
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
