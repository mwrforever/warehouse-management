<?php

// 字典模型：字典头（字典名称/编码/备注），一对多关联字典项

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * 数据字典头模型
 *
 * 承载一类枚举数据的字典定义（如计量单位、仓库类型），
 * code 全局唯一（数据库 unique 约束兜底），删除时级联删除全部字典项。
 * 依赖 DictionaryItem 模型（hasMany）；被 DictionaryController 与字典项接口使用。
 *
 * @property int $id
 * @property string $name
 * @property string $code
 * @property string|null $remark
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Dictionary extends Model
{
    protected $fillable = ['name', 'code', 'remark'];

    // 字典下挂的字典项（一对多，删除字典时级联删除）
    /** @return HasMany<DictionaryItem, *> */
    public function items(): HasMany
    {
        return $this->hasMany(DictionaryItem::class);
    }
}
