<?php

// 生产工单服务：BOM 展开（物料需求快照 + 工序序列生成）+ 完成率计算

namespace App\Services;

use App\Models\BomHeader;
use App\Models\Process;
use App\Models\Product;
use App\Models\WorkOrderOperation;

class ProductionOrderService
{
    /**
     * BOM 展开：物料需求快照 + 工序序列（供工单创建/更新时调用）
     *
     * @param  Product  $product  工单成品
     * @param  string  $quantity  工单计划数量（decimal 字符串）
     * @param  BomHeader  $bom  启用版本 BOM（调用方已锁定）
     * @return array{materials: array<int, array{material_id:int, required_qty:string}>, operations: array<int, array{process_id:int, seq:int}>}
     */
    public function expandBom(Product $product, string $quantity, BomHeader $bom): array
    {
        // 物料需求 = 计划数量 ÷ 基准产出 × 用量（bcmath 4 位中间精度防误差，最终 2 位）
        $materials = $bom->items()->get()->map(fn ($i) => [
            'material_id' => $i->material_id,
            'required_qty' => bcmul(bcdiv($quantity, (string) $bom->quantity, 4), (string) $i->quantity, 2),
        ])->values()->all();

        // 工序序列 = 全部启用工序按 sort 升序（V1 设计：BOM 头无工序字段，全量启用工序进入工单）
        $seq = 0;
        $operations = Process::query()
            ->where('status', 1)
            ->orderBy('sort')
            ->orderBy('id')
            ->get()
            ->map(function (Process $p) use (&$seq) {
                // 按遍历顺序自增 seq（引用捕获：箭头函数按值捕获会导致 seq 恒为 1）
                return [
                    'process_id' => $p->id,
                    'seq' => ++$seq,
                ];
            })->values()->all();

        return compact('materials', 'operations');
    }

    /**
     * 完成率（%）：completed ÷ quantity × 100，保留 1 位小数（列表进度条展示）
     *
     * @param  string  $completed  累计完工数量
     * @param  string  $quantity  计划数量
     * @return float 完成率（0-100，如 50.0）
     */
    public function progress(string $completed, string $quantity): float
    {
        if (bccomp($quantity, '0', 2) <= 0) {
            return 0.0;
        }

        return (float) bcmul(bcdiv($completed, $quantity, 4), '100', 1);
    }

    /** 工序状态中文标签（详情/列表展示，防御未知状态） */
    public function operationStatusLabel(int $status): string
    {
        return WorkOrderOperation::STATUS_LABELS[$status] ?? '未知';
    }
}
