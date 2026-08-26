<?php

// 编号规则配置模型：决定各类单据号/商品编码的 prefix + date_format + seq_length（Spec 2）

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * 编号规则配置
 *
 * @property int $id
 * @property string $type
 * @property string $prefix
 * @property string $date_format
 * @property int $seq_length
 * @property bool $is_enabled
 * @property string|null $remark
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class DocumentNumberConfig extends Model
{
    protected $table = 'document_number_configs';

    protected $fillable = ['type', 'prefix', 'date_format', 'seq_length', 'is_enabled', 'remark'];

    protected function casts(): array
    {
        return [
            'seq_length' => 'integer',
            'is_enabled' => 'boolean',
        ];
    }
}
