<?php

// 采购订单服务：草稿创建/更新/删除、审核、关闭（事务+行锁+状态前置校验）+ 金额分单位整数运算 + 入库后订单状态重算

namespace App\Services;

use App\Exceptions\PurchaseException;
use App\Models\DocumentSequence;
use App\Models\PurchaseOrder;
use App\Support\Cents;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 采购订单领域服务（D-1：控制器写操作全部下沉至此）
 *
 * 承接原控制器的单据写流程：草稿 CRUD、审核、关闭，全部在 DB::transaction 内
 * 「锁单据头行 → 复查状态 → 变更」三步执行（锁序铁律：单据头 → 明细 → 库存）。
 * 业务失败统一抛 PurchaseException（业务码沿用原口径 1301~1306/1311/1312），
 * 由全局异常处理器渲染 {code, message, data} 信封，与原控制器 fail() 响应字节级等价。
 * 非线程安全：同一订单的并发写依赖数据库行锁串行化，勿在事务外组合调用多个写方法。
 */
class PurchaseOrderService
{
    public function __construct(private DocumentSequenceService $sequenceService) {}

    /**
     * 行金额 = 数量 × 单价（分），half-up 取整到整数分
     *
     * 数量为 decimal(12,2)（2 位小数）、单价为 bigint 分整数，乘积可能产生小数分
     * （如 1.55 × 123 分 = 190.65 分）——统一走 Cents::multiply 四舍五入到整数分落 bigint 列。
     *
     * @param  string  $quantity  数量（decimal(12,2) 字符串，来源前端入参/模型 cast，可含 2 位小数）
     * @param  int|string  $priceCents  含税单价（分单位整数，来源前端 integer 校验入参或 bigint 列读取；允许 0，负数由业务校验拦截）
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
     * 新建采购订单草稿（原控制器 store 下沉）
     *
     * 校验链：明细业务校验（1301/1302/1311/1312）→ 事务内持久序列取号建单 + 建明细。
     * 幂等性：单号撞号由序列服务自动换号重试；事务死锁(1213)整体回滚后重跑重取号。
     *
     * @param  array  $data  已过 SaveOrderRequest 格式校验的载荷（supplier_id/order_date/expected_date/remark/items）
     * @return PurchaseOrder 新建的订单模型（含单号，供控制器回显）
     *
     * @throws PurchaseException 明细空 1301 / 数量≤0 1302 / 负价 1311 / 重复商品 1312
     */
    public function create(array $data): PurchaseOrder
    {
        // 业务码校验（422 仅格式层，业务冲突走业务码；明细校验逻辑见 assertBusinessItems）；
        // items 键可整体缺失（rules 未 required），?? 兜底与入库单口径一致
        $this->assertBusinessItems($data['items'] ?? []);

        // 事务第 2 参数为死锁(1213)重试次数（机理同 BomController::store：序列行首建间隙锁
        // 死锁败方整体回滚后重跑闭包重新取号，幂等安全）
        $order = DB::transaction(function () use ($data) {
            // 单号走持久序列（撞号自动换号；删除不回退；老库 max 衔接）
            $order = $this->sequenceService->nextNoByConfig(
                DocumentSequence::TYPE_PO,
                fn (string $no) => PurchaseOrder::create([
                    'no' => $no,
                    'supplier_id' => $data['supplier_id'],
                    'order_date' => $data['order_date'],
                    'expected_date' => $data['expected_date'] ?? null,
                    'status' => PurchaseOrder::STATUS_DRAFT,
                    'total_amount' => $this->calculateTotal($data['items'] ?? []),
                    'remark' => $data['remark'] ?? null,
                    'created_by' => auth()->id(),
                ]),
                fn (string $prefix, string $dateKey) => ($no = PurchaseOrder::where('no', 'like', $prefix.date('Ymd').'%')
                    ->orderByDesc('no')->value('no')) ? DocumentSequenceService::seqFromNo($no, $prefix, $dateKey) : 0,
            );
            $order->items()->createMany(array_map(fn ($i) => [
                'product_id' => $i['product_id'],
                'quantity' => $i['quantity'],
                'price' => $i['price'],
                'received_qty' => 0,
                'amount' => $this->lineAmount((string) $i['quantity'], $i['price']),
            ], $data['items'] ?? []));

            return $order;
        }, 2);

        // 单据创建审计日志（事务提交后记）：单号 + 操作人
        Log::info('采购订单创建成功', ['no' => $order->no, 'created_by' => auth()->id()]);

        return $order;
    }

