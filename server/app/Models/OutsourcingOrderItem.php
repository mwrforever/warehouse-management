<?php

// 委外发料组件模型：应发/实发/已退三量；同单同物料唯一（uniq_outsourcing_order_items 兜底防重复）；
// 实发时全额扣减 issued_qty（不受应发数量约束），退回量回写 returned_qty 驱动余料退回校验

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 委外发料组件
 *
 * @property int $id
 * @property int $outsourcing_id
 * @property int $material_id
 * @property string $required_qty
 * @property string $issued_qty
 * @property string $returned_qty
 * @property int $unit_id
 */
class OutsourcingOrderItem extends Model
{
    protected $fillable = ['outsourcing_id', 'material_id', 'required_qty', 'issued_qty', 'returned_qty', 'unit_id'];

    protected function casts(): array
    {
        return [
            'required_qty' => 'decimal:2',
            'issued_qty' => 'decimal:2',
            'returned_qty' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<OutsourcingOrder, $this> */
    // 所属委外单
    public function outsourcing(): BelongsTo
    {
        return $this->belongsTo(OutsourcingOrder::class, 'outsourcing_id');
    }

    /** @return BelongsTo<Product, $this> */
    // 发料组件（原料/半成品；被引用不可删）
    public function material(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'material_id');
    }

    /** @return BelongsTo<Unit, $this> */
    // 计量单位
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }
}
