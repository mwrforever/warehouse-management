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
            // 库存管理模块权限（inventory 查询 + check 四动作，group=库存管理；盘点审核复用 check.update）
            ['name' => '库存查询', 'code' => 'inventory.list', 'group' => '库存管理'],
            ['name' => '盘点单列表', 'code' => 'check.list', 'group' => '库存管理'],
            ['name' => '盘点单创建', 'code' => 'check.create', 'group' => '库存管理'],
            ['name' => '盘点单更新', 'code' => 'check.update', 'group' => '库存管理'],
            ['name' => '盘点单删除', 'code' => 'check.delete', 'group' => '库存管理'],
            // 采购管理模块权限（订单 + 入库单 各四动作，group=采购管理；审核/关闭复用 update）
            ['name' => '采购订单列表', 'code' => 'purchase.order.list', 'group' => '采购管理'],
            ['name' => '采购订单创建', 'code' => 'purchase.order.create', 'group' => '采购管理'],
            ['name' => '采购订单更新', 'code' => 'purchase.order.update', 'group' => '采购管理'],
            ['name' => '采购订单删除', 'code' => 'purchase.order.delete', 'group' => '采购管理'],
            ['name' => '采购入库单列表', 'code' => 'purchase.inbound.list', 'group' => '采购管理'],
            ['name' => '采购入库单创建', 'code' => 'purchase.inbound.create', 'group' => '采购管理'],
            ['name' => '采购入库单更新', 'code' => 'purchase.inbound.update', 'group' => '采购管理'],
            ['name' => '采购入库单删除', 'code' => 'purchase.inbound.delete', 'group' => '采购管理'],
            // 销售管理模块权限（订单 + 出库单 各四动作，group=销售管理；审核/关闭复用 update）
            ['name' => '销售订单列表', 'code' => 'sales.order.list', 'group' => '销售管理'],
            ['name' => '销售订单创建', 'code' => 'sales.order.create', 'group' => '销售管理'],
            ['name' => '销售订单更新', 'code' => 'sales.order.update', 'group' => '销售管理'],
            ['name' => '销售订单删除', 'code' => 'sales.order.delete', 'group' => '销售管理'],
            ['name' => '销售出库单列表', 'code' => 'sales.outbound.list', 'group' => '销售管理'],
            ['name' => '销售出库单创建', 'code' => 'sales.outbound.create', 'group' => '销售管理'],
            ['name' => '销售出库单更新', 'code' => 'sales.outbound.update', 'group' => '销售管理'],
            ['name' => '销售出库单删除', 'code' => 'sales.outbound.delete', 'group' => '销售管理'],
            // 生产管理模块权限（工单 + 报工 各四动作，group=生产管理；下达/开工/完工/关闭复用 update，报工提交复用 report.create）
            ['name' => '生产工单列表', 'code' => 'production.order.list', 'group' => '生产管理'],
            ['name' => '生产工单创建', 'code' => 'production.order.create', 'group' => '生产管理'],
            ['name' => '生产工单更新', 'code' => 'production.order.update', 'group' => '生产管理'],
            ['name' => '生产工单删除', 'code' => 'production.order.delete', 'group' => '生产管理'],
            ['name' => '工序报工列表', 'code' => 'production.report.list', 'group' => '生产管理'],
            ['name' => '工序报工创建', 'code' => 'production.report.create', 'group' => '生产管理'],
            ['name' => '工序报工更新', 'code' => 'production.report.update', 'group' => '生产管理'],
            ['name' => '工序报工删除', 'code' => 'production.report.delete', 'group' => '生产管理'],
            // 生产单据域权限（领料/退料/委外/成品入库 各四动作，group=生产管理；审核复用 update，发料/回收复用 create）
            ['name' => '生产领料列表', 'code' => 'production.pick.list', 'group' => '生产管理'],
            ['name' => '生产领料创建', 'code' => 'production.pick.create', 'group' => '生产管理'],
            ['name' => '生产领料更新', 'code' => 'production.pick.update', 'group' => '生产管理'],
            ['name' => '生产领料删除', 'code' => 'production.pick.delete', 'group' => '生产管理'],
            ['name' => '生产退料列表', 'code' => 'production.return.list', 'group' => '生产管理'],
            ['name' => '生产退料创建', 'code' => 'production.return.create', 'group' => '生产管理'],
            ['name' => '生产退料更新', 'code' => 'production.return.update', 'group' => '生产管理'],
            ['name' => '生产退料删除', 'code' => 'production.return.delete', 'group' => '生产管理'],
            ['name' => '委外加工列表', 'code' => 'production.outsource.list', 'group' => '生产管理'],
            ['name' => '委外加工创建', 'code' => 'production.outsource.create', 'group' => '生产管理'],
            ['name' => '委外加工更新', 'code' => 'production.outsource.update', 'group' => '生产管理'],
            ['name' => '委外加工删除', 'code' => 'production.outsource.delete', 'group' => '生产管理'],
            ['name' => '成品入库列表', 'code' => 'production.finished.list', 'group' => '生产管理'],
            ['name' => '成品入库创建', 'code' => 'production.finished.create', 'group' => '生产管理'],
            ['name' => '成品入库更新', 'code' => 'production.finished.update', 'group' => '生产管理'],
            ['name' => '成品入库删除', 'code' => 'production.finished.delete', 'group' => '生产管理'],
            // 统计报表模块权限（4 项只读查看权限，group=统计报表）
            // 决策（TC-RPT-06 锁定）：刻意不带 .list 后缀——operator 自动持有全部 %.list，
            // 报表为管理层视图（E2E 断言 limited01 菜单隐藏 + 后端 403），故不纳入 operator 默认持有
            ['name' => '库存报表', 'code' => 'report.inventory', 'group' => '统计报表'],
            ['name' => '出入库汇总', 'code' => 'report.movements', 'group' => '统计报表'],
            ['name' => '生产统计', 'code' => 'report.production', 'group' => '统计报表'],
            ['name' => '采购销售汇总', 'code' => 'report.purchase_sales', 'group' => '统计报表'],
            // 仪表盘模块权限（1 项只读查看权限，group=仪表盘）
            // 决策（TC-DSH-07 锁定）：仪表盘为登录默认落地页，所有角色可见——operator 也显式持有（下方 sync 例外）；
            // 待审核单据由接口内部按审核权限过滤（operator 无审核权限 → 恒为 0/空列表），不构成数据泄露
            ['name' => '仪表盘查看', 'code' => 'dashboard.view', 'group' => '仪表盘'],
        ];
        foreach ($permissions as $p) {
            Permission::firstOrCreate(['code' => $p['code']], $p);
        }

        // admin 角色挂全权限；operator 仅挂 list 权限
        $admin = Role::firstOrCreate(['code' => 'admin'], ['name' => '管理员', 'remark' => '超级管理员']);
        $admin->permissions()->sync(Permission::pluck('id'));

        $operator = Role::firstOrCreate(['code' => 'operator'], ['name' => '操作员', 'remark' => '只读操作员']);
        // operator 挂全部 list 权限 + dashboard.view 例外（仪表盘为全角色默认落地页，TC-DSH-07 锁定）
        $operator->permissions()->sync(
            Permission::where('code', 'like', '%.list')->orWhere('code', 'dashboard.view')->pluck('id')
        );

        // 内置 admin 用户（不可删除），挂 admin 角色
        // 密码支持 ADMIN_PASSWORD 环境变量覆盖（生产部署必须设置强口令；默认值仅限本地开发/E2E）
        $adminUser = User::firstOrCreate(
            ['username' => 'admin'],
            [
                'name' => '管理员',
                'email' => 'admin@php-design.local',
                'password' => env('ADMIN_PASSWORD', 'admin123'),
                'status' => 1,
            ]
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
