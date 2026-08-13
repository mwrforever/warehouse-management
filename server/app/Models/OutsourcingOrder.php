<?php

// 委外加工单模型：草稿→已审核(发出)→已回收 三态；发出扣成品库存(outsourcing_out)、回收加库存(outsourcing_in)

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * 委外加工单
 *
 * @property int $id
 * @property string $no
 * @property int $order_id
 * @property int $operation_id
 * @property int $supplier_id
 * @property int $status
 * @property int $warehouse_id
 * @property int $location_id
 * @property string $quantity
 * @property Carbon|null $approved_at
 * @property string|null $operator
 * @property string|null $remark
 */
class OutsourcingOrder extends Model
{
    public const STATUS_DRAFT = 0;     // 草稿

    public const STATUS_APPROVED = 1;  // 已审核（已发出）

    public const STATUS_RECEIVED = 2;  // 已回收

    /** 状态中文标签（列表展示） */
    public const STATUS_LABELS = [
        self::STATUS_DRAFT => '草稿',
        self::STATUS_APPROVED => '已审核',
        self::STATUS_RECEIVED => '已回收',
    ];

    protected $fillable = ['no', 'order_id', 'operation_id', 'supplier_id', 'status', 'warehouse_id', 'location_id', 'quantity', 'approved_at', 'operator', 'remark'];

    protected function casts(): array
    {
        return [
            'status' => 'integer',
            'quantity' => 'decimal:2',
            'approved_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<ProductionOrder, $this> */
    // 所属工单（委外商品 = 工单成品）
    public function order(): BelongsTo
    {
        return $this->belongsTo(ProductionOrder::class, 'order_id');
    }

    /** @return BelongsTo<WorkOrderOperation, $this> */
    // 委外工序
    public function operation(): BelongsTo
    {
        return $this->belongsTo(WorkOrderOperation::class, 'operation_id');
    }

    /** @return BelongsTo<Supplier, $this> */
    // 委外供应商
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /** @return BelongsTo<Warehouse, $this> */
    // 发出仓库
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /** @return BelongsTo<Location, $this> */
    // 发出库位
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /** @return HasMany<OutsourcingReceipt, $this> */
    // 回收记录（随单级联删除）
    public function receipts(): HasMany
    {
        return $this->hasMany(OutsourcingReceipt::class, 'outsourcing_id');
    }
}
