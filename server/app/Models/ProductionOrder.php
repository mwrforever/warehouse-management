<?php

// 生产工单模型：草稿→已下达→生产中→已完成→关闭 五态状态机；BOM 展开快照物料与工序；成品入库审核回写状态

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * 生产工单
 *
 * @property int $id
 * @property string $no
 * @property int $product_id
 * @property string $quantity
 * @property string $plan_date
 * @property int $bom_id
 * @property int $status
 * @property string $completed_qty
 * @property int|null $created_by
 * @property Carbon|null $released_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $closed_at
 * @property string|null $remark
 */
class ProductionOrder extends Model
{
    public const STATUS_DRAFT = 0;      // 草稿

    public const STATUS_RELEASED = 1;   // 已下达

    public const STATUS_PRODUCING = 2;  // 生产中

    public const STATUS_COMPLETED = 3;  // 已完成

    public const STATUS_CLOSED = 4;     // 关闭

    /** 状态中文标签（列表/详情展示） */
    public const STATUS_LABELS = [
        self::STATUS_DRAFT => '草稿',
        self::STATUS_RELEASED => '已下达',
        self::STATUS_PRODUCING => '生产中',
        self::STATUS_COMPLETED => '已完成',
        self::STATUS_CLOSED => '关闭',
    ];

    protected $fillable = ['no', 'product_id', 'quantity', 'plan_date', 'bom_id', 'routing_id', 'status', 'completed_qty', 'created_by', 'released_at', 'completed_at', 'closed_at', 'remark'];

    protected function casts(): array
    {
        return [
            'status' => 'integer',
            'quantity' => 'decimal:2',
            'completed_qty' => 'decimal:2',
            'released_at' => 'datetime',
            'completed_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Product, $this> */
    // 成品商品
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<BomHeader, $this> */
    // BOM 版本（快照来源，下达后停用不影响）
    public function bom(): BelongsTo
    {
        return $this->belongsTo(BomHeader::class, 'bom_id');
    }

    /** @return BelongsTo<RoutingHeader, $this> */
    // 工艺路线快照（null=旧逻辑 BOM 展开，存量单不回写）
    public function routing(): BelongsTo
    {
        return $this->belongsTo(RoutingHeader::class, 'routing_id');
    }

    /** @return HasMany<ProductionOrderMaterial, $this> */
    // 物料需求快照（BOM 展开结果，随单级联删除）
    public function materials(): HasMany
    {
        return $this->hasMany(ProductionOrderMaterial::class, 'order_id');
    }

    /** @return HasMany<WorkOrderOperation, $this> */
    // 工单工序序列（随单级联删除）
    public function operations(): HasMany
    {
        return $this->hasMany(WorkOrderOperation::class, 'order_id');
    }
}
