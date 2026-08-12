<?php

// 盘点明细模型：账面数快照 + 实盘数；差异审核时计算

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * 盘点明细
 *
 * @property int $id
 * @property int $check_id
 * @property int $product_id
 * @property int $location_id
 * @property string $book_qty
 * @property string $actual_qty
 * @property string $diff_qty
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class InventoryCheckItem extends Model
{
    protected $fillable = ['check_id', 'product_id', 'location_id', 'book_qty', 'actual_qty', 'diff_qty'];

    protected function casts(): array
    {
        return [
            'book_qty' => 'decimal:2',
            'actual_qty' => 'decimal:2',
            'diff_qty' => 'decimal:2',
        ];
    }

    // 盘点商品
    /** @return BelongsTo<Product, *> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    // 盘点库位
    /** @return BelongsTo<Location, *> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }
}
