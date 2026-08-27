<?php

// 工艺路线节点材料：单位产出的投入用量（原料或前驱半成品），同节点同物料唯一

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * 工艺路线节点材料
 *
 * @property int $id
 * @property int $node_id
 * @property int $material_id
 * @property string $qty_per_unit
 * @property int $unit_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class RoutingNodeMaterial extends Model
{
    protected $fillable = ['node_id', 'material_id', 'qty_per_unit', 'unit_id'];

    protected function casts(): array
    {
        return ['qty_per_unit' => 'decimal:2'];
    }

    /** @return BelongsTo<RoutingNode, *> */
    public function node(): BelongsTo
    {
        return $this->belongsTo(RoutingNode::class, 'node_id');
    }

    /** @return BelongsTo<Product, *> */
    public function material(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'material_id');
    }

    /** @return BelongsTo<Unit, *> */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'unit_id');
    }
}
