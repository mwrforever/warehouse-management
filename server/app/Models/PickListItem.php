<?php

// 领料单明细模型：需求快照/本次领用/已发；issued_qty 仅发料动作回写

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 领料单明细
 *
 * @property int $id
 * @property int $pick_id
 * @property int $product_id
 * @property string $required_qty
 * @property string $pick_qty
 * @property string $issued_qty
 */
class PickListItem extends Model
{
    protected $fillable = ['pick_id', 'product_id', 'required_qty', 'pick_qty', 'issued_qty'];

    protected function casts(): array
    {
        return [
            'required_qty' => 'decimal:2',
            'pick_qty' => 'decimal:2',
            'issued_qty' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<Product, $this> */
    // 物料商品
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
