<?php

// 商品服务：商品创建/更新/删除（编码/条码唯一 1114/1115、安全库存区间 1122、删除引用保护 1116），
// 编码/条码留空自动生成（Spec 2，DocumentSequenceService 驱动；创建路径为单表写但保留事务只为
// 编码序列死锁重试语义，见 create 注释）

namespace App\Services;

use App\Exceptions\MasterDataException;
use App\Models\BomHeader;
use App\Models\BomItem;
use App\Models\DocumentSequence;
use App\Models\Product;
use App\Support\DeletionGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 商品领域服务（D-1：控制器写操作全部下沉至此）
 *
 * 承接原控制器的商品写流程：创建（含 Spec 2 自动编码）、更新、删除。业务失败统一抛
 * MasterDataException（业务码沿用原口径 1114/1115/1116/1122），由全局异常处理器渲染
 * {code, message, data} 信封，与原控制器 fail() 响应字节级等价。
 * 依赖 DocumentSequenceService 取号（商品编码 PRD 前缀全局自增）；不直接写库存等下游表。
 */
class ProductService
{
    public function __construct(private DocumentSequenceService $sequenceService) {}

    /**
     * 新建商品（原控制器 store 下沉）：编码/条码留空自动生成（Spec 2）+ 唯一预检 1114/1115 + 安全库存区间 1122
     *
     * @param  array  $data  已过 SaveProductRequest 格式校验的载荷
     * @return Product 新建商品（含自动生成的编码/条码，供控制器响应回填）
     *
     * @throws MasterDataException 编码重复 1114 / 条码重复 1115 / 下限大于上限 1122
     */
    public function create(array $data): Product
    {
        // 编码唯一 1114；条码非空时唯一 1115（手填场景；自动生成由持久序列保证不撞）
        if (! empty($data['code']) && Product::where('code', $data['code'])->exists()) {
            throw new MasterDataException('商品编码已存在', 1114);
        }
        if (! empty($data['barcode']) && Product::where('barcode', $data['barcode'])->exists()) {
            throw new MasterDataException('条码已存在', 1115);
        }
        [$min, $max] = $this->safetyRange($data);

        // 事务第 2 参数为死锁(1213)重试次数（机理同 BomController::store：商品编码序列行首建
        // 间隙锁死锁败方整体回滚后重跑闭包重新取号，幂等安全）
        $product = DB::transaction(function () use ($data, $min, $max) {
            // 除编码/条码外的商品属性（手填/自动两条创建路径共用，避免字段清单两份漂移）
            $attributes = [
                'name' => $data['name'], 'type' => $data['type'],
                'category_id' => $data['category_id'], 'unit_id' => $data['unit_id'],
                'spec' => $data['spec'] ?? null,
                'safety_min' => $min, 'safety_max' => $max,
                'status' => $data['status'] ?? Product::STATUS_ENABLED, 'remark' => $data['remark'] ?? null,
            ];
            // 编码留空 → 走编号配置自动生成（商品编码 PRD 前缀全局自增，含老库衔接）；条码留空 → 默认 = 编码。
            // Product::create 必须封装在持久闭包内（与其余 12 个单据调用点对齐，B-1）：
            // 手填 code/barcode 占用未来自动号时，create 撞 products 唯一索引的 1062/19 才能被
            // 服务的换号重试消化；若 create 落在闭包外，异常直接 500 且序列自增随事务回滚，
            // 自动编码路径将每次取同一号反复失败、永久不可用
            if (empty($data['code'])) {
                return $this->sequenceService->nextNoByConfig(
                    DocumentSequence::TYPE_PRD,
                    fn (string $no) => Product::create($attributes + [
                        'code' => $no,
                        'barcode' => $data['barcode'] ?? $no,
                    ]),
                    fn (string $prefix, string $dateKey) => ($no = Product::where('code', 'like', $prefix.'%')
                        ->orderByDesc('code')->value('code')) ? DocumentSequenceService::seqFromNo($no, $prefix, $dateKey) : 0,
                );
            }

            // 手填编码：唯一性已由上方 1114/1115 预检把关，直接创建（条码留空默认 = 编码）
            return Product::create($attributes + [
                'code' => $data['code'],
                'barcode' => $data['barcode'] ?? $data['code'],
            ]);
        }, 2);

        // 创建审计日志（D-14）：记录自动生成后的编码/条码供追溯
        Log::info('商品创建成功', ['product_id' => $product->id, 'code' => $product->code, 'name' => $product->name, 'operator' => auth()->id()]);

        return $product;
    }

