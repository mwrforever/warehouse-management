<?php

// BOM 控制器：单头+明细事务维护、启用版本唯一（事务内成品行锁串行化）、单号冲突重试、启用切换、删除保护

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BomHeader;
use App\Models\DocumentSequence;
use App\Models\Product;
use App\Services\DocumentSequenceService;
use App\Support\ApiResponse;
use App\Support\DeletionGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BomController extends Controller
{
    use ApiResponse;

    public function __construct(private DocumentSequenceService $sequenceService) {}

    /** 分页列表：成品过滤 + 单号模糊，含成品名称 */
    public function index(Request $request)
    {
        $query = BomHeader::with('product')->orderByDesc('id');
        if ($request->filled('product_id')) {
            $query->where('product_id', $request->input('product_id'));
        }
        if ($keyword = $request->input('keyword')) {
            $query->where('code', 'like', "%{$keyword}%");
        }
        $rows = $query->paginate(max(1, min(100, (int) $request->input('per_page', 10))));

        return $this->ok([
            'items' => $rows->map(fn ($b) => [
                'id' => $b->id, 'code' => $b->code, 'product_id' => $b->product_id,
                'product_name' => $b->product?->name, 'version' => $b->version,
                'quantity' => (float) $b->quantity, 'status' => $b->status, 'remark' => $b->remark,
            ]),
            'total' => $rows->total(), 'page' => $rows->currentPage(), 'per_page' => $rows->perPage(),
        ]);
    }

    /** 新建 BOM：单头+明细一次提交（事务）；启用版本唯一、成品/物料类型校验；单号走持久序列 */
    public function store(Request $request)
    {
        $data = $this->validateBom($request);
        // 业务校验失败：校验器返回 fail 信封，直接透出（不进入建单事务）
        if ($data instanceof JsonResponse) {
            return $data;
        }

        // 事务第 2 参数为死锁(1213)重试次数：编号序列行首建时（MySQL RR 隔离级别下取号走
        // lockForUpdate+INSERT，间隙锁使并发双方各自 INSERT 同键序列行互等死锁）败方整单回滚 500；
        // attempts=2 让框架检测到死锁后整体回滚并重跑整个闭包（重新取号+重建单据），
        // 闭包无半途续跑副作用，重跑幂等安全（机理：docs/pref/2026-08-23-数据库查询性能审查.md P1-1）
        return DB::transaction(function () use ($data) {
            // 先锁成品行串行化同成品并发创建，再查启用版本，守住「同成品启用版本唯一」核心不变式
            Product::whereKey($data['product_id'])->lockForUpdate()->first();
            if ($data['status'] === 1 && $this->hasEnabledVersion($data['product_id'], null)) {
                return $this->fail(1120, '该成品已有启用版本的 BOM');
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

            return $this->ok(['id' => $bom->id, 'code' => $bom->code]);
        }, 2);
    }

    /** 更新 BOM：明细全量替换（事务）；启用版本唯一（排除自身） */
    public function update(Request $request, BomHeader $bom)
    {
        $data = $this->validateBom($request);
        // 业务校验失败：校验器返回 fail 信封，直接透出（不进入更新事务）
        if ($data instanceof JsonResponse) {
            return $data;
        }

        return DB::transaction(function () use ($data, $bom) {
            // 先锁成品行串行化同成品并发更新，再查启用版本（排除自身 id）
            Product::whereKey($data['product_id'])->lockForUpdate()->first();
            if ($data['status'] === 1 && $this->hasEnabledVersion($data['product_id'], $bom->id)) {
                return $this->fail(1120, '该成品已有启用版本的 BOM');
            }
            $bom->update([
                'product_id' => $data['product_id'], 'version' => $data['version'],
                'quantity' => $data['quantity'], 'status' => $data['status'], 'remark' => $data['remark'] ?? null,
            ]);
            // 明细全量替换：先删后建（事务内，失败自动回滚）
            $bom->items()->delete();
            $bom->items()->createMany($data['items']);

            return $this->ok();
        });
    }

    /** 删除 BOM：被生产工单引用 1121（工单表由生产模块创建，未建时守卫自动放行） */
    public function destroy(BomHeader $bom)
    {
        if (DeletionGuard::referenced('production_orders', 'bom_id', $bom->id)) {
            return $this->fail(1121, 'BOM 已被生产工单使用，不可删除');
        }
        $bom->delete();

        return $this->ok();
    }

    /** 明细列表：物料名/单位名联查 */
    public function items(BomHeader $bom)
    {
        $items = $bom->items()->with(['material', 'unit'])->orderBy('id')->get()
            ->map(fn ($i) => [
                'id' => $i->id, 'material_id' => $i->material_id, 'material_name' => $i->material?->name,
                'quantity' => (float) $i->quantity, 'unit_id' => $i->unit_id, 'unit_name' => $i->unit?->name,
            ]);

        return $this->ok(['items' => $items]);
    }

    /** 启用/停用切换：启用时自动停用同成品其他版本（事务） */
    public function toggle(Request $request, BomHeader $bom)
    {
        $data = $request->validate(['status' => 'required|in:0,1']);
        $status = (int) $data['status'];

        DB::transaction(function () use ($bom, $status) {
            // 启用新版本：同成品其他启用版本全部停用，保证启用唯一
            if ($status === 1) {
                BomHeader::where('product_id', $bom->product_id)
                    ->where('status', 1)->where('id', '!=', $bom->id)
                    ->update(['status' => 0]);
            }
            $bom->update(['status' => $status]);
        });

        return $this->ok();
    }

    // BOM 表单校验：格式 422 + 业务码（1118 成品类型/1119 物料类型/1123 重复物料；1120 启用唯一在事务内配合成品行锁检查）。
    // 业务校验失败直接返回 fail 信封（JsonResponse），调用方（store/update）须先判 instanceof 早退再使用数组
    private function validateBom(Request $request): array|JsonResponse
    {
        $data = $request->validate([
            'product_id' => 'required|exists:products,id',
            'version' => 'required|string|max:20',
            'quantity' => 'nullable|numeric|min:0.01',
            'remark' => 'nullable|string',
            'status' => 'nullable|in:0,1',
            'items' => 'required|array|min:1',
            'items.*.material_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|numeric|gt:0',
            'items.*.unit_id' => 'required|exists:units,id',
        ]);

        // 成品类型校验 1118
        $product = Product::find($data['product_id']);
        if ($product->type !== 'finished') {
            return $this->fail(1118, 'BOM 关联商品必须是成品');
        }

        // 物料类型校验 1119：明细物料仅原料/半成品（不允许成品嵌套）
        $materialIds = array_column($data['items'], 'material_id');
        $materials = Product::whereIn('id', $materialIds)->get();
        if ($materials->contains(fn ($m) => $m->type === 'finished')) {
            return $this->fail(1119, 'BOM 明细物料必须是原料或半成品');
        }

        // 重复物料 1123
        if (count($materialIds) !== count(array_unique($materialIds))) {
            return $this->fail(1123, 'BOM 明细存在重复物料');
        }

        // 启用状态：status 为空默认 1=启用（1120 唯一性检查在事务内配合成品行锁执行）
        $status = (int) ($data['status'] ?? 1);

        return [
            'product_id' => $data['product_id'], 'version' => $data['version'],
            'quantity' => $data['quantity'] ?? 1, 'status' => $status,
            'remark' => $data['remark'] ?? null,
            'items' => array_map(fn ($i) => [
                'material_id' => $i['material_id'], 'quantity' => $i['quantity'], 'unit_id' => $i['unit_id'],
            ], $data['items']),
        ];
    }

    // 同成品是否存在启用版本（更新场景排除自身 id）
    private function hasEnabledVersion(int $productId, ?int $ignoreId): bool
    {
        $query = BomHeader::where('product_id', $productId)->where('status', 1);
        if ($ignoreId) {
            $query->where('id', '!=', $ignoreId);
        }

        return $query->exists();
    }
}
