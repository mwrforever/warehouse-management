<?php

// 仓库模型：库位挂载点，库存模块余额按仓库聚合

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * 仓库模型
 *
 * 库位挂载点（code 全局唯一，由 DocumentSequenceService 自动生成），库存模块余额按仓库聚合；
 * 依赖 Location（hasMany），存在库存余额时删除受限。
 *
 * @property int $id
 * @property string $name
 * @property string $code
 * @property string|null $address
 * @property string|null $province
 * @property string|null $city
 * @property string|null $district
 * @property string|null $town
 * @property string|null $manager
 * @property int $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Warehouse extends Model
{
    /** 仓库状态：0停用 1启用 */
    public const STATUS_DISABLED = 0;

    public const STATUS_ENABLED = 1;

    protected $fillable = ['name', 'code', 'address', 'province', 'city', 'district', 'town', 'manager', 'status'];

    protected function casts(): array
    {
        return ['status' => 'integer'];
    }

    // 仓库下库位
    /** @return HasMany<Location, *> */
    public function locations(): HasMany
    {
        return $this->hasMany(Location::class);
    }
}
