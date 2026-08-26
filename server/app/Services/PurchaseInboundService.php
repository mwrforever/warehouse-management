<?php

// 采购入库单服务：草稿创建/更新/删除、审核（核心：事务内 InventoryService 加库存 + 订单行回写 + 订单状态重算）

namespace App\Services;

use App\Exceptions\PurchaseException;
use App\Models\DocumentSequence;
use App\Models\PurchaseInbound;
use App\Models\PurchaseInboundItem;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 采购入库单领域服务（D-1：控制器写操作全部下沉至此）
 *
 * 承接原控制器的单据写流程：草稿 CRUD 与审核。审核为库存关键节点，单事务内完成
 * 「锁入库单行 → 批量预锁订单行 → 批量预锁订单头 → InventoryService 写流水加库存
 * → 回写订单行 received_qty → 订单状态重算 → 置已审核」（锁序铁律：单据头 → 明细 → 库存，
 * 订单行/订单头批量按索引序获取，无 ABBA 交叉锁）。
 * 业务失败统一抛 PurchaseException（业务码沿用原口径 1301/1302/1307~1312），
 * 由全局异常处理器渲染 {code, message, data} 信封，与原控制器 fail() 响应字节级等价。
 * 库存写入口唯一：一律经 InventoryService，本服务不直接写库存表。
 * 非线程安全：同一单据的并发写依赖数据库行锁串行化，勿在事务外组合调用多个写方法。
 */
class PurchaseInboundService
{
    public function __construct(
        private InventoryService $inventoryService,
        private PurchaseOrderService $orderService,
        private DocumentSequenceService $sequenceService,
        private CostPriceService $costPriceService,
    ) {}

    /**
     * 新建采购入库单草稿（原控制器 store 下沉）：仓库/库位必填 1307；订单行剩余量校验 1308；重复商品 1312
     *
     * 校验链：仓库/库位业务码 → 明细过滤（从订单生成的 0 数量行剔除）→ 明细逐行业务码
     * → 订单行归属/供应商/剩余量校验 → 事务内持久序列取号建单 + 建明细。
     * 幂等性：单号撞号由序列服务自动换号重试；事务死锁(1213)整体回滚后重跑重取号。
     *
     * @param  array  $data  已过 SaveInboundRequest 格式校验的载荷
     *                       （supplier_id/warehouse_id/location_id/order_id/remark/items）
     * @return PurchaseInbound 新建的入库单模型（含单号，供控制器回显）
     *
     * @throws PurchaseException 仓库/库位缺失 1307 / 明细空 1301 / 数量非法 1302 / 负价 1311 /
     *                           重复明细 1312 / 订单行不一致或不可入库或超量 1308
     */
    public function create(array $data): PurchaseInbound
    {
        // 业务码校验（仓库/库位必填走业务码而非 422，与 spec 1307 一致）：
        // empty 与原 request->filled 等价（'' 经全局中间件转 null，0 会被 exists 规则先行 422 拦截）
        if (empty($data['warehouse_id']) || empty($data['location_id'])) {
            throw new PurchaseException('仓库与库位不能为空', 1307);
        }
        // 从订单生成：数量 0 = 本次不收货（剔除不落库）；手动新增：仍要求 > 0（防空数量单据）；
        // items 键可整体缺失（rules 未 required），?? 兜底防 undefined key
        $items = $this->normalizeItems($data);
        $this->assertInboundItems($items, ! empty($data['order_id']));
        // 关联订单行校验：行归属/订单可入库/供应商一致/不超剩余量（草稿期即拦截，审核期再锁行复核）
        if ($orderId = $data['order_id'] ?? null) {
            $this->assertOrderItems($orderId, (int) $data['supplier_id'], $items);
        }

        // 事务第 2 参数为死锁(1213)重试次数（机理同 BomController::store：序列行首建间隙锁
        // 死锁败方整体回滚后重跑闭包重新取号，幂等安全）
        $inbound = DB::transaction(function () use ($data, $items) {
            // 单号走持久序列（撞号自动换号；删除不回退；老库 max 衔接）
            $inbound = $this->sequenceService->nextNoByConfig(
                DocumentSequence::TYPE_PI,
                fn (string $no) => PurchaseInbound::create([
                    'no' => $no,
                    'supplier_id' => $data['supplier_id'],
                    'warehouse_id' => $data['warehouse_id'],
                    'location_id' => $data['location_id'],
                    'order_id' => $data['order_id'] ?? null,
                    'status' => PurchaseInbound::STATUS_DRAFT,
                    'total_amount' => $this->orderService->calculateTotal($items),
                    'remark' => $data['remark'] ?? null,
                ]),
                fn (string $prefix, string $dateKey) => ($no = PurchaseInbound::where('no', 'like', $prefix.date('Ymd').'%')
                    ->orderByDesc('no')->value('no')) ? DocumentSequenceService::seqFromNo($no, $prefix, $dateKey) : 0,
            );
            $inbound->items()->createMany(array_map(fn ($i) => [
                'product_id' => $i['product_id'],
                'quantity' => $i['quantity'],
                'price' => $i['price'],
                'amount' => $this->orderService->lineAmount((string) $i['quantity'], $i['price']),
                'order_item_id' => $i['order_item_id'] ?? null,
            ], $items));

            return $inbound;
        }, 2);

        // 单据创建审计日志（事务提交后记）：单号 + 来源订单 + 操作人
        Log::info('采购入库单创建成功', ['no' => $inbound->no, 'order_id' => $inbound->order_id, 'created_by' => auth()->id()]);

        return $inbound;
    }

