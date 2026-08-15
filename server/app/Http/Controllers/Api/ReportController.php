<?php

// 统计报表控制器：4 类只读聚合接口（参数校验 + 业务码 1601 + 统一响应）
// 聚合逻辑全部在 ReportService（Task 1/2），本控制器仅做契约层

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InventoryMovement;
use App\Services\ReportService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ReportService $service) {}

    /**
     * 库存报表：按维度汇总当前余额（group_by=category|warehouse|type；date_to V1 预留）
     * 权限 report.inventory
     */
    public function inventorySummary(Request $request)
    {
        $v = $request->validate([
            'group_by' => ['sometimes', 'string', 'in:category,warehouse,type'],
            'date_to' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ]);

        return $this->ok($this->service->inventorySummary($v['group_by'] ?? 'category', $v['date_to'] ?? null));
    }

    /**
     * 出入库汇总：按日/月粒度聚合流水方向（闭区间；source_type 可空筛选）
     * 权限 report.movements
     */
    public function movementsSummary(Request $request)
    {
        $range = $this->validDateRange($request);
        if ($range === null) {
            return $this->fail(1601, '开始日期不能晚于结束日期');
        }
        $v = $request->validate([
            'granularity' => ['sometimes', 'string', 'in:day,month'],
            'source_type' => ['sometimes', 'nullable', 'string', 'in:'.implode(',', InventoryMovement::SOURCE_TYPES)],
        ]);

        // 区间跨度上限（P2-2①）：日粒度 ≤ 366 天、月粒度 ≤ 36 个月，超出 1601「日期区间过长」——
        // 防区间无上限导致流水全量遍历（复用 1601 业务码，与倒置区间消息区分）
        $granularity = $v['granularity'] ?? 'day';
        $span = (new \DateTime($range['date_to']))->diff(new \DateTime($range['date_from']));
        if ($granularity === 'day') {
            if ($span->days > 366) {
                return $this->fail(1601, '日期区间过长');
            }
        } elseif ($span->y * 12 + $span->m > 36 || ($span->y * 12 + $span->m === 36 && $span->d > 0)) {
            // 月粒度含天数分量：36 个月 + 1 天以上同样超限（与日粒度 366 天口径对齐，防月+日拼接绕过）
            return $this->fail(1601, '日期区间过长');
        }

        return $this->ok($this->service->movementsSummary(
            $range['date_from'],
            $range['date_to'],
            $granularity,
            $v['source_type'] ?? null,
        ));
    }

    /**
     * 生产统计：计划日期窗口内工单达成率/良率/工时/物料耗用（product_id 可空筛选）
     * 权限 report.production
     */
    public function production(Request $request)
    {
        $range = $this->validDateRange($request);
        if ($range === null) {
            return $this->fail(1601, '开始日期不能晚于结束日期');
        }
        $v = $request->validate([
            'product_id' => ['sometimes', 'nullable', 'integer'],
        ]);

        return $this->ok($this->service->production($range['date_from'], $range['date_to'], $v['product_id'] ?? null));
    }

    /**
     * 采购销售汇总：已审核单据金额/数量按审核时间分桶（金额分→元）
     * 权限 report.purchase_sales
     */
    public function purchaseSales(Request $request)
    {
        $range = $this->validDateRange($request);
        if ($range === null) {
            return $this->fail(1601, '开始日期不能晚于结束日期');
        }
        $v = $request->validate([
            'granularity' => ['sometimes', 'string', 'in:day,month'],
        ]);

        return $this->ok($this->service->purchaseSales($range['date_from'], $range['date_to'], $v['granularity'] ?? 'day'));
    }

    /**
     * 日期闭区间公共校验（出入库/生产/采购销售共用）
     * 格式层：缺参数/格式非 Y-m-d → 422（validate 抛 ValidationException）；
     * 业务层：倒置区间返回 null（调用方响应 1601 业务码）
     */
    private function validDateRange(Request $request): ?array
    {
        $v = $request->validate([
            'date_from' => ['required', 'date_format:Y-m-d'],
            'date_to' => ['required', 'date_format:Y-m-d'],
        ]);

        // 倒置区间：业务码 1601（spec §7），Y-m-d 字符串比较=日期比较
        return $v['date_from'] > $v['date_to'] ? null : $v;
    }
}
