<?php

// 工序标签分类字典种子：名称「工序分类」code process_category + 5 个启用字典项（幂等，可重复执行）

namespace Database\Seeders;

use App\Models\Dictionary;
use App\Models\DictionaryItem;
use Illuminate\Database\Seeder;

class DictionaryProcessCategorySeeder extends Seeder
{
    /** 字典项：label → [value, sort]（value 为机器值，sort 200 起递增预留前插空间） */
    private const ITEMS = [
        '机械加工' => ['machining', 200],
        '冲压焊接' => ['stamping_welding', 210],
        '装配' => ['assembly', 220],
        '检验' => ['inspection', 230],
        '辅助' => ['auxiliary', 240],
    ];

    public function run(): void
    {
        // 字典头幂等（code 唯一）+ 字典项幂等（dictionary_id+value 组合重复执行不新增）
        $dict = Dictionary::firstOrCreate(['code' => 'process_category'], [
            'name' => '工序分类',
            'remark' => '工序标签分类（字典项）',
        ]);
        foreach (self::ITEMS as $label => [$value, $sort]) {
            DictionaryItem::firstOrCreate(['dictionary_id' => $dict->id, 'value' => $value], [
                'label' => $label,
                'sort' => $sort,
                'status' => DictionaryItem::STATUS_ENABLED,
            ]);
        }
    }
}
