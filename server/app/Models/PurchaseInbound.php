<?php

// 采购入库单模型：草稿→已审核 两级状态；审核时调 InventoryService 加库存并回写订单

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $no
 * @property int $supplier_id
 * @property int $warehouse_id
 * @property int $location_id
 * @property int|null $order_id
 * @property int $status
 * @property string $total_amount
 * @property Carbon|null $inbound_at
 * @property string|null $operator
 * @property string|null $remark
 */
class PurchaseInbound extends Model
{
    public const STATUS_DRAFT = 0;

    public const STATUS_APPROVED = 1;

    /** 状态中文标签（列表/详情展示） */
    public const STATUS_LABELS = [
        self::STATUS_DRAFT => '草稿',
        self::STATUS_APPROVED => '已审核',
    ];

    protected $fillable = ['no', 'supplier_id', 'warehouse_id', 'location_id', 'order_id', 'status', 'total_amount', 'inbound_at', 'operator', 'remark'];

    protected function casts(): array
    {
        return [
            'status' => 'integer',
            'total_amount' => 'decimal:2',
            'inbound_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Supplier, $this> */
    // 供应商
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
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

    /** @return BelongsTo<PurchaseOrder, $this> */
    // 来源采购订单（独立入库为空）
    public function order(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'order_id');
    }

    /** @return HasMany<PurchaseInboundItem, $this> */
    // 明细行（随单级联删除）
    public function items(): HasMany
    {
        return $this->hasMany(PurchaseInboundItem::class, 'inbound_id');
    }
}
