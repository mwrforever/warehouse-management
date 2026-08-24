<?php

// 工艺路线边：DAG 直接前驱→后继依赖，同路线同三元组唯一（防重复边）

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RoutingEdge extends Model
{
    protected $fillable = ['routing_id', 'from_node_id', 'to_node_id'];

    public function routing(): BelongsTo
    {
        return $this->belongsTo(RoutingHeader::class, 'routing_id');
    }

    public function fromNode(): BelongsTo
    {
        return $this->belongsTo(RoutingNode::class, 'from_node_id');
    }

    public function toNode(): BelongsTo
    {
        return $this->belongsTo(RoutingNode::class, 'to_node_id');
    }
}
