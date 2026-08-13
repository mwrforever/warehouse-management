<?php

// 报表聚合服务：4 类只读实时聚合（不落快照、零迁移），口径与业务模块事实一致
// 日期分组一律 PHP 侧完成（phpunit/E2E 跑 SQLite、生产跑 MySQL，禁用数据库方言日期函数）；
// 数量/金额一律 bcmath 字符串运算，比率 4 位中间精度输出 2 位小数字符串

namespace App\Services;

use App\Models\InventoryBalance;
use App\Models\InventoryMovement;
use App\Models\OperationReport;
use App\Models\PickListItem;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\PurchaseInbound;
use App\Models\PurchaseInboundItem;
use App\Models\ReturnListItem;
use App\Models\SalesOutbound;
use Illuminate\Support\Carbon;

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
        $priceQuery = PurchaseInboundItem::query()
            ->select('product_id', 'price')
            ->orderByDesc('created_at')
            ->orderByDesc('id');
        foreach ($priceQuery->cursor() as $item) {
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

    /**
     * 生产统计聚合：计划日期窗口内工单的达成率/良率/工时/物料耗用
     *
     * 达成率=completed_qty/quantity×100；良率=合格/(合格+不良)×100（无不良=100）；
     * 合格/不良/工时=operation_reports 按工单求和；物料耗用=Σ已审核领料-Σ已审核退料
     * （按物料分组；审核写流水的行数量与行明细一致，与 E2E 流水核对口径等价）。
     * 计划日期 [date_from, date_to] 闭区间；product_id 可空筛选成品。
     * 返回 totals=全区间工单合计（order_count/计划/完工/合格/不良，KPI 口径；先于 items 截断计算）。
     *
     * @param  string  $dateFrom  计划日期起始 Y-m-d
     * @param  string  $dateTo  计划日期结束 Y-m-d
     * @param  int|null  $productId  成品筛选（可空=全部）
     */
    public function production(string $dateFrom, string $dateTo, ?int $productId = null): array
    {
        $query = ProductionOrder::query()
            ->join('products', 'products.id', '=', 'production_orders.product_id')
            ->whereDate('production_orders.plan_date', '>=', $dateFrom)
            ->whereDate('production_orders.plan_date', '<=', $dateTo)
            ->select('production_orders.*', 'products.name as product_name', 'products.code as product_code')
            ->orderBy('production_orders.plan_date')
            ->orderBy('production_orders.id');
        if ($productId !== null) {
            $query->where('production_orders.product_id', $productId);
        }
        $orders = $query->get();
        $orderIds = $orders->pluck('id')->all();

        // 报工聚合：按工单求和（合格/不良/工时）
        $reports = collect();
        $materials = ['picks' => collect(), 'returns' => collect()];
        if ($orderIds !== []) {
            $reports = OperationReport::query()
                ->whereIn('order_id', $orderIds)
                ->selectRaw('order_id, SUM(qualified_qty) as q, SUM(defective_qty) as d, SUM(hours) as h')
                ->groupBy('order_id')
                ->get()
                ->keyBy('order_id');
            // 已审核领料明细（耗用加项）
            $materials['picks'] = PickListItem::query()
                ->join('pick_lists', 'pick_lists.id', '=', 'pick_list_items.pick_id')
                ->where('pick_lists.status', 1)
                ->whereIn('pick_lists.order_id', $orderIds)
                ->selectRaw('pick_lists.order_id, pick_list_items.product_id, SUM(pick_list_items.pick_qty) as qty')
                ->groupBy('pick_lists.order_id', 'pick_list_items.product_id')
                ->get();
            // 已审核退料明细（耗用减项）
            $materials['returns'] = ReturnListItem::query()
                ->join('return_lists', 'return_lists.id', '=', 'return_list_items.return_id')
                ->where('return_lists.status', 1)
                ->whereIn('return_lists.order_id', $orderIds)
                ->selectRaw('return_lists.order_id, return_list_items.product_id, SUM(return_list_items.quantity) as qty')
                ->groupBy('return_lists.order_id', 'return_list_items.product_id')
                ->get();
        }
        // 物料名称/编码/单位（一次查询避免 N+1）
        $productIds = $materials['picks']->pluck('product_id')
            ->merge($materials['returns']->pluck('product_id'))
            ->unique()
            ->all();
        $products = Product::query()
            ->join('units', 'units.id', '=', 'products.unit_id')
            ->whereIn('products.id', $productIds)
            ->select('products.id', 'products.name', 'products.code', 'units.name as unit_name')
            ->get()
            ->keyBy('id');

        // 全区间 totals：对窗口内全部工单求和（KPI 依赖全量口径，不受 items 500 行截断影响——
        // 其他三接口 KPI 均用全区间 totals，production 此前缺失导致截断时 KPI 静默低估失真）
        $totals = ['order_count' => 0, 'total_plan' => '0', 'total_completed' => '0', 'total_qualified' => '0', 'total_defective' => '0'];
        foreach ($orders as $order) {
            $agg = $reports->get($order->id);
            $totals['order_count']++;
            $totals['total_plan'] = bcadd($totals['total_plan'], (string) $order->quantity, 2);
            $totals['total_completed'] = bcadd($totals['total_completed'], (string) $order->completed_qty, 2);
            $totals['total_qualified'] = bcadd($totals['total_qualified'], (string) ($agg?->getAttribute('q') ?? '0'), 2);
            $totals['total_defective'] = bcadd($totals['total_defective'], (string) ($agg?->getAttribute('d') ?? '0'), 2);
        }
        // 数量列与 items 归一约定一致：仅剥离 '.00' 尾零（'30'→'30'、'30.50'→'30.50'）
        $totals['total_plan'] = preg_replace('/\.00$/', '', $totals['total_plan']);
        $totals['total_completed'] = preg_replace('/\.00$/', '', $totals['total_completed']);
        $totals['total_qualified'] = preg_replace('/\.00$/', '', $totals['total_qualified']);
        $totals['total_defective'] = preg_replace('/\.00$/', '', $totals['total_defective']);

        $items = $orders->map(function ($order) use ($reports, $materials, $products) {
            $agg = $reports->get($order->id);
            // 聚合别名列经 getAttribute 读取（PHPStan 静态分析可识别，同 InventoryController 模式）
            // 求和结果跨库不一致（SQLite 返回 int、MySQL 返回 decimal 字符串）——统一 bcmath 归一：
            // 数量类仅剥离 '.00' 尾零（'10'→'10'、'10.50'→'10.50'，保留 2 位小数语义）、工时固定 2 位小数
            $qualified = preg_replace('/\.00$/', '', bcadd((string) ($agg?->getAttribute('q') ?? '0'), '0', 2));
            $defective = preg_replace('/\.00$/', '', bcadd((string) ($agg?->getAttribute('d') ?? '0'), '0', 2));
            $hours = bcadd((string) ($agg?->getAttribute('h') ?? '0'), '0', 2);
            // 达成率：完工/计划（4 位中间精度 → 2 位输出；计划 0 防御 0.00）
            $achievement = bccomp($order->quantity, '0', 2) === 0
                ? '0.00'
                : number_format((float) bcmul(bcdiv($order->completed_qty, $order->quantity, 4), '100', 2), 2, '.', '');
            // 良率：无不良（含无报工）→ 100.00
            $yield = bccomp($defective, '0', 2) === 0
                ? '100.00'
                : number_format((float) bcmul(bcdiv($qualified, bcadd($qualified, $defective, 2), 4), '100', 2), 2, '.', '');

            // 物料耗用：领料-退料（仅含任一方向 >0 的物料）
            $used = [];
            foreach ($materials['picks']->where('order_id', $order->id) as $pick) {
                $used[$pick->product_id] = ['pick' => $pick->getAttribute('qty'), 'return' => '0'];
            }
            foreach ($materials['returns']->where('order_id', $order->id) as $ret) {
                $used[$ret->product_id] = ['pick' => $used[$ret->product_id]['pick'] ?? '0', 'return' => $ret->getAttribute('qty')];
            }
            $materialUsed = [];
            foreach ($used as $productId => $sums) {
                $usedQty = bcsub($sums['pick'], $sums['return'], 2);
                if (bccomp($sums['pick'], '0', 2) === 1 || bccomp($sums['return'], '0', 2) === 1) {
                    $materialUsed[] = [
                        'material_id' => (int) $productId,
                        'material_name' => $products->get($productId)->name ?? '',
                        'material_code' => $products->get($productId)->code ?? '',
                        'used_qty' => $usedQty,
                        'unit' => $products->get($productId)?->getAttribute('unit_name') ?? '',
                    ];
                }
            }

            return [
                'order_id' => $order->id,
                'order_no' => $order->no,
                'product_name' => $order->getAttribute('product_name'),
                'product_code' => $order->getAttribute('product_code'),
                'quantity' => preg_replace('/\.00$/', '', bcadd((string) $order->quantity, '0', 2)),
                'completed_qty' => preg_replace('/\.00$/', '', bcadd((string) $order->completed_qty, '0', 2)),
                'achievement_rate' => $achievement,
                'qualified_qty' => $qualified,
                'defective_qty' => $defective,
                'yield_rate' => $yield,
                'total_hours' => $hours,
                'material_used' => $materialUsed,
            ];
        })->all();
        $truncated = count($items) > self::MAX_ROWS;

        return ['items' => $truncated ? array_slice($items, 0, self::MAX_ROWS) : $items, 'totals' => $totals, 'truncated' => $truncated];
    }

    /**
     * 采购销售汇总聚合：已审核单据金额/数量按审核时间分桶（日/月），金额分→元
     *
     * 采购口径=purchase_inbounds（status=1，inbound_at 闭区间，total_amount 合计）；
     * 销售口径=sales_outbounds（status=1，outbound_at 闭区间，total_amount 合计）；
     * 数量=已审核单据明细 quantity 合计。金额 bcdiv(,100,2) 输出元（2 位字符串）。
     *
     * @param  string  $dateFrom  起始日期 Y-m-d（含当天 00:00:00）
     * @param  string  $dateTo  结束日期 Y-m-d（含当天 23:59:59）
     * @param  string  $granularity  粒度：day|month
     */
    public function purchaseSales(string $dateFrom, string $dateTo, string $granularity): array
    {
        $groups = [];
        $totals = ['purchase_amount' => '0', 'sales_amount' => '0', 'purchase_qty' => '0', 'sales_qty' => '0'];

        // 采购侧：已审核入库单 + 明细（含 period 计算与数量合计）
        $inbounds = PurchaseInbound::query()
            ->with('items')
            ->where('status', 1)
            ->where('inbound_at', '>=', $dateFrom.' 00:00:00')
            ->where('inbound_at', '<=', $dateTo.' 23:59:59')
            ->get();
        foreach ($inbounds as $in) {
            $period = Carbon::parse($in->inbound_at)->format($granularity === 'month' ? 'Y-m' : 'Y-m-d');
            $groups[$period] ??= ['purchase_amount' => '0', 'sales_amount' => '0', 'purchase_qty' => '0', 'sales_qty' => '0'];
            $yuan = bcdiv($in->total_amount, '100', 2);
            $groups[$period]['purchase_amount'] = bcadd($groups[$period]['purchase_amount'], $yuan, 2);
            $totals['purchase_amount'] = bcadd($totals['purchase_amount'], $yuan, 2);
            $qty = '0';
            foreach ($in->items as $item) {
                $qty = bcadd($qty, $item->quantity, 2);
            }
            $groups[$period]['purchase_qty'] = bcadd($groups[$period]['purchase_qty'], $qty, 2);
            $totals['purchase_qty'] = bcadd($totals['purchase_qty'], $qty, 2);
        }

        // 销售侧：已审核出库单 + 明细（同结构）
        $outbounds = SalesOutbound::query()
            ->with('items')
            ->where('status', 1)
            ->where('outbound_at', '>=', $dateFrom.' 00:00:00')
            ->where('outbound_at', '<=', $dateTo.' 23:59:59')
            ->get();
        foreach ($outbounds as $out) {
            $period = Carbon::parse($out->outbound_at)->format($granularity === 'month' ? 'Y-m' : 'Y-m-d');
            $groups[$period] ??= ['purchase_amount' => '0', 'sales_amount' => '0', 'purchase_qty' => '0', 'sales_qty' => '0'];
            $yuan = bcdiv($out->total_amount, '100', 2);
            $groups[$period]['sales_amount'] = bcadd($groups[$period]['sales_amount'], $yuan, 2);
            $totals['sales_amount'] = bcadd($totals['sales_amount'], $yuan, 2);
            $qty = '0';
            foreach ($out->items as $item) {
                $qty = bcadd($qty, $item->quantity, 2);
            }
            $groups[$period]['sales_qty'] = bcadd($groups[$period]['sales_qty'], $qty, 2);
            $totals['sales_qty'] = bcadd($totals['sales_qty'], $qty, 2);
        }

        ksort($groups);
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
