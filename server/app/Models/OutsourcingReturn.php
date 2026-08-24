<?php

// 委外余料退回单模型：创建即审核（恒 STATUS_APPROVED）；item_id 列可空仅历史兼容——
// V1 未开放按物料整体退回（载荷 item_id 必填）；
// 入账经 InventoryService 写 outsourcing_return 流水；退回量累计与组件 issued_qty 比对防超退

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * 委外余料退回单
 *
 * @property int $id
 * @property string $no
 * @property int $outsourcing_id
 * @property int|null $item_id
 * @property int $material_id
 * @property string $quantity
 * @property int $warehouse_id
 * @property int $location_id
 * @property int $status
 * @property Carbon $returned_at
 * @property string|null $operator
 * @property string|null $remark
 */
class OutsourcingReturn extends Model
{
    public const STATUS_APPROVED = 1; // 创建即审核，恒为已审核

    protected $fillable = ['no', 'outsourcing_id', 'item_id', 'material_id', 'quantity', 'warehouse_id', 'location_id', 'status', 'returned_at', 'operator', 'remark'];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'status' => 'integer',
            'returned_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<OutsourcingOrder, $this> */
    // 所属委外单
    public function outsourcing(): BelongsTo
    {
        return $this->belongsTo(OutsourcingOrder::class, 'outsourcing_id');
    }

    /** @return BelongsTo<OutsourcingOrderItem, $this> */
    // 退回组件行（V1 未开放按物料整体退回——载荷 item_id 必填；列可空仅历史兼容）
    public function item(): BelongsTo
    {
        return $this->belongsTo(OutsourcingOrderItem::class, 'item_id');
    }

    /** @return BelongsTo<Product, $this> */
    // 退回物料（原料/半成品；被引用不可删）
    public function material(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'material_id');
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
}
