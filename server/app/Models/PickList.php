<?php

// 领料单模型：草稿→已审核 两级状态 + 发料状态（未发料/部分/全部）；审核经 InventoryService 扣原料（防超领）

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 领料单
 *
 * @property int $id
 * @property string $no
 * @property int $order_id
 * @property int $status
 * @property int $issue_status
 * @property int $warehouse_id
 * @property int $location_id
 * @property string|null $approved_at
 * @property string|null $operator
 * @property string|null $remark
 */
class PickList extends Model
{
    public const STATUS_DRAFT = 0;

    public const STATUS_APPROVED = 1;

    public const ISSUE_NONE = 0;     // 未发料

    public const ISSUE_PARTIAL = 1;  // 部分发料

    public const ISSUE_ALL = 2;      // 全部发料

    /** 状态中文标签（列表展示） */
    public const STATUS_LABELS = [
        self::STATUS_DRAFT => '草稿',
        self::STATUS_APPROVED => '已审核',
    ];

    /** 发料状态中文标签（列表展示） */
    public const ISSUE_LABELS = [
        self::ISSUE_NONE => '未发料',
        self::ISSUE_PARTIAL => '部分发料',
        self::ISSUE_ALL => '全部发料',
    ];

    protected $fillable = ['no', 'order_id', 'status', 'issue_status', 'warehouse_id', 'location_id', 'approved_at', 'operator', 'remark'];

    protected function casts(): array
    {
        return [
            'status' => 'integer',
            'issue_status' => 'integer',
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
    // 领料仓库
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /** @return BelongsTo<Location, $this> */
    // 领料库位
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /** @return HasMany<PickListItem, $this> */
    // 明细行（随单级联删除）
    public function items(): HasMany
    {
        return $this->hasMany(PickListItem::class, 'pick_id');
    }
}
