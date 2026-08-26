<?php

// 工序控制器：列表（sort 升序）读取 + CRUD 薄壳（写流程全部下沉 ProcessService）

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Master\SaveProcessRequest;
use App\Models\Process;
use App\Services\ProcessService;
use App\Support\ApiResponse;

class ProcessController extends Controller
{
    use ApiResponse;

    public function __construct(private ProcessService $processService) {}

    /** 列表：sort 升序全量返回（生产模块工序下拉数据源） */
    public function index()
    {
        $items = Process::orderBy('sort')->orderBy('id')->get()
            ->map(fn ($p) => [
                'id' => $p->id,
                'name' => $p->name,
                'code' => $p->code,
                'sort' => $p->sort,
                'description' => $p->description,
                'status' => $p->status,
            ]);

        return $this->ok(['items' => $items]);
    }

    /** 新建工序：编码重复 1112 */
    public function store(SaveProcessRequest $request)
    {
        // 写流程下沉 ProcessService（编码唯一 1112、删除引用保护 1113 由其抛出）
        return $this->ok(['id' => $this->processService->create($request->validated())->id]);
    }

    /** 更新工序 */
    public function update(SaveProcessRequest $request, Process $process)
    {
        $this->processService->update($process, $request->validated());

        return $this->ok();
    }

    /** 删除工序：被生产工单引用 1113（工单表由生产模块创建，未建时守卫自动放行） */
    public function destroy(Process $process)
    {
        $this->processService->delete($process);

        return $this->ok();
    }
}
