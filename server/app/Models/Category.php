<?php

// 商品分类模型：两级树形（parent_id 自关联，0=顶级）

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * 商品分类模型
 *
 * 两级树形（parent_id 自关联，0=顶级），
 * 依赖自身（parent/children 自关联）；被商品引用时删除受限。
 *
 * @property int $id
 * @property string $name
 * @property int $parent_id
 * @property int $sort
 * @property int $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Category extends Model
{
    /** 分类状态：0停用 1启用 */
    public const STATUS_DISABLED = 0;

    public const STATUS_ENABLED = 1;

    protected $fillable = ['name', 'parent_id', 'sort', 'status'];

    protected function casts(): array
    {
        return ['parent_id' => 'integer', 'sort' => 'integer', 'status' => 'integer'];
    }

    // 上级分类（顶级分类的 parent 为 null）
    /** @return BelongsTo<Category, *> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    // 直接子分类
    /** @return HasMany<Category, *> */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }
}
