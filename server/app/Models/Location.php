<?php

// 库位模型：挂载在仓库下，编码全局唯一

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * 库位模型
 *
 * 挂载在仓库下（warehouse_id 外键），编码全局唯一；
 * 依赖 Warehouse 模型（belongsTo）。
 *
 * @property int $id
 * @property int $warehouse_id
 * @property string $name
 * @property string $code
 * @property int $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Location extends Model
{
    /** 库位状态：0停用 1启用 */
    public const STATUS_DISABLED = 0;

    public const STATUS_ENABLED = 1;

    protected $fillable = ['warehouse_id', 'name', 'code', 'status'];

    protected function casts(): array
    {
        return ['status' => 'integer'];
    }

    /** @return BelongsTo<Warehouse, *> */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }
}
