<?php
// 工序控制器：列表（sort 升序）+ CRUD + 编码唯一 + 被工单引用保护
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Process;
use App\Support\ApiResponse;
use App\Support\DeletionGuard;
use Illuminate\Http\Request;

class ProcessController extends Controller
{
    use ApiResponse;

    /** 列表：sort 升序全量返回（生产模块工序下拉数据源） */
    public function index()
    {
        $items = Process::orderBy('sort')->orderBy('id')->get()
            ->map(fn ($p) => ['id' => $p->id, 'name' => $p->name, 'code' => $p->code, 'sort' => $p->sort, 'description' => $p->description, 'status' => $p->status]);
        return $this->ok(['items' => $items]);
    }

    /** 新建工序：编码重复 1112 */
    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:50',
            'code' => 'required|string|max:50',
            'sort' => 'nullable|integer',
            'description' => 'nullable|string',
            'status' => 'nullable|in:0,1',
        ]);
        if (Process::where('code', $data['code'])->exists()) {
            return $this->fail(1112, '工序编码已存在');
        }
        $process = Process::create([
            'name' => $data['name'], 'code' => $data['code'],
            'sort' => $data['sort'] ?? 0, 'description' => $data['description'] ?? null, 'status' => $data['status'] ?? 1,
        ]);
        return $this->ok(['id' => $process->id]);
    }

    /** 更新工序 */
    public function update(Request $request, Process $process)
    {
        $data = $request->validate([
            'name' => 'required|string|max:50',
            'code' => 'required|string|max:50',
            'sort' => 'nullable|integer',
            'description' => 'nullable|string',
            'status' => 'nullable|in:0,1',
        ]);
        if (Process::where('code', $data['code'])->where('id', '!=', $process->id)->exists()) {
            return $this->fail(1112, '工序编码已存在');
        }
        $process->update([
            'name' => $data['name'], 'code' => $data['code'],
            'sort' => $data['sort'] ?? $process->sort, 'description' => $data['description'] ?? $process->description,
            'status' => $data['status'] ?? $process->status,
        ]);
        return $this->ok();
    }

    /** 删除工序：被生产工单引用 1113（工单表由生产模块创建，未建时守卫自动放行） */
    public function destroy(Process $process)
    {
        if (DeletionGuard::referenced('work_order_operations', 'process_id', $process->id)) {
            return $this->fail(1113, '工序已被生产工单使用，不可删除');
        }
        $process->delete();
        return $this->ok();
    }
}
