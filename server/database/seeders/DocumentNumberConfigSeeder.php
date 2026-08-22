<?php

// 编号规则默认配置种子：12 类单据 + 商品编码 prd；type 唯一幂等；osrt（委外退料）由委外重构 spec 追加

namespace Database\Seeders;

use App\Models\DocumentNumberConfig;
use Illuminate\Database\Seeder;

class DocumentNumberConfigSeeder extends Seeder
{
    /** 默认规则：type → [prefix, date_format, seq_length]（product 编码无日期段、6 位补零） */
    private const RULES = [
        'check' => ['CK', 'YmdHi', 3],
        'bom' => ['BOM', 'YmdHi', 3],
        'po' => ['PO', 'YmdHi', 3],
        'pi' => ['PI', 'YmdHi', 3],
        'so' => ['SO', 'YmdHi', 3],
        'sout' => ['ST', 'YmdHi', 3],
        'mo' => ['MO', 'YmdHi', 3],
        'pl' => ['PL', 'YmdHi', 3],
        'rl' => ['RL', 'YmdHi', 3],
        'os' => ['OS', 'YmdHi', 3],
        'osr' => ['OSR', 'YmdHi', 3],
        'fi' => ['FI', 'YmdHi', 3],
        'prd' => ['PRD', '', 6],
    ];

    public function run(): void
    {
        foreach (self::RULES as $type => [$prefix, $dateFormat, $seqLength]) {
            DocumentNumberConfig::firstOrCreate(['type' => $type], [
                'prefix' => $prefix,
                'date_format' => $dateFormat,
                'seq_length' => $seqLength,
                'enabled' => true,
                'remark' => null,
            ]);
        }
    }
}
