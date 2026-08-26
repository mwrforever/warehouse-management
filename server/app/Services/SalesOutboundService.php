<?php

// 销售出库单服务：草稿创建/更新/删除、审核（核心：事务内锁余额行防超卖 + InventoryService 扣库存 + 订单联动）

namespace App\Services;

use App\Exceptions\InventoryException;
use App\Exceptions\SalesException;
use App\Models\DocumentSequence;
use App\Models\InventoryBalance;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use App\Models\SalesOutbound;
use App\Models\SalesOutboundItem;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 销售出库单领域服务（D-1：控制器写操作全部下沉至此）
 *
 * 承接原控制器的单据写流程：草稿 CRUD 与审核。审核为库存关键节点，单事务内完成
 * 「锁出库单行 → 批量预锁订单行 → 批量预锁订单头 → 批量预锁余额行 → InventoryService
 * 写流水扣库存 → 回写订单行 shipped_qty → 订单状态重算 → 置已审核」（锁序铁律：
 * 单据头 → 明细 → 库存，订单行/订单头/余额行批量按索引序获取，无 ABBA 交叉锁）。
 * 业务失败统一抛 SalesException（业务码沿用原口径 1401~1412），由全局异常处理器
 * 渲染 {code, message, data} 信封，与原控制器 fail() 响应字节级等价。
 * 库存写入口唯一：一律经 InventoryService，本服务不直接写库存表；
 * 余额引擎兜底拒绝（InventoryException）在此翻译为 1409 业务码，不进入全局渲染器。
 * 非线程安全：同一单据的并发写依赖数据库行锁串行化，勿在事务外组合调用多个写方法。
 */
class SalesOutboundService
{
    public function __construct(
        private InventoryService $inventoryService,
        private SalesOrderService $orderService,
        private DocumentSequenceService $sequenceService,
    ) {}

    /**
     * 新建销售出库单草稿（原控制器 store 下沉）：仓库/库位必填 1406；订单行剩余量校验 1407；重复商品 1412
     *
     * 校验链：仓库/库位业务码 → 明细逐行业务码 → 订单行引用与订单行归属/客户/剩余量校验
     * → 事务内持久序列取号建单 + 建明细。
     * 幂等性：单号撞号由序列服务自动换号重试；事务死锁(1213)整体回滚后重跑重取号。
     *
     * @param  array  $data  已过 SaveSalesOutboundRequest 格式校验的载荷
     *                       （customer_id/warehouse_id/location_id/order_id/remark/items）
     * @return SalesOutbound 新建的出库单模型（含单号，供控制器回显）
     *
     * @throws SalesException 仓库/库位缺失 1406 / 明细空 1401 / 数量非法 422 / 负价 1411 /
     *                        原料禁售 422 / 重复明细 1412 / 订单行不一致或不可出库或超量 1407
     */
    public function create(array $data): SalesOutbound
    {
        // 业务码校验（仓库/库位必填走业务码而非 422，与 spec 1406 一致）：
        // empty 与原 request->filled 等价（'' 经全局中间件转 null，0 会被 exists 规则先行 422 拦截）
        if (empty($data['warehouse_id']) || empty($data['location_id'])) {
            throw new SalesException('仓库与库位不能为空', 1406);
        }
        // items 键可整体缺失（rules 未 required），?? 兜底防 undefined key
        $items = $data['items'] ?? [];
        $this->assertOutboundItems($items, empty($data['order_id']));
        // 关联订单行校验：行归属/订单可出库/客户一致/不超剩余量（草稿期即拦截，审核期再锁行复核）
        if ($orderId = $data['order_id'] ?? null) {
            $this->assertOrderItems($orderId, (int) $data['customer_id'], $items);
        }

        // 事务第 2 参数为死锁(1213)重试次数（机理同 BomController::store：序列行首建间隙锁
        // 死锁败方整体回滚后重跑闭包重新取号，幂等安全）
        $outbound = DB::transaction(function () use ($data, $items) {
            // 单号走持久序列（撞号自动换号；删除不回退；老库 max 衔接）
            $outbound = $this->sequenceService->nextNoByConfig(
                DocumentSequence::TYPE_SOUT,
                fn (string $no) => SalesOutbound::create([
                    'no' => $no,
                    'customer_id' => $data['customer_id'],
                    'warehouse_id' => $data['warehouse_id'],
                    'location_id' => $data['location_id'],
                    'order_id' => $data['order_id'] ?? null,
                    'status' => SalesOutbound::STATUS_DRAFT,
                    'total_amount' => $this->orderService->calculateTotal($items),
                    'remark' => $data['remark'] ?? null,
                ]),
                fn (string $prefix, string $dateKey) => ($no = SalesOutbound::where('no', 'like', $prefix.date('Ymd').'%')
                    ->orderByDesc('no')->value('no')) ? DocumentSequenceService::seqFromNo($no, $prefix, $dateKey) : 0,
            );
            $outbound->items()->createMany(array_map(fn ($i) => [
                'product_id' => $i['product_id'],
                'quantity' => $i['quantity'],
                'price' => $i['price'],
                'amount' => $this->orderService->lineAmount((string) $i['quantity'], $i['price']),
                'order_item_id' => $i['order_item_id'] ?? null,
            ], $items));

            return $outbound;
        }, 2);

        // 单据创建审计日志（事务提交后记）：单号 + 来源订单 + 操作人
        Log::info('销售出库单创建成功', [
            'no' => $outbound->no, 'order_id' => $outbound->order_id, 'created_by' => auth()->id(),
        ]);

        return $outbound;
    }

