<?php

// 销售订单服务：草稿创建/更新/删除、审核、关闭（事务+行锁+状态前置校验）+ 金额分单位整数运算 + 出库后订单状态重算

namespace App\Services;

use App\Exceptions\SalesException;
use App\Models\DocumentSequence;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Support\Cents;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 销售订单领域服务（D-1：控制器写操作全部下沉至此）
 *
 * 承接原控制器的单据写流程：草稿 CRUD、审核、关闭，全部在 DB::transaction 内
 * 「锁单据头行 → 复查状态 → 变更」三步执行（锁序铁律：单据头 → 明细 → 库存）。
 * 金额运算沿用 R2 分单位整数口径（lineAmount/calculateTotal 经 Cents half-up 舍入，禁浮点）。
 * 业务失败统一抛 SalesException（业务码沿用原口径 1401~1405/1411/1412，数量非正与
 * 原料禁售沿用业务码 422），由全局异常处理器渲染 {code, message, data} 信封，
 * 与原控制器 fail() 响应字节级等价。
 * 非线程安全：同一订单的并发写依赖数据库行锁串行化，勿在事务外组合调用多个写方法。
 */
class SalesOrderService
{
    public function __construct(private DocumentSequenceService $sequenceService) {}

    /**
     * 行金额 = 数量 × 单价（分），half-up 取整到整数分
     *
     * 数量为 decimal(12,2)（2 位小数）、单价为 bigint 分整数，乘积可能产生小数分
     * （如 1.55 × 123 分 = 190.65 分）——统一走 Cents::multiply 四舍五入到整数分落 bigint 列。
     *
     * @param  string  $quantity  数量（decimal(12,2) 字符串，来源前端入参/模型 cast，可含 2 位小数）
     * @param  int|string  $priceCents  单价（分单位整数，来源前端 integer 校验入参或 bigint 列读取；允许 0，负数由业务校验拦截）
     * @return int 行金额（分单位整数，恒 >= 0，半分进位）
     */
    public function lineAmount(string $quantity, int|string $priceCents): int
    {
        return Cents::multiply($quantity, $priceCents);
    }

    /**
     * 明细金额合计 = Σ 行金额（整数分逐行累加——行金额已 half-up 为整数分，无浮点参与）
     *
     * @param  array  $items  明细行数组，每行含 quantity/price
     * @return int 合计金额（分单位整数）
     */
    public function calculateTotal(array $items): int
    {
        $total = 0;
        foreach ($items as $item) {
            // 整数分累加（PHP int 精确，金额域远低于 int64 上限）
            $total += $this->lineAmount((string) $item['quantity'], $item['price']);
        }

        return $total;
    }

    /**
     * 新建销售订单草稿（原控制器 store 下沉）
     *
     * 校验链：明细业务校验（1401/422/1411/1412）→ 事务内持久序列取号建单 + 建明细。
     * 幂等性：单号撞号由序列服务自动换号重试；事务死锁(1213)整体回滚后重跑重取号。
     *
     * @param  array  $data  已过 SaveSalesOrderRequest 格式校验的载荷（customer_id/order_date/expected_date/remark/items）
     * @return SalesOrder 新建的订单模型（含单号，供控制器回显）
     *
     * @throws SalesException 明细空 1401 / 数量≤0 422 / 负价 1411 / 原料禁售 422 / 重复商品 1412
     */
    public function create(array $data): SalesOrder
    {
        // 业务码校验（422 仅格式层，业务冲突走业务码；明细校验逻辑见 assertBusinessItems）；
        // items 键可整体缺失（rules 未 required），?? 兜底与出库单口径一致
        $this->assertBusinessItems($data['items'] ?? []);

        // 事务第 2 参数为死锁(1213)重试次数（机理同 BomController::store：序列行首建间隙锁
        // 死锁败方整体回滚后重跑闭包重新取号，幂等安全）
        $order = DB::transaction(function () use ($data) {
            // 单号走持久序列（撞号自动换号；删除不回退；老库 max 衔接）
            $order = $this->sequenceService->nextNoByConfig(
                DocumentSequence::TYPE_SO,
                fn (string $no) => SalesOrder::create([
                    'no' => $no,
                    'customer_id' => $data['customer_id'],
                    'order_date' => $data['order_date'],
                    'expected_date' => $data['expected_date'] ?? null,
                    'status' => SalesOrder::STATUS_DRAFT,
                    'total_amount' => $this->calculateTotal($data['items'] ?? []),
                    'remark' => $data['remark'] ?? null,
                    'created_by' => auth()->id(),
                ]),
                fn (string $prefix, string $dateKey) => ($no = SalesOrder::where('no', 'like', $prefix.date('Ymd').'%')
                    ->orderByDesc('no')->value('no')) ? DocumentSequenceService::seqFromNo($no, $prefix, $dateKey) : 0,
            );
            $order->items()->createMany(array_map(fn ($i) => [
                'product_id' => $i['product_id'],
                'quantity' => $i['quantity'],
                'price' => $i['price'],
                'shipped_qty' => 0,
                'amount' => $this->lineAmount((string) $i['quantity'], $i['price']),
            ], $data['items'] ?? []));

            return $order;
        }, 2);

        // 单据创建审计日志（事务提交后记）：单号 + 操作人
        Log::info('销售订单创建成功', ['no' => $order->no, 'created_by' => auth()->id()]);

        return $order;
    }

