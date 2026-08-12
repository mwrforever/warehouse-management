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
        $this->call([RbacSeeder::class]);
    }
}
