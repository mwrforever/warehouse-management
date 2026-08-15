<?php

// 成本价估算服务：全项目唯一口径「每商品最近一次已审核采购入库单价」（created_at DESC, id DESC 首条生效）
// 仪表盘 KPI（summary）与库存报表（inventorySummary）共用，替代两处各自历史明细全量扫描。
//
// 缓存失效契约唯一且完备：该价格集合只在采购入库单「审核」（status 0→1，控制器 approve）时变化——
// 明细行在 store 时写入（草稿价被 whereHas 排除）、草稿修改/删除不影响集合、审核后单据不可改删，
// 故 approve 成功路径 flush 一次即可；事务回滚最多导致缓存早清（下次访问重建），无脏读风险。

namespace App\Services;

use App\Models\PurchaseInbound;
use App\Models\PurchaseInboundItem;
use Illuminate\Support\Facades\Cache;

class CostPriceService
{
    /** 缓存键：成本价 map [product_id => 单价（分，2 位小数字符串）] */
    public const CACHE_KEY = 'cost_price_map';

    /**
     * 全量成本价 map（含缓存）
     *
     * 首次访问构建全量 map（全部存在已审核采购入库记录的商品），此后读缓存；
     * 调用方按自身商品集直接 isset 判定（map 为超集，无需再过滤）。
     * CACHE_STORE=database 下读缓存表 1 次远优于历史明细扫描+filesort；失效路径唯一故可长期缓存。
     *
     * @return array<int, string> [product_id => 单价（分，2 位小数字符串，跨库形态经 bcmath 归一）]
     */
    public function latestPriceMap(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, fn (): array => $this->build());
    }

    /**
     * 重建全量成本价 map（缓存未命中时执行）
     *
     * 复合索引 (product_id, created_at, id)（迁移 2026_08_15_160000）下按索引序流式输出免 filesort，
     * 每商品首条即最新价；whereHas 过滤已审核入库单——草稿入库单 store 即写明细且审核不改
     * created_at，草稿价参与会导致金额跳变（bug #7 口径，与缓存化之前的旧实现一致）。
     */
    private function build(): array
    {
        $prices = [];
        foreach (
            PurchaseInboundItem::query()
                ->whereHas('purchaseInbound', fn ($q) => $q->where('status', PurchaseInbound::STATUS_APPROVED))
                ->select('product_id', 'price')
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->cursor() as $item
        ) {
            // DESC 序首条生效（?? 保留已写入的最新价）；bcadd 归一跨库形态（SQLite int / MySQL decimal 字符串）
            $prices[(int) $item->product_id] = $prices[(int) $item->product_id] ?? bcadd((string) $item->price, '0', 2);
        }

        return $prices;
    }

    /** 失效缓存：仅采购入库单审核成功路径调用（失效契约见类注释） */
    public function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
