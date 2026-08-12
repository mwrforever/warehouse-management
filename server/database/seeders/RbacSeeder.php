<?php

// RBAC 种子：权限分组数据 + admin/operator 角色 + 超级管理员

namespace Database\Seeders;

use App\Models\Dictionary;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

class RbacSeeder extends Seeder
{
    public function run(): void
    {
        // 全系统权限清单（后续模块在各自种子中追加，本计划先注册系统管理权限）
        $permissions = [
            ['name' => '用户列表', 'code' => 'user.list', 'group' => '系统管理'],
            ['name' => '用户创建', 'code' => 'user.create', 'group' => '系统管理'],
            ['name' => '用户更新', 'code' => 'user.update', 'group' => '系统管理'],
            ['name' => '用户删除', 'code' => 'user.delete', 'group' => '系统管理'],
            ['name' => '角色列表', 'code' => 'role.list', 'group' => '系统管理'],
            ['name' => '角色创建', 'code' => 'role.create', 'group' => '系统管理'],
            ['name' => '角色更新', 'code' => 'role.update', 'group' => '系统管理'],
            ['name' => '角色删除', 'code' => 'role.delete', 'group' => '系统管理'],
            ['name' => '字典列表', 'code' => 'dictionary.list', 'group' => '系统管理'],
            ['name' => '字典创建', 'code' => 'dictionary.create', 'group' => '系统管理'],
            ['name' => '字典更新', 'code' => 'dictionary.update', 'group' => '系统管理'],
            ['name' => '字典删除', 'code' => 'dictionary.delete', 'group' => '系统管理'],
            // 基础资料模块权限（8 资源 × 4 动作）
            ['name' => '商品列表', 'code' => 'product.list', 'group' => '基础资料'],
            ['name' => '商品创建', 'code' => 'product.create', 'group' => '基础资料'],
            ['name' => '商品更新', 'code' => 'product.update', 'group' => '基础资料'],
            ['name' => '商品删除', 'code' => 'product.delete', 'group' => '基础资料'],
            ['name' => '分类列表', 'code' => 'category.list', 'group' => '基础资料'],
            ['name' => '分类创建', 'code' => 'category.create', 'group' => '基础资料'],
            ['name' => '分类更新', 'code' => 'category.update', 'group' => '基础资料'],
            ['name' => '分类删除', 'code' => 'category.delete', 'group' => '基础资料'],
            ['name' => '单位列表', 'code' => 'unit.list', 'group' => '基础资料'],
            ['name' => '单位创建', 'code' => 'unit.create', 'group' => '基础资料'],
            ['name' => '单位更新', 'code' => 'unit.update', 'group' => '基础资料'],
            ['name' => '单位删除', 'code' => 'unit.delete', 'group' => '基础资料'],
            ['name' => '仓库列表', 'code' => 'warehouse.list', 'group' => '基础资料'],
            ['name' => '仓库创建', 'code' => 'warehouse.create', 'group' => '基础资料'],
            ['name' => '仓库更新', 'code' => 'warehouse.update', 'group' => '基础资料'],
            ['name' => '仓库删除', 'code' => 'warehouse.delete', 'group' => '基础资料'],
            ['name' => '供应商列表', 'code' => 'supplier.list', 'group' => '基础资料'],
            ['name' => '供应商创建', 'code' => 'supplier.create', 'group' => '基础资料'],
            ['name' => '供应商更新', 'code' => 'supplier.update', 'group' => '基础资料'],
            ['name' => '供应商删除', 'code' => 'supplier.delete', 'group' => '基础资料'],
            ['name' => '客户列表', 'code' => 'customer.list', 'group' => '基础资料'],
            ['name' => '客户创建', 'code' => 'customer.create', 'group' => '基础资料'],
            ['name' => '客户更新', 'code' => 'customer.update', 'group' => '基础资料'],
            ['name' => '客户删除', 'code' => 'customer.delete', 'group' => '基础资料'],
            ['name' => '工序列表', 'code' => 'process.list', 'group' => '基础资料'],
            ['name' => '工序创建', 'code' => 'process.create', 'group' => '基础资料'],
            ['name' => '工序更新', 'code' => 'process.update', 'group' => '基础资料'],
            ['name' => '工序删除', 'code' => 'process.delete', 'group' => '基础资料'],
            ['name' => 'BOM列表', 'code' => 'bom.list', 'group' => '基础资料'],
            ['name' => 'BOM创建', 'code' => 'bom.create', 'group' => '基础资料'],
            ['name' => 'BOM更新', 'code' => 'bom.update', 'group' => '基础资料'],
            ['name' => 'BOM删除', 'code' => 'bom.delete', 'group' => '基础资料'],
        ];
        foreach ($permissions as $p) {
            Permission::firstOrCreate(['code' => $p['code']], $p);
        }

        // admin 角色挂全权限；operator 仅挂 list 权限
        $admin = Role::firstOrCreate(['code' => 'admin'], ['name' => '管理员', 'remark' => '超级管理员']);
        $admin->permissions()->sync(Permission::pluck('id'));

        $operator = Role::firstOrCreate(['code' => 'operator'], ['name' => '操作员', 'remark' => '只读操作员']);
        $operator->permissions()->sync(Permission::where('code', 'like', '%.list')->pluck('id'));

        // 内置 admin 用户（不可删除），挂 admin 角色
        $adminUser = User::firstOrCreate(
            ['username' => 'admin'],
            ['name' => '管理员', 'email' => 'admin@php-design.local', 'password' => 'admin123', 'status' => 1]
        );
        $adminUser->roles()->syncWithoutDetaching([$admin->id]);

        // 种子字典：计量单位（供基础资料模块下拉引用）
        $unit = Dictionary::firstOrCreate(['code' => 'unit'], ['name' => '计量单位', 'remark' => '全系统计量单位']);
        $unit->items()->delete();
        $unit->items()->createMany([
            ['label' => '个', 'value' => 'pc', 'sort' => 1, 'status' => 1],
            ['label' => '箱', 'value' => 'box', 'sort' => 2, 'status' => 1],
        ]);
    }
}
