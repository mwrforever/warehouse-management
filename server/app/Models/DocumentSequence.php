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
    /** 单据号段类型：盘点单 check（库存模块）/ BOM（迁移）/ 采购订单 po / 采购入库单 pi / 销售订单 so / 销售出库单 sout / 生产工单 mo / 生产领料单 pl / 生产退料单 rl / 委外加工单 os / 委外回收单 osr / 委外退料单 osrt / 成品入库单 fi / 工艺路线 rtg */
    public const TYPE_CHECK = 'check';

    public const TYPE_BOM = 'bom';

    public const TYPE_PO = 'po';

    public const TYPE_PI = 'pi';

    public const TYPE_SO = 'so';

    public const TYPE_SOUT = 'sout';

    public const TYPE_MO = 'mo';

    public const TYPE_PL = 'pl';

    public const TYPE_RL = 'rl';

    public const TYPE_OS = 'os';

    public const TYPE_OSR = 'osr';

    public const TYPE_OSRT = 'osrt';

    public const TYPE_FI = 'fi';

    public const TYPE_RTG = 'rtg';

    /** 商品编码序列（全局自增、无日期段，条码默认=编码） */
    public const TYPE_PRD = 'prd';

    protected $fillable = ['type', 'date', 'seq'];

    protected function casts(): array
    {
        return [
            'seq' => 'integer',
        ];
    }
}
