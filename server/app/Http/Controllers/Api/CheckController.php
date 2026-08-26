<?php

// 盘点单控制器：草稿 CRUD + 账面预填 + 审核（事务+行锁，盘盈盘亏走统一库存引擎）

namespace App\Http\Controllers\Api;

use App\Exceptions\InventoryException;
use App\Http\Controllers\Controller;
use App\Models\DocumentSequence;
use App\Models\InventoryBalance;
use App\Models\InventoryCheck;
use App\Models\InventoryCheckItem;
use App\Services\DocumentSequenceService;
use App\Services\InventoryService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CheckController extends Controller
{
    use ApiResponse;

    public function __construct(
        private InventoryService $inventoryService,
        private DocumentSequenceService $sequenceService,
    ) {}

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

    /** 账面预填：该仓库全部有余额的 商品×库位 行（盘点弹窗加载用） */
    public function autoBooks(Request $request)
    {
        $data = $request->validate(['warehouse_id' => 'required|integer|exists:warehouses,id']);
        $items = InventoryBalance::where('inventory_balances.warehouse_id', $data['warehouse_id'])
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

    /** 新建草稿：账面数服务端快照；实盘负数 1201；无余额商品 1205 */
    public function store(Request $request)
    {
        $data = $this->validatePayload($request);
        $items = [];
        // 明细查重：同商品×库位 只允许一行（防扫码/粘贴误加重复行）
        $seen = [];
        foreach ($data['items'] as $item) {
            $key = $item['product_id'].'-'.$item['location_id'];
            if (isset($seen[$key])) {
                return $this->fail(422, '盘点明细存在重复商品与库位');
            }
            $seen[$key] = true;
            // 实盘数不能为负（bccomp 判负，D-3 铁律禁浮点比较；正则已保证入参为两位小数十进制）
            if (bccomp((string) $item['actual_qty'], '0', 2) < 0) {
                return $this->fail(1201, '实盘数量不能为负数');
            }
            // 无余额商品不可录盘（1205）：账外资产盘盈（book_qty=0 建账）属功能需求，
            // 用户 2026-08-13 裁决暂不做——实施改动点见 docs/bugs/2026-08-13-盘点盘盈无余额行误拒.md
            $balance = InventoryBalance::where('product_id', $item['product_id'])
                ->where('warehouse_id', $data['warehouse_id'])
                ->where('location_id', $item['location_id'])
                ->first();
            if (! $balance) {
                return $this->fail(1205, '商品在该仓库无库存，无需盘点');
            }
            // 账面数=创建时余额快照（审核时以此校验并发变动）
            $items[] = [
                'product_id' => $item['product_id'],
                'location_id' => $item['location_id'],
                'book_qty' => $balance->quantity,
                'actual_qty' => $item['actual_qty'],
            ];
        }
        // 事务第 2 参数为死锁(1213)重试次数（机理同 BomController::store：序列行首建间隙锁
        // 死锁败方整体回滚后重跑闭包重新取号，幂等安全）
        $check = DB::transaction(function () use ($data, $items) {
            // 建单：单号走持久序列（并发撞号 1062/19 由服务换号重试；删除不回退号段）
            $check = $this->sequenceService->nextNoByConfig(
                DocumentSequence::TYPE_CHECK,
                fn (string $no) => InventoryCheck::create([
                    'no' => $no,
                    'warehouse_id' => $data['warehouse_id'],
                    'status' => InventoryCheck::STATUS_DRAFT,
                    'remark' => $data['remark'] ?? null,
                ]),
                // 老库衔接：序列行首次初始化时以当日既有 CK 单号段最大值为起点
                fn (string $prefix, string $dateKey) => ($no = InventoryCheck::where('no', 'like', $prefix.date('Ymd').'%')
                    ->orderByDesc('no')->value('no')) ? DocumentSequenceService::seqFromNo($no, $prefix, $dateKey) : 0,
            );
            foreach ($items as $i) {
                InventoryCheckItem::create(['check_id' => $check->id] + $i);
            }

            return $check;
        }, 2);

        // 单据创建审计日志（事务提交后记）：单号 + 盘点行数 + 操作人
        Log::info('盘点单创建成功', [
            'no' => $check->no, 'item_count' => count($items), 'created_by' => auth()->id(),
        ]);

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

    /** 更新草稿：仅 status=草稿 可改（1202）；items 全量替换；事务内锁行复查防并发 */
    public function update(Request $request, InventoryCheck $check)
    {
        $data = $this->validatePayload($request);
        $items = [];
        // 明细查重：同商品×库位 只允许一行（防扫码/粘贴误加重复行）
        $seen = [];
        foreach ($data['items'] as $item) {
            $key = $item['product_id'].'-'.$item['location_id'];
            if (isset($seen[$key])) {
                return $this->fail(422, '盘点明细存在重复商品与库位');
            }
            $seen[$key] = true;
            // 实盘数不能为负（bccomp 判负，与 store 同口径）
            if (bccomp((string) $item['actual_qty'], '0', 2) < 0) {
                return $this->fail(1201, '实盘数量不能为负数');
            }
            // 无余额商品不可录盘（1205）：与 store 同口径
            // 账外盘盈暂不做，裁决与实施改动点见 docs/bugs/2026-08-13-盘点盘盈无余额行误拒.md
            $balance = InventoryBalance::where('product_id', $item['product_id'])
                ->where('warehouse_id', $data['warehouse_id'])
                ->where('location_id', $item['location_id'])
                ->first();
            if (! $balance) {
                return $this->fail(1205, '商品在该仓库无库存，无需盘点');
            }
            $items[] = [
                'product_id' => $item['product_id'],
                'location_id' => $item['location_id'],
                'book_qty' => $balance->quantity,
                'actual_qty' => $item['actual_qty'],
            ];
        }
        DB::transaction(function () use ($check, $data, $items) {
            // 锁盘点单行复查状态：与审核并发时防止改到正在审核的单（幂等 1202）
            $locked = InventoryCheck::whereKey($check->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === InventoryCheck::STATUS_APPROVED) {
                throw new InventoryException('已审核单据不可修改', 1202);
            }
            $locked->update(['warehouse_id' => $data['warehouse_id'], 'remark' => $data['remark'] ?? $locked->remark]);
            // 明细全量替换（旧行随头级联或先删后插）
            $locked->items()->delete();
            foreach ($items as $i) {
                InventoryCheckItem::create(['check_id' => $locked->id] + $i);
            }
        });

        return $this->ok();
    }

    /** 删除草稿：已审核不可删（1203）；事务内锁行复查防并发 */
    public function destroy(InventoryCheck $check)
    {
        DB::transaction(function () use ($check) {
            // 锁盘点单行复查状态：与审核并发时防止删到正在审核的单（幂等 1203）
            $locked = InventoryCheck::whereKey($check->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === InventoryCheck::STATUS_APPROVED) {
                throw new InventoryException('已审核单据不可删除', 1203);
            }
            $locked->delete();
        });

        // 单据删除审计日志（事务提交后记）：内存模型仍持有单号，可用于追溯
        Log::info('盘点单草稿删除', ['no' => $check->no, 'operator' => auth()->id()]);

        return $this->ok();
    }

    /** 审核：事务内逐项生成 check_in/check_out 流水并更新余额；幂等 1204；并发 1206 */
    public function approve(InventoryCheck $check)
    {
        $result = null;
        // attempts=2：死锁自动重试一次（B-3；余额行锁序已由 InventoryService 统一，兜底盘点按明细序
        // 内联预锁的残余交叉窗口——apply 单条调用无法重排该预锁序列）
        DB::transaction(function () use ($check, &$result) {
            // 锁盘点单行：同一单据重复审核在此判重（幂等）
            $locked = InventoryCheck::whereKey($check->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === InventoryCheck::STATUS_APPROVED) {
                throw new InventoryException('该盘点单已审核', 1204);
            }
            $changed = 0;
            $increased = '0';  // 盘盈数量合计（bcadd 累加，D-3 铁律禁浮点累加）
            $decreased = '0';  // 盘亏数量合计
            $increasedItems = 0; // 盘盈项数（前端「盘盈 X 项 +N」文案用）
            $decreasedItems = 0; // 盘亏项数
            // 明细行显式标注模型类型（larastan 无法从关系泛型推断 foreach 元素）
            /** @var InventoryCheckItem $item */
            foreach ($locked->items as $item) {
                // 差异 = 实盘 − 账面（bcsub 构造性精确，两位小数字符串满足 decimal cast 的 string 属性）
                $diff = bcsub((string) $item->actual_qty, (string) $item->book_qty, 2);
                $item->diff_qty = $diff;
                $item->save();
                // 零差异不生成流水（差值恒为两位小数，bccomp === 0 即零差异，无浮点容差窗口）
                if (bccomp($diff, '0', 2) === 0) {
                    continue;
                }
                // 锁余额行：账面快照已被并发变动（其他盘点单先审）→ 整体回滚 1206
                $balance = InventoryBalance::where('product_id', $item->product_id)
                    ->where('warehouse_id', $locked->warehouse_id)
                    ->where('location_id', $item->location_id)
                    ->lockForUpdate()
                    ->first();
                // ! $balance 为防御性分支：余额行只增不删，账面快照存在时理论不可达（1205 已拦无账商品）；
                // 若未来支持账外盘盈（暂不做，见 docs/bugs/2026-08-13-盘点盘盈无余额行误拒.md），
                // 此处须改为按「无余额行=账面 0」比对放行盘盈
                // 余额与账面快照均为两位小数，bcsub 差值非 0 即并发变动（替代浮点 0.005 容差比较）
                if (
                    ! $balance
                    || bccomp(bcsub((string) $balance->quantity, (string) $item->book_qty, 2), '0', 2) !== 0
                ) {
                    throw new InventoryException('库存已变动，请重新盘点', 1206);
                }
                $direction = bccomp($diff, '0', 2) > 0 ? 1 : -1;
                // 流水数量 = 差值绝对值（负差值侧 bcsub 反转符号，保持恒正契约）
                $diffAbs = $direction > 0 ? $diff : bcsub('0', $diff, 2);
                // 盘盈/盘亏走统一引擎（同事务，双写一致）
                $this->inventoryService->apply([[
                    'product_id' => $item->product_id,
                    'warehouse_id' => $locked->warehouse_id,
                    'location_id' => $item->location_id,
                    'direction' => $direction,
                    'quantity' => $diffAbs,
                    'source_type' => $direction > 0 ? 'check_in' : 'check_out',
                    'source_id' => $locked->id,
                    'source_no' => $locked->no,
                    'remark' => $direction > 0 ? '盘盈' : '盘亏',
                ]], auth()->id());
                $changed++;
                if ($direction > 0) {
                    $increased = bcadd($increased, $diffAbs, 2);
                    $increasedItems++;
                } else {
                    $decreased = bcadd($decreased, $diffAbs, 2);
                    $decreasedItems++;
                }
            }
            $locked->status = InventoryCheck::STATUS_APPROVED;
            $locked->checker = auth()->user()->name ?? '';
            $locked->check_time = now();
            $locked->save();
            $result = [
                'changed_items' => $changed,
                // 合计出参转数值保持 JSON 数字类型不变（累加本身已 bcmath 精确，此转型仅序列化口径）
                'increased' => (float) $increased,
                'decreased' => (float) $decreased,
                'increased_items' => $increasedItems,
                'decreased_items' => $decreasedItems,
            ];
        }, 2);

        // 状态变更审计日志（事务提交后记）：盘盈/盘亏汇总（循环外聚合一条，禁止逐明细打印；
        // 数量为 decimal 原值，inventory_movements 流水已逐笔留痕）
        Log::info('盘点单审核通过', [
            'no' => $check->no, 'changed_items' => $result['changed_items'],
            'increased' => $result['increased'], 'decreased' => $result['decreased'],
            'operator' => auth()->id(),
        ]);

        return $this->ok($result);
    }

    // 载荷格式校验（422 仅格式层）：warehouse 必填、items 非空数组
    private function validatePayload(Request $request): array
    {
        return $request->validate([
            'warehouse_id' => 'required|integer|exists:warehouses,id',
            'remark' => 'nullable|string|max:200',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|integer|exists:products,id',
            'items.*.location_id' => 'required|integer|exists:locations,id',
            // 数量限两位小数（正则防科学计数法；负值形态放行到方法内业务码 1201）——
            // bccomp 判负的字符串安全前提（D-3），与 PickList 等其余单据口径一致
            'items.*.actual_qty' => 'required|numeric|regex:/^-?\d+(\.\d{1,2})?$/',
        ]);
    }
}
