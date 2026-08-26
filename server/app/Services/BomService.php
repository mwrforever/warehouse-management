<?php

// BOM 领域服务：草稿创建/更新/删除、启用切换（事务+成品行锁+启用版本唯一）+ 业务码校验（1118/1119/1120/1121/1123）

namespace App\Services;

use App\Exceptions\ProductionException;
use App\Models\BomHeader;
use App\Models\DocumentSequence;
use App\Models\Product;
use App\Support\DeletionGuard;
use Illuminate\Support\Facades\DB;

/**
 * BOM 领域服务（D-1：控制器写操作全部下沉至此）
 *
 * 承接原 BomController 的单头+明细事务维护：创建/更新/删除/启用切换，均在 DB::transaction 内
 * 「锁成品行 → 启用版本唯一校验 → 变更」三步执行（与工单创建同锁序：成品行先锁）。
 * 业务失败统一抛 ProductionException（业务码沿用原口径 1118/1119/1120/1121/1123），
 * 由全局异常处理器渲染 {code, message, data} 信封，与原控制器 fail() 响应字节级等价。
 * 非线程安全：同一成品的并发写依赖成品行锁串行化，勿在事务外组合调用多个写方法。
 */
class BomService
{
    public function __construct(private DocumentSequenceService $sequenceService) {}

    /**
     * 新建 BOM：单头+明细一次提交（事务）；启用版本唯一、成品/物料类型校验；单号走持久序列
     *
     * 事务第 2 参数为死锁(1213)重试次数：编号序列行首建时（MySQL RR 隔离级别下取号走
     * lockForUpdate+INSERT，间隙锁使并发双方各自 INSERT 同键序列行互等死锁）败方整单回滚 500；
     * attempts=2 让框架检测到死锁后整体回滚并重跑整个闭包（重新取号+重建单据），
     * 闭包无半途续跑副作用，重跑幂等安全（机理：docs/pref/2026-08-23-数据库查询性能审查.md P1-1）。
     *
     * @param  array  $data  已过 SaveBomRequest 格式校验的原始载荷
     * @return BomHeader 新建的 BOM 模型（含单号，供控制器回显 id/code）
     *
     * @throws ProductionException 成品类型 1118 / 明细物料类型 1119 / 启用唯一 1120 / 重复物料 1123
     */
    public function create(array $data): BomHeader
    {
        // 业务码校验 + 归一化（422 仅格式层已由 FormRequest 拦截，此处为业务冲突；1120 在事务内配合成品行锁检查）
        $data = $this->normalizePayload($data);

        return DB::transaction(function () use ($data) {
            // 先锁成品行串行化同成品并发创建，再查启用版本，守住「同成品启用版本唯一」核心不变式
            Product::whereKey($data['product_id'])->lockForUpdate()->first();
            if ($data['status'] === BomHeader::STATUS_ENABLED && $this->hasEnabledVersion($data['product_id'], null)) {
                throw new ProductionException('该成品已有启用版本的 BOM', 1120);
            }
            // 生成单号：按编号规则配置格式（BOM 前缀+日期段+补零），持久序列原子取号（删除不回退，撞号自动重试）
            $bom = $this->sequenceService->nextNoByConfig(
                DocumentSequence::TYPE_BOM,
                fn (string $code) => BomHeader::create([
                    'code' => $code,
                    'product_id' => $data['product_id'],
                    'version' => $data['version'],
                    'quantity' => $data['quantity'],
                    'status' => $data['status'],
                    'remark' => $data['remark'] ?? null,
                ]),
                // 老库衔接：序列行首次初始化时以当日既有 BOM 单号段最大值为起点
                fn (string $prefix, string $dateKey) => ($no = BomHeader::where('code', 'like', $prefix.date('Ymd').'%')
                    ->orderByDesc('code')->value('code')) ? DocumentSequenceService::seqFromNo($no, $prefix, $dateKey) : 0,
            );
            $bom->items()->createMany($data['items']);

            return $bom;
        }, 2);
    }

