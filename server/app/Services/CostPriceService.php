<?php

// 成本价估算服务：全项目唯一口径「每商品最近一次已审核采购入库单价」（商品内 created_at/id 最新一条生效）
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
    /** 缓存键：成本价 map [product_id => 单价（分单位整数）] */
    public const CACHE_KEY = 'cost_price_map';

    /**
     * 全量成本价 map（含缓存）
     *
     * 首次访问构建全量 map（全部存在已审核采购入库记录的商品），此后读缓存；
     * 调用方按自身商品集直接 isset 判定（map 为超集，无需再过滤）。
     * CACHE_STORE=database 下读缓存表 1 次远优于历史明细扫描+filesort；失效路径唯一故可长期缓存。
     *
     * @return array<int, int> [product_id => 单价（分单位整数，R2 后 price 列 bigint、模型 integer cast，跨库均为 int）]
     */
    public function latestPriceMap(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, fn (): array => $this->build());
    }

    /**
     * 重建全量成本价 map（缓存未命中或审核失效后重建时执行）
     *
     * ORDER BY (product_id, created_at, id) 与复合索引（迁移 2026_08_15_160000）全序一致，
     * 优化器可经索引序扫描免 filesort、按序流式输出；每商品末条即最新价（升序下无条件覆盖）。
     * whereHas 过滤已审核入库单——草稿入库单 store 即写明细且审核不改 created_at，
     * 草稿价参与会导致金额跳变（bug #7 口径，与缓存化之前的旧实现一致）。
     */
    private function build(): array
    {
        $prices = [];
        foreach (
            PurchaseInboundItem::query()
                ->whereHas('purchaseInbound', fn ($q) => $q->where('status', PurchaseInbound::STATUS_APPROVED))
                ->select('product_id', 'price')
                ->orderBy('product_id')
                ->orderBy('created_at')
                ->orderBy('id')
                ->cursor() as $item
        ) {
            // 升序末条生效（无条件覆盖：末条即该商品最新价，与旧 DESC 首条语义等价）；
            // price 列为 bigint 分整数（模型 integer cast），跨库形态统一为 int
            $prices[(int) $item->product_id] = (int) $item->price;
        }

        return $prices;
    }

    /** 失效缓存：仅采购入库单审核成功路径调用（失效契约见类注释） */
    public function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
