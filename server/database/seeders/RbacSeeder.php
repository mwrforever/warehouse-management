<?php
// RBAC 种子：权限分组数据 + admin/operator 角色 + 超级管理员
namespace Database\Seeders;

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
    }
}
