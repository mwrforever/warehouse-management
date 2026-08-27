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
 * @property string|null $province
 * @property string|null $city
 * @property string|null $district
 * @property string|null $town
 * @property string|null $address
 * @property string|null $remark
 * @property int $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Supplier extends Model
{
    /** 供应商状态：0停用 1启用 */
    public const STATUS_DISABLED = 0;

    public const STATUS_ENABLED = 1;

    /** $fillable：name/code 必填，其余可空；province-city-district-town 为四级地址（存区划名称） */
    protected $fillable = ['name', 'code', 'contact', 'phone', 'province', 'city', 'district', 'town', 'address', 'remark', 'status'];

    protected function casts(): array
    {
        return ['status' => 'integer'];
    }
}
