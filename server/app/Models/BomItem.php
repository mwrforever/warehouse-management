<?php
// BOM 明细模型：物料（原料/半成品）+ 用量
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BomItem extends Model
{
    protected $fillable = ['bom_header_id', 'material_id', 'quantity', 'unit_id'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:2'];
    }

    // 物料商品（原料/半成品）
    public function material(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'material_id');
    }

    // 用量单位
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }
}
