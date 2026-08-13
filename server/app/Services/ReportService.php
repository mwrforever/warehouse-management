<?php

// 报表聚合服务：4 类只读实时聚合（不落快照、零迁移），口径与业务模块事实一致
// 日期分组一律 PHP 侧完成（phpunit/E2E 跑 SQLite、生产跑 MySQL，禁用数据库方言日期函数）；
// 数量/金额一律 bcmath 字符串运算，比率 4 位中间精度输出 2 位小数字符串

namespace App\Services;

use App\Models\InventoryBalance;
use App\Models\InventoryMovement;
use App\Models\PurchaseInboundItem;

class ReportService
{
    /** 聚合行数上限：超出截断前 MAX_ROWS 行并置 truncated 标记（spec §7） */
    public const MAX_ROWS = 500;

    /** 商品类型中文标签（按类型分组维度展示用） */
    private const TYPE_LABELS = ['raw_material' => '原料', 'semi_finished' => '半成品', 'finished' => '成品'];

    /**
     * 库存报表聚合：按维度汇总当前余额（group_by=category/warehouse/type）
     *
     * 数量=余额行求和、商品种类=商品去重计数；金额=Σ(余额×最近一次采购入库单价) 估算转元，
     * 无采购记录的商品仅计数量不计金额（全组无成本价时 amount_total=null）。
     * date_to 参数 V1 仅预留（余额表无历史快照）——TODO(report-snapshot): 历史余额快照语义
     * 计划于仪表盘版本引入，届时按 updated_at<=date_to 过滤或引入余额快照表。
     *
     * @param  string  $groupBy  分组维度：category|warehouse|type（合法性由控制器校验）
     * @param  string|null  $dateTo  截至日期（V1 不参与过滤，仅保留参数契约）
     */
    public function inventorySummary(string $groupBy, ?string $dateTo = null): array
    {
        $rows = InventoryBalance::query()
            ->join('products', 'products.id', '=', 'inventory_balances.product_id')
            ->join('categories', 'categories.id', '=', 'products.category_id')
            ->join('warehouses', 'warehouses.id', '=', 'inventory_balances.warehouse_id')
            ->select(
                'inventory_balances.product_id',
                'inventory_balances.quantity',
                'products.type',
                'categories.name as category_name',
                'warehouses.name as warehouse_name'
            )
            ->get();

        // 成本价估算：每商品取最近一次采购入库单价（created_at DESC, id DESC 首条生效；无记录则不参与金额）
        $prices = [];
        foreach (PurchaseInboundItem::query()
            ->select('product_id', 'price')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->cursor() as $item) {
            $prices[$item->product_id] = $prices[$item->product_id] ?? $item->price;
        }

        $groups = [];
        $totalQty = '0';
        $totalAmount = '0';
        $totalAmountKnown = false;
        $totalProducts = [];
        foreach ($rows as $row) {
            // joined 列经 getAttribute 读取（larastan 将查询结果按 InventoryBalance 模型定型，属性访问报 undefined）
            $rowType = $row->getAttribute('type');
            $name = match ($groupBy) {
                'warehouse' => $row->getAttribute('warehouse_name'),
                'type' => self::TYPE_LABELS[$rowType] ?? $rowType,
                default => $row->getAttribute('category_name'),
            };
            $groups[$name]['quantity_total'] = bcadd($groups[$name]['quantity_total'] ?? '0', (string) $row->quantity, 2);
            $groups[$name]['product_count'][$row->product_id] = true;
            if (isset($prices[$row->product_id])) {
                // 行金额 = 余额 × 单价（分）→ 元（2 位）；bcmath 全程无浮点（decimal cast 静态定型为 float，显式转字符串）
                $lineYuan = bcdiv(bcmul((string) $row->quantity, (string) $prices[$row->product_id], 2), '100', 2);
                $groups[$name]['amount_total'] = bcadd($groups[$name]['amount_total'] ?? '0', $lineYuan, 2);
                $totalAmount = bcadd($totalAmount, $lineYuan, 2);
                $totalAmountKnown = true;
            }
            $totalQty = bcadd($totalQty, (string) $row->quantity, 2);
            $totalProducts[$row->product_id] = true;
        }

        $items = collect($groups)
            ->map(fn ($g, $name) => [
                'group_name' => $name,
                'quantity_total' => $g['quantity_total'],
                'product_count' => count($g['product_count']),
                'amount_total' => $g['amount_total'] ?? null,
            ])
            // 按组名升序（数量为字符串，禁止字典序排数值——排序仅保证输出确定性）
            ->sortBy('group_name')
            ->values()
            ->all();

        $truncated = count($items) > self::MAX_ROWS;

        return [
            'items' => $truncated ? array_slice($items, 0, self::MAX_ROWS) : $items,
            'total' => [
                'quantity_total' => $totalQty,
                'product_count' => count($totalProducts),
                'amount_total' => $totalAmountKnown ? $totalAmount : null,
            ],
            'truncated' => $truncated,
        ];
    }

