<?php

// 生产工序模型：sort 决定生产模块工序序列

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * 生产工序模型
 *
 * sort 决定生产模块工序序列（下拉按 sort 升序），
 * 被生产工单引用时删除受限（见 DeletionGuard）。
 *
 * @property int $id
 * @property string $name
 * @property string $code
 * @property int $sort
 * @property string|null $description
 * @property int $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Process extends Model
{
    protected $fillable = ['name', 'code', 'sort', 'description', 'status'];

    protected function casts(): array
    {
        return ['sort' => 'integer', 'status' => 'integer'];
    }
}
