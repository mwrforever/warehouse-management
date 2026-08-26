<?php

// 工序服务：工序创建/更新/删除（编码唯一 1112 + 被工单引用保护 1113）
// 写操作均为单表原子写，无跨表事务，不包 DB::transaction（原子性由单条 insert/update/delete 保证）

namespace App\Services;

use App\Exceptions\MasterDataException;
use App\Models\Process;
use App\Support\DeletionGuard;
use Illuminate\Support\Facades\Log;

class ProcessService
{
    /**
     * 新建工序（原控制器 store 下沉）：编码重复 1112
     *
     * @param  array  $data  已过 SaveProcessRequest 格式校验的载荷（name/code/sort/description/status）
     * @return Process 新建工序（供控制器回显 id）
     *
     * @throws MasterDataException 编码重复 1112
     */
    public function create(array $data): Process
    {
        // 编码唯一 1112（读检查无需持锁；数据库 unique 约束兜底并发场景）
        if (Process::where('code', $data['code'])->exists()) {
            throw new MasterDataException('工序编码已存在', 1112);
        }
        $process = Process::create([
            'name' => $data['name'],
            'code' => $data['code'],
            'sort' => $data['sort'] ?? 0,
            'description' => $data['description'] ?? null,
            'status' => $data['status'] ?? Process::STATUS_ENABLED,
        ]);

        Log::info('工序创建成功', ['process_id' => $process->id, 'code' => $process->code, 'operator' => auth()->id()]);

        return $process;
    }

    /**
     * 更新工序（原控制器 update 下沉）：编码唯一（排除自身）1112
     *
     * @param  Process  $process  路由绑定的工序模型
     * @param  array  $data  已过 SaveProcessRequest 格式校验的载荷
     *
     * @throws MasterDataException 编码重复 1112
     */
    public function update(Process $process, array $data): void
    {
        if (Process::where('code', $data['code'])->where('id', '!=', $process->id)->exists()) {
            throw new MasterDataException('工序编码已存在', 1112);
        }
        $process->update([
            'name' => $data['name'], 'code' => $data['code'],
            'sort' => $data['sort'] ?? $process->sort, 'description' => $data['description'] ?? $process->description,
            'status' => $data['status'] ?? $process->status,
        ]);

        Log::info('工序更新成功', ['process_id' => $process->id, 'code' => $process->code, 'operator' => auth()->id()]);
    }

    /**
     * 删除工序（原控制器 destroy 下沉）：被生产工单引用 1113（工单表由生产模块创建，未建时守卫自动放行）
     *
     * @param  Process  $process  路由绑定的工序模型
     *
     * @throws MasterDataException 被工单引用 1113
     */
    public function delete(Process $process): void
    {
        if (DeletionGuard::referenced('work_order_operations', 'process_id', $process->id)) {
            throw new MasterDataException('工序已被生产工单使用，不可删除', 1113);
        }
        $process->delete();

        Log::info('工序删除成功', ['process_id' => $process->id, 'code' => $process->code, 'operator' => auth()->id()]);
    }
}
