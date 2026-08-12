<?php
// BOM 头模型：关联成品，同成品启用版本唯一
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BomHeader extends Model
{
    protected $fillable = ['code', 'product_id', 'version', 'quantity', 'status', 'remark'];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'status' => 'integer',
        ];
    }

    // BOM 关联的成品商品
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    // 明细行（随头级联删除）
    public function items(): HasMany
    {
        return $this->hasMany(BomItem::class);
    }
}
