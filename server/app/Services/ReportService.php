<?php

// 报表聚合服务：4 类只读实时聚合（不落快照、零迁移），口径与业务模块事实一致
// 日期分组一律 PHP 侧完成（phpunit/E2E 跑 SQLite、生产跑 MySQL，禁用数据库方言日期函数）；
// 数量 bcmath 字符串运算、金额分单位整数（R2 纯分口径），比率 4 位中间精度输出 2 位小数字符串

namespace App\Services;

use App\Models\InventoryBalance;
use App\Models\InventoryMovement;
use App\Models\OperationReport;
use App\Models\PickList;
use App\Models\PickListItem;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\PurchaseInbound;
use App\Models\PurchaseInboundItem;
use App\Models\ReturnList;
use App\Models\ReturnListItem;
use App\Models\SalesOutbound;
use App\Models\SalesOutboundItem;
use App\Support\Cents;
use Illuminate\Support\Carbon;

class ReportService
{
    /** 聚合行数上限：超出截断前 MAX_ROWS 行并置 truncated 标记（spec §7） */
    public const MAX_ROWS = 500;

    /** 商品类型中文标签（按类型分组维度展示用） */
    private const TYPE_LABELS = ['raw_material' => '原料', 'semi_finished' => '半成品', 'finished' => '成品'];

    // 成本价 map 走共享服务（含缓存，采购入库审核时失效）——与仪表盘 KPI 共用一份缓存
    public function __construct(private readonly CostPriceService $costPriceService) {}

