<?php

// 客户模型：销售模块引用；被销售单据引用时不可删除

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * 客户模型
 *
 * 销售模块基础资料，被销售单据引用时不可删除（删除保护见 DeletionGuard）。
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
class Customer extends Model
{
    /** 客户状态：0停用 1启用 */
    public const STATUS_DISABLED = 0;

    public const STATUS_ENABLED = 1;

    protected $fillable = ['name', 'code', 'contact', 'phone', 'address', 'remark', 'status'];

    protected function casts(): array
    {
        return ['status' => 'integer'];
    }
}