    /**
     * 更新商品（原控制器 update 下沉）：编码/条码唯一（排除自身 1114/1115）+ 安全库存区间 1122
     *
     * @param  Product  $product  路由绑定的商品模型
     * @param  array  $data  已过 SaveProductRequest 格式校验的载荷
     *
     * @throws MasterDataException 编码重复 1114 / 条码重复 1115 / 下限大于上限 1122
     */
    public function update(Product $product, array $data): void
    {
        // 编码唯一（排除自身）
        if (Product::where('code', $data['code'])->where('id', '!=', $product->id)->exists()) {
            throw new MasterDataException('商品编码已存在', 1114);
        }
        if (
            ! empty($data['barcode']) && Product::where('barcode', $data['barcode'])
                ->where('id', '!=', $product->id)->exists()
        ) {
            throw new MasterDataException('条码已存在', 1115);
        }
        [$min, $max] = $this->safetyRange($data);

        $product->update([
            'name' => $data['name'], 'code' => $data['code'], 'type' => $data['type'],
            'category_id' => $data['category_id'], 'unit_id' => $data['unit_id'],
            'spec' => $data['spec'] ?? null, 'barcode' => $data['barcode'] ?? null,
            'safety_min' => $min, 'safety_max' => $max,
            'status' => $data['status'] ?? $product->status, 'remark' => $data['remark'] ?? null,
        ]);

        Log::info('商品更新成功', ['product_id' => $product->id, 'code' => $product->code, 'operator' => auth()->id()]);
    }

    /**
     * 删除商品（原控制器 destroy 下沉）：被 BOM 头/明细、库存流水、盘点明细、采购/销售明细、
     * 生产工单/工单物料/领退料/成品入库明细引用不可删 1116
     *
     * @param  Product  $product  路由绑定的商品模型
     *
     * @throws MasterDataException 被业务单据引用 1116
     */
    public function delete(Product $product): void
    {
        // 本模块表（BOM）直接检查；下游模块表经守卫（未建自动放行，建后自动生效）
        $referencedByBom = BomItem::where('material_id', $product->id)->exists()
            || BomHeader::where('product_id', $product->id)->exists();
        $referencedByOther = DeletionGuard::referenced('inventory_movements', 'product_id', $product->id)
            || DeletionGuard::referenced('inventory_check_items', 'product_id', $product->id)
            || DeletionGuard::referenced('purchase_order_items', 'product_id', $product->id)
            || DeletionGuard::referenced('purchase_inbound_items', 'product_id', $product->id)
            || DeletionGuard::referenced('sales_order_items', 'product_id', $product->id)
            || DeletionGuard::referenced('sales_outbound_items', 'product_id', $product->id)
            || DeletionGuard::referenced('production_orders', 'product_id', $product->id)
            || DeletionGuard::referenced('production_order_materials', 'material_id', $product->id)
            || DeletionGuard::referenced('pick_list_items', 'product_id', $product->id)
            || DeletionGuard::referenced('return_list_items', 'product_id', $product->id)
            || DeletionGuard::referenced('finished_inbound_items', 'product_id', $product->id);
        if ($referencedByBom || $referencedByOther) {
            throw new MasterDataException('商品已被业务单据使用，不可删除', 1116);
        }
        $product->delete();

        Log::info('商品删除成功', ['product_id' => $product->id, 'code' => $product->code, 'operator' => auth()->id()]);
    }

    /**
     * 安全库存区间归一化 + 区间校验（create/update 共用）
     *
     * 安全库存下限不能大于上限 1122（bccomp 数量比较，D-3 铁律禁浮点参与；正则已保证入参为两位小数十进制）
     *
     * @param  array  $data  已过格式校验的载荷
     * @return array{0: string, 1: string} [safety_min, safety_max] 归一化为两位小数字符串
     *
     * @throws MasterDataException 下限大于上限 1122
     */
    private function safetyRange(array $data): array
    {
        $min = (string) ($data['safety_min'] ?? 0);
        $max = (string) ($data['safety_max'] ?? 0);
        if (bccomp($max, '0', 2) > 0 && bccomp($min, $max, 2) > 0) {
            throw new MasterDataException('安全库存下限不能大于上限', 1122);
        }

        return [$min, $max];
    }
}
