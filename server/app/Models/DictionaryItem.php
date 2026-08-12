<?php
// 字典项模型：字典下的具体枚举值（显示名/值/排序/状态）
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 数据字典项模型
 *
 * 表示字典下的一个枚举选项，label 为展示文案、value 为接口传输值，
 * status 控制启停（1启用 0停用），下拉取值接口仅返回启用项。
 * 依赖 Dictionary 模型（belongsTo，删除字典时随外键级联删除）。
 */
class DictionaryItem extends Model
{
    protected $fillable = ['dictionary_id', 'label', 'value', 'sort', 'status'];

    // 所属字典（一对一反向，外键 dictionary_id）
    public function dictionary(): BelongsTo
    {
        return $this->belongsTo(Dictionary::class);
    }
}
