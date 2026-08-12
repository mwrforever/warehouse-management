<?php

// 基础资料种子：E2E 前置与手工演示所需的最小主数据集

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Process;
use App\Models\Product;
use App\Models\Unit;
use App\Models\Warehouse;
use Illuminate\Database\Seeder;

class MasterDataSeeder extends Seeder
{
    public function run(): void
    {
        // 分类：原材料/成品（均为顶级）
        $raw = Category::firstOrCreate(['name' => '原材料'], ['parent_id' => 0, 'sort' => 1, 'status' => 1]);
        $fin = Category::firstOrCreate(['name' => '成品'], ['parent_id' => 0, 'sort' => 2, 'status' => 1]);

        // 单位：个/件/千克
        Unit::firstOrCreate(['code' => 'pc'], ['name' => '个', 'status' => 1]);
        Unit::firstOrCreate(['code' => 'piece'], ['name' => '件', 'status' => 1]);
        Unit::firstOrCreate(['code' => 'kg'], ['name' => '千克', 'status' => 1]);

        // 仓库：主仓
        Warehouse::firstOrCreate(['code' => 'WH01'], ['name' => '主仓', 'address' => '厂区A', 'manager' => '张三', 'status' => 1]);

        // 商品：原料铝材 + 成品A（供 BOM 与后续库存模块演示）
        Product::firstOrCreate(['code' => 'RAW-001'], [
            'name' => '铝材', 'type' => 'raw_material', 'category_id' => $raw->id,
            'unit_id' => Unit::where('code', 'pc')->value('id'), 'spec' => '1mm',
            'barcode' => null, 'safety_min' => 10, 'safety_max' => 100, 'status' => 1,
        ]);
        Product::firstOrCreate(['code' => 'FIN-001'], [
            'name' => '成品A', 'type' => 'finished', 'category_id' => $fin->id,
            'unit_id' => Unit::where('code', 'pc')->value('id'), 'spec' => '',
            'barcode' => null, 'safety_min' => 0, 'safety_max' => 0, 'status' => 1,
        ]);

        // 工序：下料
        Process::firstOrCreate(['code' => 'PROC-01'], ['name' => '下料', 'sort' => 1, 'description' => '原料切割下料', 'status' => 1]);
    }
}
