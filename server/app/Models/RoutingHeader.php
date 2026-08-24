<?php

// 工艺路线头：成品级可版本化容器，DAG 网络存于 nodes/edges；启用版本同成品唯一（应用层保证）

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * 工艺路线头
 *
 * @property int $id
 * @property string $code
 * @property int $product_id
 * @property string $version
 * @property string $quantity
 * @property int $status
 * @property string|null $remark
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class RoutingHeader extends Model
{
    /** 状态：0停用 1启用 */
    public const STATUS_DISABLED = 0;

    public const STATUS_ENABLED = 1;

    public const STATUS_LABELS = [
        self::STATUS_DISABLED => '停用',
        self::STATUS_ENABLED => '启用',
    ];

    protected $fillable = ['code', 'product_id', 'version', 'quantity', 'status', 'remark'];

    protected function casts(): array
    {
        return ['status' => 'int', 'quantity' => 'decimal:2'];
    }

    /** @return BelongsTo<Product, *> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return HasMany<RoutingNode, *> */
    public function nodes(): HasMany
    {
        return $this->hasMany(RoutingNode::class, 'routing_id');
    }

    /** @return HasMany<RoutingEdge, *> */
    public function edges(): HasMany
    {
        return $this->hasMany(RoutingEdge::class, 'routing_id');
    }
}
