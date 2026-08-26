<?php

// 盘点单控制器：草稿 CRUD + 账面预填 + 审核（写操作全部下沉 CheckService，盘盈盘亏经 InventoryService 统一库存引擎）

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Check\AutoBooksRequest;
use App\Http\Requests\Check\SaveCheckRequest;
use App\Models\InventoryBalance;
use App\Models\InventoryCheck;
use App\Models\InventoryCheckItem;
use App\Services\CheckService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class CheckController extends Controller
{
    use ApiResponse;

    public function __construct(private CheckService $checkService) {}

    /** 盘点单分页列表：单号关键字/状态/仓库 筛选 */
    public function index(Request $request)
    {
        $query = InventoryCheck::query()->orderByDesc('id');
        if ($keyword = $request->input('keyword')) {
            $query->where('no', 'like', "%{$keyword}%");
        }
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->filled('warehouse_id')) {
            $query->where('warehouse_id', $request->input('warehouse_id'));
        }
        $rows = $query->paginate(max(1, min(100, (int) $request->input('per_page', 10))));

        return $this->ok([
            // 闭包参数显式声明模型类型（larastan 可识别关系与 cast 后的属性类型）
            'items' => $rows->map(fn (InventoryCheck $c) => [
                'id' => $c->id,
                'no' => $c->no,
                'warehouse_name' => $c->warehouse?->name,
                'status' => $c->status,
                'checker' => $c->checker,
                'check_time' => $c->check_time?->toDateTimeString(),
                'remark' => $c->remark,
                'created_at' => $c->created_at?->toDateTimeString(),
            ]),
            'total' => $rows->total(), 'page' => $rows->currentPage(), 'per_page' => $rows->perPage(),
        ]);
    }

    /** 账面预填：该仓库全部有余额的 商品×库位 行（盘点弹窗加载用；校验在 AutoBooksRequest） */
    public function autoBooks(AutoBooksRequest $request)
    {
        $items = InventoryBalance::where('inventory_balances.warehouse_id', $request->validated('warehouse_id'))
            ->join('products', 'products.id', '=', 'inventory_balances.product_id')
            ->join('locations', 'locations.id', '=', 'inventory_balances.location_id')
            ->select(
                'inventory_balances.product_id',
                'inventory_balances.location_id',
                'inventory_balances.quantity as book_qty',
                'products.name as product_name',
                'products.code as product_code',
                'locations.name as location_name'
            )
            ->orderBy('inventory_balances.product_id')
            ->orderBy('inventory_balances.location_id')
            ->get()
            // 显式映射为数组：账面数统一两位小数字符串（sqlite 下别名列不走 decimal cast）
            ->map(fn ($r) => [
                'product_id' => $r->product_id,
                'location_id' => $r->location_id,
                // join 别名列经 getAttribute 读取（PHPStan 静态分析可识别）
                'book_qty' => number_format((float) $r->getAttribute('book_qty'), 2, '.', ''),
                'product_name' => $r->getAttribute('product_name'),
                'product_code' => $r->getAttribute('product_code'),
                'location_name' => $r->getAttribute('location_name'),
            ])
            ->values();

        return $this->ok(['items' => $items]);
    }

    /** 新建草稿：账面数服务端快照；实盘负数 1201；无余额商品 1205（写流程在 CheckService） */
    public function store(SaveCheckRequest $request)
    {
        $check = $this->checkService->create($request->validated());

        return $this->ok(['no' => $check->no]);
    }

    /** 详情：含明细（book_qty/actual_qty/diff_qty） */
    public function show(InventoryCheck $check)
    {
        return $this->ok([
            'id' => $check->id,
            'no' => $check->no,
            'warehouse_id' => $check->warehouse_id,
            'warehouse_name' => $check->warehouse?->name,
            'status' => $check->status,
            'checker' => $check->checker,
            'check_time' => $check->check_time?->toDateTimeString(),
            'remark' => $check->remark,
            'created_at' => $check->created_at?->toDateTimeString(),
            'items' => $check->items()->with(['product', 'location'])->get()->map(fn (InventoryCheckItem $i) => [
                'id' => $i->id,
                'product_id' => $i->product_id,
                'location_id' => $i->location_id,
                'product_name' => $i->product?->name,
                'product_code' => $i->product?->code,
                'location_name' => $i->location?->name,
                'book_qty' => $i->book_qty,
                'actual_qty' => $i->actual_qty,
                'diff_qty' => $i->diff_qty,
            ]),
        ]);
    }

    /** 更新草稿：仅草稿可改（1202）；items 全量替换；事务内锁行复查防并发（写流程在 CheckService） */
    public function update(SaveCheckRequest $request, InventoryCheck $check)
    {
        $this->checkService->update($check, $request->validated());

        return $this->ok();
    }

    /** 删除草稿：已审核不可删（1203）；事务内锁行复查防并发（写流程在 CheckService） */
    public function destroy(InventoryCheck $check)
    {
        $this->checkService->delete($check);

        return $this->ok();
    }

    /** 审核：事务内逐项生成 check_in/check_out 流水并更新余额；幂等 1204；并发 1206（写流程在 CheckService） */
    public function approve(InventoryCheck $check)
    {
        return $this->ok($this->checkService->approve($check));
    }
}
