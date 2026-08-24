<?php

// 委外加工单模型：草稿→已审核(发出)→已回收→已关闭 四态；发出按发料组件扣库存(outsourcing_out)、回收加节点输出库存(outsourcing_in)；
// 已关闭=余料全部退回后自动（status 3）；附发料组件（items）与余料退回（returns）两个明细域

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
 * @property int|null $output_product_id
 * @property Carbon|null $approved_at
 * @property string|null $operator
 * @property string|null $remark
 */
class OutsourcingOrder extends Model
{
    public const STATUS_DRAFT = 0;     // 草稿

    public const STATUS_APPROVED = 1;  // 已审核（已发出）

    public const STATUS_RECEIVED = 2;  // 已回收

    public const STATUS_CLOSED = 3;    // 已关闭（余料全部退回后自动）

    /** 状态中文标签（列表展示） */
    public const STATUS_LABELS = [
        self::STATUS_DRAFT => '草稿',
        self::STATUS_APPROVED => '已审核',
        self::STATUS_RECEIVED => '已回收',
        self::STATUS_CLOSED => '已关闭',
    ];

    // 已回收累计不落列（实时 SUM outsourcing_receipts 派生，spec 5 §13.13）
    protected $fillable = ['no', 'order_id', 'operation_id', 'supplier_id', 'status', 'warehouse_id', 'location_id', 'quantity', 'output_product_id', 'approved_at', 'operator', 'remark'];

    protected function casts(): array
    {
        return [
            'status' => 'integer',
            'quantity' => 'decimal:2',
            'approved_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<ProductionOrder, $this> */
    // 所属工单（委外加工归属的工单；发料组件与回收品均取工艺节点口径，spec 5 §4 规则定义）
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

    /** @return BelongsTo<Product, $this> */
    // 回收品（工序节点输出：半成品或成品）
    public function outputProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'output_product_id');
    }

    /** @return HasMany<OutsourcingOrderItem, $this> */
    // 发料组件明细（随单级联删除）
    public function items(): HasMany
    {
        return $this->hasMany(OutsourcingOrderItem::class, 'outsourcing_id');
    }

    /** @return HasMany<OutsourcingReturn, $this> */
    // 余料退回单（随单级联删除）
    public function returns(): HasMany
    {
        return $this->hasMany(OutsourcingReturn::class, 'outsourcing_id');
    }
}
