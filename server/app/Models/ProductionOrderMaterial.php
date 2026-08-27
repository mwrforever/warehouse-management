<?php

// 工单物料需求快照模型：BOM 展开结果持久化（required_qty 快照、issued_qty 领料审核回写/退料审核冲销）

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 工单物料需求快照
 *
 * @property int $id
 * @property int $order_id
 * @property int $material_id
 * @property string $required_qty
 * @property string $issued_qty
 */
class ProductionOrderMaterial extends Model
{
    protected $fillable = ['order_id', 'material_id', 'required_qty', 'issued_qty', 'node_no'];

    protected function casts(): array
    {
        return [
            'required_qty' => 'decimal:2',
            'issued_qty' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<ProductionOrder, $this> */
    // 所属工单
    public function order(): BelongsTo
    {
        return $this->belongsTo(ProductionOrder::class, 'order_id');
    }

    /** @return BelongsTo<Product, $this> */
    // 物料商品
    public function material(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'material_id');
    }
}