    /**
     * 更新销售出库单草稿（原控制器 update 下沉）：仅草稿可改（1408）；items 全量替换；订单行校验同 create
     *
     * 事务内锁单据头行复查状态：与审核并发时防止改到正在审核的单（幂等 1408）。
     *
     * @param  SalesOutbound  $outbound  路由绑定的出库单模型（草稿状态才可改）
     * @param  array  $data  已过 SaveSalesOutboundRequest 格式校验的载荷
     *
     * @throws SalesException 非草稿 1408 / 其余业务码同 create（1406/1401/422/1411/1412/1407）
     */
    public function update(SalesOutbound $outbound, array $data): void
    {
        // 状态前置校验（不进事务，快速失败）：非草稿直接拒绝
        if ($outbound->status !== SalesOutbound::STATUS_DRAFT) {
            throw new SalesException('已审核单据不可修改', 1408);
        }
        // 业务码校验口径与 create 完全一致（同一组私有方法，防两处口径漂移）
        if (empty($data['warehouse_id']) || empty($data['location_id'])) {
            throw new SalesException('仓库与库位不能为空', 1406);
        }
        $items = $data['items'] ?? [];
        $this->assertOutboundItems($items, empty($data['order_id']));
        if ($orderId = $data['order_id'] ?? null) {
            $this->assertOrderItems($orderId, (int) $data['customer_id'], $items);
        }

        DB::transaction(function () use ($outbound, $data, $items) {
            // 锁出库单行复查状态：与审核并发时防止改到正在审核的单（幂等 1408）
            $locked = SalesOutbound::whereKey($outbound->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== SalesOutbound::STATUS_DRAFT) {
                throw new SalesException('已审核单据不可修改', 1408);
            }
            $locked->update([
                'customer_id' => $data['customer_id'],
                'warehouse_id' => $data['warehouse_id'],
                'location_id' => $data['location_id'],
                'order_id' => $data['order_id'] ?? null,
                'total_amount' => $this->orderService->calculateTotal($items),
                'remark' => $data['remark'] ?? $locked->remark,
            ]);
            // 明细全量替换（草稿单无流水引用，直接重建）
            $locked->items()->delete();
            $locked->items()->createMany(array_map(fn ($i) => [
                'product_id' => $i['product_id'],
                'quantity' => $i['quantity'],
                'price' => $i['price'],
                'amount' => $this->orderService->lineAmount((string) $i['quantity'], $i['price']),
                'order_item_id' => $i['order_item_id'] ?? null,
            ], $items));
        });
    }

    /**
     * 删除销售出库单草稿（原控制器 destroy 下沉）：仅草稿可删（1408）
     *
     * 事务内锁单据头行复查状态：与审核并发时防止删到正在审核的单（幂等 1408）。
     *
     * @param  SalesOutbound  $outbound  路由绑定的出库单模型（内存模型持单号供审计日志追溯）
     *
     * @throws SalesException 非草稿 1408
     */
    public function delete(SalesOutbound $outbound): void
    {
        // 状态前置校验（不进事务，快速失败）：非草稿直接拒绝
        if ($outbound->status !== SalesOutbound::STATUS_DRAFT) {
            throw new SalesException('已审核单据不可删除', 1408);
        }
        DB::transaction(function () use ($outbound) {
            // 锁出库单行复查状态：与审核并发时防止删到正在审核的单（幂等 1408）
            $locked = SalesOutbound::whereKey($outbound->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== SalesOutbound::STATUS_DRAFT) {
                throw new SalesException('已审核单据不可删除', 1408);
            }
            $locked->delete();
        });

        // 单据删除审计日志（事务提交后记）：内存模型仍持有单号，可用于追溯
        Log::info('销售出库单草稿删除', ['no' => $outbound->no, 'operator' => auth()->id()]);
    }

