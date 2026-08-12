<?php

// 库存流水模型：一切库存变动的唯一事实来源，只增不改不删（审计要求）

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryMovement extends Model
{
    /** 来源类型枚举：采购/销售/生产模块审核单据时复用 */
    public const SOURCE_TYPES = ['purchase_inbound', 'sales_outbound', 'pick', 'return', 'finished_inbound', 'outsourcing_out', 'outsourcing_in', 'check_in', 'check_out'];

    /** 来源类型中文标签（流水列表展示） */
    public const SOURCE_TYPE_LABELS = [
        'purchase_inbound' => '采购入库',
        'sales_outbound' => '销售出库',
        'pick' => '领料出库',
        'return' => '退料入库',
        'finished_inbound' => '成品入库',
        'outsourcing_out' => '委外发出',
        'outsourcing_in' => '委外回收',
        'check_in' => '盘盈',
        'check_out' => '盘亏',
    ];

    // created_at 允许指定（历史流水补录/审计回填场景，未传则走自动时间戳）
    protected $fillable = ['product_id', 'warehouse_id', 'location_id', 'direction', 'quantity', 'balance_after', 'source_type', 'source_id', 'source_no', 'remark', 'operator_id', 'created_at'];

    protected function casts(): array
    {
        return [
            'direction' => 'integer',
            'quantity' => 'decimal:2',
            'balance_after' => 'decimal:2',
        ];
    }

    // 流水商品
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    // 操作人
    public function operator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'operator_id');
    }
}
