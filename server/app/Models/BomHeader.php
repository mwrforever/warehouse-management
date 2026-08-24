<?php

// BOM 头模型：关联成品，同成品启用版本唯一

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * BOM 头模型
 *
 * 承载 BOM 主记录（成品 + 版本 + 基准产出量），同成品启用版本唯一，
 * 明细行随头级联删除；依赖 Product（belongsTo）与 BomItem（hasMany）。
 *
 * @property int $id
 * @property string $code
 * @property int $product_id
 * @property string $version
 * @property string $quantity
 * @property int $status
 * @property string|null $remark
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class BomHeader extends Model
{
    /** 版本状态：0停用 1启用（同成品启用版本唯一，由控制器事务保证） */
    public const STATUS_DISABLED = 0;

    public const STATUS_ENABLED = 1;

    protected $fillable = ['code', 'product_id', 'version', 'quantity', 'status', 'remark'];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'status' => 'integer',
        ];
    }

    // BOM 关联的成品商品
    /** @return BelongsTo<Product, *> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    // 明细行（随头级联删除）
    /** @return HasMany<BomItem, *> */
    public function items(): HasMany
    {
        return $this->hasMany(BomItem::class);
    }
}