    /**
     * 更新采购入库单草稿（原控制器 update 下沉）：仅草稿可改（1309）；items 全量替换；订单行校验同 create
     *
     * 事务内锁单据头行复查状态：与审核并发时防止改到正在审核的单（幂等 1309）。
     *
     * @param  PurchaseInbound  $inbound  路由绑定的入库单模型（草稿状态才可改）
     * @param  array  $data  已过 SaveInboundRequest 格式校验的载荷
     *
     * @throws PurchaseException 非草稿 1309 / 其余业务码同 create（1307/1301/1302/1311/1312/1308）
     */
    public function update(PurchaseInbound $inbound, array $data): void
    {
        // 状态前置校验（不进事务，快速失败）：非草稿直接拒绝
        if ($inbound->status !== PurchaseInbound::STATUS_DRAFT) {
            throw new PurchaseException('已审核单据不可修改', 1309);
        }
        // 业务码校验口径与 create 完全一致（同一组私有方法，防两处口径漂移）
        if (empty($data['warehouse_id']) || empty($data['location_id'])) {
            throw new PurchaseException('仓库与库位不能为空', 1307);
        }
        $items = $this->normalizeItems($data);
        $this->assertInboundItems($items, ! empty($data['order_id']));
        if ($orderId = $data['order_id'] ?? null) {
            $this->assertOrderItems($orderId, (int) $data['supplier_id'], $items);
        }

        DB::transaction(function () use ($inbound, $data, $items) {
            // 锁入库单行复查状态：与审核并发时防止改到正在审核的单（幂等 1309）
            $locked = PurchaseInbound::whereKey($inbound->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== PurchaseInbound::STATUS_DRAFT) {
                throw new PurchaseException('已审核单据不可修改', 1309);
            }
            $locked->update([
                'supplier_id' => $data['supplier_id'],
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
     * 删除采购入库单草稿（原控制器 destroy 下沉）：仅草稿可删（1309）
     *
     * 事务内锁单据头行复查状态：与审核并发时防止删到正在审核的单（幂等 1309）。
     *
     * @param  PurchaseInbound  $inbound  路由绑定的入库单模型（内存模型持单号供审计日志追溯）
     *
     * @throws PurchaseException 非草稿 1309
     */
    public function delete(PurchaseInbound $inbound): void
    {
        // 状态前置校验（不进事务，快速失败）：非草稿直接拒绝
        if ($inbound->status !== PurchaseInbound::STATUS_DRAFT) {
            throw new PurchaseException('已审核单据不可删除', 1309);
        }
        DB::transaction(function () use ($inbound) {
            // 锁入库单行复查状态：与审核并发时防止删到正在审核的单（幂等 1309）
            $locked = PurchaseInbound::whereKey($inbound->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== PurchaseInbound::STATUS_DRAFT) {
                throw new PurchaseException('已审核单据不可删除', 1309);
            }
            $locked->delete();
        });

        // 单据删除审计日志（事务提交后记）：内存模型仍持有单号，可用于追溯
        Log::info('采购入库单草稿删除', ['no' => $inbound->no, 'operator' => auth()->id()]);
    }

    /**
     * 审核采购入库单（原控制器 approve 下沉，核心库存链路）
     *
     * 单事务内「锁单幂等（1310）→ 批量预锁订单行验剩余量 → 批量预锁订单头验状态
     * → InventoryService 写流水加库存 → 回写 received_qty → 订单状态重算 → 置已审核」，
     * 任一步失败整体回滚。审核成功后失效成本价缓存（失败仅记 warn，不回滚已提交审核）。
     *
     * @param  PurchaseInbound  $inbound  路由绑定的入库单模型
     * @return array{no: string} 单号（供控制器响应回显）
     *
     * @throws PurchaseException 重复审核 1310 / 订单行不一致 1308 / 订单不可入库 1308 / 超剩余量 1308
     */
    public function approve(PurchaseInbound $inbound): array
    {
        $result = null;
        // attempts=2：死锁自动重试一次（B-3 纵深防御；余额行锁序已由 InventoryService 排序规范化统一）
        DB::transaction(function () use ($inbound, &$result) {
            // 锁入库单行：同一单据重复审核在此判重（幂等 1310）
            $locked = PurchaseInbound::whereKey($inbound->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === PurchaseInbound::STATUS_APPROVED) {
                throw new PurchaseException('该入库单已审核', 1310);
            }
            $movements = [];
            $received = []; // [order_item_id => 本次累计入库量] 待回写
            // 循环前批量预锁（P1-2，宪法 §4.2.4 建议）：订单行按 id 一次锁定、订单头按去重
            // order_id 批量锁一次（同订单多明细不再重复锁同一行）；锁序保持「订单行→订单头」
            // 全局方向，批量按索引序获取还消除了「两单明细顺序相反」时的交叉锁窗口（无 ABBA 新方向）
            $orderItemIds = $locked->items->pluck('order_item_id')->filter()->values()->all();
            /** @var Collection<int, PurchaseOrderItem> $oiMap 已锁定的订单行（回写复用，免二次查询） */
            $oiMap = $orderItemIds === []
                ? collect()
                : PurchaseOrderItem::query()->whereIn('id', $orderItemIds)->lockForUpdate()->get()->keyBy('id');
            $orderIds = $oiMap->pluck('order_id')->unique()->values()->all();
            /** @var Collection<int, PurchaseOrder> $orderMap 已锁定的订单头（状态复核复用） */
            $orderMap = $orderIds === []
                ? collect()
                : PurchaseOrder::query()->whereIn('id', $orderIds)->lockForUpdate()->get()->keyBy('id');
            /** @var PurchaseInboundItem $item */
            foreach ($locked->items as $item) {
                if ($item->order_item_id) {
                    // 复核订单行：防并发超收（并发审核同一行已在上方批量锁定串行化）
                    $oi = $oiMap->get($item->order_item_id);
                    // 防御：订单行已被删除或商品不一致（数据完整性，归 1308 语义族）
                    if (! $oi || $oi->product_id !== $item->product_id) {
                        throw new PurchaseException('入库明细与订单行不一致', 1308);
                    }
                    // 复核订单头状态：关闭/已完成/草稿均不可入库（1308）；
                    // 头缺失（FK cascade 下不可达的防御路径）与 firstOrFail 同 404 语义
                    $order = $orderMap->get($oi->order_id);
                    if (! $order) {
                        throw (new ModelNotFoundException)->setModel(PurchaseOrder::class, $oi->order_id);
                    }
                    if (! in_array($order->status, [PurchaseOrder::STATUS_APPROVED, PurchaseOrder::STATUS_PARTIAL], true)) {
                        throw new PurchaseException('该订单当前不可入库', 1308);
                    }
                    // 剩余量 = 订购数 - 已入库累计；本次入库量超剩余 → 1308 整体回滚（防超收）
                    $remaining = bcsub((string) $oi->quantity, (string) $oi->received_qty, 2);
                    if (bccomp((string) $item->quantity, $remaining, 2) > 0) {
                        throw new PurchaseException('入库数量超过订单剩余数量', 1308);
                    }
                    $received[$oi->id] = bcadd((string) ($received[$oi->id] ?? '0'), (string) $item->quantity, 2);
                }
                $movements[] = [
                    'product_id' => $item->product_id,
                    'warehouse_id' => $locked->warehouse_id,
                    'location_id' => $locked->location_id,
                    'direction' => 1,
                    'quantity' => $item->quantity,
                    'source_type' => 'purchase_inbound',
                    'source_id' => $locked->id,
                    'source_no' => $locked->no,
                    'remark' => '采购入库',
                ];
            }
            // 统一引擎写流水+余额（同事务双写，恒等式由 InventoryService 保证）
            $this->inventoryService->apply($movements, auth()->id());
            // 回写订单行累计入库量（bcmath 累加）并重算订单状态（全部入完 → 已完成）：
            // 复用第一循环已锁定的行对象——行已被本事务锁定且期间无人可改，
            // 二次查询纯属多余（N 条订单行省 N 次查询；与领退料审核回写同构）
            foreach ($received as $oiId => $addQty) {
                $oi = $oiMap[$oiId];
                $oi->received_qty = bcadd((string) $oi->received_qty, $addQty, 2);
                $oi->save();
            }
            $this->orderService->syncStatus($locked->order_id);
            // 置已审核 + 审核人/时间
            $locked->status = PurchaseInbound::STATUS_APPROVED;
            $locked->operator = auth()->user()->name ?? '';
            $locked->inbound_at = now();
            $locked->save();
            $result = ['no' => $locked->no];
        }, 2);
        // 状态变更审计日志（事务提交后记）：审核即库存入账 + 订单回写生效，属库存关键节点
        // （库存笔级明细由 InventoryService 聚合记录，此处仅记单据维度，避免重复）
        Log::info('采购入库单审核通过', ['no' => $result['no'], 'order_id' => $inbound->order_id, 'operator' => auth()->id()]);
        // 审核成功（事务已提交）失效成本价缓存：审核是价格集合的唯一变化点（见 CostPriceService 失效契约）；
        // 回滚路径抛异常跳过此处，缓存最多早清（下次访问重建），无脏读风险
        try {
            $this->costPriceService->flush();
        } catch (\Throwable $e) {
            // 缓存层失败不回滚已提交的审核（单据已生效）：最坏缓存脏一次、下次访问重建；
            // 若向调用方抛错，前端会误判审核失败而重试，撞 1310 幂等造成困惑，故吞异常仅记 warn
            Log::warning('采购入库审核后成本价缓存失效失败，已忽略：'.$e->getMessage());
        }

        return $result;
    }

    /**
     * 明细规整（create/update 共用）：从订单生成的 0 数量行剔除、手动新增原样保留
     *
     * 从订单生成：数量 0 = 本次不收货（剔除不落库）；手动新增：0 保留由业务校验拦截
     * （防空数量单据）；items 键可整体缺失（rules 未 required），?? 兜底防 undefined key。
     *
     * @param  array  $data  已过格式校验的载荷
     * @return array 规整后的明细行数组
     */
    private function normalizeItems(array $data): array
    {
        $fromOrder = ! empty($data['order_id']);

        return $fromOrder
            ? array_values(array_filter($data['items'] ?? [], fn ($i) => bccomp((string) $i['quantity'], '0', 2) !== 0))
            : ($data['items'] ?? []);
    }

    /**
     * 明细业务校验（create/update 共用）：空明细 1301 / 数量非法 1302 / 负价 1311 / 重复 1312 /
     * 明细带订单行引用但未携带 order_id 1308
     *
     * 校验未通过抛对应业务码的 PurchaseException（全局渲染为统一信封，
     * 与原控制器 return fail() 响应等价）。
     *
     * @param  array  $items  normalizeItems 规整后的明细行数组
     * @param  bool  $fromOrder  是否从订单生成（true 时数量允许 0 已被剔除，仅拦负数；false 要求 > 0）
     *
     * @throws PurchaseException 任一校验未通过
     */
    private function assertInboundItems(array $items, bool $fromOrder): void
    {
        if (empty($items)) {
            throw new PurchaseException('请至少添加一条明细', 1301);
        }
        foreach ($items as $item) {
            // 从订单允许 0（已在规整时剔除），仅拦负数；手动新增 0 仍拒绝（bcmath 精确比较）
            $cmp = bccomp((string) $item['quantity'], '0', 2);
            if ($cmp < 0 || (! $fromOrder && $cmp === 0)) {
                throw new PurchaseException($fromOrder ? '数量不能小于 0' : '数量必须大于 0', 1302);
            }
            // 单价经 integer 校验后为整数分，直接整数比较（无浮点参与）
            if ((int) $item['price'] < 0) {
                throw new PurchaseException('价格不能为负数', 1311);
            }
        }
        if ($this->hasDuplicateItem($items)) {
            throw new PurchaseException('明细存在重复商品', 1312);
        }
        // 明细带订单行引用但未携带 order_id → 1308（防绕过订单状态联动）
        if (! $fromOrder && $this->hasOrderItemRef($items)) {
            throw new PurchaseException('入库明细与订单行不一致', 1308);
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
     * 校验：行必须属于该订单、商品一致、供应商一致、订单可入库、不超剩余量。
     *
     * @param  int  $orderId  来源采购订单 ID（来源前端入参 order_id，经 exists 校验）
     * @param  int  $supplierId  入库单供应商 ID（来源前端入参，须与来源订单一致）
     * @param  array  $items  明细行数组
     *
     * @throws PurchaseException 任一校验未通过（业务码统一 1308，文案区分具体原因）
     */
    private function assertOrderItems(int $orderId, int $supplierId, array $items): void
    {
        $order = PurchaseOrder::with('items')->find($orderId);
        if (! $order || ! in_array($order->status, [PurchaseOrder::STATUS_APPROVED, PurchaseOrder::STATUS_PARTIAL], true)) {
            throw new PurchaseException('该订单当前不可入库', 1308);
        }
        // 入库单供应商必须与来源订单一致（防跨供应商挂单，前端禁用选择器仅 UI 层）
        if ((int) $order->supplier_id !== $supplierId) {
            throw new PurchaseException('供应商与来源订单不一致', 1308);
        }
        foreach ($items as $item) {
            if (! isset($item['order_item_id'])) {
                continue; // 独立行不受订单约束
            }
            $oi = $order->items->firstWhere('id', $item['order_item_id']);
            if (! $oi || $oi->product_id !== $item['product_id']) {
                throw new PurchaseException('入库明细与订单行不一致', 1308);
            }
            // 剩余量 = 订购数 - 已入库累计；超量拒绝（草稿期即拦截）
            $remaining = bcsub((string) $oi->quantity, (string) $oi->received_qty, 2);
            if (bccomp((string) $item['quantity'], $remaining, 2) > 0) {
                throw new PurchaseException('入库数量超过订单剩余数量', 1308);
            }
        }
    }
}
