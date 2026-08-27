<?php

// 商品模型：原料/半成品/成品，安全库存上下限为库存预警数据源

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * 商品模型
 *
 * 原料/半成品/成品三种类型（type 枚举），安全库存上下限为库存预警数据源；
 * 依赖 Category（belongsTo）与 Unit（belongsTo），被 BOM/业务单据引用时删除受限。
 *
 * @property int $id
 * @property string $name
 * @property string $code
 * @property string $type
 * @property int $category_id
 * @property int $unit_id
 * @property string|null $spec
 * @property string|null $barcode
 * @property string $safety_min
 * @property string $safety_max
 * @property int $status
 * @property string|null $remark
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Product extends Model
{
    /** 商品状态：0停用 1启用 */
    public const STATUS_DISABLED = 0;

    public const STATUS_ENABLED = 1;

    protected $fillable = [
        'name',
        'code',
        'type',
        'category_id',
        'unit_id',
        'spec',
        'barcode',
        'safety_min',
        'safety_max',
        'status',
        'remark',
    ];

    protected function casts(): array
    {
        return [
            'safety_min' => 'decimal:2',
            'safety_max' => 'decimal:2',
            'status' => 'integer',
        ];
    }

    // 所属分类
    /** @return BelongsTo<Category, *> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    // 计量单位
    /** @return BelongsTo<Unit, *> */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }
}
