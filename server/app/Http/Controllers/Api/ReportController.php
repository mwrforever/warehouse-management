<?php

// 统计报表控制器：4 类只读聚合接口（参数校验 + 业务码 1601 + 统一响应）
// 聚合逻辑全部在 ReportService（Task 1/2），本控制器仅做契约层

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Report\InventorySummaryRequest;
use App\Http\Requests\Report\MovementsSummaryRequest;
use App\Http\Requests\Report\ProductionReportRequest;
use App\Http\Requests\Report\PurchaseSalesRequest;
use App\Services\ReportService;
use App\Support\ApiResponse;
use Illuminate\Foundation\Http\FormRequest;

class ReportController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ReportService $service) {}

    /**
     * 库存报表：按维度汇总当前余额（group_by=category|warehouse|type；date_to V1 预留）
     * 权限 report.inventory
     */
    public function inventorySummary(InventorySummaryRequest $request)
    {
        // 格式层（维度枚举/日期格式）已由 FormRequest 拦截 422，此处仅消费校验后参数
        $v = $request->validated();

        return $this->ok($this->service->inventorySummary($v['group_by'] ?? 'category', $v['date_to'] ?? null));
    }

    /**
     * 出入库汇总：按日/月粒度聚合流水方向（闭区间；source_type 可空筛选）
     * 权限 report.movements
     */
    public function movementsSummary(MovementsSummaryRequest $request)
    {
        $range = $this->validDateRange($request);
        if ($range === null) {
            return $this->fail(1601, '开始日期不能晚于结束日期');
        }
        // 格式层（粒度/来源类型枚举）已由 FormRequest 拦截 422，此处仅消费校验后参数
        $v = $request->validated();

        // 区间跨度上限（日粒度 ≤ 366 天、月粒度 ≤ 36 个月，超出 1601「日期区间过长」）——
        // 防区间无上限导致流水全量遍历（复用 1601 业务码，与倒置区间消息区分）
        $granularity = $v['granularity'] ?? 'day';
        if ($this->spanTooLong($range, $granularity)) {
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
    public function production(ProductionReportRequest $request)
    {
        $range = $this->validDateRange($request);
        if ($range === null) {
            return $this->fail(1601, '开始日期不能晚于结束日期');
        }
        // 区间跨度上限（无粒度参数，按日粒度 366 天上限）：防区间无上限导致区间工单全量装载
        if ($this->spanTooLong($range)) {
            return $this->fail(1601, '日期区间过长');
        }
        // 格式层（product_id 整数形态）已由 FormRequest 拦截 422，此处仅消费校验后参数
        $v = $request->validated();

        return $this->ok($this->service->production($range['date_from'], $range['date_to'], $v['product_id'] ?? null));
    }

    /**
     * 采购销售汇总：已审核单据金额/数量按审核时间分桶（金额分→元）
     * 权限 report.purchase_sales
     */
    public function purchaseSales(PurchaseSalesRequest $request)
    {
        $range = $this->validDateRange($request);
        if ($range === null) {
            return $this->fail(1601, '开始日期不能晚于结束日期');
        }
        // 格式层（粒度枚举）已由 FormRequest 拦截 422，此处仅消费校验后参数
        $v = $request->validated();

        // 区间跨度上限（与出入库汇总同款）：防区间无上限导致单据+明细全量装载
        if ($this->spanTooLong($range, $v['granularity'] ?? 'day')) {
            return $this->fail(1601, '日期区间过长');
        }

        return $this->ok($this->service->purchaseSales($range['date_from'], $range['date_to'], $v['granularity'] ?? 'day'));
    }

    /**
     * 日期闭区间公共校验（出入库/生产/采购销售共用）
     * 格式层：缺参数/格式非 Y-m-d → 422（各接口 FormRequest 抛 ValidationException）；
     * 业务层：倒置区间返回 null（调用方响应 1601 业务码）
     */
    private function validDateRange(FormRequest $request): ?array
    {
        $v = $request->validated();

        // 倒置区间：业务码 1601（spec §7），Y-m-d 字符串比较=日期比较
        return $v['date_from'] > $v['date_to'] ? null : $v;
    }

    /**
     * 区间跨度上限校验（出入库/采购销售/生产共用）：日粒度 ≤ 366 天、月粒度 ≤ 36 个月
     * 月粒度含天数分量：36 个月 + 1 天以上同样超限（与日粒度 366 天口径对齐，防月+日拼接绕过）；
     * 无粒度参数的接口（生产统计）默认按日粒度上限
     */
    private function spanTooLong(array $range, string $granularity = 'day'): bool
    {
        $span = (new \DateTime($range['date_to']))->diff(new \DateTime($range['date_from']));
        if ($granularity === 'day') {
            return $span->days > 366;
        }

        return $span->y * 12 + $span->m > 36 || ($span->y * 12 + $span->m === 36 && $span->d > 0);
    }
}
