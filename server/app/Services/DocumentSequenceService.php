<?php

// 单据号持久序列服务：全系统单据号唯一出口（盘点 CK/采购 PO/PI/BOM/商品编码 PRD 统一走此服务）
//
// 取号规则（Spec 2 配置驱动）：从 document_number_configs 读 type 的 prefix/date_format/seq_length，
// 拼装 {prefix}{日期段}{序列补零}（无连字符）；序号存 document_sequences 表（type+date 唯一），
// FOR UPDATE 原子自增；单据删除不回退号段；并发撞号（MySQL 1062 / SQLite 19）自动换号重试；
// 老库首次衔接：序列行 seq=0 时以 legacyMax 闭包返回的当日最大序号为起点。

namespace App\Services;

use App\Models\DocumentNumberConfig;
use App\Models\DocumentSequence;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

class DocumentSequenceService
{
    /**
     * 按配置生成并占号下一张单据号/商品编码（须在调用方 DB::transaction 内调用）
     *
     * @param  string  $type  DocumentSequence::TYPE_* 常量（check/bom/po/pi/.../prd）
     * @param  callable  $persist  占号闭包：接收最终单号返回创建的单据模型（撞号冲突在此重试）
     * @param  callable|null  $legacyMax  老库衔接闭包：接收配置前缀 string 与日期键 string，返回当日既有单号段最大序号（int；null 跳过）
     * @return mixed $persist 的返回值（创建的单据）
     *
     * @throws \RuntimeException 连续冲突 3 次（并发异常，需人工检查）
     */
    public function nextNoByConfig(string $type, callable $persist, ?callable $legacyMax = null): mixed
    {
        // 读配置：缺失或停用时回退默认规则（前缀=type 大写、YmdHi、3 位），保证编号能力不因配置损坏而中断
        $cfg = DocumentNumberConfig::where('type', $type)->where('enabled', true)->first();
        // 注意 date_format 空字符串（商品编码无日期段）是合法配置值，回退默认判断须在其值之后（?? 区分 null 与空串）
        $prefix = $cfg !== null ? $cfg->prefix : strtoupper($type);
        $dateFormat = $cfg !== null ? $cfg->date_format : 'YmdHi';
        $seqLength = $cfg !== null ? $cfg->seq_length : 3;
        // 序列键粒度与 date_format 对齐（YmdHi → 键为分钟）；商品编码 date_format 为空 → 键为空串全局自增
        $dateKey = $dateFormat === '' ? '' : date($dateFormat);

        for ($i = 0; $i < 3; $i++) {
            try {
                // 原子取号：序列行不存在则创建（并发下由唯一索引拦截后换号重试）
                $row = DocumentSequence::lockForUpdate()->firstOrCreate(
                    ['type' => $type, 'date' => $dateKey],
                    ['seq' => 0],
                );
                // 锁内衔接老库：序列行首次初始化（seq=0）时，起点取当日既有单号段最大序号
                if ((int) $row->seq === 0 && $legacyMax !== null) {
                    $maxSeq = (int) $legacyMax($prefix, $dateKey);
                    if ($maxSeq > 0) {
                        $row->seq = $maxSeq;
                    }
                }
                $seq = $row->seq + 1;
                $row->seq = $seq;
                $row->save();

                $no = $prefix.$dateKey.str_pad((string) $seq, $seqLength, '0', STR_PAD_LEFT);
                // 位宽溢出（如 999→1000 时输出 4 位）：不截断继续增长，但提示调整配置（spec §7）
                if (strlen((string) $seq) > $seqLength) {
                    Log::warning("单号序号已超出配置位数（type={$type}, seq={$seq}, seq_length={$seqLength}），请评审位宽一致性");
                }

                // 占号：persist 内创建单据，若与历史遗留单号冲突由唯一索引拦截后换号重试
                return $persist($no);
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

    /**
     * 从既有单号中提取序号（legacyMax 闭包共用）
     *
     * 兼容两种格式：切换当天的旧格式 {prefix}{Ymd}-{seq}（带连字符）与新格式 {prefix}{dateKey}{seq}（无连字符）。
     * 旧格式取连字符之后的数字；新格式剥离前缀+日期键后剩余即为序号。
     *
     * @param  string  $no  既有单号
     * @param  string  $prefix  配置前缀
     * @param  string  $dateKey  配置日期键（date_format 格式化结果，可能为空串）
     */
    public static function seqFromNo(string $no, string $prefix, string $dateKey): int
    {
        if (preg_match('/-(\d+)$/', $no, $m)) {
            return (int) $m[1];
        }

        return (int) substr($no, strlen($prefix.$dateKey));
    }
}
