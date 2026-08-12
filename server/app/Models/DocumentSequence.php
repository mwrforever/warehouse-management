<?php

// 单据号段持久序列模型：type+date 唯一行，seq 原子自增取号
// 与存量单据行数解耦——删除单据不回退号段，杜绝"按计数生成单号 → 删除后复用已存在单号"的撞号缺陷

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * 单据号段持久序列
 *
 * @property int $id
 * @property string $type
 * @property string $date
 * @property int $seq
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class DocumentSequence extends Model
{
    /** 盘点单号段类型 */
    public const TYPE_CHECK = 'check';

    protected $fillable = ['type', 'date', 'seq'];

    protected function casts(): array
    {
        return [
            'seq' => 'integer',
        ];
    }
}
