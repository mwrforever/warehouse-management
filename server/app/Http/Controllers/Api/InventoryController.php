<?php

// 库存查询控制器：余额列表/导出、流水列表、预警列表（只读接口，权限 inventory.list）

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InventoryBalance;
use App\Models\InventoryMovement;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class InventoryController extends Controller
{
    use ApiResponse;

    /** 余额分页列表：关键字(编码/名称/条码)/仓库/类型/仅预警 筛选 */
    public function balances(Request $request)
    {
        $rows = $this->balanceQuery($request)->paginate(max(1, min(100, (int) $request->input('per_page', 10))));

        return $this->ok([
            'items' => $rows->map(fn ($r) => $this->balanceItem($r)),
            'total' => $rows->total(), 'page' => $rows->currentPage(), 'per_page' => $rows->perPage(),
        ]);
    }

    /** 余额导出 CSV：UTF-8 BOM + 中文表头（与当前筛选一致的全量行）；流式输出不占内存 */
    public function exportBalances(Request $request)
    {
        // 预热查询：响应头发送前先执行一次 count（值不使用，仅触发 SQL 编译/执行）——
        // SQL 结构错误在此暴露为 JSON 500，而非流式输出中途降级为「200 + 半截 CSV」（bug #10 回归）
        (clone $this->balanceQuery($request))->count();

        // 用流式响应输出 CSV（测试 streamedContent 依赖）：回调内游标逐行读取 + fputcsv 逐行写出，
        // 避免 ->get() 全量装载 + 全量拼串导致的内存随行数线性增长
        return response()->stream(function () use ($request): void {
            // 表头保持无引号原样（导出测试按精确表头断言）
            echo "\xEF\xBB\xBF"."商品编码,商品名称,仓库,库位,数量,下限,上限,状态\n";
            $out = fopen('php://output', 'w');
            // 游标逐行读取：单行内存驻留，与总行数无关
            foreach ($this->balanceQuery($request)->cursor() as $r) {
                $level = $this->alertLevel($r);
                $status = $level === 1 ? '低库存' : ($level === 2 ? '超上限' : '正常');
                // CSV 字段统一加引号转义（防中文逗号破坏列结构；fputcsv 默认全字段引号包裹）
                fputcsv($out, [
                    $r->product_code, $r->product_name, $r->warehouse_name,
                    $r->location_name, $r->quantity, $r->safety_min, $r->safety_max, $status,
                ]);
            }
            fclose($out);
        }, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="balances_'.date('YmdHis').'.csv"',
        ]);
    }

    /** 流水分页列表：时间倒序；商品/仓库/类型/方向/日期范围 筛选 */
    public function movements(Request $request)
    {
        $query = InventoryMovement::query()
            ->join('products', 'products.id', '=', 'inventory_movements.product_id')
            ->join('warehouses', 'warehouses.id', '=', 'inventory_movements.warehouse_id')
            ->join('locations', 'locations.id', '=', 'inventory_movements.location_id')
            ->leftJoin('users', 'users.id', '=', 'inventory_movements.operator_id')
            ->select(
                'inventory_movements.*',
                'products.name as product_name',
                'products.code as product_code',
                'warehouses.name as warehouse_name',
                'locations.name as location_name',
                'users.name as operator_name'
            )
            ->orderByDesc('inventory_movements.created_at')
            ->orderByDesc('inventory_movements.id');

        if ($request->filled('product_id')) {
            $query->where('inventory_movements.product_id', $request->input('product_id'));
        }
        if ($request->filled('warehouse_id')) {
            $query->where('inventory_movements.warehouse_id', $request->input('warehouse_id'));
        }
        if ($request->filled('source_type')) {
            $query->where('inventory_movements.source_type', $request->input('source_type'));
        }
        // 来源单号筛选（E2E 断言「超卖/回滚无流水」按单号核对）
        if ($request->filled('source_no')) {
            $query->where('inventory_movements.source_no', $request->input('source_no'));
        }
        if ($request->filled('direction')) {
            $query->where('inventory_movements.direction', (int) $request->input('direction'));
        }
        // 日期范围闭区间筛选（created_at 流水时间）
        if ($request->filled('date_from')) {
            $query->whereDate('inventory_movements.created_at', '>=', $request->input('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('inventory_movements.created_at', '<=', $request->input('date_to'));
        }

        $rows = $query->paginate(max(1, min(100, (int) $request->input('per_page', 10))));

        return $this->ok([
            'items' => $rows->map(fn ($r) => [
                'id' => $r->id,
                // join 别名列经 getAttribute 读取（PHPStan 静态分析可识别）
                'product_name' => $r->getAttribute('product_name'),
                'product_code' => $r->getAttribute('product_code'),
                'warehouse_name' => $r->getAttribute('warehouse_name'),
                'location_name' => $r->getAttribute('location_name'),
                'direction' => (int) $r->direction,
                'quantity' => $r->quantity,
                'balance_after' => $r->balance_after,
                'source_type' => $r->source_type,
                'source_type_label' => InventoryMovement::SOURCE_TYPE_LABELS[$r->source_type] ?? $r->source_type,
                'source_id' => $r->source_id,
                'source_no' => $r->source_no,
                'operator_name' => $r->getAttribute('operator_name'),
                'created_at' => $r->created_at?->toDateTimeString(),
            ]),
            'total' => $rows->total(), 'page' => $rows->currentPage(), 'per_page' => $rows->perPage(),
        ]);
    }

    /** 预警列表：查询时计算（上下限自商品实时读取，修改后立即生效） */
    public function alerts()
    {
        $rows = InventoryBalance::query()
            ->join('products', 'products.id', '=', 'inventory_balances.product_id')
            ->join('warehouses', 'warehouses.id', '=', 'inventory_balances.warehouse_id')
            ->select(
                'inventory_balances.quantity',
                // 上下限取商品实时值（预警计算依赖，修改后立即生效）
                'products.name as product_name',
                'products.code as product_code',
                'products.safety_min as safety_min',
                'products.safety_max as safety_max',
                'warehouses.name as warehouse_name'
            )
            // 预警 SQL 条件：低于下限或高于上限（0=不预警该侧）
            ->whereRaw(
                '(products.safety_min > 0 AND inventory_balances.quantity < products.safety_min) '
                .'OR (products.safety_max > 0 AND inventory_balances.quantity > products.safety_max)'
            )
            ->orderBy('inventory_balances.product_id')
            ->get();

        $items = $rows->map(fn ($r) => [
            // join 别名列经 getAttribute 读取（PHPStan 静态分析可识别）
            'product_name' => $r->getAttribute('product_name'),
            'product_code' => $r->getAttribute('product_code'),
            'warehouse_name' => $r->getAttribute('warehouse_name'),
            'quantity' => $r->quantity,
            'safety_min' => $r->safety_min,
            'safety_max' => $r->safety_max,
            'level' => $this->alertLevel($r),
        ])->values();

        return $this->ok(['items' => $items]);
    }

    // 余额查询基座：join 商品/仓库/库位 + 公共筛选（列表与导出复用）
    private function balanceQuery(Request $request)
    {
        $query = InventoryBalance::query()
            ->join('products', 'products.id', '=', 'inventory_balances.product_id')
            ->join('warehouses', 'warehouses.id', '=', 'inventory_balances.warehouse_id')
            ->join('locations', 'locations.id', '=', 'inventory_balances.location_id')
            ->select(
                'inventory_balances.id',
                'inventory_balances.product_id',
                'inventory_balances.warehouse_id',
                'inventory_balances.location_id',
                'inventory_balances.quantity',
                // 上下限取商品实时值（预警计算依赖，修改后立即生效）
                'products.name as product_name',
                'products.code as product_code',
                'products.type',
                'products.safety_min',
                'products.safety_max',
                'warehouses.name as warehouse_name',
                'locations.name as location_name'
            )
            ->orderBy('inventory_balances.product_id')
            ->orderBy('inventory_balances.location_id');

        if ($keyword = $request->input('keyword')) {
            $query->where(fn ($q) => $q->where('products.code', 'like', "%{$keyword}%")
                ->orWhere('products.name', 'like', "%{$keyword}%")
                ->orWhere('products.barcode', 'like', "%{$keyword}%"));
        }
        if ($request->filled('warehouse_id')) {
            $query->where('inventory_balances.warehouse_id', $request->input('warehouse_id'));
        }
        if ($request->filled('type')) {
            $query->where('products.type', $request->input('type'));
        }
        // 仅看预警：SQL 层过滤（与 alerts 同规则）
        if ($request->input('alert') == 1) {
            $query->whereRaw(
                '(products.safety_min > 0 AND inventory_balances.quantity < products.safety_min) '
                .'OR (products.safety_max > 0 AND inventory_balances.quantity > products.safety_max)'
            );
        }

        return $query;
    }

    // 行对象转响应条目（上下限/预警级别字段统一）
    private function balanceItem($r): array
    {
        return [
            'id' => $r->id,
            'product_id' => $r->product_id,
            'product_name' => $r->product_name,
            'product_code' => $r->product_code,
            'type' => $r->type,
            'warehouse_id' => $r->warehouse_id,
            'warehouse_name' => $r->warehouse_name,
            'location_id' => $r->location_id,
            'location_name' => $r->location_name,
            'quantity' => $r->quantity,
            'safety_min' => $r->safety_min,
            'safety_max' => $r->safety_max,
            'alert_level' => $this->alertLevel($r),
        ];
    }

    // 预警级别：min>0 且 quantity<min → 1；max>0 且 quantity>max → 2；否则 0
    private function alertLevel($r): int
    {
        $qty = (float) $r->quantity;
        if ((float) $r->safety_min > 0 && $qty < (float) $r->safety_min) {
            return 1;
        }
        if ((float) $r->safety_max > 0 && $qty > (float) $r->safety_max) {
            return 2;
        }

        return 0;
    }
}