    /**
     * 更新销售订单草稿（原控制器 update 下沉）：仅草稿可改（1402）；items 全量替换；金额重算
     *
     * 事务内锁单据头行复查状态：与审核并发时防止改到正在审核的单（幂等 1402）。
     *
     * @param  SalesOrder  $order  路由绑定的订单模型（草稿状态才可改）
     * @param  array  $data  已过 SaveSalesOrderRequest 格式校验的载荷
     *
     * @throws SalesException 非草稿 1402 / 明细业务校验 1401/422/1411/1412
     */
    public function update(SalesOrder $order, array $data): void
    {
        // 状态前置校验（不进事务，快速失败）：非草稿直接拒绝
        if ($order->status !== SalesOrder::STATUS_DRAFT) {
            throw new SalesException('已审核订单不可修改', 1402);
        }
        // 明细业务校验与 create 共用同一私有方法，保证两处校验口径一致（见 assertBusinessItems）
        $this->assertBusinessItems($data['items'] ?? []);

        DB::transaction(function () use ($order, $data) {
            // 锁订单行复查状态：与审核并发时防止改到正在审核的单（幂等 1402）
            $locked = SalesOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== SalesOrder::STATUS_DRAFT) {
                throw new SalesException('已审核订单不可修改', 1402);
            }
            $locked->update([
                'customer_id' => $data['customer_id'],
                'order_date' => $data['order_date'],
                'expected_date' => $data['expected_date'] ?? null,
                'total_amount' => $this->calculateTotal($data['items'] ?? []),
                'remark' => $data['remark'] ?? $locked->remark,
            ]);
            // 明细全量替换（草稿单无流水引用，直接重建）
            $locked->items()->delete();
            $locked->items()->createMany(array_map(fn ($i) => [
                'product_id' => $i['product_id'],
                'quantity' => $i['quantity'],
                'price' => $i['price'],
                'shipped_qty' => 0,
                'amount' => $this->lineAmount((string) $i['quantity'], $i['price']),
            ], $data['items'] ?? []));
        });
    }

    /**
     * 删除销售订单草稿（原控制器 destroy 下沉）：仅草稿可删（1403）
     *
     * 事务内锁单据头行复查状态：与审核并发时防止删到正在审核的单（幂等 1403）。
     *
     * @param  SalesOrder  $order  路由绑定的订单模型（内存模型持单号供审计日志追溯）
     *
     * @throws SalesException 非草稿 1403
     */
    public function delete(SalesOrder $order): void
    {
        // 状态前置校验（不进事务，快速失败）：非草稿直接拒绝
        if ($order->status !== SalesOrder::STATUS_DRAFT) {
            throw new SalesException('已审核订单不可删除', 1403);
        }
        DB::transaction(function () use ($order) {
            // 锁订单行复查状态：与审核并发时防止删到正在审核的单（幂等 1403）
            $locked = SalesOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== SalesOrder::STATUS_DRAFT) {
                throw new SalesException('已审核订单不可删除', 1403);
            }
            $locked->delete();
        });

        // 单据删除审计日志（事务提交后记）：内存模型仍持有单号，可用于追溯
        Log::info('销售订单草稿删除', ['no' => $order->no, 'operator' => auth()->id()]);
    }

    /**
     * 审核销售订单（原控制器 approve 下沉）：仅草稿可审（幂等 1404）；置已审核 + approved_at + 创建人
     *
     * 审核是订单生效节点，审核后方可生成出库单。
     *
     * @param  SalesOrder  $order  路由绑定的订单模型
     *
     * @throws SalesException 非草稿（含重复审核）1404
     */
    public function approve(SalesOrder $order): void
    {
        // 状态前置校验（不进事务，快速失败）：同一订单重复审核在此判重（幂等）
        if ($order->status !== SalesOrder::STATUS_DRAFT) {
            throw new SalesException('该订单已审核', 1404);
        }
        DB::transaction(function () use ($order) {
            // 锁订单行：同一订单重复审核在此判重（幂等）
            $locked = SalesOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== SalesOrder::STATUS_DRAFT) {
                throw new SalesException('该订单已审核', 1404);
            }
            $locked->status = SalesOrder::STATUS_APPROVED;
            $locked->approved_at = now();
            $locked->created_by = $locked->created_by ?? auth()->id();
            $locked->save();
        });

        // 状态变更审计日志（事务提交后记）：审核是订单生效节点，后续可生成出库单
        Log::info('销售订单审核通过', ['no' => $order->no, 'operator' => auth()->id()]);
    }

    /**
     * 关闭销售订单（原控制器 close 下沉）：仅已审核/部分出库可关闭（1405）；置关闭 + closed_at
     *
     * 关闭后不可再生成出库单，属不可逆业务节点。
     *
     * @param  SalesOrder  $order  路由绑定的订单模型
     *
     * @throws SalesException 非已审核/部分出库状态 1405
     */
    public function close(SalesOrder $order): void
    {
        // 状态前置校验（不进事务，快速失败）：草稿/已完成/已关闭均不可关闭
        if (! in_array($order->status, [SalesOrder::STATUS_APPROVED, SalesOrder::STATUS_PARTIAL], true)) {
            throw new SalesException('当前状态不可关闭', 1405);
        }
        DB::transaction(function () use ($order) {
            // 锁订单行复查状态：与出库审核并发时防止关闭正在出库的订单
            $locked = SalesOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if (! in_array($locked->status, [SalesOrder::STATUS_APPROVED, SalesOrder::STATUS_PARTIAL], true)) {
                throw new SalesException('当前状态不可关闭', 1405);
            }
            $locked->status = SalesOrder::STATUS_CLOSED;
            $locked->closed_at = now();
            $locked->save();
        });

        // 状态变更审计日志（事务提交后记）：关闭后订单不可再生成出库单，属不可逆业务节点
        Log::info('销售订单关闭', ['no' => $order->no, 'operator' => auth()->id()]);
    }

    /**
     * 重算订单状态：全部订单行 shipped_qty >= quantity → 已完成；否则 → 部分出库
     *
     * 仅对 已审核/部分出库 的订单生效（已完成/关闭/草稿不扰动）。
     * 由销售出库单审核在事务内调用（回写 shipped_qty 之后）。
     */
    public function syncStatus(?int $orderId): void
    {
        if (! $orderId) {
            return;
        }
        $order = SalesOrder::whereKey($orderId)->firstOrFail();
        if (! in_array($order->status, [SalesOrder::STATUS_APPROVED, SalesOrder::STATUS_PARTIAL], true)) {
            return;
        }
        // 全部行已出完才可判「已完成」（数量带 2 位小数，bcmath 比较防浮点）
        $allDone = $order->items()->get()->every(
            fn ($i) => bccomp((string) $i->shipped_qty, (string) $i->quantity, 2) >= 0
        );
        $order->status = $allDone ? SalesOrder::STATUS_COMPLETED : SalesOrder::STATUS_PARTIAL;
        $order->save();
    }

    /**
     * 明细业务校验（create/update 共用）：空明细 1401 / 数量≤0 422 / 负价 1411 / 原料禁售 422 / 重复商品 1412
     *
     * 校验未通过抛对应业务码的 SalesException（全局渲染为统一信封，
     * 与原控制器 return fail() 响应等价——数量非正/原料禁售沿用业务码 422 保持前端口径不变）。
     *
     * @param  array  $items  明细行数组（每行含 product_id/quantity/price）
     *
     * @throws SalesException 任一校验未通过
     */
    private function assertBusinessItems(array $items): void
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
        if ($this->hasDuplicateProduct($items)) {
            throw new SalesException('明细存在重复商品', 1412);
        }
    }

    // 明细查重：同商品只允许一行
    private function hasDuplicateProduct(array $items): bool
    {
        $seen = [];
        foreach ($items as $item) {
            if (isset($seen[$item['product_id']])) {
                return true;
            }
            $seen[$item['product_id']] = true;
        }

        return false;
    }
}
