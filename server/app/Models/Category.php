<?php
// 商品分类模型：两级树形（parent_id 自关联，0=顶级）
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    protected $fillable = ['name', 'parent_id', 'sort', 'status'];

    protected function casts(): array
    {
        return ['parent_id' => 'integer', 'sort' => 'integer', 'status' => 'integer'];
    }

    // 上级分类（顶级分类的 parent 为 null）
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    // 直接子分类
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }
}
