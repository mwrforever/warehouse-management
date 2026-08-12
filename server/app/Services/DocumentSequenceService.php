<?php

// 单据号持久序列服务：全系统单据号唯一出口（盘点 CK/采购 PO/PI/BOM 统一走此服务）
//
// 取号规则：序号存 document_sequences 表（type+date 唯一），FOR UPDATE 原子自增；
// 单据删除不回退号段（与存量行数解耦）；并发撞号（MySQL 1062 / SQLite 19）自动换号重试；
// 老库首次衔接：序列行 seq=0 时以当日既有单号段最大序号为起点。

namespace App\Services;

use App\Models\DocumentSequence;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

class DocumentSequenceService
{
    /**
     * 生成并占号下一张单据号（须在调用方 DB::transaction 内调用）
     *
     * @param  string  $type  DocumentSequence::TYPE_* 常量（check/bom/po/pi）
     * @param  string  $prefix  单号前缀（如 CK/PO/PI/BOM）
     * @param  callable  $persist  占号闭包：接收单号返回创建的单据模型（撞号冲突在此重试）
     * @param  callable|null  $legacyMax  老库衔接闭包：返回当日既有单号段最大序号（null 跳过）
     * @return mixed $persist 的返回值（创建的单据）
     *
     * @throws \RuntimeException 连续冲突 3 次（并发异常，需人工检查）
     */
    public function nextNo(string $type, string $prefix, callable $persist, ?callable $legacyMax = null): mixed
    {
        $date = date('Ymd');
        for ($i = 0; $i < 3; $i++) {
            try {
                // 原子取号：序列行不存在则创建（并发下由唯一索引拦截后换号重试）
                $row = DocumentSequence::lockForUpdate()->firstOrCreate(
                    ['type' => $type, 'date' => $date],
                    ['seq' => 0],
                );
                // 锁内衔接老库：序列行首次初始化（seq=0）时，起点取当日既有单号段最大值
                if ((int) $row->seq === 0 && $legacyMax !== null) {
                    $maxSeq = (int) $legacyMax();
                    if ($maxSeq > 0) {
                        $row->seq = $maxSeq;
                    }
                }
                $seq = $row->seq + 1;
                $row->seq = $seq;
                $row->save();

                // 占号：persist 内创建单据，若与历史遗留单号冲突由唯一索引拦截后换号重试
                return $persist(sprintf('%s%s-%03d', $prefix, $date, $seq));
            } catch (QueryException $e) {
                // 唯一冲突（MySQL 1062 / SQLite 19）：换号重试；其余异常原样抛出
                if (! in_array($e->errorInfo[1] ?? null, [1062, 19], true)) {
                    throw $e;
                }
            }
        }
        Log::warning("单号生成连续冲突 3 次（type={$type}），请人工检查并发");
        throw new \RuntimeException('单号生成失败，请重试');
    }
}
