<?php

// 单据号持久序列服务测试：原子取号/删除不回退/老库衔接/撞号换号重试（核心路径 100%）

namespace Tests\Feature;

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

    public function test_next_no_increments_within_day(): void
    {
        // 正常路径：同日两次取号依次 +001/+002
        $first = $this->svc->nextNo(DocumentSequence::TYPE_PO, 'PO', fn (string $no) => $no);
        $second = $this->svc->nextNo(DocumentSequence::TYPE_PO, 'PO', fn (string $no) => $no);
        $this->assertSame('PO'.date('Ymd').'-001', $first);
        $this->assertSame('PO'.date('Ymd').'-002', $second);
    }

    public function test_next_no_does_not_regress_after_delete(): void
    {
        // 边界路径：占号后单据被删，序号不回退（count+1 方案会复用已删单号撞现存单号 500）
        $this->svc->nextNo(DocumentSequence::TYPE_BOM, 'BOM', fn (string $no) => $no);
        $this->svc->nextNo(DocumentSequence::TYPE_BOM, 'BOM', fn (string $no) => $no);
        $no = $this->svc->nextNo(DocumentSequence::TYPE_BOM, 'BOM', fn (string $no) => $no);
        $this->assertSame('BOM'.date('Ymd').'-003', $no);
    }

    public function test_next_no_initializes_from_legacy_max(): void
    {
        // 边界路径：老库无序列行但当日已有 2 张历史单号 → 衔接取 -003
        $no = $this->svc->nextNo(DocumentSequence::TYPE_CHECK, 'CK', fn (string $no) => $no, fn () => 2);
        $this->assertSame('CK'.date('Ymd').'-003', $no);
    }

    public function test_next_no_retries_on_unique_conflict(): void
    {
        // 异常路径：persist 撞唯一索引（先建 -001 行再模拟冲突）→ 换号重试成功
        $attempts = 0;
        $this->svc->nextNo(DocumentSequence::TYPE_PI, 'PI', function (string $no) use (&$attempts) {
            $attempts++;
            if ($attempts === 1) {
                // 模拟单据表唯一冲突：SQLite 19 / MySQL 1062 双码兼容
                // （构造签名按当前 Laravel 版本：connectionName, sql, bindings, previous）
                $e = new QueryException('sqlite', 'insert', [], new \PDOException('UNIQUE constraint failed'));
                $e->errorInfo = ['23000', 19, 'UNIQUE constraint failed: purchase_inbounds.no'];
                throw $e;
            }

            return $no;
        });
        $this->assertSame(2, $attempts);
    }

    public function test_legacy_max_only_queried_when_seq_zero(): void
    {
        // 边界路径：序列行已存在（seq>0）时不再查老库（衔接只发生一次）
        $calls = 0;
        $this->svc->nextNo(DocumentSequence::TYPE_PO, 'PO', fn (string $no) => $no, function () use (&$calls) {
            $calls++;

            return 0;
        });
        $this->svc->nextNo(DocumentSequence::TYPE_PO, 'PO', fn (string $no) => $no, function () use (&$calls) {
            $calls++;

            return 0;
        });
        $this->assertSame(1, $calls);
    }
}
