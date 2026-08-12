<?php
// 商品模型：原料/半成品/成品，安全库存上下限为库存预警数据源
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Product extends Model
{
    protected $fillable = ['name', 'code', 'type', 'category_id', 'unit_id', 'spec', 'barcode', 'safety_min', 'safety_max', 'status', 'remark'];

    protected function casts(): array
    {
        return [
            'safety_min' => 'decimal:2',
            'safety_max' => 'decimal:2',
            'status' => 'integer',
        ];
    }

    // 所属分类
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    // 计量单位
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }
}
