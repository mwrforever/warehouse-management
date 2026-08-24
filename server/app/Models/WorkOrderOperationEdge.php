<?php

// 工单工序边：下达时从工艺路线 DAG 快照的依赖边，工序删除随删（级联）

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkOrderOperationEdge extends Model
{
    protected $fillable = ['order_id', 'from_operation_id', 'to_operation_id'];

    public function order(): BelongsTo
    {
        return $this->belongsTo(ProductionOrder::class, 'order_id');
    }

    public function fromOperation(): BelongsTo
    {
        return $this->belongsTo(WorkOrderOperation::class, 'from_operation_id');
    }

    public function toOperation(): BelongsTo
    {
        return $this->belongsTo(WorkOrderOperation::class, 'to_operation_id');
    }
}