    /**
     * 更新采购订单草稿（原控制器 update 下沉）：仅草稿可改（1303）；items 全量替换；金额重算
     *
     * 事务内锁单据头行复查状态：与审核并发时防止改到正在审核的单（幂等 1303）。
     *
     * @param  PurchaseOrder  $order  路由绑定的订单模型（草稿状态才可改）
     * @param  array  $data  已过 SaveOrderRequest 格式校验的载荷
     *
     * @throws PurchaseException 非草稿 1303 / 明细业务校验 1301/1302/1311/1312
     */
    public function update(PurchaseOrder $order, array $data): void
    {
        // 状态前置校验（不进事务，快速失败）：非草稿直接拒绝
        if ($order->status !== PurchaseOrder::STATUS_DRAFT) {
            throw new PurchaseException('已审核订单不可修改', 1303);
        }
        // 明细业务校验与 create 共用同一私有方法，保证两处校验口径一致（见 assertBusinessItems）
        $this->assertBusinessItems($data['items'] ?? []);

        DB::transaction(function () use ($order, $data) {
            // 锁订单行复查状态：与审核并发时防止改到正在审核的单（幂等 1303）
            $locked = PurchaseOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== PurchaseOrder::STATUS_DRAFT) {
                throw new PurchaseException('已审核订单不可修改', 1303);
            }
            $locked->update([
                'supplier_id' => $data['supplier_id'],
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
                'received_qty' => 0,
                'amount' => $this->lineAmount((string) $i['quantity'], $i['price']),
            ], $data['items'] ?? []));
        });
    }

    /**
     * 删除采购订单草稿（原控制器 destroy 下沉）：仅草稿可删（1304）
     *
     * 事务内锁单据头行复查状态：与审核并发时防止删到正在审核的单（幂等 1304）。
     *
     * @param  PurchaseOrder  $order  路由绑定的订单模型（内存模型持单号供审计日志追溯）
     *
     * @throws PurchaseException 非草稿 1304
     */
    public function delete(PurchaseOrder $order): void
    {
        // 状态前置校验（不进事务，快速失败）：非草稿直接拒绝
        if ($order->status !== PurchaseOrder::STATUS_DRAFT) {
            throw new PurchaseException('已审核订单不可删除', 1304);
        }
        DB::transaction(function () use ($order) {
            // 锁订单行复查状态：与审核并发时防止删到正在审核的单（幂等 1304）
            $locked = PurchaseOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== PurchaseOrder::STATUS_DRAFT) {
                throw new PurchaseException('已审核订单不可删除', 1304);
            }
            $locked->delete();
        });

        // 单据删除审计日志（事务提交后记）：内存模型仍持有单号，可用于追溯
        Log::info('采购订单草稿删除', ['no' => $order->no, 'operator' => auth()->id()]);
    }

    /**
     * 审核采购订单（原控制器 approve 下沉）：仅草稿可审（幂等 1305）；置已审核 + approved_at + 创建人
     *
     * 审核是订单生效节点，审核后方可生成入库单。
     *
     * @param  PurchaseOrder  $order  路由绑定的订单模型
     *
     * @throws PurchaseException 非草稿（含重复审核）1305
     */
    public function approve(PurchaseOrder $order): void
    {
        // 状态前置校验（不进事务，快速失败）：同一订单重复审核在此判重（幂等）
        if ($order->status !== PurchaseOrder::STATUS_DRAFT) {
            throw new PurchaseException('该订单已审核', 1305);
        }
        DB::transaction(function () use ($order) {
            // 锁订单行：同一订单重复审核在此判重（幂等）
            $locked = PurchaseOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== PurchaseOrder::STATUS_DRAFT) {
                throw new PurchaseException('该订单已审核', 1305);
            }
            $locked->status = PurchaseOrder::STATUS_APPROVED;
            $locked->approved_at = now();
            $locked->created_by = $locked->created_by ?? auth()->id();
            $locked->save();
        });

        // 状态变更审计日志（事务提交后记）：审核是订单生效节点，后续可生成入库单
        Log::info('采购订单审核通过', ['no' => $order->no, 'operator' => auth()->id()]);
    }

    /**
     * 关闭采购订单（原控制器 close 下沉）：仅已审核/部分入库可关闭（1306）；置关闭 + closed_at
     *
     * 关闭后不可再生成入库单，属不可逆业务节点。
     *
     * @param  PurchaseOrder  $order  路由绑定的订单模型
     *
     * @throws PurchaseException 非已审核/部分入库状态 1306
     */
    public function close(PurchaseOrder $order): void
    {
        // 状态前置校验（不进事务，快速失败）：草稿/已完成/已关闭均不可关闭
        if (! in_array($order->status, [PurchaseOrder::STATUS_APPROVED, PurchaseOrder::STATUS_PARTIAL], true)) {
            throw new PurchaseException('当前状态不可关闭', 1306);
        }
        DB::transaction(function () use ($order) {
            // 锁订单行复查状态：与入库审核并发时防止关闭正在入库的订单
            $locked = PurchaseOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if (! in_array($locked->status, [PurchaseOrder::STATUS_APPROVED, PurchaseOrder::STATUS_PARTIAL], true)) {
                throw new PurchaseException('当前状态不可关闭', 1306);
            }
            $locked->status = PurchaseOrder::STATUS_CLOSED;
            $locked->closed_at = now();
            $locked->save();
        });

        // 状态变更审计日志（事务提交后记）：关闭后订单不可再生成入库单，属不可逆业务节点
        Log::info('采购订单关闭', ['no' => $order->no, 'operator' => auth()->id()]);
    }

    /**
     * 重算订单状态：全部订单行 received_qty >= quantity → 已完成；否则 → 部分入库
     *
     * 仅对 已审核/部分入库 的订单生效（已完成/关闭/草稿不扰动）。
     * 由采购入库单审核在事务内调用（回写 received_qty 之后）。
     */
    public function syncStatus(?int $orderId): void
    {
        if (! $orderId) {
            return;
        }
        $order = PurchaseOrder::whereKey($orderId)->firstOrFail();
        if (! in_array($order->status, [PurchaseOrder::STATUS_APPROVED, PurchaseOrder::STATUS_PARTIAL], true)) {
            return;
        }
        // 全部行已入完才可判「已完成」（数量带 2 位小数，bcmath 比较防浮点）
        $allDone = $order->items()->get()->every(
            fn ($i) => bccomp((string) $i->received_qty, (string) $i->quantity, 2) >= 0
        );
        $order->status = $allDone ? PurchaseOrder::STATUS_COMPLETED : PurchaseOrder::STATUS_PARTIAL;
        $order->save();
    }

    /**
     * 明细业务校验（create/update 共用）：空明细 1301 / 数量≤0 1302 / 负价 1311 / 重复商品 1312
     *
     * 校验未通过抛对应业务码的 PurchaseException（全局渲染为统一信封，
     * 与原控制器 return fail() 响应等价）。
     *
     * @param  array  $items  明细行数组（每行含 product_id/quantity/price）
     *
     * @throws PurchaseException 任一校验未通过
     */
    private function assertBusinessItems(array $items): void
    {
        if (empty($items)) {
            throw new PurchaseException('请至少添加一条明细', 1301);
        }
        foreach ($items as $item) {
            // 数量正负校验走 bccomp（D-3 铁律：禁浮点参与数量与金额比较；正则已保证入参为两位小数十进制）；
            // 单价经 integer 校验后为整数分，直接整数比较（无浮点参与）
            if (bccomp((string) $item['quantity'], '0', 2) <= 0) {
                throw new PurchaseException('数量必须大于 0', 1302);
            }
            if ((int) $item['price'] < 0) {
                throw new PurchaseException('价格不能为负数', 1311);
            }
        }
        if ($this->hasDuplicateProduct($items)) {
            throw new PurchaseException('明细存在重复商品', 1312);
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
