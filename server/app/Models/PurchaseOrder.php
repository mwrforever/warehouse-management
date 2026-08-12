<?php

// 采购订单模型：草稿→已审核→部分入库→已完成→关闭 五态状态机；审核后不可改删；入库审核回写状态

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $no
 * @property int $supplier_id
 * @property string $order_date
 * @property string|null $expected_date
 * @property int $status
 * @property string $total_amount
 * @property string|null $remark
 * @property int|null $created_by
 * @property string|null $approved_at
 * @property string|null $closed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class PurchaseOrder extends Model
{
    public const STATUS_DRAFT = 0;      // 草稿

    public const STATUS_APPROVED = 1;   // 已审核

    public const STATUS_PARTIAL = 2;    // 部分入库

    public const STATUS_COMPLETED = 3;  // 已完成

    public const STATUS_CLOSED = 4;     // 关闭

    /** 状态中文标签（列表/详情展示） */
    public const STATUS_LABELS = [
        self::STATUS_DRAFT => '草稿',
        self::STATUS_APPROVED => '已审核',
        self::STATUS_PARTIAL => '部分入库',
        self::STATUS_COMPLETED => '已完成',
        self::STATUS_CLOSED => '关闭',
    ];

    protected $fillable = ['no', 'supplier_id', 'order_date', 'expected_date', 'status', 'total_amount', 'remark', 'created_by', 'approved_at', 'closed_at'];

    protected function casts(): array
    {
        return [
            'status' => 'integer',
            'total_amount' => 'decimal:2',
            'approved_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Supplier, $this> */
    // 供应商
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /** @return HasMany<PurchaseOrderItem, $this> */
    // 明细行（随单级联删除）
    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class, 'order_id');
    }
}
