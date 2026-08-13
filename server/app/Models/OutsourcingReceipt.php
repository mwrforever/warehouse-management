<?php

// 委外回收单模型：创建即审核（恒已审核）；回收量累计与委外量比对驱动委外单状态

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 委外回收单
 *
 * @property int $id
 * @property string $no
 * @property int $outsourcing_id
 * @property string $quantity
 * @property int $warehouse_id
 * @property int $location_id
 * @property int $status
 * @property string $received_at
 * @property string|null $operator
 * @property string|null $remark
 */
class OutsourcingReceipt extends Model
{
    public const STATUS_APPROVED = 1; // 创建即审核，恒为已审核

    protected $fillable = ['no', 'outsourcing_id', 'quantity', 'warehouse_id', 'location_id', 'status', 'received_at', 'operator', 'remark'];

    protected function casts(): array
    {
        return [
            'status' => 'integer',
            'quantity' => 'decimal:2',
            'received_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<OutsourcingOrder, $this> */
    // 所属委外单
    public function outsourcing(): BelongsTo
    {
        return $this->belongsTo(OutsourcingOrder::class, 'outsourcing_id');
    }
}
