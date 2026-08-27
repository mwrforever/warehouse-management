<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // RBAC 基础数据：权限、角色、内置 admin 用户、计量单位字典
        // 注：已移除脚手架默认 User::factory() 调用（其创建的用户缺 username，且与内置 admin 种子职责重复）
        // 基础资料主数据：分类/单位/仓库/商品/工序
        // 库存基线：E2E 与演示用的商品/库位/已知库存（经 InventoryService 注入）
        // 编号规则：各类单据号/商品编码的 prefix + date_format + seq_length 默认配置（Spec 2）
        // 工序标签分类字典：工序管理分类下拉数据源（process_category）
        $this->call([
            RbacSeeder::class,
            MasterDataSeeder::class,
            InventorySeeder::class,
            DocumentNumberConfigSeeder::class,
            DictionaryProcessCategorySeeder::class,
        ]);
    }
}
