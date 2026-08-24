<?php

// 工艺路线节点：一道工序的 DAG 顶点（工序快照+输入材料+输出产品+委外标记），node_no 同路线唯一

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RoutingNode extends Model
{
    protected $fillable = ['routing_id', 'node_no', 'process_id', 'name', 'output_product_id', 'output_qty', 'is_outsourced', 'remark'];

    protected function casts(): array
    {
        return ['output_qty' => 'decimal:2', 'is_outsourced' => 'int'];
    }

    public function routing(): BelongsTo
    {
        return $this->belongsTo(RoutingHeader::class, 'routing_id');
    }

    public function process(): BelongsTo
    {
        return $this->belongsTo(Process::class, 'process_id');
    }

    public function outputProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'output_product_id');
    }

    // 节点输入材料（原料或前驱半成品，随节点级联删除）
    public function materials(): HasMany
    {
        return $this->hasMany(RoutingNodeMaterial::class, 'node_id');
    }
}
