<?php

// 仪表盘聚合服务：4 类只读实时聚合（KPI/待审核/工单进度/预警），零迁移零新表
// 全部口径与业务模块事实一致；数量/金额 bcmath 字符串运算；不落快照、无缓存；
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
use App\Models\PurchaseInboundItem;
use App\Models\PurchaseOrder;
use App\Models\ReturnList;
use App\Models\SalesOrder;
use App\Models\SalesOutbound;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

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
     * 库存总量=全部余额行求和；总值=Σ(余额×最近一次采购入库单价)÷100 元（无任何已知成本价→null）；
     * 今日出入库=流水 created_at 当天闭区间按方向求和；待审核数=9 类草稿按审核权限过滤后计数；
     * 预警数=低库存条数（高库存不占仪表盘，spec §7）。
     *
     * @param  User  $user  当前登录用户（待审核数按其所持审核权限过滤）
     */
    public function summary(User $user): array
    {
        $balances = InventoryBalance::query()->select('product_id', 'quantity')->get();

        // 成本价估算：每商品取最近一次「已审核」采购入库单价（created_at DESC, id DESC 首条生效——与报表模块同口径）
        // 限定余额行商品集（whereIn 单查）消除全表 cursor 扫描（性能债 P1-1）；
        // whereHas 过滤已审核入库单——草稿入库单 store 即写明细且审核不改 created_at，草稿价参与会导致金额跳变（bug #7）
        $prices = [];
        $productIds = $balances->pluck('product_id')->unique()->all();
        foreach (
            PurchaseInboundItem::query()
                ->whereIn('product_id', $productIds)
                ->whereHas('purchaseInbound', fn ($q) => $q->where('status', PurchaseInbound::STATUS_APPROVED))
                ->select('product_id', 'price')
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->cursor() as $item
        ) {
            $prices[$item->product_id] = $prices[$item->product_id] ?? $item->price;
        }

        $totalQty = '0';
        $totalValue = '0';
        $valueKnown = false;
        foreach ($balances as $row) {
            $totalQty = bcadd($totalQty, (string) $row->quantity, 2);
            if (isset($prices[$row->product_id])) {
                // 行金额 = 余额 × 单价（分）→ 元（2 位）；bcmath 全程无浮点
                $totalValue = bcadd($totalValue, bcdiv(bcmul((string) $row->quantity, (string) $prices[$row->product_id], 2), '100', 2), 2);
                $valueKnown = true;
            }
        }

        // 今日出入库：流水 created_at 当天闭区间（Carbon 本地时区边界，方言无关）
        $today = Carbon::today();
        $inbound = '0';
        $outbound = '0';
        foreach (
            InventoryMovement::query()
                ->whereBetween('created_at', [$today->startOfDay(), $today->copy()->endOfDay()])
                ->select('direction', 'quantity')
                ->cursor() as $m
        ) {
            if ((int) $m->direction === 1) {
                $inbound = bcadd($inbound, (string) $m->quantity, 2);
            } else {
                $outbound = bcadd($outbound, (string) $m->quantity, 2);
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
