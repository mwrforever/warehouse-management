<?php

// 库存盘点单模型：草稿→已审核 两级状态；审核后生成盘盈/盘亏流水

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * 库存盘点单
 *
 * @property int $id
 * @property string $no
 * @property int $warehouse_id
 * @property int $status
 * @property string|null $checker
 * @property Carbon|null $check_time
 * @property string|null $remark
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class InventoryCheck extends Model
{
    public const STATUS_DRAFT = 0;

    public const STATUS_APPROVED = 1;

    protected $fillable = ['no', 'warehouse_id', 'status', 'checker', 'check_time', 'remark'];

    protected function casts(): array
    {
        return [
            'status' => 'integer',
            'check_time' => 'datetime',
        ];
    }

    // 盘点仓库
    /** @return BelongsTo<Warehouse, *> */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    // 明细行（随单级联删除）
    /** @return HasMany<InventoryCheckItem, *> */
    public function items(): HasMany
    {
        return $this->hasMany(InventoryCheckItem::class, 'check_id');
    }
}
