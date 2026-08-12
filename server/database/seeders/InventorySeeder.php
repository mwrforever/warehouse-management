<?php

// 库存基线种子：E2E 与演示所需的商品/库位/已知库存（经 InventoryService 注入，保证余额=流水恒等式）

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Location;
use App\Models\Product;
use App\Models\Unit;
use App\Models\Warehouse;
use App\Services\InventoryService;
use Illuminate\Database\Seeder;

class InventorySeeder extends Seeder
{
    public function run(): void
    {
        $svc = app(InventoryService::class);
        $wh = Warehouse::firstOrCreate(
            ['code' => 'WH01'],
            ['name' => '主仓', 'address' => '厂区A', 'manager' => '张三', 'status' => 1]
        );
        // 库位：A-01（原料/半成品）、B-01（成品）
        $a01 = Location::firstOrCreate(
            ['code' => 'A-01'],
            ['warehouse_id' => $wh->id, 'name' => 'A-01', 'status' => 1]
        );
        $b01 = Location::firstOrCreate(
            ['code' => 'B-01'],
            ['warehouse_id' => $wh->id, 'name' => 'B-01', 'status' => 1]
        );

        // 分类：半成品（原材料/成品由基础资料种子提供）
        Category::firstOrCreate(['name' => '半成品'], ['parent_id' => 0, 'sort' => 3, 'status' => 1]);
        $pc = Unit::firstOrCreate(['code' => 'pc'], ['name' => '个', 'status' => 1]);
        $rawCat = Category::where('name', '原材料')->first();
        $finCat = Category::where('name', '成品')->first();
        $semiCat = Category::where('name', '半成品')->first();

        // E2E 基线商品（条码供扫码盘点）——测试专用，数值勿改
        $mat = Product::firstOrCreate(['code' => 'MAT-001'], [
            'name' => '测试铝材', 'type' => 'raw_material', 'category_id' => $rawCat->id,
            'unit_id' => $pc->id, 'barcode' => '100001', 'safety_min' => 50, 'safety_max' => 500, 'status' => 1,
        ]);
        $semi = Product::firstOrCreate(['code' => 'SEMI-001'], [
            'name' => '半成品A', 'type' => 'semi_finished', 'category_id' => $semiCat->id,
            'unit_id' => $pc->id, 'barcode' => '100002', 'safety_min' => 10, 'safety_max' => 200, 'status' => 1,
        ]);
        $fin = Product::firstOrCreate(['code' => 'FIN-002'], [
            'name' => '成品B', 'type' => 'finished', 'category_id' => $finCat->id,
            'unit_id' => $pc->id, 'barcode' => '888888', 'safety_min' => 0, 'safety_max' => 0, 'status' => 1,
        ]);

        // 基线库存（E2E TC-INV-01 断言精确数值）：流水来源用占位采购单号
        $this->inbound($svc, $mat, $a01, 100);
        $this->inbound($svc, $semi, $a01, 30);
        $this->inbound($svc, $fin, $b01, 20);
    }

    // 通过统一引擎注入采购入库流水（种子同样满足余额=流水恒等式）
    private function inbound(InventoryService $svc, Product $product, Location $location, float $qty): void
    {
        $svc->apply([[
            'product_id' => $product->id,
            'warehouse_id' => $location->warehouse_id,
            'location_id' => $location->id,
            'direction' => 1,
            'quantity' => $qty,
            'source_type' => 'purchase_inbound',
            'source_id' => 0,
            'source_no' => 'PO'.date('Ymd').'-SEED',
            'remark' => '测试基线库存（采购模块实施后由真实单据取代）',
        ]], null);
    }
}
