<?php

// 工序报工记录模型：每次报工的合格/不良/工时明细（只增不改，统计口径来源）

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 工序报工记录
 *
 * @property int $id
 * @property int $operation_id
 * @property int $order_id
 * @property string|null $operator
 * @property string $qualified_qty
 * @property string $defective_qty
 * @property string $hours
 * @property string $report_time
 * @property string|null $remark
 */
class OperationReport extends Model
{
    protected $fillable = ['operation_id', 'order_id', 'operator', 'qualified_qty', 'defective_qty', 'hours', 'report_time', 'remark'];

    protected function casts(): array
    {
        return [
            'qualified_qty' => 'decimal:2',
            'defective_qty' => 'decimal:2',
            'hours' => 'decimal:2',
            'report_time' => 'datetime',
        ];
    }

    /** @return BelongsTo<WorkOrderOperation, $this> */
    // 所属工序
    public function operation(): BelongsTo
    {
        return $this->belongsTo(WorkOrderOperation::class, 'operation_id');
    }
}
