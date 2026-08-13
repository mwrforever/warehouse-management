<?php

// 成品入库单模型：草稿→已审核；审核经 InventoryService 写 finished_inbound 流水(+1) 并回写工单 completed_qty（满产自动完成）

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 成品入库单
 *
 * @property int $id
 * @property string $no
 * @property int $order_id
 * @property int $status
 * @property int $warehouse_id
 * @property int $location_id
 * @property string|null $approved_at
 * @property string|null $operator
 * @property string|null $remark
 */
class FinishedInbound extends Model
{
    public const STATUS_DRAFT = 0;

    public const STATUS_APPROVED = 1;

    /** 状态中文标签（列表展示） */
    public const STATUS_LABELS = [
        self::STATUS_DRAFT => '草稿',
        self::STATUS_APPROVED => '已审核',
    ];

    protected $fillable = ['no', 'order_id', 'status', 'warehouse_id', 'location_id', 'approved_at', 'operator', 'remark'];

    protected function casts(): array
    {
        return [
            'status' => 'integer',
            'approved_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<ProductionOrder, $this> */
    // 所属工单
    public function order(): BelongsTo
    {
        return $this->belongsTo(ProductionOrder::class, 'order_id');
    }

    /** @return BelongsTo<Warehouse, $this> */
    // 入库仓库
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /** @return BelongsTo<Location, $this> */
    // 入库库位
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /** @return HasMany<FinishedInboundItem, $this> */
    // 明细行（随单级联删除）
    public function items(): HasMany
    {
        return $this->hasMany(FinishedInboundItem::class, 'finished_inbound_id');
    }
}
