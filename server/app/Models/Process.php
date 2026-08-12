<?php
// 生产工序模型：sort 决定生产模块工序序列
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Process extends Model
{
    protected $fillable = ['name', 'code', 'sort', 'description', 'status'];

    protected function casts(): array
    {
        return ['sort' => 'integer', 'status' => 'integer'];
    }
}
