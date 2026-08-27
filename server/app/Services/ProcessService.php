<?php

// 工序服务：工序创建/更新/删除（编码自动生成、被工单引用保护 1113）
// 创建走 DocumentSequenceService 取号（PROC 前缀全局自增，无日期段），为承载编码序列行
// 间隙锁死锁重试语义，创建路径包 DB::transaction（机理与 ProductService::create 一致）

namespace App\Services;

use App\Exceptions\MasterDataException;
use App\Models\DocumentSequence;
use App\Models\Process;
use App\Support\DeletionGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessService
{
    public function __construct(private DocumentSequenceService $sequenceService) {}

    /**
     * 新建工序：编码由编号配置自动生成（PROC 前缀全局自增，历史 PROC-xx 老库衔接）
     *
     * @param  array  $data  已过 SaveProcessRequest 格式校验的载荷（name/category_id/sort/description/status，无 code）
     * @return Process 新建工序（含自动生成的编码，供控制器响应回填）
     *
     * @throws MasterDataException 删除引用保护 1113（delete 路径）
     */
    public function create(array $data): Process
    {
        // 除编码外的工序属性（编码由序列服务生成，见下方持久闭包）
        $attributes = [
            'name' => $data['name'],
            'category_id' => $data['category_id'] ?? null,
            'sort' => $data['sort'] ?? 0,
            'description' => $data['description'] ?? null,
            'status' => $data['status'] ?? Process::STATUS_ENABLED,
        ];

        // 事务第 2 参数为死锁(1213)重试次数（机理同 ProductService::create：工序编码序列行首建
        // 间隙锁死锁败方整体回滚后重跑闭包重新取号，幂等安全）
        $process = DB::transaction(function () use ($attributes) {
            return $this->sequenceService->nextNoByConfig(
                DocumentSequence::TYPE_PROC,
                // Process::create 必须封装在持久闭包内（B-1）：create 撞 processes 唯一索引
                // （历史 PROC-01 等占号冲突）时由服务的换号重试消化；若 create 落在闭包外，
                // 异常直接 500 且序列自增随事务回滚，自动编码路径将每次取同一号反复失败、永久不可用
                fn (string $no) => Process::create($attributes + ['code' => $no]),
                // 老库衔接：历史种子 PROC-01/PROC-02 这类「PROC- 分隔符 + 数字」编码（历史实现预留）
                // 由 seqFromNo 兼容解析（含无分隔符 PROC01 新格式，解析失败返回 0），取既有最大序号为序列起点
                fn (string $prefix, string $dateKey) => ($no = Process::where('code', 'like', $prefix.'%')
                    ->orderByDesc('code')->value('code')) ? DocumentSequenceService::seqFromNo($no, $prefix, $dateKey) : 0,
            );
        }, 2);

        Log::info('工序创建成功', ['process_id' => $process->id, 'code' => $process->code, 'operator' => auth()->id()]);

        return $process;
    }

    /**
     * 更新工序：名称/分类/排序/描述/状态；编码保持不变（编号创建时自动生成，更新不重排）
     *
     * @param  Process  $process  路由绑定的工序模型
     * @param  array  $data  已过 SaveProcessRequest 格式校验的载荷（无 code；category_id 传空即清除分类）
     */
    public function update(Process $process, array $data): void
    {
        $process->update([
            'name' => $data['name'],
            'category_id' => $data['category_id'] ?? null,
            'sort' => $data['sort'] ?? $process->sort,
            'description' => $data['description'] ?? $process->description,
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
