<?php

// 工序控制器：列表（sort 升序 + 可选分类筛选）读取 + CRUD 薄壳（写流程全部下沉 ProcessService）

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Master\SaveProcessRequest;
use App\Models\Process;
use App\Services\ProcessService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class ProcessController extends Controller
{
    use ApiResponse;

    public function __construct(private ProcessService $processService) {}

    /** 列表：sort 升序全量返回（生产模块工序下拉数据源），支持 category_id 分类筛选 */
    public function index(Request $request)
    {
        $query = Process::with('category')->orderBy('sort')->orderBy('id');
        if ($request->filled('category_id')) {
            $query->where('category_id', $request->input('category_id'));
        }
        $items = $query->get()
            ->map(fn ($p) => [
                'id' => $p->id,
                'name' => $p->name,
                'code' => $p->code,
                'category_id' => $p->category_id,
                'category_label' => $p->category?->label,
                'sort' => $p->sort,
                'description' => $p->description,
                'status' => $p->status,
            ]);

        return $this->ok(['items' => $items]);
    }

    /** 新建工序：编码自动生成（PROC 前缀），响应回填 code 供前端展示 */
    public function store(SaveProcessRequest $request)
    {
        // 写流程下沉 ProcessService（编码经编号配置自动生成；删除引用保护 1113 由其抛出）
        $process = $this->processService->create($request->validated());

        // 响应回填自动生成的编码（前端弹窗保存后可展示，对齐商品编码路径）
        return $this->ok(['id' => $process->id, 'code' => $process->code]);
    }

    /** 更新工序：编码保持不变（载荷中的 code 忽略） */
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