    /**
     * 出入库汇总聚合：按日/月粒度汇总流水方向与条数（闭区间）
     *
     * inbound=方向 1、outbound=方向 -1；count=流水条数；不补零——items 仅含有流水的周期、
     * period 升序，空区间 items=[]（E2E TC-RPT-05 空态契约）。source_type 可空筛选。
     *
     * @param  string  $dateFrom  起始日期 Y-m-d（含当天 00:00:00，合法性由控制器校验）
     * @param  string  $dateTo  结束日期 Y-m-d（含当天 23:59:59）
     * @param  string  $granularity  粒度：day|month
     * @param  string|null  $sourceType  流水来源类型筛选（可空=全部）
     */
    public function movementsSummary(string $dateFrom, string $dateTo, string $granularity, ?string $sourceType = null): array
    {
        $query = InventoryMovement::query()
            // 闭区间：起始日 00:00:00 至结束日 23:59:59（字符串比较，跨月边界正确）
            ->where('created_at', '>=', $dateFrom.' 00:00:00')
            ->where('created_at', '<=', $dateTo.' 23:59:59')
            ->select('direction', 'quantity', 'created_at');
        if ($sourceType !== null) {
            $query->where('source_type', $sourceType);
        }

        $groups = [];
        $totals = ['inbound_qty' => '0', 'outbound_qty' => '0', 'inbound_count' => 0, 'outbound_count' => 0];
        foreach ($query->cursor() as $row) {
            // PHP 侧按日/月分组（Carbon format，SQLite/MySQL 方言兼容）
            $period = $row->created_at->format($granularity === 'month' ? 'Y-m' : 'Y-m-d');
            $groups[$period] ??= ['inbound_qty' => '0', 'outbound_qty' => '0', 'inbound_count' => 0, 'outbound_count' => 0];
            if ((int) $row->direction === 1) {
                $groups[$period]['inbound_qty'] = bcadd($groups[$period]['inbound_qty'], (string) $row->quantity, 2);
                $groups[$period]['inbound_count']++;
                $totals['inbound_qty'] = bcadd($totals['inbound_qty'], (string) $row->quantity, 2);
                $totals['inbound_count']++;
            } else {
                $groups[$period]['outbound_qty'] = bcadd($groups[$period]['outbound_qty'], (string) $row->quantity, 2);
                $groups[$period]['outbound_count']++;
                $totals['outbound_qty'] = bcadd($totals['outbound_qty'], (string) $row->quantity, 2);
                $totals['outbound_count']++;
            }
        }

        ksort($groups); // period 字符串升序（Y-m-d / Y-m 字典序=时间序）
        $items = collect($groups)
            ->map(fn ($g, $period) => ['period' => $period] + $g)
            ->values()
            ->all();
        $truncated = count($items) > self::MAX_ROWS;

        return [
            'items' => $truncated ? array_slice($items, 0, self::MAX_ROWS) : $items,
            'totals' => $totals,
            'truncated' => $truncated,
        ];
    }
}
