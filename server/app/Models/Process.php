<?php

// 生产工序模型：sort 决定生产模块工序序列

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * 生产工序模型
 *
 * sort 决定生产模块工序序列（下拉按 sort 升序），
 * 被生产工单引用时删除受限（见 DeletionGuard）。
 * 编码由 DocumentSequenceService 自动生成（PROC 前缀全局自增，录入时不再手填）。
 *
 * @property int $id
 * @property string $name
 * @property string $code
 * @property int|null $category_id
 * @property int $sort
 * @property string|null $description
 * @property int $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Process extends Model
{
    /** 工序状态：0停用 1启用（仅启用工序进入生产工单工序序列） */
    public const STATUS_DISABLED = 0;

    public const STATUS_ENABLED = 1;

    protected $fillable = ['name', 'code', 'category_id', 'sort', 'description', 'status'];

    // 标签分类（字典项，可空；字典项删除后置空）
    /** @return BelongsTo<DictionaryItem, *> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(DictionaryItem::class, 'category_id');
    }

    protected function casts(): array
    {
        return ['sort' => 'integer', 'status' => 'integer'];
    }
}
