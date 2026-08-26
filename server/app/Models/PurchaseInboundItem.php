<?php

// 采购入库单明细模型：入库数量/单价/行金额；order_item_id 关联订单行（独立入库为空）

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $inbound_id
 * @property int $product_id
 * @property string $quantity
 * @property int $price 含税单价（分单位整数）
 * @property int $amount 行金额（分单位整数，half-up 取整）
 * @property int|null $order_item_id
 */
class PurchaseInboundItem extends Model
{
    protected $fillable = ['inbound_id', 'product_id', 'quantity', 'price', 'amount', 'order_item_id'];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'price' => 'integer',
            'amount' => 'integer',
        ];
    }

    /** @return BelongsTo<Product, $this> */
    // 明细商品
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<PurchaseInbound, $this> */
    // 所属入库单（成本价估算按单据审核状态过滤：草稿入库单单价不参与）
    public function purchaseInbound(): BelongsTo
    {
        return $this->belongsTo(PurchaseInbound::class, 'inbound_id');
    }

    /** @return BelongsTo<PurchaseOrderItem, $this> */
    // 来源订单行（独立入库为空）
    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderItem::class, 'order_item_id');
    }
}
