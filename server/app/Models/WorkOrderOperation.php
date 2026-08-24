<?php

// 工单工序模型：待开工→进行中→已完成 三态流转；合格/不良/工时累计由报工回写

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 工单工序
 *
 * @property int $id
 * @property int $order_id
 * @property int $process_id
 * @property int $seq
 * @property int $status
 * @property string $qualified_qty
 * @property string $defective_qty
 * @property string $hours
 */
class WorkOrderOperation extends Model
{
    public const STATUS_PENDING = 0;   // 待开工

    public const STATUS_RUNNING = 1;   // 进行中

    public const STATUS_DONE = 2;      // 已完成

    /** 状态中文标签（步骤条/详情展示） */
    public const STATUS_LABELS = [
        self::STATUS_PENDING => '待开工',
        self::STATUS_RUNNING => '进行中',
        self::STATUS_DONE => '已完成',
    ];

    protected $fillable = ['order_id', 'process_id', 'seq', 'node_no', 'output_product_id', 'is_outsourced', 'status', 'qualified_qty', 'defective_qty', 'hours'];

    protected function casts(): array
    {
        return [
            'status' => 'integer',
            'seq' => 'integer',
            'is_outsourced' => 'integer',
            'qualified_qty' => 'decimal:2',
            'defective_qty' => 'decimal:2',
            'hours' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<ProductionOrder, $this> */
    // 所属工单
    public function order(): BelongsTo
    {
        return $this->belongsTo(ProductionOrder::class, 'order_id');
    }

    /** @return BelongsTo<Process, $this> */
    // 工序
    public function process(): BelongsTo
    {
        return $this->belongsTo(Process::class, 'process_id');
    }

    /** @return BelongsTo<Product, $this> */
    // 节点输出产品（DAG 节点快照，null=旧逻辑线性工序）
    public function outputProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'output_product_id');
    }

    /** @return HasMany<WorkOrderOperationEdge, $this> */
    // 以本工序为起点的依赖边（快照自工艺路线 DAG）
    public function edgesFrom(): HasMany
    {
        return $this->hasMany(WorkOrderOperationEdge::class, 'from_operation_id');
    }

    /** @return HasMany<WorkOrderOperationEdge, $this> */
    // 以本工序为终点的依赖边（快照自工艺路线 DAG）
    public function edgesTo(): HasMany
    {
        return $this->hasMany(WorkOrderOperationEdge::class, 'to_operation_id');
    }

    /** @return HasMany<OperationReport, $this> */
    // 报工记录（随工序级联删除）
    public function reports(): HasMany
    {
        return $this->hasMany(OperationReport::class, 'operation_id');
    }
}