    /**
     * 审核销售出库单（原控制器 approve 下沉，核心库存链路）
     *
     * 单事务内「锁单幂等（1410）→ 批量预锁订单行验剩余量 → 批量预锁订单头验状态
     * → 批量预锁余额行防超卖 → InventoryService 扣库存 → 回写 shipped_qty →
     * 订单状态重算 → 置已审核」，任一步失败整体回滚。
     * 余额引擎兜底拒绝（InventoryException）在事务外交付前翻译为 1409 业务码，
     * 保持原控制器语境化 catch 的语义（消息走通用文案，细节记 warn）。
     *
     * @param  SalesOutbound  $outbound  路由绑定的出库单模型
     * @return array{no: string} 单号（供控制器响应回显）
     *
     * @throws SalesException 重复审核 1410 / 订单行不一致 1407 / 订单不可出库 1407 /
     *                        超剩余量 1407 / 库存不足 1409
     */
    public function approve(SalesOutbound $outbound): array
    {
        try {
            $result = null;
            // attempts=2：死锁自动重试一次（B-3 纵深防御；余额行锁序已由 InventoryService 排序规范化统一）
            DB::transaction(function () use ($outbound, &$result) {
                // 锁出库单行：同一单据重复审核在此判重（幂等 1410）
                $locked = SalesOutbound::whereKey($outbound->id)->lockForUpdate()->firstOrFail();
                if ($locked->status === SalesOutbound::STATUS_APPROVED) {
                    throw new SalesException('该出库单已审核', 1410);
                }
                $movements = [];
                $shipped = []; // [order_item_id => 本次累计出库量] 待回写
                // 循环前批量预锁（P1-2，宪法 §4.2.4 建议）：订单行按 id 一次锁定、订单头按去重
                // order_id 批量锁一次（同订单多明细不再重复锁同一行）、余额行按商品一次锁定；
                // 锁序保持「订单行→订单头→余额」全局方向，批量按索引序获取还消除了
                // 「两单明细顺序相反」时的交叉锁窗口（无 ABBA 新方向）
                $orderItemIds = $locked->items->pluck('order_item_id')->filter()->values()->all();
                /** @var Collection<int, SalesOrderItem> $oiMap 已锁定的订单行（回写复用，免二次查询） */
                $oiMap = $orderItemIds === []
                    ? collect()
                    : SalesOrderItem::query()->whereIn('id', $orderItemIds)->lockForUpdate()->get()->keyBy('id');
                $orderIds = $oiMap->pluck('order_id')->unique()->values()->all();
                /** @var Collection<int, SalesOrder> $orderMap 已锁定的订单头（状态复核复用） */
                $orderMap = $orderIds === []
                    ? collect()
                    : SalesOrder::query()->whereIn('id', $orderIds)->lockForUpdate()->get()->keyBy('id');
                /** @var Collection<int, InventoryBalance> $balanceMap 已锁定的余额行（防超卖校验复用） */
                $balanceMap = InventoryBalance::query()
                    ->whereIn('product_id', $locked->items->pluck('product_id'))
                    ->where('warehouse_id', $locked->warehouse_id)
                    ->where('location_id', $locked->location_id)
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('product_id');
                /** @var SalesOutboundItem $item */
                foreach ($locked->items as $item) {
                    if ($item->order_item_id) {
                        // 复核订单行：防并发超收（并发审核同一行已在上方批量锁定串行化）
                        $oi = $oiMap->get($item->order_item_id);
                        // 防御：订单行已被删除或商品不一致（数据完整性，归 1407 语义族）
                        if (! $oi || $oi->product_id !== $item->product_id) {
                            throw new SalesException('出库明细与订单行不一致', 1407);
                        }
                        // 复核订单头状态：关闭/已完成/草稿均不可出库（1407）；
                        // 头缺失（FK cascade 下不可达的防御路径）与 firstOrFail 同 404 语义
                        $order = $orderMap->get($oi->order_id);
                        if (! $order) {
                            throw (new ModelNotFoundException)->setModel(SalesOrder::class, $oi->order_id);
                        }
                        // 仅已审核/部分出库订单可继续出库（其余状态一律拒绝，防关闭单旁路出库）
                        $orderUnlockable = in_array(
                            $order->status,
                            [SalesOrder::STATUS_APPROVED, SalesOrder::STATUS_PARTIAL],
                            true
                        );
                        if (! $orderUnlockable) {
                            throw new SalesException('该订单当前不可出库', 1407);
                        }
                        // 剩余量 = 订购数 - 已出库累计；本次出库量超剩余 → 1407 整体回滚（防超收）
                        $remaining = bcsub((string) $oi->quantity, (string) $oi->shipped_qty, 2);
                        if (bccomp((string) $item->quantity, $remaining, 2) > 0) {
                            throw new SalesException('出库数量超过订单剩余数量', 1407);
                        }
                        $shipped[$oi->id] = bcadd((string) ($shipped[$oi->id] ?? '0'), (string) $item->quantity, 2);
                    }
                    // 防超卖：余额行已批量锁定，校验余额充足（并发审核同一商品在此串行化；消息含商品名与当前库存快照）
                    $balance = $balanceMap->get($item->product_id);
                    $current = $balance ? (string) $balance->quantity : '0';
                    if (bccomp((string) $item->quantity, $current, 2) > 0) {
                        // 库存快照去掉小数尾零展示（14.00 → 14；0.00/缺行 → 0）
                        $qtyText = str_contains($current, '.') ? rtrim(rtrim($current, '0'), '.') : $current;
                        // 商品名取不到时回退商品 id（商品被删的兜底展示）
                        $product = Product::find($item->product_id);
                        $name = $product ? $product->name : ('#'.$item->product_id);
                        throw new SalesException("商品[{$name}]库存不足，当前库存 {$qtyText}", 1409);
                    }
                    $movements[] = [
                        'product_id' => $item->product_id,
                        'warehouse_id' => $locked->warehouse_id,
                        'location_id' => $locked->location_id,
                        'direction' => -1,
                        'quantity' => $item->quantity,
                        'source_type' => 'sales_outbound',
                        'source_id' => $locked->id,
                        'source_no' => $locked->no,
                        'remark' => '销售出库',
                    ];
                }
                // 统一引擎写流水+扣余额（同事务双写，恒等式由 InventoryService 保证；余额行已被本事务锁定，引擎内重复加锁幂等）
                $this->inventoryService->apply($movements, auth()->id());
                // 回写订单行累计出库量（bcmath 累加）并重算订单状态（全部出完 → 已完成）：
                // 复用第一循环已锁定的行对象——行已被本事务锁定且期间无人可改，
                // 二次查询纯属多余（N 条订单行省 N 次查询；与采购入库/领退料审核回写同构）
                foreach ($shipped as $oiId => $addQty) {
                    $oi = $oiMap[$oiId];
                    $oi->shipped_qty = bcadd((string) $oi->shipped_qty, $addQty, 2);
                    $oi->save();
                }
                $this->orderService->syncStatus($locked->order_id);
                // 置已审核 + 审核人/时间
                $locked->status = SalesOutbound::STATUS_APPROVED;
                $locked->operator = auth()->user()->name ?? '';
                $locked->outbound_at = now();
                $locked->save();
                $result = ['no' => $locked->no];
            }, 2);
        } catch (InventoryException $e) {
            // 余额引擎兜底拒绝（理论上被预校验拦截，防御路径；消息不含商品名时用通用文案）；
            // 走到此分支说明预校验与引擎判定不一致，记 warn 便于排查数据不一致；
            // 语境化翻译为 1409 业务码保持前端口径（不发原始引擎消息，防内部细节外泄）
            Log::warning('销售出库审核被余额引擎兜底拒绝（预校验未拦截，疑似数据不一致）', [
                'no' => $outbound->no, 'reason' => $e->getMessage(),
            ]);

            throw new SalesException('库存不足，出库被拒绝', 1409);
        }

        // 状态变更审计日志（事务提交后记）：审核即库存扣减 + 订单回写生效，属库存关键节点
        // （库存笔级明细由 InventoryService 聚合记录，此处仅记单据维度，避免重复）
        Log::info('销售出库单审核通过', ['no' => $result['no'], 'order_id' => $outbound->order_id, 'operator' => auth()->id()]);

        return $result;
    }

