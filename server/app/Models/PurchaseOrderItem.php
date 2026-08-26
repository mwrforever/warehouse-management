<?php

// 采购订单明细模型：订购数/已入库累计/行金额；received_qty 仅入库审核回写，禁止旁路修改

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $order_id
 * @property int $product_id
 * @property string $quantity
 * @property int $price 含税单价（分单位整数）
 * @property string $received_qty
 * @property int $amount 行金额（分单位整数，half-up 取整）
 */
class PurchaseOrderItem extends Model
{
    protected $fillable = ['order_id', 'product_id', 'quantity', 'price', 'received_qty', 'amount'];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'price' => 'integer',
            'received_qty' => 'decimal:2',
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
