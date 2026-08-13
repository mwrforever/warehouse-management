<?php

// 仪表盘控制器：4 个只读聚合接口（无参数、无校验、无业务码）
// 聚合逻辑全部在 DashboardService（Task 1），本控制器仅做契约层

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DashboardService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly DashboardService $service) {}

    /**
     * KPI 汇总：库存总量/总值/今日出入库/待审核数/生产中工单数/预警数
     * 权限 dashboard.view（operator 亦持有——默认落地页）
     */
    public function summary(Request $request)
    {
        return $this->ok($this->service->summary($request->user()));
    }

    /**
     * 待审核单据列表：按当前用户审核权限过滤（最多 20 条，创建时间倒序）
     * 权限 dashboard.view
     */
    public function pendingApprovals(Request $request)
    {
        return $this->ok($this->service->pendingApprovals($request->user()));
    }

    /**
     * 工单进度列表：生产中/已完成工单（最多 10 条，更新时间倒序）
     * 权限 dashboard.view
     */
    public function workOrderProgress()
    {
        return $this->ok($this->service->workOrderProgress());
    }

    /**
     * 库存预警列表：低库存（level=1）前 10 条，与库存预警页同口径
     * 权限 dashboard.view
     */
    public function alerts()
    {
        return $this->ok($this->service->alerts());
    }
}
