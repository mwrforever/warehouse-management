<?php

// 工艺路线节点材料：单位产出的投入用量（原料或前驱半成品），同节点同物料唯一

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RoutingNodeMaterial extends Model
{
    protected $fillable = ['node_id', 'material_id', 'qty_per_unit', 'unit_id'];

    protected function casts(): array
    {
        return ['qty_per_unit' => 'decimal:2'];
    }

    public function node(): BelongsTo
    {
        return $this->belongsTo(RoutingNode::class, 'node_id');
    }

    public function material(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'material_id');
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'unit_id');
    }
}
