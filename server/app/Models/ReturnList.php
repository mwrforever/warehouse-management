<?php

// 退料单模型：草稿→已审核；审核经 InventoryService 写 return 流水(+1) 并冲销工单物料已领量

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 退料单
 *
 * @property int $id
 * @property string $no
 * @property int $order_id
 * @property int|null $pick_id
 * @property int $status
 * @property int $warehouse_id
 * @property int $location_id
 * @property string|null $approved_at
 * @property string|null $operator
 * @property string|null $remark
 */
class ReturnList extends Model
{
    public const STATUS_DRAFT = 0;

    public const STATUS_APPROVED = 1;

    /** 状态中文标签（列表展示） */
    public const STATUS_LABELS = [
        self::STATUS_DRAFT => '草稿',
        self::STATUS_APPROVED => '已审核',
    ];

    protected $fillable = ['no', 'order_id', 'pick_id', 'status', 'warehouse_id', 'location_id', 'approved_at', 'operator', 'remark'];

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

    /** @return BelongsTo<PickList, $this> */
    // 冲销来源领料单（可空）
    public function pick(): BelongsTo
    {
        return $this->belongsTo(PickList::class, 'pick_id');
    }

    /** @return HasMany<ReturnListItem, $this> */
    // 明细行（随单级联删除）
    public function items(): HasMany
    {
        return $this->hasMany(ReturnListItem::class, 'return_id');
    }
}