    /**
     * 更新 BOM：明细全量替换（事务）；启用版本唯一（排除自身）
     *
     * @param  BomHeader  $bom  路由绑定的 BOM 模型
     * @param  array  $data  已过 SaveBomRequest 格式校验的原始载荷
     *
     * @throws ProductionException 成品类型 1118 / 明细物料类型 1119 / 启用唯一 1120 / 重复物料 1123
     */
    public function update(BomHeader $bom, array $data): void
    {
        // 业务码校验 + 归一化（与 create 共用，口径一致）
        $data = $this->normalizePayload($data);

        DB::transaction(function () use ($bom, $data) {
            // 先锁成品行串行化同成品并发更新，再查启用版本（排除自身 id）
            Product::whereKey($data['product_id'])->lockForUpdate()->first();
            if (
                $data['status'] === BomHeader::STATUS_ENABLED
                && $this->hasEnabledVersion($data['product_id'], $bom->id)
            ) {
                throw new ProductionException('该成品已有启用版本的 BOM', 1120);
            }
            $bom->update([
                'product_id' => $data['product_id'], 'version' => $data['version'],
                'quantity' => $data['quantity'], 'status' => $data['status'], 'remark' => $data['remark'] ?? null,
            ]);
            // 明细全量替换：先删后建（事务内，失败自动回滚）
            $bom->items()->delete();
            $bom->items()->createMany($data['items']);
        });
    }

    /**
     * 删除 BOM：被生产工单引用 1121（工单表由生产模块创建，未建时守卫自动放行）
     *
     * @throws ProductionException 被生产工单引用 1121
     */
    public function delete(BomHeader $bom): void
    {
        // 被工单引用保护（读检查无需持锁；原控制器 DeletionGuard 检查下沉）
        if (DeletionGuard::referenced('production_orders', 'bom_id', $bom->id)) {
            throw new ProductionException('BOM 已被生产工单使用，不可删除', 1121);
        }
        $bom->delete();
    }

    /**
     * 启用/停用切换：启用时自动停用同成品其他版本（事务）
     *
     * @param  BomHeader  $bom  路由绑定的 BOM 模型
     * @param  int  $status  目标状态（BomHeader::STATUS_ENABLED / STATUS_DISABLED，来自 ToggleStatusRequest）
     */
    public function toggle(BomHeader $bom, int $status): void
    {
        DB::transaction(function () use ($bom, $status) {
            // 先锁成品行串行化同成品并发启停（B-103）：与 store/update 写入路径同锁序，
            // 否则 toggle 与新建/更新交错时各自的启用判断互看不到对方未提交的变更，可产生双启用版本
            Product::whereKey($bom->product_id)->lockForUpdate()->first();
            // 启用新版本：同成品其他启用版本全部停用，保证启用唯一
            if ($status === BomHeader::STATUS_ENABLED) {
                BomHeader::where('product_id', $bom->product_id)
                    ->where('status', BomHeader::STATUS_ENABLED)->where('id', '!=', $bom->id)
                    ->update(['status' => BomHeader::STATUS_DISABLED]);
            }
            $bom->update(['status' => $status]);
        });
    }

    // BOM 业务码校验 + 归一化（原控制器 validateBom 后半段下沉）：格式 422 已由 FormRequest 拦截；
    // 1118 成品类型 / 1119 明细物料类型 / 1123 重复物料 在此检查；1120 启用唯一在事务内配合成品行锁检查。
    // 与原实现差异仅在失败出口：fail 信封改为抛 ProductionException（全局渲染字节级等价）
    private function normalizePayload(array $data): array
    {
        // 成品类型校验 1118
        $product = Product::find($data['product_id']);
        if ($product->type !== 'finished') {
            throw new ProductionException('BOM 关联商品必须是成品', 1118);
        }

        // 物料类型校验 1119：明细物料仅原料/半成品（不允许成品嵌套）
        $materialIds = array_column($data['items'], 'material_id');
        $materials = Product::whereIn('id', $materialIds)->get();
        if ($materials->contains(fn ($m) => $m->type === 'finished')) {
            throw new ProductionException('BOM 明细物料必须是原料或半成品', 1119);
        }

        // 重复物料 1123
        if (count($materialIds) !== count(array_unique($materialIds))) {
            throw new ProductionException('BOM 明细存在重复物料', 1123);
        }

        // 归一化默认值（status 为空默认启用；与控制器 validateBom 逐条等价）
        return [
            'product_id' => $data['product_id'], 'version' => $data['version'],
            'quantity' => $data['quantity'] ?? 1, 'status' => (int) ($data['status'] ?? BomHeader::STATUS_ENABLED),
            'remark' => $data['remark'] ?? null,
            'items' => array_map(fn ($i) => [
                'material_id' => $i['material_id'], 'quantity' => $i['quantity'], 'unit_id' => $i['unit_id'],
            ], $data['items']),
        ];
    }

    // 同成品是否存在启用版本（更新场景排除自身 id）
    private function hasEnabledVersion(int $productId, ?int $ignoreId): bool
    {
        $query = BomHeader::where('product_id', $productId)->where('status', BomHeader::STATUS_ENABLED);
        if ($ignoreId) {
            $query->where('id', '!=', $ignoreId);
        }

        return $query->exists();
    }
}
