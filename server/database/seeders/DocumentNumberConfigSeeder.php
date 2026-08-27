<?php

// 编号规则默认配置种子：单据/商品/主数据编码 17 类；type 唯一幂等

namespace Database\Seeders;

use App\Models\DocumentNumberConfig;
use Illuminate\Database\Seeder;

class DocumentNumberConfigSeeder extends Seeder
{
    /** 默认规则：type → [prefix, date_format, seq_length]（编码类无日期段、4 位补零；商品 prd 6 位补零） */
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
        'osrt' => ['ORT', 'YmdHi', 3],
        'fi' => ['FI', 'YmdHi', 3],
        'rtg' => ['RTG', 'YmdHi', 3],
        'prd' => ['PRD', '', 6],
        'proc' => ['PROC', '', 4],
        'wh' => ['WH', '', 4],
    ];

    public function run(): void
    {
        foreach (self::RULES as $type => [$prefix, $dateFormat, $seqLength]) {
            DocumentNumberConfig::firstOrCreate(['type' => $type], [
                'prefix' => $prefix,
                'date_format' => $dateFormat,
                'seq_length' => $seqLength,
                'is_enabled' => true,
                'remark' => null,
            ]);
        }
    }
}