    /**
     * 明细业务校验（create/update 共用）：空明细 1401 / 数量≤0 422 / 负价 1411 / 原料禁售 422 /
     * 重复 1412 / 明细带订单行引用但未携带 order_id 1407
     *
     * 校验未通过抛对应业务码的 SalesException（全局渲染为统一信封，
     * 与原控制器 return fail() 响应等价——数量非正/原料禁售沿用业务码 422 保持前端口径不变）。
     *
     * @param  array  $items  明细行数组
     * @param  bool  $noOrderRef  是否未携带来源订单（true 时明细不得引用订单行，防绕过订单状态联动）
     *
     * @throws SalesException 任一校验未通过
     */
    private function assertOutboundItems(array $items, bool $noOrderRef): void
    {
        if (empty($items)) {
            throw new SalesException('请至少添加一条明细', 1401);
        }
        // 商品批量预取（B-105）：一次 whereIn 拉全明细商品，替代循环内逐行 Product::find 的 N+1 查询
        $products = Product::whereIn('id', collect($items)->pluck('product_id')->unique())->get()->keyBy('id');
        foreach ($items as $item) {
            // 数量正负校验走 bccomp（D-3 铁律：禁浮点参与数量与金额比较；正则已保证入参为两位小数十进制）；
            // 单价经 integer 校验后为整数分，直接整数比较（无浮点参与）
            if (bccomp((string) $item['quantity'], '0', 2) <= 0) {
                throw new SalesException('数量必须大于 0', 422);
            }
            if ((int) $item['price'] < 0) {
                throw new SalesException('价格不能为负数', 1411);
            }
            // 原料禁售（SAL-10）：仅成品/半成品可销售（前端下拉已过滤，后端防御性兜底）
            $product = $products->get($item['product_id']);
            if ($product && $product->type === 'raw_material') {
                throw new SalesException('原料商品不可销售', 422);
            }
        }
        if ($this->hasDuplicateItem($items)) {
            throw new SalesException('明细存在重复商品', 1412);
        }
        // 明细带订单行引用但未携带 order_id → 1407（防绕过订单状态联动）
        if ($noOrderRef && $this->hasOrderItemRef($items)) {
            throw new SalesException('出库明细与订单行不一致', 1407);
        }
    }