    /**
     * 库存报表聚合：按维度汇总当前余额（group_by=category/warehouse/type）
     *
     * 数量=余额行求和、商品种类=商品去重计数；金额=Σ(余额×最近一次采购入库单价) 的整数分值
     * （R2：逐行 half-up 到整数分后整数累加，元展示由前端负责），
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
                'inventory_balances.warehouse_id',
                'products.type',
                'products.category_id',
                'categories.name as category_name',
                'warehouses.name as warehouse_name'
            )
            ->get();

        // 成本价估算：每商品取最近一次「已审核」采购入库单价（created_at DESC, id DESC 首条生效；无记录则不参与金额）
        // 全量 map 含缓存（采购入库审核时失效），消除每次报表加载的历史明细扫描+filesort——口径与失效契约见 CostPriceService
        $prices = $this->costPriceService->latestPriceMap();

        $groups = [];
        $totalQty = '0';
        $totalAmount = 0;
        $totalAmountKnown = false;
        $totalProducts = [];
        foreach ($rows as $row) {
            // joined 列经 getAttribute 读取（larastan 将查询结果按 InventoryBalance 模型定型，属性访问报 undefined）
            $rowType = $row->getAttribute('type');
            // 分组键用 id（name 无唯一约束，同名仓库/分类会静默合并数据——bug #9）；名称仅作展示输出
            $key = match ($groupBy) {
                'warehouse' => (int) $row->getAttribute('warehouse_id'),
                'type' => $rowType,
                default => (int) $row->getAttribute('category_id'),
            };
            $name = match ($groupBy) {
                'warehouse' => $row->getAttribute('warehouse_name'),
                'type' => self::TYPE_LABELS[$rowType] ?? $rowType,
                default => $row->getAttribute('category_name'),
            };
            $groups[$key]['group_name'] = $name;
            $groups[$key]['quantity_total'] = bcadd($groups[$key]['quantity_total'] ?? '0', (string) $row->quantity, 2);
            $groups[$key]['product_count'][$row->product_id] = true;
            if (isset($prices[$row->product_id])) {
                // 行金额 = 余额 × 单价（分）half-up 到整数分（R2：与单据行金额同口径，元展示由前端负责）
                $lineCents = Cents::multiply((string) $row->quantity, $prices[$row->product_id]);
                $groups[$key]['amount_total'] = ($groups[$key]['amount_total'] ?? 0) + $lineCents;
                $totalAmount += $lineCents;
                $totalAmountKnown = true;
            }
            $totalQty = bcadd($totalQty, (string) $row->quantity, 2);
            $totalProducts[$row->product_id] = true;
        }

        $items = collect($groups)
            ->map(fn ($g) => [
                'group_name' => $g['group_name'],
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
            ->where('created_at', '<=', $dateTo.' 23:59:59');
        if ($sourceType !== null) {
            $query->where('source_type', $sourceType);
        }

        // totals 下推 SQL（P2-2②）：GROUP BY direction 单查取全区间合计（SUM/COUNT 标准 SQL 无方言差异；
        // 跨库返回形态经 (string)/bcmath 归一，同 production() 先例）
        $totals = ['inbound_qty' => '0', 'outbound_qty' => '0', 'inbound_count' => 0, 'outbound_count' => 0];
        foreach (
            (clone $query)
                ->select('direction')
                ->selectRaw('SUM(quantity) as q, COUNT(*) as c')
                ->groupBy('direction')
                ->get() as $row
        ) {
            if ((int) $row->direction === 1) {
                $totals['inbound_qty'] = bcadd((string) $row->getAttribute('q'), '0', 2);
                $totals['inbound_count'] = (int) $row->getAttribute('c');
            } else {
                $totals['outbound_qty'] = bcadd((string) $row->getAttribute('q'), '0', 2);
                $totals['outbound_count'] = (int) $row->getAttribute('c');
            }
        }

        // 分桶遍历（P2-2③）：created_at 升序 + 500 桶预剪枝——出现第 501 个周期即置截断并 break
        // （同周期行连续，break 时前 500 个最小周期已完整聚合，与 ksort+截断语义逐字节等价；
        //   有序扫描走 movement_created_at 索引，传输量从「区间全量行」降为「前 500 周期行」）
        $groups = [];
        $truncated = false;
        foreach ($query->select('direction', 'quantity', 'created_at')->orderBy('created_at')->cursor() as $row) {
            // PHP 侧按日/月分组（Carbon format，SQLite/MySQL 方言兼容）
            $period = $row->created_at->format($granularity === 'month' ? 'Y-m' : 'Y-m-d');
            if (! isset($groups[$period])) {
                if (count($groups) >= self::MAX_ROWS) {
                    $truncated = true;
                    break;
                }
                $groups[$period] = ['inbound_qty' => '0', 'outbound_qty' => '0', 'inbound_count' => 0, 'outbound_count' => 0];
            }
            if ((int) $row->direction === 1) {
                $groups[$period]['inbound_qty'] = bcadd($groups[$period]['inbound_qty'], (string) $row->quantity, 2);
                $groups[$period]['inbound_count']++;
            } else {
                $groups[$period]['outbound_qty'] = bcadd($groups[$period]['outbound_qty'], (string) $row->quantity, 2);
                $groups[$period]['outbound_count']++;
            }
        }

        // period 按 created_at 升序遍历自然升序（Y-m-d / Y-m 字典序=时间序，无需 ksort）
        $items = collect($groups)
            ->map(fn ($g, $period) => ['period' => $period] + $g)
            ->values()
            ->all();

        return [
            'items' => $items,
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
     * 返回 totals=全区间工单合计（order_count/计划/完工/合格/不良，KPI 口径；SQL 下推聚合，
     * 不受 items 截断影响）；items=plan_date 升序前 500 工单（P1-1：lazy 装载第 501 行即截断，
     * 报工/领退料聚合仅对展示行执行——大区间传输量从「区间全量」降为「前 500 行+关联聚合」）。
     *
     * @param  string  $dateFrom  计划日期起始 Y-m-d
     * @param  string  $dateTo  计划日期结束 Y-m-d
     * @param  int|null  $productId  成品筛选（可空=全部）
     */
    public function production(string $dateFrom, string $dateTo, ?int $productId = null): array
    {
        // 窗口过滤（totals 与 items 三查共用同一口径：plan_date 闭区间 + 成品可空筛选）
        $window = ProductionOrder::query()
            ->whereDate('production_orders.plan_date', '>=', $dateFrom)
            ->whereDate('production_orders.plan_date', '<=', $dateTo);
        if ($productId !== null) {
            $window->where('production_orders.product_id', $productId);
        }

        // totals 下推 SQL（P1-1）：工单数/计划/完工由窗口单行聚合得出（空窗口 COUNT=0、SUM=null 归 '0'），
        // 免去区间工单数千张时的全量装载+PHP 逐行累加；报工合格/不良 join 工单按同一窗口过滤
        // （不依赖 id 列表；order_id 外键 cascade 保证与明细聚合同一行级口径）
        $orderAgg = (clone $window)->selectRaw('COUNT(*) as c, SUM(quantity) as p, SUM(completed_qty) as cp')->first();
        $reportQuery = OperationReport::query()
            ->join('production_orders', 'production_orders.id', '=', 'operation_reports.order_id')
            ->whereDate('production_orders.plan_date', '>=', $dateFrom)
            ->whereDate('production_orders.plan_date', '<=', $dateTo);
        if ($productId !== null) {
            $reportQuery->where('production_orders.product_id', $productId);
        }
        $reportTotals = $reportQuery
            ->selectRaw('SUM(operation_reports.qualified_qty) as q, SUM(operation_reports.defective_qty) as d')
            ->first();

        $totals = [
            'order_count' => (int) ($orderAgg?->getAttribute('c') ?? 0),
            'total_plan' => $this->normalizeQty((string) ($orderAgg?->getAttribute('p') ?? '0')),
            'total_completed' => $this->normalizeQty((string) ($orderAgg?->getAttribute('cp') ?? '0')),
            'total_qualified' => $this->normalizeQty((string) ($reportTotals?->getAttribute('q') ?? '0')),
            'total_defective' => $this->normalizeQty((string) ($reportTotals?->getAttribute('d') ?? '0')),
        ];

        // items 装载端预剪枝（P1-1）：join 商品名/编码后按 (plan_date, id) 升序 lazy 分块遍历，
        // 收集前 500 行——第 501 行出现即置截断并 break（首块 1000 行内 break，顺序与旧
        // get()+array_slice(0,500) 逐字节等价；不触发跨块偏移分页）
        $orders = [];
        $truncated = false;
        $ordersQuery = (clone $window)
            ->join('products', 'products.id', '=', 'production_orders.product_id')
            // 显式列出 items 装载端实际使用的工单列（id 同时供 lazy 分块），避免 select 通配拉取未列字段
            ->select(
                'production_orders.id',
                'production_orders.no',
                'production_orders.quantity',
                'production_orders.completed_qty',
                'products.name as product_name',
                'products.code as product_code',
            )
            ->orderBy('production_orders.plan_date')
            ->orderBy('production_orders.id');
        foreach ($ordersQuery->lazy() as $order) {
            if (count($orders) >= self::MAX_ROWS) {
                $truncated = true; // 第 501 行即知截断，该行不入展示与聚合
                break;
            }
            $orders[] = $order;
        }
        $orders = collect($orders);
        $orderIds = $orders->pluck('id')->all();

        // 报工聚合：仅对展示行（截断后前 500 工单）执行——截断后工单的聚合数据不再传输（P1-1）
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
                ->where('pick_lists.status', PickList::STATUS_APPROVED)
                ->whereIn('pick_lists.order_id', $orderIds)
                ->selectRaw('pick_lists.order_id, pick_list_items.product_id, SUM(pick_list_items.pick_qty) as qty')
                ->groupBy('pick_lists.order_id', 'pick_list_items.product_id')
                ->get();
            // 已审核退料明细（耗用减项）
            $materials['returns'] = ReturnListItem::query()
                ->join('return_lists', 'return_lists.id', '=', 'return_list_items.return_id')
                ->where('return_lists.status', ReturnList::STATUS_APPROVED)
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

        // 截断标志在装载循环内已置位（第 501 行出现即知），此处仅组装响应
        return ['items' => $items, 'totals' => $totals, 'truncated' => $truncated];
    }

