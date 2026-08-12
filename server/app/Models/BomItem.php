<?php

// BOM 明细模型：物料（原料/半成品）+ 用量

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * BOM 明细模型
 *
 * 表示 BOM 下的一行物料（原料/半成品）+ 用量，
 * 随 BOM 头级联删除；依赖 Product（material，belongsTo）与 Unit（belongsTo）。
 *
 * @property int $id
 * @property int $bom_header_id
 * @property int $material_id
 * @property string $quantity
 * @property int $unit_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class BomItem extends Model
{
    protected $fillable = ['bom_header_id', 'material_id', 'quantity', 'unit_id'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:2'];
    }

    // 物料商品（原料/半成品）
    /** @return BelongsTo<Product, *> */
    public function material(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'material_id');
    }

    // 用量单位
    /** @return BelongsTo<Unit, *> */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }
}
