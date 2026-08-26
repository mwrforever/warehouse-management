<?php

// 仪表盘聚合服务：4 类只读实时聚合（KPI/待审核/工单进度/预警），零迁移零新表
// 全部口径与业务模块事实一致；数量 bcmath 字符串运算、金额分单位整数（R2 纯分口径）；
// 不落快照、无缓存；
// 待审核单据按当前用户审核权限过滤（审核复用各模块 update 权限——安全语义所在）

namespace App\Services;

use App\Models\FinishedInbound;
use App\Models\InventoryBalance;
use App\Models\InventoryCheck;
use App\Models\InventoryMovement;
use App\Models\OutsourcingOrder;
use App\Models\PickList;
use App\Models\ProductionOrder;
use App\Models\PurchaseInbound;
use App\Models\PurchaseOrder;
use App\Models\ReturnList;
use App\Models\SalesOrder;
use App\Models\SalesOutbound;
use App\Models\User;
use App\Support\Cents;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class DashboardService
{
    /** 待审核列表条数上限（spec §3：最多 20 条，按创建时间倒序） */
    public const MAX_PENDING = 20;

    /** 工单进度列表条数上限（spec §3：最多 10 条，按更新时间倒序） */
    public const MAX_ORDERS = 10;

    /** 预警列表条数上限（spec §3：低库存前 10 条） */
    public const MAX_ALERTS = 10;

    /**
     * 待审核数据统计：9 类草稿单据按审核权限过滤（rows 供列表 / count 供 KPI）
     *
     * 9 类单据显式逐类收集（全静态类型 Eloquent 查询，禁动态类名访问）；
     * rows 每类先取 MAX_PENDING 条最新再合并全局排序（某类型第 21 条不可能进入全局前 20）；
     * count 不受 20 条上限影响——KPI 必须为真实总数。
     *
     * @param  User  $user  当前登录用户（permissions() 返回角色合并去重权限码集合）
     * @return array{rows: array<int, array{module: string, type: string, no: string, created_at: string, url: string}>, count: int}
     */
    private function pendingData(User $user): array
    {
        $perms = $user->permissions();
        $rows = [];
        $count = 0;

        // 9 类草稿单据逐类收集：审核权限过滤（无权限整类不可见，TC-DSH-07）+ 草稿状态过滤；
        // 审核动作复用各模块 update 权限（全局约定）；生产工单草稿不入待审核——
        // 其流转动作是「下达」而非「审核」
        if ($perms->contains('purchase.order.update')) {
            $q = PurchaseOrder::where('status', PurchaseOrder::STATUS_DRAFT);
            $count += $q->count();
            $this->appendPending($q, '采购', '订单', '/purchase/orders', $rows);
        }
        if ($perms->contains('purchase.inbound.update')) {
            $q = PurchaseInbound::where('status', PurchaseInbound::STATUS_DRAFT);
            $count += $q->count();
            $this->appendPending($q, '采购', '入库单', '/purchase/inbounds', $rows);
        }
        if ($perms->contains('sales.order.update')) {
            $q = SalesOrder::where('status', SalesOrder::STATUS_DRAFT);
            $count += $q->count();
            $this->appendPending($q, '销售', '订单', '/sales/orders', $rows);
        }
        if ($perms->contains('sales.outbound.update')) {
            $q = SalesOutbound::where('status', SalesOutbound::STATUS_DRAFT);
            $count += $q->count();
            $this->appendPending($q, '销售', '出库单', '/sales/outbounds', $rows);
        }
        if ($perms->contains('check.update')) {
            $q = InventoryCheck::where('status', InventoryCheck::STATUS_DRAFT);
            $count += $q->count();
            $this->appendPending($q, '库存', '盘点单', '/inventory/checks', $rows);
        }
        if ($perms->contains('production.pick.update')) {
            $q = PickList::where('status', PickList::STATUS_DRAFT);
            $count += $q->count();
            $this->appendPending($q, '生产', '领料单', '/production/picks', $rows);
        }
        if ($perms->contains('production.return.update')) {
            $q = ReturnList::where('status', ReturnList::STATUS_DRAFT);
            $count += $q->count();
            $this->appendPending($q, '生产', '退料单', '/production/returns', $rows);
        }
        if ($perms->contains('production.outsource.update')) {
            $q = OutsourcingOrder::where('status', OutsourcingOrder::STATUS_DRAFT);
            $count += $q->count();
            $this->appendPending($q, '生产', '委外单', '/production/outsourcings', $rows);
        }
        if ($perms->contains('production.finished.update')) {
            $q = FinishedInbound::where('status', FinishedInbound::STATUS_DRAFT);
            $count += $q->count();
            $this->appendPending($q, '生产', '成品入库单', '/production/finished-inbounds', $rows);
        }

        // 跨类型按创建时间倒序全局排序（Y-m-d H:i:s 字典序=时间序；PHP 8 usort 稳定，同秒保持登记序）
        usort($rows, fn ($a, $b) => strcmp($b['created_at'], $a['created_at']));

        return ['rows' => $rows, 'count' => $count];
    }

    /**
     * 追加单类草稿单据行（每类先取 MAX_PENDING 条最新；全局排序与截断由 pendingData 完成）
     *
     * @param  Builder<PurchaseOrder>|Builder<PurchaseInbound>|Builder<SalesOrder>|Builder<SalesOutbound>|Builder<InventoryCheck>|Builder<PickList>|Builder<ReturnList>|Builder<OutsourcingOrder>|Builder<FinishedInbound>  $query  草稿查询（status=STATUS_DRAFT 已过滤）
     * @param  string  $module  模块名（采购/销售/库存/生产）
     * @param  string  $type  单据类型（订单/入库单/出库单/盘点单/领料单/退料单/委外单/成品入库单）
     * @param  string  $url  前端路由（列表行点击跳转目标，前端白名单放行）
     * @param  array<int, array{module: string, type: string, no: string, created_at: string, url: string}>  $rows  追加目标（引用传递）
     */
    private function appendPending($query, string $module, string $type, string $url, array &$rows): void
    {
        foreach (
            $query->select(['no', 'created_at'])
                ->orderByDesc('created_at')
                ->limit(self::MAX_PENDING)
                ->cursor() as $doc
        ) {
            $rows[] = [
                'module' => $module,
                'type' => $type,
                'no' => $doc->no,
                // created_at 为 Carbon（模型默认 cast），统一输出 Y-m-d H:i:s
                'created_at' => $doc->created_at->format('Y-m-d H:i:s'),
                'url' => $url,
            ];
        }
    }

    /**
     * KPI 汇总：库存总量/总值/今日出入库/待审核数/生产中工单数/预警数
     *
     * 库存总量/总值均为 SQL 聚合下推（D-18）：总量=SUM(quantity)；总值=Σ(余额×最近一次「已审核」
     * 采购入库单价)的整数分值（R2：后端纯分口径，聚合后 half-up 单次取整，元展示由前端负责；
     * 无任何已知成本价→null）；
     * 今日出入库=流水 created_at 当天闭区间按方向求和；待审核数=9 类草稿按审核权限过滤后计数；
     * 预警数=低库存条数（高库存不占仪表盘，spec §7）。
     *
     * @param  User  $user  当前登录用户（待审核数按其所持审核权限过滤）
     */
    public function summary(User $user): array
    {
        // 库存总量：全表 SUM 下推（与「今日出入库」同模式），不再全量取行到 PHP 累加（D-18）；
        // 空表 sum 归 0（Builder 底层 ?: 0）；跨库 SUM 形态经 bcmath 统一两位小数字符串口径
        $totalQty = bcadd((string) InventoryBalance::query()->sum('quantity'), '0', 2);

        // 每商品最新「已审核」采购入库单价子查询（与 CostPriceService::build 同口径：
        // created_at DESC, id DESC 首条生效；草稿价被 status 过滤排除——bug #7 口径）。
        // ROW_NUMBER 窗口序与复合索引 (product_id, created_at, id) 全序一致，MySQL 8 免 filesort；
        // SQLite 3.25+ 同构支持窗口函数，测试/生产跨库一致
        $latestPrices = DB::query()->fromSub(
            DB::table('purchase_inbound_items as p')
                ->join('purchase_inbounds as h', 'h.id', '=', 'p.inbound_id')
                ->where('h.status', PurchaseInbound::STATUS_APPROVED)
                ->select('p.product_id', 'p.price')
                ->selectRaw(
                    'ROW_NUMBER() OVER (PARTITION BY p.product_id ORDER BY p.created_at DESC, p.id DESC) as rn'
                ),
            'lp'
        )->where('lp.rn', 1)->select('lp.product_id', 'lp.price');

        // 库存总值下推（D-18）：余额行 join 最新价后单条聚合（join 天然排除无价商品——原 isset 语义）；
        // SUM(quantity×price) 累计「数量×分」，聚合后经 Cents::round 单次 half-up 到整数分
        // （精度不低于逐行取整）；matched>0 即至少一行有已知成本价（原 valueKnown 语义：部分有价 → 总值非 null）
        $valueRow = InventoryBalance::query()
            ->joinSub(
                $latestPrices,
                'cp',
                fn ($join) => $join->on('cp.product_id', '=', 'inventory_balances.product_id')
            )
            ->selectRaw('COUNT(*) as matched, SUM(inventory_balances.quantity * cp.price) as total_value')
            ->first();
        $totalValue = 0;
        $valueKnown = $valueRow !== null && (int) $valueRow->getAttribute('matched') > 0;
        if ($valueKnown) {
            // 跨库 SUM 形态归一（SQLite real / MySQL decimal 字符串）后 half-up 取整数分（R2：后端纯分口径，元换算由前端展示层负责）
            $totalValue = Cents::round((string) $valueRow->getAttribute('total_value'));
        }

        // 今日出入库：流水 created_at 当天闭区间（Carbon 本地时区边界，方言无关）
        // SQL 聚合下推（P2-1②）：GROUP BY direction 单查替代全量 cursor + PHP 逐行求和
        // （SUM 为标准 SQL 无方言差异；decimal(12,2) 求和与逐行 bcadd 精确等价，跨库形态 bcmath 归一）
        $today = Carbon::today();
        $inbound = '0';
        $outbound = '0';
        foreach (
            InventoryMovement::query()
                ->whereBetween('created_at', [$today->startOfDay(), $today->copy()->endOfDay()])
                ->select('direction')
                ->selectRaw('SUM(quantity) as total')
                ->groupBy('direction')
                ->get() as $row
        ) {
            $total = bcadd((string) $row->getAttribute('total'), '0', 2);
            if ((int) $row->direction === 1) {
                $inbound = $total;
            } else {
                $outbound = $total;
            }
        }

        return [
            'inventory_total_qty' => $totalQty,
            'inventory_value' => $valueKnown ? $totalValue : null,
            'today_inbound_qty' => $inbound,
            'today_outbound_qty' => $outbound,
            'pending_approvals' => $this->pendingData($user)['count'],
            'work_order_running' => ProductionOrder::where('status', ProductionOrder::STATUS_PRODUCING)->count(),
            'alert_count' => $this->alertQuery()->count(),
        ];
    }

    /**
     * 待审核单据列表：9 类草稿按审核权限过滤，创建时间倒序，最多 MAX_PENDING 条
     *
     * 单类型先各取 MAX_PENDING 条再合并全局排序（某类型第 21 条不可能进入全局前 20）；
     * created_at 输出 Y-m-d H:i:s 字符串；url 为前端路由（前端白名单放行）。
     *
     * @param  User  $user  当前登录用户（无对应审核权限的类型整类不可见）
     */
    public function pendingApprovals(User $user): array
    {
        return ['items' => array_slice($this->pendingData($user)['rows'], 0, self::MAX_PENDING)];
    }

    /**
     * 工单进度列表：生产中/已完成工单，更新时间倒序，最多 MAX_ORDERS 条
     *
     * progress = completed_qty/quantity×100（4 位中间精度输出 2 位字符串；计划 0 防御 0.00）；
     * status_label 为展示扩展字段（前端状态标签）。
     */
    public function workOrderProgress(): array
    {
        $orders = ProductionOrder::query()
            ->join('products', 'products.id', '=', 'production_orders.product_id')
            ->whereIn('production_orders.status', [ProductionOrder::STATUS_PRODUCING, ProductionOrder::STATUS_COMPLETED])
            ->select(
                'production_orders.no',
                'production_orders.quantity',
                'production_orders.completed_qty',
                'production_orders.status',
                'products.name as product_name'
            )
            ->orderByDesc('production_orders.updated_at')
            ->limit(self::MAX_ORDERS)
            ->get();

        $items = $orders->map(function ($order) {
            $progress = bccomp($order->quantity, '0', 2) === 0
                ? '0.00'
                : number_format((float) bcmul(bcdiv($order->completed_qty, $order->quantity, 4), '100', 2), 2, '.', '');

            return [
                'no' => $order->no,
                // join 别名列经 getAttribute 读取（PHPStan 静态分析可识别，同 InventoryController 模式）
                'product_name' => $order->getAttribute('product_name'),
                'quantity' => $order->quantity,
                'completed_qty' => $order->completed_qty,
                'progress' => $progress,
                'status' => (int) $order->status,
                'status_label' => ProductionOrder::STATUS_LABELS[(int) $order->status] ?? '未知',
            ];
        })->all();

        return ['items' => $items];
    }

    /**
     * 库存预警列表：仅低库存（level=1：低于下限），product_id 升序前 MAX_ALERTS 条
     *
     * 与库存预警页 /api/v1/inventory/alerts 同口径同排序（保证两处「前 10」一致）；
     * 高库存（level=2）不占仪表盘（spec §7）。
     */
    public function alerts(): array
    {
        $items = $this->alertQuery()
            ->select(
                'products.name as product_name',
                'products.code as product_code',
                'warehouses.name as warehouse_name',
                'inventory_balances.quantity',
                'products.safety_min as safety_min'
            )
            ->limit(self::MAX_ALERTS)
            ->get()
            ->map(fn ($r) => [
                'product_name' => $r->getAttribute('product_name'),
                'product_code' => $r->getAttribute('product_code'),
                'warehouse_name' => $r->getAttribute('warehouse_name'),
                'quantity' => $r->quantity,
                'safety_min' => (int) $r->getAttribute('safety_min'),
            ])->all();

        return ['items' => $items];
    }

    /**
     * 低库存预警查询基座（alerts 列表与 summary 计数共用；spec §7：仅 level=1 低于下限，0=不预警该侧）
     */
    private function alertQuery()
    {
        return InventoryBalance::query()
            ->join('products', 'products.id', '=', 'inventory_balances.product_id')
            ->join('warehouses', 'warehouses.id', '=', 'inventory_balances.warehouse_id')
            ->whereRaw('products.safety_min > 0 AND inventory_balances.quantity < products.safety_min')
            ->orderBy('inventory_balances.product_id');
    }
}
