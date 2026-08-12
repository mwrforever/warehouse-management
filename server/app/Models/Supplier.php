<?php

// 供应商模型：采购模块引用；被采购单据引用时不可删除

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * 供应商模型
 *
 * 采购模块基础资料，被采购单据引用时不可删除（删除保护见 DeletionGuard）。
 *
 * @property int $id
 * @property string $name
 * @property string $code
 * @property string|null $contact
 * @property string|null $phone
 * @property string|null $address
 * @property string|null $remark
 * @property int $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Supplier extends Model
{
    protected $fillable = ['name', 'code', 'contact', 'phone', 'address', 'remark', 'status'];

    protected function casts(): array
    {
        return ['status' => 'integer'];
    }
}
