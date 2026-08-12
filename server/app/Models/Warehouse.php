<?php
// 仓库模型：库位挂载点，库存模块余额按仓库聚合
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Warehouse extends Model
{
    protected $fillable = ['name', 'code', 'address', 'manager', 'status'];

    protected function casts(): array
    {
        return ['status' => 'integer'];
    }

    // 仓库下库位
    public function locations(): HasMany
    {
        return $this->hasMany(Location::class);
    }
}