    /**
     * 采购销售汇总聚合：已审核单据金额/数量按审核时间分桶（日/月），金额分单位整数（R2）
     *
     * 采购口径=purchase_inbounds（status=1，inbound_at 闭区间，total_amount 合计）；
     * 销售口径=sales_outbounds（status=1，outbound_at 闭区间，total_amount 合计）；
     * 数量=已审核单据明细 quantity 合计。金额输出整数分（total_amount 已为 bigint 分列，
     * 无需换算；元展示由前端负责）。
     * totals=全区间 SQL 聚合（KPI 口径，不受分桶剪枝影响）；分桶按时间升序遍历 + 500 周期
     * 预剪枝——第 501 个周期出现即置截断并 break（与旧「全量装载+ksort+截断」语义等价，
     * 同 movementsSummary 先例；区间内单据/明细传输量受控，不再全量装载）。
     *
     * @param  string  $dateFrom  起始日期 Y-m-d（含当天 00:00:00）
     * @param  string  $dateTo  结束日期 Y-m-d（含当天 23:59:59）
     * @param  string  $granularity  粒度：day|month
     */
    public function purchaseSales(string $dateFrom, string $dateTo, string $granularity): array
    {
        $periodFormat = $granularity === 'month' ? 'Y-m' : 'Y-m-d';
        $groups = [];
        $truncated = false;

        // totals 下推 SQL：单行聚合取全区间合计（跨层口径与剪枝前一致；SUM 标准 SQL 无方言差异，
        // 空集返回 null 统一归 '0'，与旧实现空区间输出 '0' 的契约一致）
        $totals = [
            'purchase_amount' => 0, 'sales_amount' => 0, 'purchase_qty' => '0', 'sales_qty' => '0',
        ];
        $amount = PurchaseInbound::query()
            ->where('status', PurchaseInbound::STATUS_APPROVED)
            ->where('inbound_at', '>=', $dateFrom.' 00:00:00')
            ->where('inbound_at', '<=', $dateTo.' 23:59:59')
            ->selectRaw('SUM(total_amount) as a')
            ->value('a');
        $qty = PurchaseInboundItem::query()
            ->join('purchase_inbounds', 'purchase_inbounds.id', '=', 'purchase_inbound_items.inbound_id')
            ->where('purchase_inbounds.status', PurchaseInbound::STATUS_APPROVED)
            ->where('purchase_inbounds.inbound_at', '>=', $dateFrom.' 00:00:00')
            ->where('purchase_inbounds.inbound_at', '<=', $dateTo.' 23:59:59')
            ->selectRaw('SUM(purchase_inbound_items.quantity) as q')
            ->value('q');
        // 金额/数量各自独立判空：存在单据但无明细行时金额非空而数量 SUM 为 null（归 '0'，与旧实现一致）
        if ($amount !== null) {
            // total_amount 已为 bigint 分整数（MySQL SUM(bigint) 返回 decimal 字符串/SQLite 返回 int，统一 int 归一）
            $totals['purchase_amount'] = (int) $amount;
        }
        if ($qty !== null) {
            $totals['purchase_qty'] = bcadd((string) $qty, '0', 2);
        }
        $amount = SalesOutbound::query()
            ->where('status', SalesOutbound::STATUS_APPROVED)
            ->where('outbound_at', '>=', $dateFrom.' 00:00:00')
            ->where('outbound_at', '<=', $dateTo.' 23:59:59')
            ->selectRaw('SUM(total_amount) as a')
            ->value('a');
        $qty = SalesOutboundItem::query()
            ->join('sales_outbounds', 'sales_outbounds.id', '=', 'sales_outbound_items.outbound_id')
            ->where('sales_outbounds.status', SalesOutbound::STATUS_APPROVED)
            ->where('sales_outbounds.outbound_at', '>=', $dateFrom.' 00:00:00')
            ->where('sales_outbounds.outbound_at', '<=', $dateTo.' 23:59:59')
            ->selectRaw('SUM(sales_outbound_items.quantity) as q')
            ->value('q');
        if ($amount !== null) {
            // total_amount 已为 bigint 分整数，直接整数分口径输出
            $totals['sales_amount'] = (int) $amount;
        }
        if ($qty !== null) {
            $totals['sales_qty'] = bcadd((string) $qty, '0', 2);
        }

        // 分桶遍历（升序 + 500 周期预剪枝）：lazy 分块装载并按块预载明细（cursor 不执行预载，
        // 会退化为逐单懒加载 N+1，故用 lazy）；明细数量合计保留 PHP 侧分桶（跨库方言无关）
        $inbounds = PurchaseInbound::query()
            ->with('items')
            ->where('status', PurchaseInbound::STATUS_APPROVED)
            ->where('inbound_at', '>=', $dateFrom.' 00:00:00')
            ->where('inbound_at', '<=', $dateTo.' 23:59:59')
            ->orderBy('inbound_at')
            ->orderBy('id')
            ->lazy();
        foreach ($inbounds as $in) {
            $period = Carbon::parse($in->inbound_at)->format($periodFormat);
            if (! isset($groups[$period])) {
                if (count($groups) >= self::MAX_ROWS) {
                    $truncated = true;
                    break;
                }
                $groups[$period] = ['purchase_amount' => 0, 'sales_amount' => 0, 'purchase_qty' => '0', 'sales_qty' => '0'];
            }
            // total_amount 为 bigint 分整数，整数累加（R2：与 totals 同口径）
            $groups[$period]['purchase_amount'] += (int) $in->total_amount;
            $qty = '0';
            foreach ($in->items as $item) {
                $qty = bcadd($qty, $item->quantity, 2);
            }
            $groups[$period]['purchase_qty'] = bcadd($groups[$period]['purchase_qty'], $qty, 2);
        }

        // 销售侧：同构（升序 + 500 周期预剪枝；截断标志两侧共用，任一侧触及即置位）
        $outbounds = SalesOutbound::query()
            ->with('items')
            ->where('status', SalesOutbound::STATUS_APPROVED)
            ->where('outbound_at', '>=', $dateFrom.' 00:00:00')
            ->where('outbound_at', '<=', $dateTo.' 23:59:59')
            ->orderBy('outbound_at')
            ->orderBy('id')
            ->lazy();
        foreach ($outbounds as $out) {
            $period = Carbon::parse($out->outbound_at)->format($periodFormat);
            if (! isset($groups[$period])) {
                if (count($groups) >= self::MAX_ROWS) {
                    $truncated = true;
                    break;
                }
                $groups[$period] = ['purchase_amount' => 0, 'sales_amount' => 0, 'purchase_qty' => '0', 'sales_qty' => '0'];
            }
            // total_amount 为 bigint 分整数，整数累加（R2：与 totals 同口径）
            $groups[$period]['sales_amount'] += (int) $out->total_amount;
            $qty = '0';
            foreach ($out->items as $item) {
                $qty = bcadd($qty, $item->quantity, 2);
            }
            $groups[$period]['sales_qty'] = bcadd($groups[$period]['sales_qty'], $qty, 2);
        }

        // period 按升序遍历自然有序（Y-m-d / Y-m 字典序=时间序，无需 ksort）
        $items = collect($groups)
            ->map(fn ($g, $period) => ['period' => $period] + $g)
            ->values()
            ->all();

        return [
            'items' => $items,
            'totals' => $totals,
            'truncated' => $truncated,
        ];
    }

    // 数量列归一（totals 与 items 展示共用口径）：bcmath 转 2 位小数字符串后仅剥离 '.00' 尾零
    // （'30'→'30'、'30.50'→'30.50'，保留 2 位小数语义；SQL SUM 空值已由调用方归 '0'）
    private function normalizeQty(string $value): string
    {
        return preg_replace('/\.00$/', '', bcadd($value, '0', 2));
    }
}
