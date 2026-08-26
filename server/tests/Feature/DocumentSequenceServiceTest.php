<?php

// 单据号持久序列服务测试（Spec 2 配置驱动）：格式拼装/位数配置/回退默认/并发不撞/老库衔接/位宽溢出（核心路径 100%）

namespace Tests\Feature;

use App\Models\DocumentNumberConfig;
use App\Models\DocumentSequence;
use App\Services\DocumentSequenceService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DocumentSequenceServiceTest extends TestCase
{
    use RefreshDatabase;

    private DocumentSequenceService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = app(DocumentSequenceService::class);
    }

    public function test_next_no_follows_config_format(): void
    {
        // 正常路径：前缀+日期段（YmdHi）+补零正确拼装；date_format 为空时无日期段（商品编码场景）
        DocumentNumberConfig::create(['type' => 'po', 'prefix' => 'PO', 'date_format' => 'YmdHi', 'seq_length' => 3, 'is_enabled' => true]);
        DocumentNumberConfig::create(['type' => 'prd', 'prefix' => 'PRD', 'date_format' => '', 'seq_length' => 6, 'is_enabled' => true]);
        $no = $this->svc->nextNoByConfig(DocumentSequence::TYPE_PO, fn (string $no) => $no);
        $this->assertSame('PO'.date('YmdHi').'001', $no);
        $code = $this->svc->nextNoByConfig(DocumentSequence::TYPE_PRD, fn (string $no) => $no);
        $this->assertSame('PRD000001', $code);
    }

    public function test_seq_length_configurable(): void
    {
        // 边界路径：seq_length=4 分别补零正确（同类型单号长度固定）
        DocumentNumberConfig::create(['type' => 'mo', 'prefix' => 'MO', 'date_format' => 'YmdHi', 'seq_length' => 4, 'is_enabled' => true]);
        $first = $this->svc->nextNoByConfig(DocumentSequence::TYPE_MO, fn (string $no) => $no);
        $this->assertSame('MO'.date('YmdHi').'0001', $first);
    }

    public function test_config_missing_falls_back_default(): void
    {
        // 边界路径：无配置行/停用时回退默认（type 大写 + YmdHi + 3 位），不抛业务异常
        $no = $this->svc->nextNoByConfig(DocumentSequence::TYPE_PO, fn (string $no) => $no);
        $this->assertSame('PO'.date('YmdHi').'001', $no);
        DocumentNumberConfig::create(['type' => 'po', 'prefix' => 'PO', 'date_format' => 'YmdHi', 'seq_length' => 3, 'is_enabled' => false]);
        $no2 = $this->svc->nextNoByConfig(DocumentSequence::TYPE_PO, fn (string $no) => $no);
        $this->assertSame('PO'.date('YmdHi').'002', $no2);
    }

    public function test_concurrent_sequences_no_collision(): void
    {
        // 正常+异常路径：两次取号不重复；persist 撞唯一索引（模拟已存在同号单据）→ 换号重试成功
        DocumentNumberConfig::create(['type' => 'pi', 'prefix' => 'PI', 'date_format' => 'YmdHi', 'seq_length' => 3, 'is_enabled' => true]);
        $a = $this->svc->nextNoByConfig(DocumentSequence::TYPE_PI, fn (string $no) => $no);
        $b = $this->svc->nextNoByConfig(DocumentSequence::TYPE_PI, fn (string $no) => $no);
        $this->assertNotSame($a, $b);
        // 撞号重试：首次 persist 模拟唯一冲突（SQLite 19 / MySQL 1062 双码）
        $attempts = 0;
        $this->svc->nextNoByConfig(DocumentSequence::TYPE_PI, function (string $no) use (&$attempts) {
            $attempts++;
            if ($attempts === 1) {
                $e = new QueryException('sqlite', 'insert', [], new \PDOException('UNIQUE constraint failed'));
                $e->errorInfo = ['23000', 19, 'UNIQUE constraint failed: purchase_inbounds.no'];
                throw $e;
            }

            return $no;
        });
        $this->assertSame(2, $attempts);
    }

    public function test_legacy_sequence_continue(): void
    {
        // 边界路径（老库衔接）：当日已有新格式单号段（如 -003）但无序列行 → legacyMax 返回最大序号 → 续接 004
        DocumentNumberConfig::create(['type' => 'check', 'prefix' => 'CK', 'date_format' => 'YmdHi', 'seq_length' => 3, 'is_enabled' => true]);
        $no = $this->svc->nextNoByConfig(
            DocumentSequence::TYPE_CHECK,
            fn (string $no) => $no,
            fn (string $prefix, string $dateKey) => 3,
        );
        $this->assertSame('CK'.date('YmdHi').'004', $no);
    }

    public function test_legacy_max_only_queried_when_seq_zero(): void
    {
        // 边界路径：序列行已存在（seq>0）时不再查老库（衔接只发生一次）
        DocumentNumberConfig::create(['type' => 'po', 'prefix' => 'PO', 'date_format' => 'YmdHi', 'seq_length' => 3, 'is_enabled' => true]);
        $calls = 0;
        $legacy = function () use (&$calls) {
            $calls++;

            return 0;
        };
        $this->svc->nextNoByConfig(DocumentSequence::TYPE_PO, fn (string $no) => $no, $legacy);
        $this->svc->nextNoByConfig(DocumentSequence::TYPE_PO, fn (string $no) => $no, $legacy);
        $this->assertSame(1, $calls);
    }

    public function test_seq_overflow_warns_but_continues(): void
    {
        // 边界路径：seq 达到位数上限自然溢出（999→1000 输出 4 位不截断），不抛异常。
        // 防分钟边界抖动（2026-08-23 实测期望 0338 实际 0337）：预置行/服务取号/断言三处各自读墙钟，
        // 执行中跨分钟翻转时服务查不到预置行、从新分钟空行 001 起步，断言必然失败；
        // 故统一捕获一次分钟值供预置行与断言共用，且仅在首尾分钟一致（窗口未翻转）时断言，
        // 翻转则清空序列行换新分钟整窗重试，消除对断言时刻墙钟的依赖
        DocumentNumberConfig::create(['type' => 'rl', 'prefix' => 'RL', 'date_format' => 'YmdHi', 'seq_length' => 3, 'is_enabled' => true]);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            // 清掉上一轮翻转残留的序列行，保证本轮预置的 999 行是服务取号的唯一候选
            DocumentSequence::query()->delete();
            $minute = date('YmdHi');
            DocumentSequence::create(['type' => 'rl', 'date' => $minute, 'seq' => 999]);
            $no = $this->svc->nextNoByConfig(DocumentSequence::TYPE_RL, fn (string $no) => $no);
            // 窗口稳定（取号前后同分钟）时服务命中的必是本轮预置行：999 溢出输出 4 位不截断
            if (date('YmdHi') === $minute) {
                $this->assertSame('RL'.$minute.'1000', $no);

                return;
            }
        }
        $this->fail('连续 5 个窗口均跨分钟翻转，未能稳定验证位宽溢出行为');
    }
}
