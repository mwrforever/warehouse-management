<?php

// 库存盘点单模型：草稿→已审核 两级状态；审核后生成盘盈/盘亏流水

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    // 明细行（随单级联删除）
    public function items(): HasMany
    {
        return $this->hasMany(InventoryCheckItem::class, 'check_id');
    }
}
