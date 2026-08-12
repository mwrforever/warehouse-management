<?php
// 库位模型：挂载在仓库下，编码全局唯一
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Location extends Model
{
    protected $fillable = ['warehouse_id', 'name', 'code', 'status'];

    protected function casts(): array
    {
        return ['status' => 'integer'];
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }
}
