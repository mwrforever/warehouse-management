<?php

// 销售出库单模型：草稿→已审核 两级状态；审核时调 InventoryService 扣库存并回写订单（防超卖）

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * 销售出库单
 *
 * @property int $id
 * @property string $no
 * @property int $customer_id
 * @property int $warehouse_id
 * @property int $location_id
 * @property int|null $order_id
 * @property int $status
 * @property string $total_amount
 * @property Carbon|null $outbound_at
 * @property string|null $operator
 * @property string|null $remark
 */
class SalesOutbound extends Model
{
    public const STATUS_DRAFT = 0;

    public const STATUS_APPROVED = 1;

    /** 状态中文标签（列表/详情展示） */
    public const STATUS_LABELS = [
        self::STATUS_DRAFT => '草稿',
        self::STATUS_APPROVED => '已审核',
    ];

    protected $fillable = ['no', 'customer_id', 'warehouse_id', 'location_id', 'order_id', 'status', 'total_amount', 'outbound_at', 'operator', 'remark'];

    protected function casts(): array
    {
        return [
            'status' => 'integer',
            'total_amount' => 'decimal:2',
            'outbound_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Customer, $this> */
    // 客户
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<Warehouse, $this> */
    // 出库仓库
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /** @return BelongsTo<Location, $this> */
    // 出库库位
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /** @return BelongsTo<SalesOrder, $this> */
    // 来源销售订单（独立出库为空）
    public function order(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class, 'order_id');
    }

    /** @return HasMany<SalesOutboundItem, $this> */
    // 明细行（随单级联删除）
    public function items(): HasMany
    {
        return $this->hasMany(SalesOutboundItem::class, 'outbound_id');
    }
}
