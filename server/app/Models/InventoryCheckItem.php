<?php

// 盘点明细模型：账面数快照 + 实盘数；差异审核时计算

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    // 盘点库位
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }
}