    // 明细查重：同商品+同订单行 只允许一行（独立行按商品查重；订单行按 商品+订单行 查重）
    private function hasDuplicateItem(array $items): bool
    {
        $seen = [];
        foreach ($items as $item) {
            $key = ($item['order_item_id'] ?? 0).'-'.$item['product_id'];
            if (isset($seen[$key])) {
                return true;
            }
            $seen[$key] = true;
        }

        return false;
    }

    // 明细是否带订单行引用（order_item_id 非空）
    private function hasOrderItemRef(array $items): bool
    {
        return collect($items)->contains(fn ($i) => ! empty($i['order_item_id'] ?? null));
    }

    /**
     * 订单行校验（create/update 共用，草稿期即拦截；审核期在事务内锁行复核）
     *
     * 校验：行必须属于该订单、商品一致、客户一致、订单可出库、不超剩余量。
     *
     * @param  int  $orderId  来源销售订单 ID（来源前端入参 order_id，经 exists 校验）
     * @param  int  $customerId  出库单客户 ID（来源前端入参，须与来源订单一致）
     * @param  array  $items  明细行数组
     *
     * @throws SalesException 任一校验未通过（业务码统一 1407，文案区分具体原因）
     */
    private function assertOrderItems(int $orderId, int $customerId, array $items): void
    {
        $order = SalesOrder::with('items')->find($orderId);
        if (! $order || ! in_array($order->status, [SalesOrder::STATUS_APPROVED, SalesOrder::STATUS_PARTIAL], true)) {
            throw new SalesException('该订单当前不可出库', 1407);
        }
        // 出库单客户必须与来源订单一致（防跨客户挂单，前端禁用选择器仅 UI 层）
        if ((int) $order->customer_id !== $customerId) {
            throw new SalesException('客户与来源订单不一致', 1407);
        }
        foreach ($items as $item) {
            if (! isset($item['order_item_id'])) {
                continue; // 独立行不受订单约束
            }
            $oi = $order->items->firstWhere('id', $item['order_item_id']);
            if (! $oi || $oi->product_id !== $item['product_id']) {
                throw new SalesException('出库明细与订单行不一致', 1407);
            }
            // 剩余量 = 订购数 - 已出库累计；超量拒绝（草稿期即拦截）
            $remaining = bcsub((string) $oi->quantity, (string) $oi->shipped_qty, 2);
            if (bccomp((string) $item['quantity'], $remaining, 2) > 0) {
                throw new SalesException('出库数量超过订单剩余数量', 1407);
            }
        }
    }
}
