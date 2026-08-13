# 生产管理模块 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 实现生产管理模块（生产工单 → 工序报工 → 领退料 → 委外加工 → 成品入库完整闭环）：工单创建时 **BOM 展开**（物料需求快照 + 工序序列自动生成），工单五态状态机（草稿→已下达→生产中→已完成→关闭），所有库存变动（领料 -、退料 +、委外发出 -、委外回收 +、成品入库 +）统一经 `InventoryService` 在事务内完成；通过全部 PHPUnit/Vitest 测试与 E2E 测试文档 `docs/test/2026-08-12-生产管理模块端到端测试.md` 的 TC-PRD-01~10（含基础资料 TC 补测 1113 工序删除保护）。

**Architecture:** 前后端分离，完全复用采购/销售模块沉淀的全部基座与模式：统一响应 `{code,message,data}`、`ApiResponse` trait、`permission:` 中间件、Sanctum 认证、`InventoryService::apply` 库存引擎（`pick/return/finished_inbound/outsourcing_out/outsourcing_in` 5 个 source_type 已在 `InventoryMovement::SOURCE_TYPES` 枚举，零改动）、`DocumentSequenceService` 持久序列（仅加 6 个 type 常量，零迁移）。后端 12 张新表（工单+物料快照+工序+报工、领料、退料、委外+回收、成品入库，镜像采购/销售两级单据模式）；**库存变动唯一入口仍是 `InventoryService`**——各单据审核事务内「锁单判幂等 → 逐行锁工单物料需求行复核（防超领/超退）→ 逐行锁余额行校验余额充足（出库类单据，1515/1522 防超卖）→ InventoryService::apply 写流水 → 回写累计量 → 置已审核」，任一步失败整体回滚；成品入库审核回写 `completed_qty` 并联动工单自动「已完成」；**BOM 展开结果快照在工单上**（spec §8：下达后 BOM 停用不影响已下达工单——新增 `production_order_materials` 表承载，spec 数据模型清单遗漏，本计划补齐）。前端 6 个页面（工单/报工/领料/退料/委外/成品入库）复用「工具栏 + el-table + el-dialog(900px) + 扫码」模式，样式遵循 nexus-factory 设计系统（Task 10 落地 `design-system/nexus-factory/pages/production.md` 页覆盖，ui-ux-pro-max 查询驱动）。

**Tech Stack:** PHP 8.5.9、Laravel 13.25.0、MySQL 8.4（Docker）、bcmath（数量/金额整数运算）、Vue 3 + TypeScript + Vite + Pinia + Vue Router + Element Plus、PHPUnit、Vitest、@playwright/test（web/e2e/ 自动化框架）。

## Global Constraints

以下约束对每个 Task 隐式生效（来自生产细化 spec §4/§6/§7/§8 与采购/销售模块沉淀约定，逐条原文/决策说明）：

- 统一响应：`{code, message, data}`；`code=0` 成功；生产错误码 1501-1528（**28 个码全部分配完毕**，spec 未覆盖的状态非法流转场景按销售 1407 同族先例复用已有码，见下）：
  - 1501 该成品没有启用版本的 BOM、1502 数量必须大于 0（**业务码**——生产 spec 明确，与采购/销售「数量≤0 走 422」不同，跟随 spec）、1503 已下达工单不可修改、1504 已下达工单不可删除（同族：工单已被生产单据使用，不可删除）、1505 工单已下达（同族：当前状态不可下达/关闭后 release 被拒/close 非已完成复用此码，消息「当前状态不可关闭」——spec 码段已满，与 1405/1306「不可关闭」语义对齐）、1506 工单已开工（同族：当前状态不可开工）、1507 存在未完成工序，无法完工（同族：当前状态不可完工）、1508 无成品入库，无法完工
  - 1509 该工序当前不可报工、1510 合格数不能超过工单计划数量（**累计语义**：工序已报合格 + 本次合格 > 计划数即拒，防并发虚报）、1511 合格数与不良数合计不能超过工单计划数量（累计语义）、1512 工时不能为负数
  - 1513 领料数量超过需求数量、1514 已审核领料单不可修改/删除、1515 商品[{code}]库存不足（**用商品编码**——E2E TC-PRD-10 断言 `商品[MAT-001]库存不足`，spec 的 {name} 按 E2E 修正为编码）、1516 该领料单已审核
  - 1517 退料数量超过已领数量、1518 已审核退料单不可修改/删除、1519 该退料单已审核
  - 1520 委外数量超过工单计划数量、1521 已审核委外单不可修改/删除、1522 商品[{code}]库存不足（委外发出）、1523 该委外单已审核、1524 回收数量超过委外数量
  - 1525 入库数量超过工单剩余产量、1526 入库商品与工单产品不一致、1527 已审核成品入库单不可修改/删除、1528 该成品入库单已审核
  - 422 仅格式层（含：明细为空、数量≤0 之外的负值域——合格/不良数为负、重复商品行、仓库/库位缺失、格式正则失败）；业务冲突一律走上述业务码
- **数量精度**：一切数量 decimal(12,2)，**运算必须 bcmath**（bcmul/bcadd/bcsub/bccomp/bcdiv），禁止浮点累加；工时同样 2 位小数 bcmath 累加
- **单据状态机（生产 spec §6 + 本计划精确定义）**：
  - 生产工单：草稿(0) → 已下达(1) → 生产中(2) → 已完成(3) → 关闭(4)；流转：release 仅草稿（1505）、start 仅已下达（1506）、complete 仅生产中且全部工序已完成（1507）且至少一次成品入库 `completed_qty > 0`（1508）、close 仅已完成（复用 1505 消息「当前状态不可关闭」）；update/destroy 仅草稿（1503/1504，**事务内锁行复查防并发**）
  - 工单工序：待开工(0) → 进行中(1) → 已完成(2)；开工时首工序（seq 最小）置进行中；报工累计合格 ≥ 计划数 → 本工序自动完成 + 下一工序（seq 升序下一）自动进行中；**委外回收累计 ≥ 委外量时若该工序未完成则标记完成**（spec §6）
  - 领料单/退料单/成品入库单：草稿(0) → 已审核(1)，审核幂等（1516/1519/1528）；委外单：草稿(0) → 已审核(1) → 已回收(2)，审核幂等（1523）；委外回收单：创建即审核（status 恒 1）
  - 领料单发料状态：未发料(0) → 部分发料(1) → 全部发料(2)；**V1 issue 一次置「全部发料」**（E2E TC-PRD-03 断言首次发料即全部发料；无分批发料动作）
- **BOM 展开（工单创建时快照，spec §8 语义）**：成品必须有启用版本 BOM（`BomHeader::where('product_id',x)->where('status',1)`，同成品启用版本唯一已由 BOM 模块保证）→ 物料需求 `required_qty = 工单数量 ÷ BOM 基准产出 × 用量`（bcmath：`bcmul(bcdiv($qty, $bom->quantity, 4), $item->quantity, 2)`，基准产出默认 1 时等价于 数量×用量）快照到 **`production_order_materials`**（order_id+material_id 唯一）；工序序列 = **全部启用工序**（`Process::where('status',1)->orderBy('sort')`）快照到 `work_order_operations`（order_id+seq 唯一）——BOM 头无工序字段，全量启用工序为 V1 设计（E2E TC-PRD-01 断言 3 工序全量进入）
- **核心不变式（测试必须验证）**：① 领料/退料/委外/成品入库审核后库存变动必须经 `InventoryService::apply`（source_type=pick/return/outsourcing_out/outsourcing_in/finished_inbound，方向 ±1）与余额更新同事务双写；② 出库类审核（领料 -、委外发出 -）逐行锁余额行校验，不足 1515/1522 整体回滚防超卖；③ 领料 ≤ 需求剩余（1513）、退料 ≤ 已领（1517）、委外 ≤ 计划数（1520）、回收 ≤ 委外剩余（1524）、入库 ≤ 剩余产量（1525）——**草稿期拦截 + 审核期锁行复核双保险**（镜像销售 1407 审核期复核）；④ 审核幂等：重复审核 1516/1519/1523/1528 全被拒，库存不重复变动；⑤ 工单状态联动：成品入库累计 completed_qty ≥ 计划数且末工序已完成 → 工单自动「已完成」；⑥ 余额允许 0 不允许负
- **单号规则**：`MO{yyyyMMdd}-{3位}`（工单）、`PL{...}`（领料单）、`RL{...}`（退料单）、`OS{...}`（委外单）、`OSR{...}`（委外回收单）、`FI{...}`（成品入库单）——统一走 `document_sequences` 持久序列（**Task 1/2 在 DocumentSequence 模型追加 `TYPE_MO='mo'`/`TYPE_PL='pl'`/`TYPE_RL='rl'`/`TYPE_OS='os'`/`TYPE_OSR='osr'`/`TYPE_FI='fi'` 常量即可，零迁移**；FOR UPDATE 原子取号、删除不回退、撞号 1062/19 重试、老库 max 衔接），禁止 count+1
- API 前缀 `/api/v1`；权限中间件 `permission:{资源}.{动作}`；新权限 **24 项**追加到 `RbacSeeder`（group=生产管理）：`production.order.list/create/update/delete`、`production.report.list/create/update/delete`、`production.pick.list/create/update/delete`、`production.return.list/create/update/delete`、`production.outsource.list/create/update/delete`、`production.finished.list/create/update/delete`（工单 release/start/complete/close、领料 approve/issue、退料/委外/成品入库 approve、委外回收 均复用对应资源 update，与销售 approve/close 复用 update 模式一致；报工提交复用 report.create）；admin 自动全量持有，operator 自动持有全部 `%.list`
- **删除保护（已接线，生产表落地后自动生效，Task 1 补测试）**：`work_order_operations.process_id` → 1113（ProcessController 已接线）、`production_orders.bom_id` → 1121（BomController 已接线）、`production_orders.product_id` → 1116（ProductController 已接线）；**Task 1 补充接线**：`production_order_materials.material_id`（工单物料快照引用原料/半成品）→ ProductController 1116 链追加；`work_order_operations.process_id`、`pick_lists.order_id` 等单据与工单引用由业务层拦截（工单 destroy 查四类单据引用 → 1504「工单已被生产单据使用，不可删除」；FK 均 restrictOnDelete 兜底）
- **委外发出商品语义（spec 数据模型无 product_id 字段，E2E TC-PRD-06 锁定）**：委外单商品 = 所属工单的成品（`production_orders.product_id`）——发出扣成品库存（outsourcing_out 流水）、回收加成品库存（outsourcing_in 流水，仓库/库位取回收请求）
- 分页统一 `{items,total,page,per_page}`；per_page 钳制 `max(1, min(100, (int) $request->input('per_page', 10)))`
- `source_type=pick/return/finished_inbound/outsourcing_out/outsourcing_in` 已在 `InventoryMovement::SOURCE_TYPES`（库存模块已建，零改动）
- 中文注释（类级/方法级/关键行）；UTF-8 无 BOM；LF 行尾（.gitattributes 已强制）；无死代码；核心路径（审核扣库存/防超卖/幂等/状态联动/并发复核/数量精度）单元测试 100% 覆盖，测试命名表达业务意图，覆盖正常/边界/异常
- 前端：侧边栏深色 `#0F172A`（220px），内容区 `#F8FAFC`；主色 `#334155`、强调绿 `#059669`、危险 `#DC2626`、琥珀 `#D97706`、蓝 `#3B82F6`；Fira Code + Fira Sans；所有可点击元素 `cursor:pointer`；按钮文案「新 建/保 存/编 辑/删 除/审 核/发 料/下 达/开 工/完 工/关 闭/回 收/报 工」带全角空格（E2E 按文案定位，正则 `/\s*建/` 双兼容半角）；数量列 Fira Code；状态标签：工单五态——草稿灰 `#6B7280`/已下达蓝 `#3B82F6`/生产中琥珀 `#D97706`/已完成深绿 `#047857`（tag-done）/关闭红 `#DC2626`；工序三态——待开工灰/进行中蓝/已完成绿；单据两态——草稿灰/已审核绿；委外三态——草稿灰/已审核蓝/已回收绿；发料三态——未发料灰/部分发料琥珀/全部发料绿；弹窗 900px；扫码自动聚焦（v-model + 命中清空/未命中保留）
- 端口：后端 `http://localhost:8000`、前端 `http://localhost:5173`、MySQL `3306`；本机命令 `php`/`composer` 已入 PATH；Python=`D:\code\envs\python\3.14.6\python.exe`（ui-ux-pro-max search.py 完整路径调用）
- **工程纪律**：严禁运行 pint/prettier 等格式化工具（会污染全仓；pre-commit 钩子 lint-staged 自动修复属正常）；提交前 `git status` 精确暂存（禁止 `git add -A`）；每 Task 提交一次；**Task 提交前必跑全量 phpstan（0 错误）**（销售模块 Task 3/4 教训：跳过导致 13 错误遗留到最终门禁）；phpunit 权限用例须 `$this->app['auth']->forgetGuards()`；sqlite 唯一冲突错误码 19 ≠ MySQL 1062（撞号重试已双码兼容）；sqlite 将 DECIMAL 编译为 NUMERIC（Schema 类型断言须兼容 decimal/numeric）；测试插外键数据须先建真实主数据（sqlite 外键约束开启）
- **E2E 基线数据（InventorySeeder 注入，数字精确勿改）**：MAT-001 测试铝材(原料,条码100001)@主仓 A-01=100、SEMI-001 半成品A(半成品,条码100002)@主仓 A-01=30、FIN-002 成品B(成品,条码888888)@主仓 B-01=20；BOM(FIN-002, 启用版)：MAT-001×2/成品；工序 ≥3（下料 sort1/组装 sort2/质检 sort3）；供应商 SUP-001（委外用）；生产 E2E 一律用「记录当时余额」法断言增量（全量 E2E 串行时库存末态随前置模块变化）
- **生产 spec 与 E2E 文档的不一致裁决（本计划权威）**：① 工单物料需求快照表 `production_order_materials` 为 spec 数据模型清单遗漏，本计划补充（spec §3 无此表但 §4.1 详情返回 materials、§8 快照语义必需）；② 1515/1522 消息用商品编码（E2E 断言 `商品[MAT-001]库存不足`，spec 写 {name} 按 E2E 修正）；③ close 非已完成复用 1505（spec 码段满，与 1405/1306 语义对齐）；④ issue 一次置「全部发料」；⑤ 委外商品 = 工单成品

---

## Task 1: 工单域数据模型（4 表迁移 + 4 模型 + 序列常量 + 权限种子 + 物料引用保护接线）

**Files:**
- Create: `server/database/migrations/2026_08_14_100000_create_production_tables.php`（工单域 4 表）
- Create: `server/app/Models/{ProductionOrder,ProductionOrderMaterial,WorkOrderOperation,OperationReport}.php`
- Create: `server/tests/Feature/ProductionStructureTest.php`
- Modify: `server/app/Models/DocumentSequence.php`（追加 TYPE_MO）
- Modify: `server/database/seeders/RbacSeeder.php`（权限数组追加 8 项生产工单/报工权限）
- Modify: `server/app/Http/Controllers/Api/ProductController.php`（destroy 1116 链追加 production_order_materials.material_id）
- Modify: `server/tests/Feature/ProductTest.php`（追加工单物料引用保护测试）
- Modify: `server/tests/Feature/ProcessTest.php`（追加 1113 工序被工单工序引用保护测试）
- Modify: `server/tests/Feature/BomTest.php`（追加 1121 BOM 被工单引用保护测试）

**Interfaces:**
- Consumes: 基础资料 products/bom_headers/bom_items/processes 表与模型；DeletionGuard（`App\Support\DeletionGuard::referenced`）
- Produces: 4 张表（字段见下）；4 个模型；`ProductionOrder::STATUS_*` 五状态常量 + `STATUS_LABELS`（草稿/已下达/生产中/已完成/关闭）；`WorkOrderOperation::STATUS_*` 三状态常量；`DocumentSequence::TYPE_MO`；权限 8 项（group=生产管理）。**production_orders/work_order_operations 表落地后，1113/1121/1116 删除保护自动生效**（Task 1 补测试、Task 13 E2E 补测 1113）

- [ ] **Step 1: 写失败测试 `server/tests/Feature/ProductionStructureTest.php`**

```php
<?php

// 生产工单域数据结构测试：表结构、权限种子、序列常量、唯一索引（核心数据结构 100% 覆盖）

namespace Tests\Feature;

use App\Models\DocumentSequence;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ProductionStructureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // 种子：RBAC + 基础资料主数据（生产工单域表本 Task 迁移后即可用）
        $this->seed();
    }

    public function test_production_tables_exist(): void
    {
        // 正常路径：工单域 4 张表全部建立
        foreach (['production_orders', 'production_order_materials', 'work_order_operations', 'operation_reports'] as $table) {
            $this->assertTrue(Schema::hasTable($table), "表 {$table} 不存在");
        }
    }

    public function test_production_permissions_seeded_for_admin(): void
    {
        // 正常路径：生产管理权限已注册且 admin 角色全量持有（Task 1 先注册 order/report 8 项）
        $this->assertSame(8, Permission::where('group', '生产管理')->count());
        $admin = Role::where('code', 'admin')->first();
        $this->assertSame(2, $admin->permissions()->whereIn('code', ['production.order.create', 'production.report.update'])->count());
    }

    public function test_document_sequence_has_production_order_type(): void
    {
        // 正常路径：工单单号段常量已注册（MO，DocumentSequenceService 零迁移复用）
        $this->assertSame('mo', DocumentSequence::TYPE_MO);
    }

    public function test_order_no_unique_blocks_duplicate(): void
    {
        // 边界路径：工单单号唯一（撞号由序列服务换号，此约束兜底）
        $productId = DB::table('products')->insertGetId([
            'name' => '成品', 'code' => 'FIN-X', 'type' => 'finished', 'category_id' => 1,
            'unit_id' => 1, 'status' => 1, 'safety_min' => 0, 'safety_max' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $orderId = DB::table('production_orders')->insertGetId([
            'no' => 'MO20260812-001', 'product_id' => $productId, 'quantity' => 10,
            'plan_date' => now()->toDateString(), 'bom_id' => 1, 'status' => 0,
            'completed_qty' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->assertGreaterThan(0, $orderId);
        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('production_orders')->insert([
            'no' => 'MO20260812-001', 'product_id' => $productId, 'quantity' => 10,
            'plan_date' => now()->toDateString(), 'bom_id' => 1, 'status' => 0,
            'completed_qty' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_material_snapshot_unique_per_order_and_product(): void
    {
        // 边界路径：工单物料需求快照 order_id+material_id 唯一（展开结果防重复）
        $orderId = DB::table('production_orders')->insertGetId([
            'no' => 'MO20260812-002', 'product_id' => 1, 'quantity' => 1,
            'plan_date' => now()->toDateString(), 'bom_id' => 1, 'status' => 0,
            'completed_qty' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('production_order_materials')->insert([
            'order_id' => $orderId, 'material_id' => 1, 'required_qty' => 2,
            'issued_qty' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('production_order_materials')->insert([
            'order_id' => $orderId, 'material_id' => 1, 'required_qty' => 2,
            'issued_qty' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_operation_seq_unique_per_order(): void
    {
        // 边界路径：工单工序 seq 唯一（工序序列有序性约束）
        $orderId = DB::table('production_orders')->insertGetId([
            'no' => 'MO20260812-003', 'product_id' => 1, 'quantity' => 1,
            'plan_date' => now()->toDateString(), 'bom_id' => 1, 'status' => 0,
            'completed_qty' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('work_order_operations')->insert([
            'order_id' => $orderId, 'process_id' => 1, 'seq' => 1, 'status' => 0,
            'qualified_qty' => 0, 'defective_qty' => 0, 'hours' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('work_order_operations')->insert([
            'order_id' => $orderId, 'process_id' => 2, 'seq' => 1, 'status' => 0,
            'qualified_qty' => 0, 'defective_qty' => 0, 'hours' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_quantity_columns_are_decimal(): void
    {
        // 正常路径：数量列 decimal（bcmath 整数运算，禁浮点；sqlite 编译为 numeric 双兼容）
        foreach (['quantity', 'completed_qty'] as $col) {
            $this->assertContains(Schema::getColumnType('production_orders', $col), ['decimal', 'numeric'], "{$col} 应为 decimal/numeric");
        }
        $this->assertContains(Schema::getColumnType('production_order_materials', 'required_qty'), ['decimal', 'numeric']);
        $this->assertContains(Schema::getColumnType('work_order_operations', 'qualified_qty'), ['decimal', 'numeric']);
    }
}
```

- [ ] **Step 2: 跑测试确认失败**

Run: `cd server && php artisan test --filter=ProductionStructureTest`
Expected: FAIL（表/模型/常量/权限不存在）。

- [ ] **Step 3: 创建迁移（工单域 4 表）**

创建 `server/database/migrations/2026_08_14_100000_create_production_tables.php`：

```php
<?php

// 生产核心表（工单域）：生产工单（计划）+ 物料需求快照（BOM 展开结果）+ 工单工序（流转）+ 工序报工（记录）

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_orders', function (Blueprint $table) {
            $table->id();
            $table->string('no', 30)->unique()->comment('生产工单号，如 MO20260812-001');
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete()->comment('成品商品（被引用不可删，1116 数据源）');
            $table->decimal('quantity', 12, 2)->comment('计划数量');
            $table->date('plan_date')->comment('计划日期');
            $table->foreignId('bom_id')->constrained('bom_headers')->restrictOnDelete()->comment('BOM 版本（被引用不可删，1121 数据源）');
            $table->tinyInteger('status')->default(0)->comment('0草稿 1已下达 2生产中 3已完成 4关闭');
            $table->decimal('completed_qty', 12, 2)->default(0)->comment('累计完工数量（成品入库审核回写）');
            $table->unsignedBigInteger('created_by')->nullable()->comment('创建人ID');
            $table->timestamp('released_at')->nullable()->comment('下达时间');
            $table->timestamp('completed_at')->nullable()->comment('完工时间');
            $table->timestamp('closed_at')->nullable()->comment('关闭时间');
            $table->string('remark')->nullable()->comment('备注');
            $table->index('status', 'production_orders_status');
            $table->index('product_id', 'production_orders_product');
            $table->timestamps();
        });

        Schema::create('production_order_materials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('production_orders')->cascadeOnDelete()->comment('所属工单');
            $table->foreignId('material_id')->constrained('products')->restrictOnDelete()->comment('物料商品（原料/半成品，被引用不可删 1116）');
            $table->decimal('required_qty', 12, 2)->comment('需求数量（BOM 展开快照）');
            $table->decimal('issued_qty', 12, 2)->default(0)->comment('已领累计（领料审核+、退料审核-）');
            $table->timestamps();
            $table->unique(['order_id', 'material_id'], 'production_order_materials_unique');
        });

        Schema::create('work_order_operations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('production_orders')->cascadeOnDelete()->comment('所属工单');
            $table->foreignId('process_id')->constrained('processes')->restrictOnDelete()->comment('工序（被引用不可删，1113 数据源）');
            $table->integer('seq')->comment('工序顺序（启用工序按 sort 升序快照）');
            $table->tinyInteger('status')->default(0)->comment('0待开工 1进行中 2已完成');
            $table->decimal('qualified_qty', 12, 2)->default(0)->comment('合格累计（报工回写）');
            $table->decimal('defective_qty', 12, 2)->default(0)->comment('不良累计（仅记录与统计）');
            $table->decimal('hours', 12, 2)->default(0)->comment('工时累计');
            $table->timestamps();
            $table->unique(['order_id', 'seq'], 'work_order_operations_seq_unique');
            $table->index('process_id', 'work_order_operations_process');
        });

        Schema::create('operation_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('operation_id')->constrained('work_order_operations')->cascadeOnDelete()->comment('所属工序');
            $table->foreignId('order_id')->constrained('production_orders')->cascadeOnDelete()->comment('所属工单（冗余便于按工单查询）');
            $table->string('operator', 50)->nullable()->comment('操作人');
            $table->decimal('qualified_qty', 12, 2)->comment('本次合格数');
            $table->decimal('defective_qty', 12, 2)->default(0)->comment('本次不良数（V1 仅记录与统计，返修/报废后续版本）');
            $table->decimal('hours', 12, 2)->default(0)->comment('本次工时（小时，2 位小数）');
            $table->timestamp('report_time')->comment('报工时间');
            $table->string('remark')->nullable()->comment('备注');
            $table->timestamps();
            $table->index('order_id', 'operation_reports_order');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operation_reports');
        Schema::dropIfExists('work_order_operations');
        Schema::dropIfExists('production_order_materials');
        Schema::dropIfExists('production_orders');
    }
};
```

Run: `cd server && php artisan migrate`
Expected: 4 张表创建成功。

- [ ] **Step 4: 创建 4 个模型**

`server/app/Models/ProductionOrder.php`:

```php
<?php

// 生产工单模型：草稿→已下达→生产中→已完成→关闭 五态状态机；BOM 展开快照物料与工序；成品入库审核回写状态

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 生产工单
 *
 * @property int $id
 * @property string $no
 * @property int $product_id
 * @property string $quantity
 * @property string $plan_date
 * @property int $bom_id
 * @property int $status
 * @property string $completed_qty
 * @property int|null $created_by
 * @property string|null $released_at
 * @property string|null $completed_at
 * @property string|null $closed_at
 * @property string|null $remark
 */
class ProductionOrder extends Model
{
    public const STATUS_DRAFT = 0;      // 草稿
    public const STATUS_RELEASED = 1;   // 已下达
    public const STATUS_PRODUCING = 2;  // 生产中
    public const STATUS_COMPLETED = 3;  // 已完成
    public const STATUS_CLOSED = 4;     // 关闭

    /** 状态中文标签（列表/详情展示） */
    public const STATUS_LABELS = [
        self::STATUS_DRAFT => '草稿',
        self::STATUS_RELEASED => '已下达',
        self::STATUS_PRODUCING => '生产中',
        self::STATUS_COMPLETED => '已完成',
        self::STATUS_CLOSED => '关闭',
    ];

    protected $fillable = ['no', 'product_id', 'quantity', 'plan_date', 'bom_id', 'status', 'completed_qty', 'created_by', 'released_at', 'completed_at', 'closed_at', 'remark'];

    protected function casts(): array
    {
        return [
            'status' => 'integer',
            'quantity' => 'decimal:2',
            'completed_qty' => 'decimal:2',
            'released_at' => 'datetime',
            'completed_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Product, $this> */
    // 成品商品
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<BomHeader, $this> */
    // BOM 版本（快照来源，下达后停用不影响）
    public function bom(): BelongsTo
    {
        return $this->belongsTo(BomHeader::class, 'bom_id');
    }

    /** @return HasMany<ProductionOrderMaterial, $this> */
    // 物料需求快照（BOM 展开结果，随单级联删除）
    public function materials(): HasMany
    {
        return $this->hasMany(ProductionOrderMaterial::class, 'order_id');
    }

    /** @return HasMany<WorkOrderOperation, $this> */
    // 工单工序序列（随单级联删除）
    public function operations(): HasMany
    {
        return $this->hasMany(WorkOrderOperation::class, 'order_id');
    }
}
```

`server/app/Models/ProductionOrderMaterial.php`:

```php
<?php

// 工单物料需求快照模型：BOM 展开结果持久化（required_qty 快照、issued_qty 领料审核回写/退料审核冲销）

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 工单物料需求快照
 *
 * @property int $id
 * @property int $order_id
 * @property int $material_id
 * @property string $required_qty
 * @property string $issued_qty
 */
class ProductionOrderMaterial extends Model
{
    protected $fillable = ['order_id', 'material_id', 'required_qty', 'issued_qty'];

    protected function casts(): array
    {
        return [
            'required_qty' => 'decimal:2',
            'issued_qty' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<ProductionOrder, $this> */
    // 所属工单
    public function order(): BelongsTo
    {
        return $this->belongsTo(ProductionOrder::class, 'order_id');
    }

    /** @return BelongsTo<Product, $this> */
    // 物料商品
    public function material(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'material_id');
    }
}
```

`server/app/Models/WorkOrderOperation.php`:

```php
<?php

// 工单工序模型：待开工→进行中→已完成 三态流转；合格/不良/工时累计由报工回写

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 工单工序
 *
 * @property int $id
 * @property int $order_id
 * @property int $process_id
 * @property int $seq
 * @property int $status
 * @property string $qualified_qty
 * @property string $defective_qty
 * @property string $hours
 */
class WorkOrderOperation extends Model
{
    public const STATUS_PENDING = 0;   // 待开工
    public const STATUS_RUNNING = 1;   // 进行中
    public const STATUS_DONE = 2;      // 已完成

    /** 状态中文标签（步骤条/详情展示） */
    public const STATUS_LABELS = [
        self::STATUS_PENDING => '待开工',
        self::STATUS_RUNNING => '进行中',
        self::STATUS_DONE => '已完成',
    ];

    protected $fillable = ['order_id', 'process_id', 'seq', 'status', 'qualified_qty', 'defective_qty', 'hours'];

    protected function casts(): array
    {
        return [
            'status' => 'integer',
            'seq' => 'integer',
            'qualified_qty' => 'decimal:2',
            'defective_qty' => 'decimal:2',
            'hours' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<ProductionOrder, $this> */
    // 所属工单
    public function order(): BelongsTo
    {
        return $this->belongsTo(ProductionOrder::class, 'order_id');
    }

    /** @return BelongsTo<Process, $this> */
    // 工序
    public function process(): BelongsTo
    {
        return $this->belongsTo(Process::class, 'process_id');
    }

    /** @return HasMany<OperationReport, $this> */
    // 报工记录（随工序级联删除）
    public function reports(): HasMany
    {
        return $this->hasMany(OperationReport::class, 'operation_id');
    }
}
```

`server/app/Models/OperationReport.php`:

```php
<?php

// 工序报工记录模型：每次报工的合格/不良/工时明细（只增不改，统计口径来源）

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 工序报工记录
 *
 * @property int $id
 * @property int $operation_id
 * @property int $order_id
 * @property string|null $operator
 * @property string $qualified_qty
 * @property string $defective_qty
 * @property string $hours
 * @property string $report_time
 * @property string|null $remark
 */
class OperationReport extends Model
{
    protected $fillable = ['operation_id', 'order_id', 'operator', 'qualified_qty', 'defective_qty', 'hours', 'report_time', 'remark'];

    protected function casts(): array
    {
        return [
            'qualified_qty' => 'decimal:2',
            'defective_qty' => 'decimal:2',
            'hours' => 'decimal:2',
            'report_time' => 'datetime',
        ];
    }

    /** @return BelongsTo<WorkOrderOperation, $this> */
    // 所属工序
    public function operation(): BelongsTo
    {
        return $this->belongsTo(WorkOrderOperation::class, 'operation_id');
    }
}
```

- [ ] **Step 5: DocumentSequence 追加工单单号段常量**

修改 `server/app/Models/DocumentSequence.php`：在 TYPE_SOUT 后追加：

```php
    public const TYPE_MO = 'mo';
```

- [ ] **Step 6: 追加权限种子**

修改 `server/database/seeders/RbacSeeder.php`：在 `$permissions` 数组末尾（销售管理权限之后）追加：

```php
        // 生产管理模块权限（工单 + 报工 各四动作，group=生产管理；下达/开工/完工/关闭复用 update，报工提交复用 report.create）
        ['name' => '生产工单列表', 'code' => 'production.order.list', 'group' => '生产管理'],
        ['name' => '生产工单创建', 'code' => 'production.order.create', 'group' => '生产管理'],
        ['name' => '生产工单更新', 'code' => 'production.order.update', 'group' => '生产管理'],
        ['name' => '生产工单删除', 'code' => 'production.order.delete', 'group' => '生产管理'],
        ['name' => '工序报工列表', 'code' => 'production.report.list', 'group' => '生产管理'],
        ['name' => '工序报工创建', 'code' => 'production.report.create', 'group' => '生产管理'],
        ['name' => '工序报工更新', 'code' => 'production.report.update', 'group' => '生产管理'],
        ['name' => '工序报工删除', 'code' => 'production.report.delete', 'group' => '生产管理'],
```

- [ ] **Step 7: 物料引用保护接线**

修改 `server/app/Http/Controllers/Api/ProductController.php` `destroy()`：`$referencedByOther` 链追加 `production_order_materials`（工单物料需求快照引用原料/半成品，同码 1116）：

```php
        $referencedByOther = DeletionGuard::referenced('inventory_movements', 'product_id', $product->id)
            || DeletionGuard::referenced('purchase_order_items', 'product_id', $product->id)
            || DeletionGuard::referenced('purchase_inbound_items', 'product_id', $product->id)
            || DeletionGuard::referenced('sales_order_items', 'product_id', $product->id)
            || DeletionGuard::referenced('sales_outbound_items', 'product_id', $product->id)
            || DeletionGuard::referenced('production_orders', 'product_id', $product->id)
            || DeletionGuard::referenced('production_order_materials', 'material_id', $product->id);
```

- [ ] **Step 8: 删除保护补测（1113/1121/1116 物料）**

8a. `server/tests/Feature/ProcessTest.php` 追加（镜像既有引用保护测试结构，需建成品/启用 BOM/工单/工单工序行）：

```php
    public function test_destroy_referenced_by_work_order_operation_fails_with_1113(): void
    {
        // 边界路径：work_order_operations 引用该工序时删除被拒 1113（生产表落地后自动生效）
        $p = Process::create(['name' => '下料', 'code' => 'PROC-01', 'sort' => 1, 'status' => 1]);
        $cat = Category::create(['name' => '成品', 'parent_id' => 0]);
        $unit = Unit::create(['name' => '个', 'code' => 'pc']);
        $fin = Product::create(['name' => '成品B', 'code' => 'FIN-002', 'type' => 'finished', 'category_id' => $cat->id, 'unit_id' => $unit->id, 'status' => 1]);
        $bom = BomHeader::create(['code' => 'BOM-TEST-001', 'product_id' => $fin->id, 'version' => 'v1', 'quantity' => 1, 'status' => 1]);
        $orderId = DB::table('production_orders')->insertGetId([
            'no' => 'MO-TEST-001', 'product_id' => $fin->id, 'quantity' => 10,
            'plan_date' => now()->toDateString(), 'bom_id' => $bom->id, 'status' => 0,
            'completed_qty' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('work_order_operations')->insert([
            'order_id' => $orderId, 'process_id' => $p->id, 'seq' => 1, 'status' => 0,
            'qualified_qty' => 0, 'defective_qty' => 0, 'hours' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->withToken($this->token)->deleteJson("/api/v1/processes/{$p->id}")
            ->assertJsonPath('code', 1113);
    }
```

（ProcessTest 顶部按文件现有 imports 补 `use App\Models\BomHeader;` `use App\Models\Category;` `use App\Models\Product;` `use App\Models\Unit;` `use Illuminate\Support\Facades\DB;`——如已存在则跳过）

8b. `server/tests/Feature/BomTest.php` 追加（镜像既有 1121 结构——若已有 production_orders 引用测试则仅核对存在；无则补）：

```php
    public function test_destroy_referenced_by_production_order_fails_with_1121(): void
    {
        // 边界路径：production_orders 引用该 BOM 时删除被拒 1121（生产表落地后自动生效）
        $cat = Category::create(['name' => '成品', 'parent_id' => 0]);
        $unit = Unit::create(['name' => '个', 'code' => 'pc']);
        $fin = Product::create(['name' => '成品B', 'code' => 'FIN-002', 'type' => 'finished', 'category_id' => $cat->id, 'unit_id' => $unit->id, 'status' => 1]);
        $bom = BomHeader::create(['code' => 'BOM-TEST-001', 'product_id' => $fin->id, 'version' => 'v1', 'quantity' => 1, 'status' => 1]);
        DB::table('production_orders')->insert([
            'no' => 'MO-TEST-001', 'product_id' => $fin->id, 'quantity' => 10,
            'plan_date' => now()->toDateString(), 'bom_id' => $bom->id, 'status' => 0,
            'completed_qty' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->withToken($this->token)->deleteJson("/api/v1/boms/{$bom->id}")
            ->assertJsonPath('code', 1121);
    }
```

8c. `server/tests/Feature/ProductTest.php` 追加（镜像 `test_destroy_referenced_by_sales_outbound_item_fails_with_1116` 结构——本地建原料 `$p`，复用 `$this->rawCat/$this->unit`，需建成品/启用 BOM/工单/物料快照行）：

```php
    public function test_destroy_referenced_by_production_material_fails_with_1116(): void
    {
        // 边界路径：production_order_materials 引用该物料时删除被拒 1116（工单物料快照引用保护）
        $p = Product::create([
            'name' => '铝材',
            'code' => 'RAW-001',
            'type' => 'raw_material',
            'category_id' => $this->rawCat->id,
            'unit_id' => $this->unit->id,
            'status' => 1,
        ]);
        $cat = Category::create(['name' => '成品', 'parent_id' => 0]);
        $fin = Product::create(['name' => '成品B', 'code' => 'FIN-002', 'type' => 'finished', 'category_id' => $cat->id, 'unit_id' => $this->unit->id, 'status' => 1]);
        $bom = BomHeader::create(['code' => 'BOM-TEST-001', 'product_id' => $fin->id, 'version' => 'v1', 'quantity' => 1, 'status' => 1]);
        $orderId = DB::table('production_orders')->insertGetId([
            'no' => 'MO-TEST-001', 'product_id' => $fin->id, 'quantity' => 10,
            'plan_date' => now()->toDateString(), 'bom_id' => $bom->id, 'status' => 0,
            'completed_qty' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('production_order_materials')->insert([
            'order_id' => $orderId, 'material_id' => $p->id, 'required_qty' => 20,
            'issued_qty' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->withToken($this->token)->deleteJson("/api/v1/products/{$p->id}")
            ->assertJsonPath('code', 1116);
    }
```

**注意**：ProductTest 顶部需补 `use App\Models\BomHeader;` `use App\Models\Category;`（若已有 Category 则跳过）。

- [ ] **Step 9: 跑测试确认通过**

Run: `cd server && php artisan test --filter="ProductionStructureTest|ProcessTest|BomTest|ProductTest"`
Expected: 全部 PASS（ProductionStructureTest 8 + ProcessTest 原有用例 + 新增 1 + BomTest 原有用例 + 新增 1 + ProductTest 原有用例 + 新增 1；存量测试必须保持绿）。

- [ ] **Step 10: 提交**

```bash
cd /d/code/project/php-design && git add server/database/migrations/2026_08_14_100000_create_production_tables.php server/app/Models/ProductionOrder.php server/app/Models/ProductionOrderMaterial.php server/app/Models/WorkOrderOperation.php server/app/Models/OperationReport.php server/app/Models/DocumentSequence.php server/database/seeders/RbacSeeder.php server/app/Http/Controllers/Api/ProductController.php server/tests/Feature/ProductionStructureTest.php server/tests/Feature/ProcessTest.php server/tests/Feature/BomTest.php server/tests/Feature/ProductTest.php
git commit -m "feat: 生产工单域数据模型（工单/物料快照/工序/报工 4 表）与权限种子、删除保护补全"
```

---

## Task 2: 单据域数据模型（8 表迁移 + 8 模型 + 5 序列常量 + 16 权限种子）

**Files:**
- Create: `server/database/migrations/2026_08_14_110000_create_production_documents_tables.php`（单据域 8 表）
- Create: `server/app/Models/{PickList,PickListItem,ReturnList,ReturnListItem,OutsourcingOrder,OutsourcingReceipt,FinishedInbound,FinishedInboundItem}.php`
- Modify: `server/app/Models/DocumentSequence.php`（追加 TYPE_PL/TYPE_RL/TYPE_OS/TYPE_OSR/TYPE_FI）
- Modify: `server/tests/Feature/ProductionStructureTest.php`（追加单据域表结构断言）
- Modify: `server/database/seeders/RbacSeeder.php`（权限数组追加 16 项领料/退料/委外/成品入库权限）

**Interfaces:**
- Consumes: Task 1 的 4 张工单域表与模型、常量 TYPE_MO
- Produces: 8 张表；8 个模型；`PickList::STATUS_*`/`ISSUE_*` 常量 + `ISSUE_LABELS`（未发料/部分发料/全部发料）；`OutsourcingOrder::STATUS_*`（草稿/已审核/已回收）；`DocumentSequence::TYPE_PL/TYPE_RL/TYPE_OS/TYPE_OSR/TYPE_FI`；权限 16 项（group=生产管理）。**各单据表 order_id 引用工单**（FK restrictOnDelete，业务层拦截删除）

- [ ] **Step 1: 写失败测试（追加到 `ProductionStructureTest`）**

在 `server/tests/Feature/ProductionStructureTest.php` 追加 3 个用例：

```php
    public function test_production_document_tables_exist(): void
    {
        // 正常路径：单据域 8 张表全部建立
        foreach (['pick_lists', 'pick_list_items', 'return_lists', 'return_list_items',
                  'outsourcing_orders', 'outsourcing_receipts', 'finished_inbounds', 'finished_inbound_items'] as $table) {
            $this->assertTrue(Schema::hasTable($table), "表 {$table} 不存在");
        }
    }

    public function test_production_document_permissions_seeded(): void
    {
        // 正常路径：生产管理权限累计 24 项（Task 1 的 8 + 本 Task 的 16）且 admin 全量持有
        $this->assertSame(24, Permission::where('group', '生产管理')->count());
        $admin = Role::where('code', 'admin')->first();
        $this->assertSame(2, $admin->permissions()->whereIn('code', ['production.pick.create', 'production.outsource.update'])->count());
    }

    public function test_document_sequence_has_production_types(): void
    {
        // 正常路径：单据号段常量已注册（领料/退料/委外/委外回收/成品入库）
        $this->assertSame('pl', DocumentSequence::TYPE_PL);
        $this->assertSame('rl', DocumentSequence::TYPE_RL);
        $this->assertSame('os', DocumentSequence::TYPE_OS);
        $this->assertSame('osr', DocumentSequence::TYPE_OSR);
        $this->assertSame('fi', DocumentSequence::TYPE_FI);
    }
```

- [ ] **Step 2: 跑测试确认失败**

Run: `cd server && php artisan test --filter=ProductionStructureTest`
Expected: FAIL（表/常量/权限不存在）。

- [ ] **Step 3: 创建迁移（单据域 8 表）**

创建 `server/database/migrations/2026_08_14_110000_create_production_documents_tables.php`：

```php
<?php

// 生产单据表（单据域）：领料单+明细、退料单+明细、委外单+回收单、成品入库单+明细
// 各单据审核均经 InventoryService 写流水（pick/return/outsourcing_out/outsourcing_in/finished_inbound）

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pick_lists', function (Blueprint $table) {
            $table->id();
            $table->string('no', 30)->unique()->comment('领料单号，如 PL20260812-001');
            $table->foreignId('order_id')->constrained('production_orders')->restrictOnDelete()->comment('所属工单');
            $table->tinyInteger('status')->default(0)->comment('0草稿 1已审核');
            $table->tinyInteger('issue_status')->default(0)->comment('0未发料 1部分发料 2全部发料');
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete()->comment('领料仓库');
            $table->foreignId('location_id')->constrained('locations')->restrictOnDelete()->comment('领料库位');
            $table->timestamp('approved_at')->nullable()->comment('审核时间');
            $table->string('operator', 50)->nullable()->comment('审核人');
            $table->string('remark')->nullable()->comment('备注');
            $table->index('status', 'pick_lists_status');
            $table->index('order_id', 'pick_lists_order');
            $table->timestamps();
        });

        Schema::create('pick_list_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pick_id')->constrained('pick_lists')->cascadeOnDelete()->comment('所属领料单');
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete()->comment('物料商品');
            $table->decimal('required_qty', 12, 2)->comment('需求数量（生成时快照）');
            $table->decimal('pick_qty', 12, 2)->comment('本次领用数量');
            $table->decimal('issued_qty', 12, 2)->default(0)->comment('已发数量（发料动作回写）');
            $table->timestamps();
            $table->index('product_id', 'pick_list_items_product');
        });

        Schema::create('return_lists', function (Blueprint $table) {
            $table->id();
            $table->string('no', 30)->unique()->comment('退料单号，如 RL20260812-001');
            $table->foreignId('order_id')->constrained('production_orders')->restrictOnDelete()->comment('所属工单');
            $table->foreignId('pick_id')->nullable()->constrained('pick_lists')->nullOnDelete()->comment('冲销来源领料单（可空）');
            $table->tinyInteger('status')->default(0)->comment('0草稿 1已审核');
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete()->comment('退料仓库');
            $table->foreignId('location_id')->constrained('locations')->restrictOnDelete()->comment('退料库位');
            $table->timestamp('approved_at')->nullable()->comment('审核时间');
            $table->string('operator', 50)->nullable()->comment('审核人');
            $table->string('remark')->nullable()->comment('备注');
            $table->index('status', 'return_lists_status');
            $table->index('order_id', 'return_lists_order');
            $table->timestamps();
        });

        Schema::create('return_list_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('return_id')->constrained('return_lists')->cascadeOnDelete()->comment('所属退料单');
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete()->comment('物料商品');
            $table->decimal('quantity', 12, 2)->comment('退料数量');
            $table->timestamps();
            $table->index('product_id', 'return_list_items_product');
        });

        Schema::create('outsourcing_orders', function (Blueprint $table) {
            $table->id();
            $table->string('no', 30)->unique()->comment('委外加工单号，如 OS20260812-001');
            $table->foreignId('order_id')->constrained('production_orders')->restrictOnDelete()->comment('所属工单');
            $table->foreignId('operation_id')->constrained('work_order_operations')->restrictOnDelete()->comment('委外工序');
            $table->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete()->comment('委外供应商');
            $table->tinyInteger('status')->default(0)->comment('0草稿 1已审核(已发出) 2已回收');
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete()->comment('发出仓库');
            $table->foreignId('location_id')->constrained('locations')->restrictOnDelete()->comment('发出库位');
            $table->decimal('quantity', 12, 2)->comment('委外数量（发出=回收基准）');
            $table->timestamp('approved_at')->nullable()->comment('发出时间');
            $table->string('operator', 50)->nullable()->comment('操作人');
            $table->string('remark')->nullable()->comment('备注');
            $table->index('status', 'outsourcing_orders_status');
            $table->index('order_id', 'outsourcing_orders_order');
            $table->timestamps();
        });

        Schema::create('outsourcing_receipts', function (Blueprint $table) {
            $table->id();
            $table->string('no', 30)->unique()->comment('委外回收单号，如 OSR20260812-001');
            $table->foreignId('outsourcing_id')->constrained('outsourcing_orders')->cascadeOnDelete()->comment('所属委外单');
            $table->decimal('quantity', 12, 2)->comment('回收数量');
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete()->comment('入库仓库');
            $table->foreignId('location_id')->constrained('locations')->restrictOnDelete()->comment('入库库位');
            $table->tinyInteger('status')->default(1)->comment('回收单创建即审核，恒为 1 已审核');
            $table->timestamp('received_at')->comment('回收时间');
            $table->string('operator', 50)->nullable()->comment('操作人');
            $table->string('remark')->nullable()->comment('备注');
            $table->timestamps();
        });

        Schema::create('finished_inbounds', function (Blueprint $table) {
            $table->id();
            $table->string('no', 30)->unique()->comment('成品入库单号，如 FI20260812-001');
            $table->foreignId('order_id')->constrained('production_orders')->restrictOnDelete()->comment('所属工单');
            $table->tinyInteger('status')->default(0)->comment('0草稿 1已审核');
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete()->comment('入库仓库');
            $table->foreignId('location_id')->constrained('locations')->restrictOnDelete()->comment('入库库位');
            $table->timestamp('approved_at')->nullable()->comment('审核时间');
            $table->string('operator', 50)->nullable()->comment('审核人');
            $table->string('remark')->nullable()->comment('备注');
            $table->index('status', 'finished_inbounds_status');
            $table->index('order_id', 'finished_inbounds_order');
            $table->timestamps();
        });

        Schema::create('finished_inbound_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('finished_inbound_id')->constrained('finished_inbounds')->cascadeOnDelete()->comment('所属入库单');
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete()->comment('成品商品（必须与工单产品一致 1526）');
            $table->decimal('quantity', 12, 2)->comment('入库数量');
            $table->timestamps();
            $table->index('product_id', 'finished_inbound_items_product');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finished_inbound_items');
        Schema::dropIfExists('finished_inbounds');
        Schema::dropIfExists('outsourcing_receipts');
        Schema::dropIfExists('outsourcing_orders');
        Schema::dropIfExists('return_list_items');
        Schema::dropIfExists('return_lists');
        Schema::dropIfExists('pick_list_items');
        Schema::dropIfExists('pick_lists');
    }
};
```

Run: `cd server && php artisan migrate`
Expected: 8 张表创建成功。

- [ ] **Step 4: 创建 8 个模型**

`server/app/Models/PickList.php`:

```php
<?php

// 领料单模型：草稿→已审核 两级状态 + 发料状态（未发料/部分/全部）；审核经 InventoryService 扣原料（防超领）

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 领料单
 *
 * @property int $id
 * @property string $no
 * @property int $order_id
 * @property int $status
 * @property int $issue_status
 * @property int $warehouse_id
 * @property int $location_id
 * @property string|null $approved_at
 * @property string|null $operator
 * @property string|null $remark
 */
class PickList extends Model
{
    public const STATUS_DRAFT = 0;
    public const STATUS_APPROVED = 1;

    public const ISSUE_NONE = 0;     // 未发料
    public const ISSUE_PARTIAL = 1;  // 部分发料
    public const ISSUE_ALL = 2;      // 全部发料

    /** 状态中文标签（列表展示） */
    public const STATUS_LABELS = [
        self::STATUS_DRAFT => '草稿',
        self::STATUS_APPROVED => '已审核',
    ];

    /** 发料状态中文标签（列表展示） */
    public const ISSUE_LABELS = [
        self::ISSUE_NONE => '未发料',
        self::ISSUE_PARTIAL => '部分发料',
        self::ISSUE_ALL => '全部发料',
    ];

    protected $fillable = ['no', 'order_id', 'status', 'issue_status', 'warehouse_id', 'location_id', 'approved_at', 'operator', 'remark'];

    protected function casts(): array
    {
        return [
            'status' => 'integer',
            'issue_status' => 'integer',
            'approved_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<ProductionOrder, $this> */
    // 所属工单
    public function order(): BelongsTo
    {
        return $this->belongsTo(ProductionOrder::class, 'order_id');
    }

    /** @return BelongsTo<Warehouse, $this> */
    // 领料仓库
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /** @return BelongsTo<Location, $this> */
    // 领料库位
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /** @return HasMany<PickListItem, $this> */
    // 明细行（随单级联删除）
    public function items(): HasMany
    {
        return $this->hasMany(PickListItem::class, 'pick_id');
    }
}
```

`server/app/Models/PickListItem.php`:

```php
<?php

// 领料单明细模型：需求快照/本次领用/已发；issued_qty 仅发料动作回写

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 领料单明细
 *
 * @property int $id
 * @property int $pick_id
 * @property int $product_id
 * @property string $required_qty
 * @property string $pick_qty
 * @property string $issued_qty
 */
class PickListItem extends Model
{
    protected $fillable = ['pick_id', 'product_id', 'required_qty', 'pick_qty', 'issued_qty'];

    protected function casts(): array
    {
        return [
            'required_qty' => 'decimal:2',
            'pick_qty' => 'decimal:2',
            'issued_qty' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<Product, $this> */
    // 物料商品
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
```

`server/app/Models/ReturnList.php`:

```php
<?php

// 退料单模型：草稿→已审核；审核经 InventoryService 写 return 流水(+1) 并冲销工单物料已领量

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 退料单
 *
 * @property int $id
 * @property string $no
 * @property int $order_id
 * @property int|null $pick_id
 * @property int $status
 * @property int $warehouse_id
 * @property int $location_id
 * @property string|null $approved_at
 * @property string|null $operator
 * @property string|null $remark
 */
class ReturnList extends Model
{
    public const STATUS_DRAFT = 0;
    public const STATUS_APPROVED = 1;

    /** 状态中文标签（列表展示） */
    public const STATUS_LABELS = [
        self::STATUS_DRAFT => '草稿',
        self::STATUS_APPROVED => '已审核',
    ];

    protected $fillable = ['no', 'order_id', 'pick_id', 'status', 'warehouse_id', 'location_id', 'approved_at', 'operator', 'remark'];

    protected function casts(): array
    {
        return [
            'status' => 'integer',
            'approved_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<ProductionOrder, $this> */
    // 所属工单
    public function order(): BelongsTo
    {
        return $this->belongsTo(ProductionOrder::class, 'order_id');
    }

    /** @return BelongsTo<PickList, $this> */
    // 冲销来源领料单（可空）
    public function pick(): BelongsTo
    {
        return $this->belongsTo(PickList::class, 'pick_id');
    }

    /** @return HasMany<ReturnListItem, $this> */
    // 明细行（随单级联删除）
    public function items(): HasMany
    {
        return $this->hasMany(ReturnListItem::class, 'return_id');
    }
}
```

`server/app/Models/ReturnListItem.php`:

```php
<?php

// 退料单明细模型：退料商品+数量（冲销对象为工单物料已领量，不绑定领料行）

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 退料单明细
 *
 * @property int $id
 * @property int $return_id
 * @property int $product_id
 * @property string $quantity
 */
class ReturnListItem extends Model
{
    protected $fillable = ['return_id', 'product_id', 'quantity'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:2'];
    }

    /** @return BelongsTo<Product, $this> */
    // 物料商品
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
```

`server/app/Models/OutsourcingOrder.php`:

```php
<?php

// 委外加工单模型：草稿→已审核(发出)→已回收 三态；发出扣成品库存(outsourcing_out)、回收加库存(outsourcing_in)

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 委外加工单
 *
 * @property int $id
 * @property string $no
 * @property int $order_id
 * @property int $operation_id
 * @property int $supplier_id
 * @property int $status
 * @property int $warehouse_id
 * @property int $location_id
 * @property string $quantity
 * @property string|null $approved_at
 * @property string|null $operator
 * @property string|null $remark
 */
class OutsourcingOrder extends Model
{
    public const STATUS_DRAFT = 0;     // 草稿
    public const STATUS_APPROVED = 1;  // 已审核（已发出）
    public const STATUS_RECEIVED = 2;  // 已回收

    /** 状态中文标签（列表展示） */
    public const STATUS_LABELS = [
        self::STATUS_DRAFT => '草稿',
        self::STATUS_APPROVED => '已审核',
        self::STATUS_RECEIVED => '已回收',
    ];

    protected $fillable = ['no', 'order_id', 'operation_id', 'supplier_id', 'status', 'warehouse_id', 'location_id', 'quantity', 'approved_at', 'operator', 'remark'];

    protected function casts(): array
    {
        return [
            'status' => 'integer',
            'quantity' => 'decimal:2',
            'approved_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<ProductionOrder, $this> */
    // 所属工单（委外商品 = 工单成品）
    public function order(): BelongsTo
    {
        return $this->belongsTo(ProductionOrder::class, 'order_id');
    }

    /** @return BelongsTo<WorkOrderOperation, $this> */
    // 委外工序
    public function operation(): BelongsTo
    {
        return $this->belongsTo(WorkOrderOperation::class, 'operation_id');
    }

    /** @return BelongsTo<Supplier, $this> */
    // 委外供应商
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /** @return HasMany<OutsourcingReceipt, $this> */
    // 回收记录（随单级联删除）
    public function receipts(): HasMany
    {
        return $this->hasMany(OutsourcingReceipt::class, 'outsourcing_id');
    }
}
```

`server/app/Models/OutsourcingReceipt.php`:

```php
<?php

// 委外回收单模型：创建即审核（恒已审核）；回收量累计与委外量比对驱动委外单状态

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 委外回收单
 *
 * @property int $id
 * @property string $no
 * @property int $outsourcing_id
 * @property string $quantity
 * @property int $warehouse_id
 * @property int $location_id
 * @property int $status
 * @property string $received_at
 * @property string|null $operator
 * @property string|null $remark
 */
class OutsourcingReceipt extends Model
{
    public const STATUS_APPROVED = 1; // 创建即审核，恒为已审核

    protected $fillable = ['no', 'outsourcing_id', 'quantity', 'warehouse_id', 'location_id', 'status', 'received_at', 'operator', 'remark'];

    protected function casts(): array
    {
        return [
            'status' => 'integer',
            'quantity' => 'decimal:2',
            'received_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<OutsourcingOrder, $this> */
    // 所属委外单
    public function outsourcing(): BelongsTo
    {
        return $this->belongsTo(OutsourcingOrder::class, 'outsourcing_id');
    }
}
```

`server/app/Models/FinishedInbound.php`:

```php
<?php

// 成品入库单模型：草稿→已审核；审核经 InventoryService 写 finished_inbound 流水(+1) 并回写工单 completed_qty（满产自动完成）

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 成品入库单
 *
 * @property int $id
 * @property string $no
 * @property int $order_id
 * @property int $status
 * @property int $warehouse_id
 * @property int $location_id
 * @property string|null $approved_at
 * @property string|null $operator
 * @property string|null $remark
 */
class FinishedInbound extends Model
{
    public const STATUS_DRAFT = 0;
    public const STATUS_APPROVED = 1;

    /** 状态中文标签（列表展示） */
    public const STATUS_LABELS = [
        self::STATUS_DRAFT => '草稿',
        self::STATUS_APPROVED => '已审核',
    ];

    protected $fillable = ['no', 'order_id', 'status', 'warehouse_id', 'location_id', 'approved_at', 'operator', 'remark'];

    protected function casts(): array
    {
        return [
            'status' => 'integer',
            'approved_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<ProductionOrder, $this> */
    // 所属工单
    public function order(): BelongsTo
    {
        return $this->belongsTo(ProductionOrder::class, 'order_id');
    }

    /** @return BelongsTo<Warehouse, $this> */
    // 入库仓库
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /** @return BelongsTo<Location, $this> */
    // 入库库位
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /** @return HasMany<FinishedInboundItem, $this> */
    // 明细行（随单级联删除）
    public function items(): HasMany
    {
        return $this->hasMany(FinishedInboundItem::class, 'finished_inbound_id');
    }
}
```

`server/app/Models/FinishedInboundItem.php`:

```php
<?php

// 成品入库单明细模型：成品商品+数量（必须与工单产品一致 1526）

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 成品入库单明细
 *
 * @property int $id
 * @property int $finished_inbound_id
 * @property int $product_id
 * @property string $quantity
 */
class FinishedInboundItem extends Model
{
    protected $fillable = ['finished_inbound_id', 'product_id', 'quantity'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:2'];
    }

    /** @return BelongsTo<Product, $this> */
    // 成品商品
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
```

- [ ] **Step 5: DocumentSequence 追加 5 个单据号段常量**

修改 `server/app/Models/DocumentSequence.php`：在 TYPE_MO 后追加：

```php
    public const TYPE_PL = 'pl';

    public const TYPE_RL = 'rl';

    public const TYPE_OS = 'os';

    public const TYPE_OSR = 'osr';

    public const TYPE_FI = 'fi';
```

- [ ] **Step 6: 追加权限种子（16 项）**

修改 `server/database/seeders/RbacSeeder.php`：在 Task 1 追加的生产工单权限之后追加：

```php
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
```

- [ ] **Step 7: 跑测试确认通过**

Run: `cd server && php artisan test --filter=ProductionStructureTest`
Expected: 全部 PASS（原 8 + 新增 3 = 11 个用例；权限累计 24、常量 5、单据域 8 表）。

- [ ] **Step 8: 提交**

```bash
cd /d/code/project/php-design && git add server/database/migrations/2026_08_14_110000_create_production_documents_tables.php server/app/Models/PickList.php server/app/Models/PickListItem.php server/app/Models/ReturnList.php server/app/Models/ReturnListItem.php server/app/Models/OutsourcingOrder.php server/app/Models/OutsourcingReceipt.php server/app/Models/FinishedInbound.php server/app/Models/FinishedInboundItem.php server/app/Models/DocumentSequence.php server/database/seeders/RbacSeeder.php server/tests/Feature/ProductionStructureTest.php
git commit -m "feat: 生产单据域数据模型（领料/退料/委外/成品入库 8 表）与权限种子"
```

---

## Task 3: 生产工单 API（CRUD + BOM 展开 + 单号）

**Files:**
- Create: `server/app/Exceptions/ProductionException.php`
- Create: `server/app/Services/ProductionOrderService.php`（BOM 展开 + 状态机辅助）
- Create: `server/app/Http/Controllers/Api/ProductionOrderController.php`
- Create: `server/tests/Feature/ProductionOrderTest.php`
- Modify: `server/routes/api.php`

**Interfaces:**
- Consumes: Task 1/2 模型（ProductionOrder/ProductionOrderMaterial/WorkOrderOperation）；Task 1 DocumentSequenceService（type=mo）；BomHeader/BomItem/Process（基础资料）；bcmath
- Produces: `ProductionException extends RuntimeException`（第二参数=业务码，默认 0）；`ProductionOrderService::expandBom(Product $product, string $quantity, BomHeader $bom): array`（返回 `['materials' => [...], 'operations' => [...]]`，需求 = `bcmul(bcdiv($quantity, $bom->quantity, 4), $item->quantity, 2)`、工序 = 全部启用工序按 sort 升序）+ `progress(string $completed, string $quantity): float`；`GET/POST /api/v1/production/orders`、`GET/PUT/DELETE /api/v1/production/orders/{order}`、`GET .../materials`；错误码 1501-1504（1502 数量业务码——生产 spec 明确，与采购/销售 422 不同）；**Task 4 将追加 release/start/complete/close 四接口与 1505-1508**

- [ ] **Step 1: 写失败测试 `server/tests/Feature/ProductionOrderTest.php`**

```php
<?php

// 生产工单接口测试：CRUD/BOM 展开快照/工序序列生成/无 BOM 拦截/快照语义/列表详情（核心路径 100%）

namespace Tests\Feature;

use App\Models\BomHeader;
use App\Models\Category;
use App\Models\PickList;
use App\Models\Process;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\Role;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductionOrderTest extends TestCase
{
    use RefreshDatabase;

    private string $token;
    private User $admin;
    private Product $mat;
    private Product $semi;
    private Product $fin;
    private BomHeader $bom;
    private array $processes = [];

    protected function setUp(): void
    {
        parent::setUp();
        $role = Role::create(['name' => '管理员', 'code' => 'admin']);
        $this->admin = User::create(['name' => '管理员', 'username' => 'admin', 'password' => 'admin123', 'status' => 1]);
        $this->admin->roles()->sync([$role->id]);
        $this->token = $this->admin->createToken('api')->plainTextToken;

        $cat = Category::create(['name' => '成品', 'parent_id' => 0]);
        $semiCat = Category::create(['name' => '半成品', 'parent_id' => 0]);
        $rawCat = Category::create(['name' => '原材料', 'parent_id' => 0]);
        $unit = Unit::create(['name' => '个', 'code' => 'pc']);
        $this->mat = Product::create(['name' => '测试铝材', 'code' => 'MAT-001', 'type' => 'raw_material', 'category_id' => $rawCat->id, 'unit_id' => $unit->id, 'status' => 1]);
        $this->semi = Product::create(['name' => '半成品A', 'code' => 'SEMI-001', 'type' => 'semi_finished', 'category_id' => $semiCat->id, 'unit_id' => $unit->id, 'status' => 1]);
        $this->fin = Product::create(['name' => '成品B', 'code' => 'FIN-002', 'type' => 'finished', 'category_id' => $cat->id, 'unit_id' => $unit->id, 'status' => 1]);
        // 启用 BOM：成品B = MAT-001×2 + SEMI-001×1（基准产出 1）
        $this->bom = BomHeader::create(['code' => 'BOM-001', 'product_id' => $this->fin->id, 'version' => 'v1', 'quantity' => 1, 'status' => 1]);
        $this->bom->items()->createMany([
            ['material_id' => $this->mat->id, 'quantity' => 2, 'unit_id' => $unit->id],
            ['material_id' => $this->semi->id, 'quantity' => 1, 'unit_id' => $unit->id],
        ]);
        // 工序序列源：3 个启用工序（下料/组装/质检）
        foreach ([['下料', 'CUT', 1], ['组装', 'ASSY', 2], ['质检', 'QC', 3]] as [$name, $code, $sort]) {
            $this->processes[] = Process::create(['name' => $name, 'code' => $code, 'sort' => $sort, 'status' => 1]);
        }
    }

    // 组装工单载荷（默认 FIN-002×10 计划今天）
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'product_id' => $this->fin->id,
            'quantity' => 10,
            'plan_date' => now()->toDateString(),
            'remark' => '测试工单',
        ], $overrides);
    }

    // 通过 API 建草稿工单并返回单号
    private function createOrder(array $payload): string
    {
        $res = $this->withToken($this->token)->postJson('/api/v1/production/orders', $payload);
        $res->assertJsonPath('code', 0);
        return $res->json('data.no');
    }

    public function test_store_creates_draft_with_bom_expansion(): void
    {
        // 正常路径：草稿创建成功，单号 MO{date}-001；BOM 展开物料快照（10×2=20、10×1=10）+ 工序序列 3 行待开工
        $no = $this->createOrder($this->payload());
        $this->assertMatchesRegularExpression('/^MO\d{8}-001$/', $no);
        $order = ProductionOrder::where('no', $no)->first();
        $this->assertSame(ProductionOrder::STATUS_DRAFT, $order->status);
        // 物料快照：需求 = 数量 × 用量（bcmath）
        $mats = $order->materials()->with('material')->get()->keyBy('material_id');
        $this->assertSame('20.00', $mats[$this->mat->id]->required_qty);
        $this->assertSame('10.00', $mats[$this->semi->id]->required_qty);
        $this->assertSame('0.00', $mats[$this->mat->id]->issued_qty);
        // 工序序列：3 行全部待开工，seq 按 sort 升序
        $ops = $order->operations()->with('process')->orderBy('seq')->get();
        $this->assertSame(3, $ops->count());
        $this->assertSame(['下料', '组装', '质检'], $ops->map(fn ($o) => $o->process->name)->all());
        $this->assertSame([1, 2, 3], $ops->map(fn ($o) => $o->seq)->all());
        $this->assertSame(WorkOrderOperation::STATUS_PENDING, $ops->first()->status);
    }

    public function test_store_expands_with_bom_base_quantity(): void
    {
        // 边界路径：BOM 基准产出 2 时需求 = 数量÷基准×用量（10÷2×2=10）
        $bom2 = BomHeader::create(['code' => 'BOM-002', 'product_id' => $this->fin->id, 'version' => 'v2', 'quantity' => 2, 'status' => 1]);
        $bom2->items()->create(['material_id' => $this->mat->id, 'quantity' => 2, 'unit_id' => 1]);
        $no = $this->createOrder($this->payload());
        $order = ProductionOrder::where('no', $no)->first();
        $this->assertSame('10.00', $order->materials()->where('material_id', $this->mat->id)->first()->required_qty);
    }

    public function test_store_rejects_missing_enabled_bom_with_1501(): void
    {
        // 异常路径：成品无启用版本 BOM → 1501（业务码）
        $noBom = Product::create(['name' => '无BOM成品', 'code' => 'FIN-009', 'type' => 'finished', 'category_id' => 1, 'unit_id' => 1, 'status' => 1]);
        $this->withToken($this->token)->postJson('/api/v1/production/orders', $this->payload(['product_id' => $noBom->id]))
            ->assertJsonPath('code', 1501)
            ->assertJsonPath('message', '该成品没有启用版本的 BOM');
    }

    public function test_store_rejects_non_positive_quantity_with_1502(): void
    {
        // 异常路径：数量 ≤ 0 → 1502（业务码，生产 spec 明确；与采购/销售 422 不同）
        $this->withToken($this->token)->postJson('/api/v1/production/orders', $this->payload(['quantity' => 0]))
            ->assertJsonPath('code', 1502);
    }

    public function test_store_uses_enabled_bom_ignoring_request_bom_id(): void
    {
        // 边界路径：请求携带 bom_id 也以启用版本为准（同成品启用版本唯一，停用版不可用）
        $no = $this->createOrder($this->payload(['bom_id' => 999]));
        $order = ProductionOrder::where('no', $no)->first();
        $this->assertSame($this->bom->id, $order->bom_id);
    }

    public function test_update_draft_recalculates_materials(): void
    {
        // 正常路径：草稿可改（数量 10→5），物料快照重建（需求 5×2=10）
        $no = $this->createOrder($this->payload());
        $order = ProductionOrder::where('no', $no)->first();
        $this->withToken($this->token)->putJson("/api/v1/production/orders/{$order->id}", $this->payload(['quantity' => 5]))
            ->assertJsonPath('code', 0);
        $order->refresh();
        $this->assertSame('5.00', $order->quantity);
        $this->assertSame('10.00', $order->materials()->where('material_id', $this->mat->id)->first()->required_qty);
    }

    public function test_update_released_rejected_with_1503(): void
    {
        // 异常路径：已下达工单不可修改 → 1503
        $no = $this->createOrder($this->payload());
        $order = ProductionOrder::where('no', $no)->first();
        $order->status = ProductionOrder::STATUS_RELEASED;
        $order->save();
        $this->withToken($this->token)->putJson("/api/v1/production/orders/{$order->id}", $this->payload())
            ->assertJsonPath('code', 1503);
    }

    public function test_destroy_draft_ok_and_released_rejected_with_1504(): void
    {
        // 正常+异常路径：草稿可删；已下达不可删 → 1504
        $no = $this->createOrder($this->payload());
        $draftId = ProductionOrder::where('no', $no)->first()->id;
        $this->withToken($this->token)->deleteJson("/api/v1/production/orders/{$draftId}")->assertJsonPath('code', 0);
        $this->assertDatabaseMissing('production_orders', ['id' => $draftId]);

        $no2 = $this->createOrder($this->payload());
        $released = ProductionOrder::where('no', $no2)->first();
        $released->status = ProductionOrder::STATUS_RELEASED;
        $released->save();
        $this->withToken($this->token)->deleteJson("/api/v1/production/orders/{$released->id}")
            ->assertJsonPath('code', 1504);
    }

    public function test_destroy_rejected_when_referenced_by_documents(): void
    {
        // 异常路径：草稿工单已被单据引用（领料单挂工单）→ 1504 拒绝删除（防孤儿单据）
        $no = $this->createOrder($this->payload());
        $order = ProductionOrder::where('no', $no)->first();
        PickList::create([
            'no' => 'PL-TEST-001', 'order_id' => $order->id, 'status' => 0, 'issue_status' => 0,
            'warehouse_id' => 1, 'location_id' => 1, 'remark' => null,
        ]);
        $this->withToken($this->token)->deleteJson("/api/v1/production/orders/{$order->id}")
            ->assertJsonPath('code', 1504)
            ->assertJsonPath('message', '工单已被生产单据使用，不可删除');
        $this->assertDatabaseHas('production_orders', ['id' => $order->id]);
    }

    public function test_index_with_filters_and_progress(): void
    {
        // 正常路径：列表含成品名/状态标签/完成率；keyword 筛选
        $no = $this->createOrder($this->payload());
        $order = ProductionOrder::where('no', $no)->first();
        $order->status = ProductionOrder::STATUS_PRODUCING;
        $order->completed_qty = 5;
        $order->save();
        $this->withToken($this->token)->getJson('/api/v1/production/orders')
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.items.0.product_name', '成品B')
            ->assertJsonPath('data.items.0.status_label', '生产中')
            ->assertJsonPath('data.items.0.completed_qty', '5.00')
            ->assertJsonPath('data.items.0.progress', 50.0);
        $this->withToken($this->token)->getJson('/api/v1/production/orders?keyword=MO'.date('Ymd'))
            ->assertJsonPath('data.total', 1);
        $this->withToken($this->token)->getJson('/api/v1/production/orders?status=0')
            ->assertJsonPath('data.total', 0);
    }

    public function test_show_returns_materials_and_operations(): void
    {
        // 正常路径：详情含物料需求（需求/已领/剩余）与工序列表（状态与累计值）
        $no = $this->createOrder($this->payload());
        $id = ProductionOrder::where('no', $no)->first()->id;
        $this->withToken($this->token)->getJson("/api/v1/production/orders/{$id}")
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.product_code', 'FIN-002')
            ->assertJsonPath('data.materials.0.material_code', 'MAT-001')
            ->assertJsonPath('data.materials.0.required_qty', '20.00')
            ->assertJsonPath('data.materials.0.issued_qty', '0.00')
            ->assertJsonPath('data.materials.0.remaining_qty', '20.00')
            ->assertJsonPath('data.operations.0.process_name', '下料')
            ->assertJsonPath('data.operations.0.status', 0)
            ->assertJsonPath('data.operations.0.status_label', '待开工');
    }

    public function test_show_uses_snapshot_not_live_bom(): void
    {
        // 核心不变式（spec §8）：下达后 BOM 被停用/改版不影响已建工单（物料需求已快照）
        $no = $this->createOrder($this->payload());
        $id = ProductionOrder::where('no', $no)->first()->id;
        $this->bom->status = 0;
        $this->bom->save();
        $this->withToken($this->token)->getJson("/api/v1/production/orders/{$id}")
            ->assertJsonPath('data.materials.0.required_qty', '20.00');
    }

    public function test_materials_endpoint_returns_requirements(): void
    {
        // 正常路径：materials 接口返回物料需求（领料单生成预填数据源）
        $no = $this->createOrder($this->payload());
        $id = ProductionOrder::where('no', $no)->first()->id;
        $this->withToken($this->token)->getJson("/api/v1/production/orders/{$id}/materials")
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.items.0.material_code', 'MAT-001')
            ->assertJsonPath('data.items.0.required_qty', '20.00')
            ->assertJsonPath('data.items.0.remaining_qty', '20.00');
    }

    public function test_orders_requires_production_order_permission(): void
    {
        // 异常路径：无 production.order.list 权限的角色被拒（403 JSON 信封）
        $role = Role::create(['name' => '普通', 'code' => 'plain']);
        $u = User::create(['name' => '普通', 'username' => 'plain', 'password' => 'admin123', 'status' => 1]);
        $u->roles()->sync([$role->id]);
        $token = $u->createToken('api')->plainTextToken;
        $this->withToken($token)->getJson('/api/v1/production/orders')->assertStatus(403);
    }
}
```

- [ ] **Step 2: 跑测试确认失败**

Run: `cd server && php artisan test --filter=ProductionOrderTest`
Expected: FAIL（服务/控制器/路由不存在）。

- [ ] **Step 3: 创建 ProductionException**

创建 `server/app/Exceptions/ProductionException.php`：

```php
<?php

// 生产业务异常：BOM 缺失/状态流转冲突/库存不足等，由调用方捕获后转业务码（第二参数=业务码，默认 0）

namespace App\Exceptions;

use RuntimeException;

class ProductionException extends RuntimeException {}
```

- [ ] **Step 4: 实现 ProductionOrderService**

创建 `server/app/Services/ProductionOrderService.php`：

```php
<?php

// 生产工单服务：BOM 展开（物料需求快照 + 工序序列生成）+ 完成率计算

namespace App\Services;

use App\Models\BomHeader;
use App\Models\Process;
use App\Models\Product;
use App\Models\WorkOrderOperation;

class ProductionOrderService
{
    /**
     * BOM 展开：物料需求快照 + 工序序列（供工单创建/更新时调用）
     *
     * @param Product $product 工单成品
     * @param string $quantity 工单计划数量（decimal 字符串）
     * @param BomHeader $bom 启用版本 BOM（调用方已锁定）
     * @return array{materials: array<int, array{material_id:int, required_qty:string}>, operations: array<int, array{process_id:int, seq:int}>}
     */
    public function expandBom(Product $product, string $quantity, BomHeader $bom): array
    {
        // 物料需求 = 计划数量 ÷ 基准产出 × 用量（bcmath 4 位中间精度防误差，最终 2 位）
        $materials = $bom->items()->get()->map(fn ($i) => [
            'material_id' => $i->material_id,
            'required_qty' => bcmul(bcdiv($quantity, (string) $bom->quantity, 4), (string) $i->quantity, 2),
        ])->values()->all();

        // 工序序列 = 全部启用工序按 sort 升序（V1 设计：BOM 头无工序字段，全量启用工序进入工单）
        $seq = 0;
        $operations = Process::query()
            ->where('status', 1)
            ->orderBy('sort')
            ->orderBy('id')
            ->get()
            ->map(fn (Process $p) => [
                'process_id' => $p->id,
                'seq' => ++$seq,
            ])->values()->all();

        return compact('materials', 'operations');
    }

    /**
     * 完成率（%）：completed ÷ quantity × 100，保留 1 位小数（列表进度条展示）
     *
     * @param string $completed 累计完工数量
     * @param string $quantity 计划数量
     * @return float 完成率（0-100，如 50.0）
     */
    public function progress(string $completed, string $quantity): float
    {
        if (bccomp($quantity, '0', 2) <= 0) {
            return 0.0;
        }
        return (float) bcmul(bcdiv($completed, $quantity, 4), '100', 1);
    }

    /** 工序状态中文标签（详情/列表展示，防御未知状态） */
    public function operationStatusLabel(int $status): string
    {
        return WorkOrderOperation::STATUS_LABELS[$status] ?? '未知';
    }
}
```

- [ ] **Step 5: 实现 ProductionOrderController**

创建 `server/app/Http/Controllers/Api/ProductionOrderController.php`：

```php
<?php

// 生产工单控制器：草稿 CRUD + BOM 展开（物料快照/工序序列）+ 物料需求接口；下达/开工/完工/关闭见 Task 4

namespace App\Http\Controllers\Api;

use App\Exceptions\ProductionException;
use App\Http\Controllers\Controller;
use App\Models\BomHeader;
use App\Models\DocumentSequence;
use App\Models\FinishedInbound;
use App\Models\OutsourcingOrder;
use App\Models\PickList;
use App\Models\Process;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\ProductionOrderMaterial;
use App\Models\ReturnList;
use App\Models\WorkOrderOperation;
use App\Services\DocumentSequenceService;
use App\Services\ProductionOrderService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProductionOrderController extends Controller
{
    use ApiResponse;

    public function __construct(
        private ProductionOrderService $orderService,
        private DocumentSequenceService $sequenceService,
    ) {}

    /** 分页列表：单号/成品/状态/日期范围 筛选；含成品名与状态中文标签与完成率 */
    public function index(Request $request)
    {
        $query = ProductionOrder::query()
            ->join('products', 'products.id', '=', 'production_orders.product_id')
            ->select('production_orders.*', 'products.name as product_name', 'products.code as product_code')
            ->orderByDesc('production_orders.id');

        if ($keyword = $request->input('keyword')) {
            $query->where('production_orders.no', 'like', "%{$keyword}%");
        }
        if ($request->filled('product_id')) {
            $query->where('production_orders.product_id', $request->input('product_id'));
        }
        if ($request->filled('status')) {
            $query->where('production_orders.status', (int) $request->input('status'));
        }
        // 日期范围闭区间筛选（计划日期）
        if ($request->filled('date_from')) {
            $query->whereDate('production_orders.plan_date', '>=', $request->input('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('production_orders.plan_date', '<=', $request->input('date_to'));
        }

        $rows = $query->paginate(max(1, min(100, (int) $request->input('per_page', 10))));

        return $this->ok([
            // join 别名列经 getAttribute 读取（PHPStan 静态分析可识别）
            'items' => $rows->map(fn (ProductionOrder $o) => [
                'id' => $o->id,
                'no' => $o->no,
                'product_id' => $o->product_id,
                'product_name' => $o->getAttribute('product_name'),
                'product_code' => $o->getAttribute('product_code'),
                'quantity' => $o->quantity,
                'completed_qty' => $o->completed_qty,
                // 完成率（%）供列表进度条展示
                'progress' => $this->orderService->progress((string) $o->completed_qty, (string) $o->quantity),
                'plan_date' => $o->plan_date,
                'status' => (int) $o->status,
                'status_label' => ProductionOrder::STATUS_LABELS[$o->status] ?? '未知',
                'created_by' => $o->created_by,
                'released_at' => $o->released_at?->toDateTimeString(),
                'completed_at' => $o->completed_at?->toDateTimeString(),
            ]),
            'total' => $rows->total(), 'page' => $rows->currentPage(), 'per_page' => $rows->perPage(),
        ]);
    }

    /**
     * 新建草稿：事务内「锁成品行 → 校验启用 BOM（1501）→ 单号持久序列 → BOM 展开快照物料需求与工序序列」
     * 数量 ≤ 0 → 1502（业务码，生产 spec 明确）；请求携带 bom_id 忽略，一律以启用版本为准
     */
    public function store(Request $request)
    {
        $data = $this->validatePayload($request);
        // 数量 ≤ 0 走业务码 1502（生产 spec 明确，与采购/销售 422 不同）
        if ((float) $data['quantity'] <= 0) {
            return $this->fail(1502, '数量必须大于 0');
        }

        $order = DB::transaction(function () use ($data) {
            // 锁成品行：与 BOM 启用切换并发时串行化（1501 判定读一致）
            $product = Product::whereKey($data['product_id'])->lockForUpdate()->firstOrFail();
            // 启用版本唯一（BOM 模块不变式），按 id 倒序取最新启用版
            $bom = BomHeader::where('product_id', $product->id)->where('status', 1)->orderByDesc('id')->first();
            if (! $bom) {
                throw new ProductionException('该成品没有启用版本的 BOM', 1501);
            }
            $expansion = $this->orderService->expandBom($product, (string) $data['quantity'], $bom);

            $order = $this->sequenceService->nextNo(
                DocumentSequence::TYPE_MO,
                'MO',
                fn (string $no) => ProductionOrder::create([
                    'no' => $no,
                    'product_id' => $data['product_id'],
                    'quantity' => $data['quantity'],
                    'plan_date' => $data['plan_date'],
                    'bom_id' => $bom->id,
                    'status' => ProductionOrder::STATUS_DRAFT,
                    'completed_qty' => 0,
                    'created_by' => auth()->id(),
                    'remark' => $data['remark'] ?? null,
                ]),
                fn () => (int) (ProductionOrder::where('no', 'like', 'MO'.date('Ymd').'-%')
                    ->get('no')->map(fn ($o) => (int) substr((string) $o->no, -3))->max() ?? 0),
            );
            // BOM 展开结果快照：物料需求（order_id+material_id 唯一）+ 工序序列（order_id+seq 唯一）
            $order->materials()->createMany(array_map(fn ($m) => [
                'material_id' => $m['material_id'],
                'required_qty' => $m['required_qty'],
                'issued_qty' => 0,
            ], $expansion['materials']));
            $order->operations()->createMany(array_map(fn ($op) => [
                'process_id' => $op['process_id'],
                'seq' => $op['seq'],
                'status' => WorkOrderOperation::STATUS_PENDING,
                'qualified_qty' => 0,
                'defective_qty' => 0,
                'hours' => 0,
            ], $expansion['operations']));

            return $order;
        });

        return $this->ok(['no' => $order->no]);
    }

    /** 详情：抬头 + 物料需求（需求/已领/剩余）+ 工序列表（状态与累计合格/不良/工时） */
    public function show(ProductionOrder $order)
    {
        return $this->ok([
            'id' => $order->id,
            'no' => $order->no,
            'product_id' => $order->product_id,
            'product_name' => $order->product?->name,
            'product_code' => $order->product?->code,
            'quantity' => $order->quantity,
            'plan_date' => $order->plan_date,
            'bom_id' => $order->bom_id,
            'bom_code' => $order->bom?->code,
            'status' => (int) $order->status,
            'status_label' => ProductionOrder::STATUS_LABELS[$order->status] ?? '未知',
            'completed_qty' => $order->completed_qty,
            'progress' => $this->orderService->progress((string) $order->completed_qty, (string) $order->quantity),
            'created_by' => $order->created_by,
            'released_at' => $order->released_at?->toDateTimeString(),
            'completed_at' => $order->completed_at?->toDateTimeString(),
            'closed_at' => $order->closed_at?->toDateTimeString(),
            'remark' => $order->remark,
            // 物料需求快照：剩余 = 需求 - 已领（bcmath 精确）
            'materials' => $order->materials()->with('material')->orderBy('id')->get()
                ->map(fn (ProductionOrderMaterial $m) => [
                    'material_id' => $m->material_id,
                    'material_name' => $m->material?->name,
                    'material_code' => $m->material?->code,
                    'required_qty' => $m->required_qty,
                    'issued_qty' => $m->issued_qty,
                    'remaining_qty' => bcsub((string) $m->required_qty, (string) $m->issued_qty, 2),
                ]),
            'operations' => $order->operations()->with('process')->orderBy('seq')->get()
                ->map(fn (WorkOrderOperation $op) => [
                    'id' => $op->id,
                    'seq' => $op->seq,
                    'process_id' => $op->process_id,
                    'process_name' => $op->process?->name,
                    'process_code' => $op->process?->code,
                    'status' => (int) $op->status,
                    'status_label' => $this->orderService->operationStatusLabel((int) $op->status),
                    'qualified_qty' => $op->qualified_qty,
                    'defective_qty' => $op->defective_qty,
                    'hours' => $op->hours,
                ]),
        ]);
    }

    /** 更新草稿：仅草稿（1503）；物料快照/工序序列全量重建（BOM 展开）；事务内锁行复查防并发 */
    public function update(Request $request, ProductionOrder $order)
    {
        try {
            if ($order->status !== ProductionOrder::STATUS_DRAFT) {
                return $this->fail(1503, '已下达工单不可修改');
            }
            $data = $this->validatePayload($request);
            if ((float) $data['quantity'] <= 0) {
                return $this->fail(1502, '数量必须大于 0');
            }

            DB::transaction(function () use ($order, $data) {
                // 锁工单行复查状态：与下达并发时防止改到正在下达的单（幂等 1503）
                $locked = ProductionOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
                if ($locked->status !== ProductionOrder::STATUS_DRAFT) {
                    throw new ProductionException('已下达工单不可修改', 1503);
                }
                // 锁成品行 + 取启用 BOM（与 store 同口径）
                Product::whereKey($data['product_id'])->lockForUpdate()->firstOrFail();
                $bom = BomHeader::where('product_id', $data['product_id'])->where('status', 1)->orderByDesc('id')->first();
                if (! $bom) {
                    throw new ProductionException('该成品没有启用版本的 BOM', 1501);
                }
                $expansion = $this->orderService->expandBom($locked->product, (string) $data['quantity'], $bom);

                $locked->update([
                    'product_id' => $data['product_id'],
                    'quantity' => $data['quantity'],
                    'plan_date' => $data['plan_date'],
                    'bom_id' => $bom->id,
                    'remark' => $data['remark'] ?? $locked->remark,
                ]);
                // 物料快照/工序序列全量重建（草稿工单无流水引用，直接重建）
                $locked->materials()->delete();
                $locked->materials()->createMany(array_map(fn ($m) => [
                    'material_id' => $m['material_id'],
                    'required_qty' => $m['required_qty'],
                    'issued_qty' => 0,
                ], $expansion['materials']));
                $locked->operations()->delete();
                $locked->operations()->createMany(array_map(fn ($op) => [
                    'process_id' => $op['process_id'],
                    'seq' => $op['seq'],
                    'status' => WorkOrderOperation::STATUS_PENDING,
                    'qualified_qty' => 0,
                    'defective_qty' => 0,
                    'hours' => 0,
                ], $expansion['operations']));
            });
        } catch (ProductionException $e) {
            // 1503 已下达（锁行复查与并发下达幂等拦截）/1501 BOM 变更（改成品后新成品无启用 BOM）
            return $this->fail($e->getCode() ?: 1503, $e->getMessage());
        }

        return $this->ok();
    }

    /** 删除草稿：仅草稿（1504）；被生产单据引用不可删；事务内锁行复查防并发 */
    public function destroy(ProductionOrder $order)
    {
        try {
            if ($order->status !== ProductionOrder::STATUS_DRAFT) {
                return $this->fail(1504, '已下达工单不可删除');
            }
            DB::transaction(function () use ($order) {
                // 锁工单行复查状态（幂等 1504）
                $locked = ProductionOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
                if ($locked->status !== ProductionOrder::STATUS_DRAFT) {
                    throw new ProductionException('已下达工单不可删除', 1504);
                }
                // 防孤儿单据：草稿工单已被领料/退料/委外/成品入库单引用 → 拒绝删除（1504 同族）
                $referenced = PickList::where('order_id', $locked->id)->exists()
                    || ReturnList::where('order_id', $locked->id)->exists()
                    || OutsourcingOrder::where('order_id', $locked->id)->exists()
                    || FinishedInbound::where('order_id', $locked->id)->exists();
                if ($referenced) {
                    throw new ProductionException('工单已被生产单据使用，不可删除', 1504);
                }
                $locked->delete();
            });
        } catch (ProductionException $e) {
            // 1504 已下达/被单据引用（锁行复查与并发下达幂等拦截）
            return $this->fail($e->getCode() ?: 1504, $e->getMessage());
        }

        return $this->ok();
    }

    /** 物料需求列表：BOM 展开快照（需求/已领/剩余），领料单「从工单生成」预填数据源 */
    public function materials(ProductionOrder $order)
    {
        return $this->ok([
            'items' => $order->materials()->with('material')->orderBy('id')->get()
                ->map(fn (ProductionOrderMaterial $m) => [
                    'material_id' => $m->material_id,
                    'material_name' => $m->material?->name,
                    'material_code' => $m->material?->code,
                    'required_qty' => $m->required_qty,
                    'issued_qty' => $m->issued_qty,
                    'remaining_qty' => bcsub((string) $m->required_qty, (string) $m->issued_qty, 2),
                ]),
        ]);
    }

    // 载荷格式校验（422 仅格式层）；数量值域 1502 在方法内检查（业务码）
    private function validatePayload(Request $request): array
    {
        return $request->validate([
            'product_id' => 'required|integer|exists:products,id',
            // 数量限两位小数（正则按字符串形态校验，拦截 1e2 科学计数法避免 bcmul ValueError；负值形态放行到 1502）
            'quantity' => 'required|numeric|regex:/^-?\d+(\.\d{1,2})?$/',
            'plan_date' => 'required|date',
            'bom_id' => 'nullable|integer|exists:bom_headers,id',
            'remark' => 'nullable|string|max:200',
        ]);
    }
}
```

- [ ] **Step 6: 注册路由**

修改 `server/routes/api.php`：顶部 use 区追加 `use App\Http\Controllers\Api\ProductionOrderController;`，并在销售出库单路由组之后追加：

```php
    // 生产工单：CRUD + 物料需求（production.order.*；下达/开工/完工/关闭复用 update，Task 4 追加）
    Route::middleware('auth:sanctum')->group(function () {
        Route::middleware('permission:production.order.list')->get('/production/orders', [ProductionOrderController::class, 'index']);
        Route::middleware('permission:production.order.list')->get('/production/orders/{order}/materials', [ProductionOrderController::class, 'materials']);
        Route::middleware('permission:production.order.create')->post('/production/orders', [ProductionOrderController::class, 'store']);
        Route::middleware('permission:production.order.list')->get('/production/orders/{order}', [ProductionOrderController::class, 'show']);
        Route::middleware('permission:production.order.update')->put('/production/orders/{order}', [ProductionOrderController::class, 'update']);
        Route::middleware('permission:production.order.delete')->delete('/production/orders/{order}', [ProductionOrderController::class, 'destroy']);
    });
```

- [ ] **Step 7: 跑测试确认通过**

Run: `cd server && php artisan test --filter=ProductionOrderTest`
Expected: 14 个用例全部 PASS（BOM 展开快照/基准产出/1501/1502/1503/1504/单据引用删除保护/列表进度/详情/快照语义/权限）。

- [ ] **Step 8: 提交**

```bash
cd /d/code/project/php-design && git add server/app/Exceptions/ProductionException.php server/app/Services/ProductionOrderService.php server/app/Http/Controllers/Api/ProductionOrderController.php server/tests/Feature/ProductionOrderTest.php server/routes/api.php
git commit -m "feat: 生产工单 API（BOM 展开快照物料与工序序列/1501-1504）"
```

---

## Task 4: 生产工单状态机（release/start/complete/close）

**Files:**
- Modify: `server/app/Http/Controllers/Api/ProductionOrderController.php`（追加 release/start/complete/close 四接口）
- Modify: `server/tests/Feature/ProductionOrderTest.php`（追加状态机用例）
- Modify: `server/routes/api.php`（追加 4 条状态流转路由）

**Interfaces:**
- Consumes: Task 3 控制器与模型；`ProductionOrder::STATUS_*` 五态；`WorkOrderOperation::STATUS_*` 三态
- Produces: `POST /api/v1/production/orders/{order}/release|start|complete|close`；错误码 1505-1508；release 缺料警告 `data.warnings:[{material_name, required, stock}]`（**允许下达不阻断**，缺料由领料环节控制）；complete 双前置校验（所有工序已完成 1507、至少一次成品入库 completed_qty>0 1508）；**状态非法流转复用码约定**（spec 码段满）：非草稿 release → 1505、非已下达 start → 1506、非生产中 complete → 1507、非已完成 close → 1505 消息「当前状态不可关闭」

- [ ] **Step 1: 写失败测试（追加到 `ProductionOrderTest`）**

在 `server/tests/Feature/ProductionOrderTest.php` 追加用例（顶部补 `use App\Models\InventoryBalance;` `use App\Services\InventoryService;`）：

```php
    // 通过 API 建单并下达，返回工单模型（多次用例复用）
    private function releasedOrder(array $overrides = []): ProductionOrder
    {
        $no = $this->createOrder($this->payload($overrides));
        $order = ProductionOrder::where('no', $no)->first();
        $this->withToken($this->token)->postJson("/api/v1/production/orders/{$order->id}/release")->assertJsonPath('code', 0);
        return $order->refresh();
    }

    public function test_release_transitions_and_returns_warnings(): void
    {
        // 正常路径：草稿 → 已下达（released_at 落库）；物料充足时 warnings 空
        $no = $this->createOrder($this->payload());
        $order = ProductionOrder::where('no', $no)->first();
        $res = $this->withToken($this->token)->postJson("/api/v1/production/orders/{$order->id}/release");
        $res->assertJsonPath('code', 0)->assertJsonPath('data.warnings', []);
        $this->assertSame(ProductionOrder::STATUS_RELEASED, ProductionOrder::find($order->id)->status);
        $this->assertNotNull(ProductionOrder::find($order->id)->released_at);
    }

    public function test_release_warns_when_material_insufficient(): void
    {
        // 边界路径：物料全局库存不足时仅警告不阻断（缺料由领料环节控制）
        $no = $this->createOrder($this->payload());
        $order = ProductionOrder::where('no', $no)->first();
        $res = $this->withToken($this->token)->postJson("/api/v1/production/orders/{$order->id}/release");
        $res->assertJsonPath('code', 0);
        $warnings = $res->json('data.warnings');
        $this->assertNotEmpty($warnings);
        $mat = collect($warnings)->firstWhere('material_name', '测试铝材');
        $this->assertSame('20.00', $mat['required']);
        $this->assertSame('0.00', $mat['stock']);
        $this->assertSame(ProductionOrder::STATUS_RELEASED, ProductionOrder::find($order->id)->status);
    }

    public function test_release_rejects_non_draft_with_1505(): void
    {
        // 异常路径：重复下达 → 1505「工单已下达」
        $no = $this->createOrder($this->payload());
        $order = ProductionOrder::where('no', $no)->first();
        $this->withToken($this->token)->postJson("/api/v1/production/orders/{$order->id}/release")->assertJsonPath('code', 0);
        $this->withToken($this->token)->postJson("/api/v1/production/orders/{$order->id}/release")
            ->assertJsonPath('code', 1505)
            ->assertJsonPath('message', '工单已下达');
    }

    public function test_start_transitions_first_operation_and_rejects_duplicate_with_1506(): void
    {
        // 正常+异常路径：已下达 → 生产中 + 首工序进行中；重复开工 → 1506
        $order = $this->releasedOrder();
        $this->withToken($this->token)->postJson("/api/v1/production/orders/{$order->id}/start")->assertJsonPath('code', 0);
        $order->refresh();
        $this->assertSame(ProductionOrder::STATUS_PRODUCING, $order->status);
        $first = $order->operations()->orderBy('seq')->first();
        $this->assertSame(WorkOrderOperation::STATUS_RUNNING, $first->status);
        $this->withToken($this->token)->postJson("/api/v1/production/orders/{$order->id}/start")
            ->assertJsonPath('code', 1506);
    }

    public function test_start_rejects_draft_with_1506(): void
    {
        // 异常路径：草稿未下达直接开工 → 1506
        $no = $this->createOrder($this->payload());
        $order = ProductionOrder::where('no', $no)->first();
        $this->withToken($this->token)->postJson("/api/v1/production/orders/{$order->id}/start")
            ->assertJsonPath('code', 1506);
    }

    public function test_complete_requires_all_operations_done_with_1507(): void
    {
        // 异常路径：存在未完成工序 → 1507「存在未完成工序，无法完工」
        $order = $this->releasedOrder();
        $this->withToken($this->token)->postJson("/api/v1/production/orders/{$order->id}/start")->assertJsonPath('code', 0);
        $res = $this->withToken($this->token)->postJson("/api/v1/production/orders/{$order->id}/complete");
        $res->assertJsonPath('code', 1507)->assertJsonPath('message', '存在未完成工序，无法完工');
        $this->assertSame(ProductionOrder::STATUS_PRODUCING, ProductionOrder::find($order->id)->status);
    }

    public function test_complete_requires_finished_inbound_with_1508(): void
    {
        // 异常路径：全部工序已完成但无成品入库 → 1508（completed_qty=0）
        $order = $this->releasedOrder();
        $this->withToken($this->token)->postJson("/api/v1/production/orders/{$order->id}/start")->assertJsonPath('code', 0);
        // 直接置全部工序完成（报工接口 Task 5 覆盖流转，此处绕过前置）
        $order->operations()->update(['status' => WorkOrderOperation::STATUS_DONE]);
        $res = $this->withToken($this->token)->postJson("/api/v1/production/orders/{$order->id}/complete");
        $res->assertJsonPath('code', 1508)->assertJsonPath('message', '无成品入库，无法完工');
    }

    public function test_complete_transitions_when_all_done_and_inbound(): void
    {
        // 正常路径：全部工序完成 + completed_qty>0 → 已完成（completed_at 落库）
        $order = $this->releasedOrder();
        $this->withToken($this->token)->postJson("/api/v1/production/orders/{$order->id}/start")->assertJsonPath('code', 0);
        $order->operations()->update(['status' => WorkOrderOperation::STATUS_DONE]);
        $order->completed_qty = 10;
        $order->save();
        $this->withToken($this->token)->postJson("/api/v1/production/orders/{$order->id}/complete")
            ->assertJsonPath('code', 0);
        $order->refresh();
        $this->assertSame(ProductionOrder::STATUS_COMPLETED, $order->status);
        $this->assertNotNull($order->completed_at);
    }

    public function test_complete_rejects_non_producing_with_1507(): void
    {
        // 异常路径：草稿直接完工 → 1507（当前状态不可完工）
        $no = $this->createOrder($this->payload());
        $order = ProductionOrder::where('no', $no)->first();
        $this->withToken($this->token)->postJson("/api/v1/production/orders/{$order->id}/complete")
            ->assertJsonPath('code', 1507);
    }

    public function test_close_completed_and_rejects_others_with_1505(): void
    {
        // 正常+异常路径：已完成 → 关闭（closed_at 落库）；非已完成（草稿）关闭 → 1505「当前状态不可关闭」
        $order = $this->releasedOrder();
        $this->withToken($this->token)->postJson("/api/v1/production/orders/{$order->id}/start")->assertJsonPath('code', 0);
        $order->operations()->update(['status' => WorkOrderOperation::STATUS_DONE]);
        $order->completed_qty = 10;
        $order->save();
        $this->withToken($this->token)->postJson("/api/v1/production/orders/{$order->id}/complete")->assertJsonPath('code', 0);
        $this->withToken($this->token)->postJson("/api/v1/production/orders/{$order->id}/close")
            ->assertJsonPath('code', 0);
        $order->refresh();
        $this->assertSame(ProductionOrder::STATUS_CLOSED, $order->status);
        $this->assertNotNull($order->closed_at);

        $no2 = $this->createOrder($this->payload());
        $draft = ProductionOrder::where('no', $no2)->first();
        $this->withToken($this->token)->postJson("/api/v1/production/orders/{$draft->id}/close")
            ->assertJsonPath('code', 1505)
            ->assertJsonPath('message', '当前状态不可关闭');
    }

    public function test_closed_order_blocks_release_and_start(): void
    {
        // 异常路径：关闭后无任何操作（PRD-14）——release/start 均被状态机拦截
        $order = $this->releasedOrder();
        $this->withToken($this->token)->postJson("/api/v1/production/orders/{$order->id}/start")->assertJsonPath('code', 0);
        $order->operations()->update(['status' => WorkOrderOperation::STATUS_DONE]);
        $order->completed_qty = 10;
        $order->save();
        $this->withToken($this->token)->postJson("/api/v1/production/orders/{$order->id}/complete")->assertJsonPath('code', 0);
        $this->withToken($this->token)->postJson("/api/v1/production/orders/{$order->id}/close")->assertJsonPath('code', 0);
        $this->withToken($this->token)->postJson("/api/v1/production/orders/{$order->id}/release")
            ->assertJsonPath('code', 1505);
        $this->withToken($this->token)->postJson("/api/v1/production/orders/{$order->id}/start")
            ->assertJsonPath('code', 1506);
    }
```

- [ ] **Step 2: 跑测试确认失败**

Run: `cd server && php artisan test --filter=ProductionOrderTest`
Expected: 新增用例 FAIL（release/start/complete/close 接口不存在）。

- [ ] **Step 3: 追加 4 个状态流转方法到 ProductionOrderController**

在 `materials()` 方法之后追加（`use App\Models\InventoryBalance;` 加入顶部 imports）：

```php
    /**
     * 下达（草稿→已下达）：重复/非草稿 1505；物料库存不足仅返回 warnings 不阻断（缺料由领料环节控制）
     * 事务内锁工单行复查状态防并发；warnings 读全局库存快照（Σ 全仓余额，只读不锁）
     */
    public function release(ProductionOrder $order)
    {
        try {
            $result = null;
            DB::transaction(function () use ($order, &$result) {
                // 锁工单行：同一工单重复下达在此判重（幂等 1505）
                $locked = ProductionOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
                if ($locked->status === ProductionOrder::STATUS_RELEASED) {
                    throw new ProductionException('工单已下达', 1505);
                }
                if ($locked->status !== ProductionOrder::STATUS_DRAFT) {
                    throw new ProductionException('当前状态不可下达', 1505);
                }
                // 缺料警告：全仓余额汇总 vs 需求（bcadd 累加防浮点；只读快照，允许下达）
                $warnings = [];
                foreach ($locked->materials as $m) {
                    $stock = '0';
                    foreach (InventoryBalance::where('product_id', $m->material_id)->get() as $b) {
                        $stock = bcadd($stock, (string) $b->quantity, 2);
                    }
                    if (bccomp($stock, (string) $m->required_qty, 2) < 0) {
                        $warnings[] = [
                            'material_name' => $m->material?->name ?? ('#'.$m->material_id),
                            'material_code' => $m->material?->code,
                            'required' => $m->required_qty,
                            'stock' => $stock,
                        ];
                    }
                }
                $locked->status = ProductionOrder::STATUS_RELEASED;
                $locked->released_at = now();
                $locked->save();
                $result = ['warnings' => $warnings];
            });
        } catch (ProductionException $e) {
            // 1505 重复下达/状态非法流转
            return $this->fail($e->getCode() ?: 1505, $e->getMessage());
        }

        return $this->ok($result);
    }

    /**
     * 开工（已下达→生产中）：首工序（seq 最小）置进行中；重复/非已下达 1506
     */
    public function start(ProductionOrder $order)
    {
        try {
            DB::transaction(function () use ($order) {
                // 锁工单行复查状态（幂等 1506）
                $locked = ProductionOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
                if ($locked->status !== ProductionOrder::STATUS_RELEASED) {
                    throw new ProductionException('当前状态不可开工', 1506);
                }
                $locked->status = ProductionOrder::STATUS_PRODUCING;
                $locked->save();
                // 首工序置进行中（seq 最小；锁工序行防并发报工窗口）
                $first = WorkOrderOperation::where('order_id', $locked->id)
                    ->orderBy('seq')->lockForUpdate()->first();
                if ($first && $first->status === WorkOrderOperation::STATUS_PENDING) {
                    $first->status = WorkOrderOperation::STATUS_RUNNING;
                    $first->save();
                }
            });
        } catch (ProductionException $e) {
            // 1506 重复开工/状态非法流转
            return $this->fail($e->getCode() ?: 1506, $e->getMessage());
        }

        return $this->ok();
    }

    /**
     * 完工（生产中→已完成）：双前置校验——所有工序已完成（1507）+ 至少一次成品入库 completed_qty>0（1508）
     */
    public function complete(ProductionOrder $order)
    {
        try {
            DB::transaction(function () use ($order) {
                // 锁工单行复查状态（幂等 1507）
                $locked = ProductionOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
                if ($locked->status !== ProductionOrder::STATUS_PRODUCING) {
                    throw new ProductionException('当前状态不可完工', 1507);
                }
                // 前置 1：所有工序必须已完成（存在待开工/进行中 → 1507）
                $hasUndone = $locked->operations()->where('status', '!=', WorkOrderOperation::STATUS_DONE)->exists();
                if ($hasUndone) {
                    throw new ProductionException('存在未完成工序，无法完工', 1507);
                }
                // 前置 2：至少一次成品入库（completed_qty > 0，bcmath 比较）
                if (bccomp((string) $locked->completed_qty, '0', 2) <= 0) {
                    throw new ProductionException('无成品入库，无法完工', 1508);
                }
                $locked->status = ProductionOrder::STATUS_COMPLETED;
                $locked->completed_at = now();
                $locked->save();
            });
        } catch (ProductionException $e) {
            // 1507 状态/工序未完成 或 1508 无入库
            return $this->fail($e->getCode() ?: 1507, $e->getMessage());
        }

        return $this->ok();
    }

    /**
     * 关闭（已完成→关闭）：非已完成拒绝 1505「当前状态不可关闭」（spec 码段满，复用 1505，与 1405/1306 语义对齐）
     */
    public function close(ProductionOrder $order)
    {
        try {
            DB::transaction(function () use ($order) {
                // 锁工单行复查状态（幂等 1505 关闭族）
                $locked = ProductionOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
                if ($locked->status !== ProductionOrder::STATUS_COMPLETED) {
                    throw new ProductionException('当前状态不可关闭', 1505);
                }
                $locked->status = ProductionOrder::STATUS_CLOSED;
                $locked->closed_at = now();
                $locked->save();
            });
        } catch (ProductionException $e) {
            // 1505 当前状态不可关闭
            return $this->fail($e->getCode() ?: 1505, $e->getMessage());
        }

        return $this->ok();
    }
```

- [ ] **Step 4: 注册路由**

修改 `server/routes/api.php` 生产工单路由组，追加 4 条状态流转路由：

```php
        Route::middleware('permission:production.order.update')->post('/production/orders/{order}/release', [ProductionOrderController::class, 'release']);
        Route::middleware('permission:production.order.update')->post('/production/orders/{order}/start', [ProductionOrderController::class, 'start']);
        Route::middleware('permission:production.order.update')->post('/production/orders/{order}/complete', [ProductionOrderController::class, 'complete']);
        Route::middleware('permission:production.order.update')->post('/production/orders/{order}/close', [ProductionOrderController::class, 'close']);
```

- [ ] **Step 5: 跑测试确认通过**

Run: `cd server && php artisan test --filter=ProductionOrderTest`
Expected: 全部 PASS（原 14 + 新增 11 = 25 个用例；状态机全流转/双前置/幂等/关闭封口）。

- [ ] **Step 6: 提交**

```bash
cd /d/code/project/php-design && git add server/app/Http/Controllers/Api/ProductionOrderController.php server/tests/Feature/ProductionOrderTest.php server/routes/api.php
git commit -m "feat: 生产工单状态机（下达缺料警告/开工/完工双前置/关闭 1505-1508）"
```

---

## Task 5: 工序报工 API（报工 + 自动流转 + 记录列表）

**Files:**
- Create: `server/app/Http/Controllers/Api/OperationReportController.php`
- Create: `server/tests/Feature/OperationReportTest.php`
- Modify: `server/routes/api.php`

**Interfaces:**
- Consumes: Task 1/4 模型与状态机（WorkOrderOperation 三态、ProductionOrder 五态）；bcmath
- Produces: `POST /api/v1/production/operations/{operation}/reports`（报工：累计合格≥计划 → 本工序自动完成 + 下一工序自动进行中）、`GET /api/v1/production/operations/{operation}/reports`（报工记录列表）；错误码 1509-1512（**1510/1511 累计语义**：工序已报累计 + 本次 > 计划数即拒，防并发虚报——事务内锁工序行串行化）；合格/不良数为负 → 422（值域，spec 码段满）；**委外回收联动**（Task 8 在委外单已回收时标记工序完成，与本 Task 报工完成互不冲突——回收只对「未完成」工序生效）

- [ ] **Step 1: 写失败测试 `server/tests/Feature/OperationReportTest.php`**

```php
<?php

// 工序报工接口测试：报工校验/累计边界/自动流转/记录列表/并发安全（核心路径 100%）

namespace Tests\Feature;

use App\Models\BomHeader;
use App\Models\Category;
use App\Models\OperationReport;
use App\Models\Process;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\Role;
use App\Models\Unit;
use App\Models\User;
use App\Models\WorkOrderOperation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OperationReportTest extends TestCase
{
    use RefreshDatabase;

    private string $token;
    private User $admin;
    private ProductionOrder $order;
    private array $ops = []; // [seq => WorkOrderOperation]

    protected function setUp(): void
    {
        parent::setUp();
        $role = Role::create(['name' => '管理员', 'code' => 'admin']);
        $this->admin = User::create(['name' => '管理员', 'username' => 'admin', 'password' => 'admin123', 'status' => 1]);
        $this->admin->roles()->sync([$role->id]);
        $this->token = $this->admin->createToken('api')->plainTextToken;

        $cat = Category::create(['name' => '成品', 'parent_id' => 0]);
        $unit = Unit::create(['name' => '个', 'code' => 'pc']);
        $fin = Product::create(['name' => '成品B', 'code' => 'FIN-002', 'type' => 'finished', 'category_id' => $cat->id, 'unit_id' => $unit->id, 'status' => 1]);
        $rawCat = Category::create(['name' => '原材料', 'parent_id' => 0]);
        Product::create(['name' => '测试铝材', 'code' => 'MAT-001', 'type' => 'raw_material', 'category_id' => $rawCat->id, 'unit_id' => $unit->id, 'status' => 1]);
        $bom = BomHeader::create(['code' => 'BOM-001', 'product_id' => $fin->id, 'version' => 'v1', 'quantity' => 1, 'status' => 1]);
        $bom->items()->create(['material_id' => 2, 'quantity' => 2, 'unit_id' => $unit->id]);
        // 3 个启用工序
        foreach ([['下料', 'CUT', 1], ['组装', 'ASSY', 2], ['质检', 'QC', 3]] as [$name, $code, $sort]) {
            Process::create(['name' => $name, 'code' => $code, 'sort' => $sort, 'status' => 1]);
        }
        // 建单 → 下达 → 开工（首工序进行中）
        $res = $this->withToken($this->token)->postJson('/api/v1/production/orders', [
            'product_id' => $fin->id, 'quantity' => 10, 'plan_date' => now()->toDateString(),
        ]);
        $res->assertJsonPath('code', 0);
        $this->order = ProductionOrder::where('no', $res->json('data.no'))->first();
        $this->withToken($this->token)->postJson("/api/v1/production/orders/{$this->order->id}/release")->assertJsonPath('code', 0);
        $this->withToken($this->token)->postJson("/api/v1/production/orders/{$this->order->id}/start")->assertJsonPath('code', 0);
        foreach ($this->order->operations()->orderBy('seq')->get() as $op) {
            $this->ops[$op->seq] = $op;
        }
    }

    // 组装报工载荷
    private function reportPayload(array $overrides = []): array
    {
        return array_merge([
            'qualified_qty' => 10,
            'defective_qty' => 0,
            'hours' => 2.5,
            'operator' => '张三',
            'remark' => '正常报工',
        ], $overrides);
    }

    public function test_report_success_and_auto_advance(): void
    {
        // 正常路径：报工成功（累计+记录落库）；合格累计=计划 → 本工序完成 + 下一工序进行中
        $op1 = $this->ops[1];
        $this->withToken($this->token)->postJson("/api/v1/production/operations/{$op1->id}/reports", $this->reportPayload())
            ->assertJsonPath('code', 0);
        $op1->refresh();
        $this->assertSame('10.00', $op1->qualified_qty);
        $this->assertSame('2.50', $op1->hours);
        $this->assertSame(WorkOrderOperation::STATUS_DONE, $op1->status);
        $op2 = $this->ops[2]->refresh();
        $this->assertSame(WorkOrderOperation::STATUS_RUNNING, $op2->status);
        // 报工记录落库
        $this->assertDatabaseHas('operation_reports', [
            'operation_id' => $op1->id, 'qualified_qty' => '10.00', 'defective_qty' => '0.00',
            'hours' => '2.50', 'operator' => '张三',
        ]);
    }

    public function test_report_partial_keeps_running(): void
    {
        // 边界路径：累计合格 < 计划 → 本工序仍进行中，下一工序不动
        $op1 = $this->ops[1];
        $this->withToken($this->token)->postJson("/api/v1/production/operations/{$op1->id}/reports", $this->reportPayload(['qualified_qty' => 4]))
            ->assertJsonPath('code', 0);
        $op1->refresh();
        $this->assertSame('4.00', $op1->qualified_qty);
        $this->assertSame(WorkOrderOperation::STATUS_RUNNING, $op1->status);
        $this->assertSame(WorkOrderOperation::STATUS_PENDING, $this->ops[2]->refresh()->status);
        // 再报 6 达标 → 完成 + 推进
        $this->withToken($this->token)->postJson("/api/v1/production/operations/{$op1->id}/reports", $this->reportPayload(['qualified_qty' => 6]))
            ->assertJsonPath('code', 0);
        $this->assertSame(WorkOrderOperation::STATUS_DONE, $op1->refresh()->status);
        $this->assertSame(WorkOrderOperation::STATUS_RUNNING, $this->ops[2]->refresh()->status);
        // 累计合格 = 4 + 6 = 10
        $this->assertSame('10.00', $op1->qualified_qty);
    }

    public function test_report_last_operation_completion_no_next(): void
    {
        // 边界路径：末工序报工达标 → 完成且无下一工序可推进（工单可完工）
        $op1 = $this->ops[1];
        $this->withToken($this->token)->postJson("/api/v1/production/operations/{$op1->id}/reports", $this->reportPayload())->assertJsonPath('code', 0);
        $op2 = $this->ops[2]->refresh();
        $this->withToken($this->token)->postJson("/api/v1/production/operations/{$op2->id}/reports", $this->reportPayload())->assertJsonPath('code', 0);
        $op3 = $this->ops[3]->refresh();
        $this->withToken($this->token)->postJson("/api/v1/production/operations/{$op3->id}/reports", $this->reportPayload())->assertJsonPath('code', 0);
        $op3->refresh();
        $this->assertSame(WorkOrderOperation::STATUS_DONE, $op3->status);
        $this->assertSame('10.00', $op3->qualified_qty);
    }

    public function test_report_rejects_non_running_operation_with_1509(): void
    {
        // 异常路径：已完成工序再报工 → 1509（待开工/已完成均不可报）
        $op1 = $this->ops[1];
        $this->withToken($this->token)->postJson("/api/v1/production/operations/{$op1->id}/reports", $this->reportPayload())->assertJsonPath('code', 0);
        $this->withToken($this->token)->postJson("/api/v1/production/operations/{$op1->id}/reports", $this->reportPayload())
            ->assertJsonPath('code', 1509)
            ->assertJsonPath('message', '该工序当前不可报工');
        // 待开工工序（质检）直接报工 → 1509
        $op3 = $this->ops[3];
        $this->withToken($this->token)->postJson("/api/v1/production/operations/{$op3->id}/reports", $this->reportPayload())
            ->assertJsonPath('code', 1509);
    }

    public function test_report_rejects_qualified_over_plan_with_1510(): void
    {
        // 异常路径：合格数超过计划数 → 1510（累计语义：已报 4 + 本次 8 = 12 > 10）
        $op1 = $this->ops[1];
        $this->withToken($this->token)->postJson("/api/v1/production/operations/{$op1->id}/reports", $this->reportPayload(['qualified_qty' => 11]))
            ->assertJsonPath('code', 1510)
            ->assertJsonPath('message', '合格数不能超过工单计划数量');
        // 累计场景：先报 4 再报 8（累计 12 > 10）→ 1510
        $this->withToken($this->token)->postJson("/api/v1/production/operations/{$op1->id}/reports", $this->reportPayload(['qualified_qty' => 4]))->assertJsonPath('code', 0);
        $this->withToken($this->token)->postJson("/api/v1/production/operations/{$op1->id}/reports", $this->reportPayload(['qualified_qty' => 8]))
            ->assertJsonPath('code', 1510);
    }

    public function test_report_rejects_qualified_plus_defective_over_plan_with_1511(): void
    {
        // 异常路径：合格+不良合计超计划 → 1511（8+5=13 > 10）
        $op1 = $this->ops[1];
        $this->withToken($this->token)->postJson("/api/v1/production/operations/{$op1->id}/reports", $this->reportPayload(['qualified_qty' => 8, 'defective_qty' => 5]))
            ->assertJsonPath('code', 1511)
            ->assertJsonPath('message', '合格数与不良数合计不能超过工单计划数量');
    }

    public function test_report_rejects_negative_hours_with_1512(): void
    {
        // 异常路径：工时负数 → 1512（业务码，spec 明确）
        $op1 = $this->ops[1];
        $this->withToken($this->token)->postJson("/api/v1/production/operations/{$op1->id}/reports", $this->reportPayload(['hours' => -1]))
            ->assertJsonPath('code', 1512);
    }

    public function test_report_rejects_negative_qualified_with_422(): void
    {
        // 异常路径：合格数为负 → 422（值域；spec 码段满，镜像采购/销售负值 422 先例）
        $op1 = $this->ops[1];
        $this->withToken($this->token)->postJson("/api/v1/production/operations/{$op1->id}/reports", $this->reportPayload(['qualified_qty' => -1]))
            ->assertJsonPath('code', 422);
        // 不良数为负同样 422
        $this->withToken($this->token)->postJson("/api/v1/production/operations/{$op1->id}/reports", $this->reportPayload(['defective_qty' => -2]))
            ->assertJsonPath('code', 422);
    }

    public function test_report_accumulates_defective_and_hours(): void
    {
        // 正常路径：不良数与工时累计（良率统计口径）
        $op1 = $this->ops[1];
        $this->withToken($this->token)->postJson("/api/v1/production/operations/{$op1->id}/reports", $this->reportPayload(['qualified_qty' => 4, 'defective_qty' => 1, 'hours' => 1.5]))
            ->assertJsonPath('code', 0);
        $this->withToken($this->token)->postJson("/api/v1/production/operations/{$op1->id}/reports", $this->reportPayload(['qualified_qty' => 5, 'defective_qty' => 1, 'hours' => 2]))
            ->assertJsonPath('code', 0);
        $op1->refresh();
        $this->assertSame('9.00', $op1->qualified_qty);
        $this->assertSame('2.00', $op1->defective_qty);
        $this->assertSame('3.50', $op1->hours);
    }

    public function test_reports_index_lists_records(): void
    {
        // 正常路径：报工记录列表（该工序全部记录，含操作人/时间）
        $op1 = $this->ops[1];
        $this->withToken($this->token)->postJson("/api/v1/production/operations/{$op1->id}/reports", $this->reportPayload())->assertJsonPath('code', 0);
        $this->withToken($this->token)->getJson("/api/v1/production/operations/{$op1->id}/reports")
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.items.0.qualified_qty', '10.00')
            ->assertJsonPath('data.items.0.operator', '张三');
    }

    public function test_reports_requires_report_permission(): void
    {
        // 异常路径：无 production.report.list 权限的角色被拒（403 JSON 信封）
        $role = Role::create(['name' => '普通', 'code' => 'plain']);
        $u = User::create(['name' => '普通', 'username' => 'plain', 'password' => 'admin123', 'status' => 1]);
        $u->roles()->sync([$role->id]);
        $token = $u->createToken('api')->plainTextToken;
        $op1 = $this->ops[1];
        $this->withToken($token)->getJson("/api/v1/production/operations/{$op1->id}/reports")->assertStatus(403);
    }
}
```

- [ ] **Step 2: 跑测试确认失败**

Run: `cd server && php artisan test --filter=OperationReportTest`
Expected: FAIL（控制器/路由不存在）。

- [ ] **Step 3: 实现 OperationReportController**

创建 `server/app/Http/Controllers/Api/OperationReportController.php`：

```php
<?php

// 工序报工控制器：报工（累计校验 + 自动流转）与报工记录列表；事务内锁工序行防并发虚报

namespace App\Http\Controllers\Api;

use App\Exceptions\ProductionException;
use App\Http\Controllers\Controller;
use App\Models\OperationReport;
use App\Models\ProductionOrder;
use App\Models\WorkOrderOperation;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OperationReportController extends Controller
{
    use ApiResponse;

    /**
     * 报工：仅工序进行中可报（1509）；累计校验防虚报——合格累计+本次 > 计划数 → 1510、
     * 合格不良累计+本次 > 计划数 → 1511；工时负数 → 1512；合格/不良负数 → 422（值域）。
     * 流转：累计合格 ≥ 计划数 → 本工序自动完成，下一工序（seq 升序）自动进行中。
     * 事务内锁工序行：并发报工同一工序在此串行化，累计值判定一致。
     */
    public function store(Request $request, WorkOrderOperation $operation)
    {
        $data = $this->validatePayload($request);
        // 合格/不良负数走 422 值域（spec 码段满；工时负数有专属码 1512 走业务码）
        if ((float) $data['qualified_qty'] < 0 || (float) $data['defective_qty'] < 0) {
            return $this->fail(422, '合格数与不良数不能为负数');
        }
        if ((float) $data['hours'] < 0) {
            return $this->fail(1512, '工时不能为负数');
        }

        try {
            DB::transaction(function () use ($operation, $data) {
                // 锁工序行：累计值并发安全（两次并发报工串行化后各自复核累计）
                $op = WorkOrderOperation::whereKey($operation->id)->lockForUpdate()->firstOrFail();
                if ($op->status !== WorkOrderOperation::STATUS_RUNNING) {
                    throw new ProductionException('该工序当前不可报工', 1509);
                }
                // 锁工单行：计划数快照（与工单状态流转并发一致）
                $order = ProductionOrder::whereKey($op->order_id)->lockForUpdate()->firstOrFail();
                // 累计语义：已报合格 + 本次合格 ≤ 计划数（防并发虚报）
                $qualifiedSum = bcadd((string) $op->qualified_qty, (string) $data['qualified_qty'], 2);
                if (bccomp($qualifiedSum, (string) $order->quantity, 2) > 0) {
                    throw new ProductionException('合格数不能超过工单计划数量', 1510);
                }
                // 累计语义：合格不良合计（已报 + 本次）≤ 计划数
                $defectSum = bcadd((string) $op->defective_qty, (string) $data['defective_qty'], 2);
                $totalSum = bcadd($qualifiedSum, $defectSum, 2);
                if (bccomp($totalSum, (string) $order->quantity, 2) > 0) {
                    throw new ProductionException('合格数与不良数合计不能超过工单计划数量', 1511);
                }

                // 累计回写（bcmath）
                $op->qualified_qty = $qualifiedSum;
                $op->defective_qty = $defectSum;
                $op->hours = bcadd((string) $op->hours, (string) $data['hours'], 2);

                // 自动流转：累计合格 ≥ 计划数 → 本工序完成 + 下一工序进行中
                if (bccomp($op->qualified_qty, (string) $order->quantity, 2) >= 0) {
                    $op->status = WorkOrderOperation::STATUS_DONE;
                    // 下一工序（seq 升序第一个未完成的待开工工序）
                    $next = WorkOrderOperation::where('order_id', $order->id)
                        ->where('seq', '>', $op->seq)
                        ->orderBy('seq')
                        ->first();
                    if ($next && $next->status === WorkOrderOperation::STATUS_PENDING) {
                        $next->status = WorkOrderOperation::STATUS_RUNNING;
                        $next->save();
                    }
                }
                $op->save();

                // 报工记录（只增不改，统计口径来源）
                OperationReport::create([
                    'operation_id' => $op->id,
                    'order_id' => $order->id,
                    'operator' => $data['operator'] ?? auth()->user()->name ?? null,
                    'qualified_qty' => $data['qualified_qty'],
                    'defective_qty' => $data['defective_qty'],
                    'hours' => $data['hours'],
                    'report_time' => now(),
                    'remark' => $data['remark'] ?? null,
                ]);
            });
        } catch (ProductionException $e) {
            // 1509 不可报工 / 1510 合格超计划 / 1511 合计超计划
            return $this->fail($e->getCode() ?: 1509, $e->getMessage());
        }

        return $this->ok();
    }

    /** 报工记录列表：该工序全部报工记录（按报工时间倒序） */
    public function index(WorkOrderOperation $operation)
    {
        $rows = $operation->reports()->orderByDesc('report_time')->paginate(max(1, min(100, (int) request('per_page', 10))));

        return $this->ok([
            'items' => $rows->map(fn (OperationReport $r) => [
                'id' => $r->id,
                'operator' => $r->operator,
                'qualified_qty' => $r->qualified_qty,
                'defective_qty' => $r->defective_qty,
                'hours' => $r->hours,
                'report_time' => $r->report_time?->toDateTimeString(),
                'remark' => $r->remark,
            ]),
            'total' => $rows->total(), 'page' => $rows->currentPage(), 'per_page' => $rows->perPage(),
        ]);
    }

    // 载荷格式校验（422 仅格式层）；负数值域/业务码在方法内检查
    private function validatePayload(Request $request): array
    {
        return $request->validate([
            // 数量限两位小数（正则防科学计数法；负值形态放行到方法内业务码/422）
            'qualified_qty' => 'required|numeric|regex:/^-?\d+(\.\d{1,2})?$/',
            'defective_qty' => 'nullable|numeric|regex:/^-?\d+(\.\d{1,2})?$/',
            'hours' => 'nullable|numeric|regex:/^-?\d+(\.\d{1,2})?$/',
            'operator' => 'nullable|string|max:50',
            'remark' => 'nullable|string|max:200',
        ]);
    }
}
```

- [ ] **Step 4: 注册路由**

修改 `server/routes/api.php`：顶部 use 区追加 `use App\Http\Controllers\Api\OperationReportController;`，并在生产工单路由组之后追加：

```php
    // 工序报工：报工 + 记录列表（production.report.*；报工提交复用 create）
    Route::middleware('auth:sanctum')->group(function () {
        Route::middleware('permission:production.report.create')->post('/production/operations/{operation}/reports', [OperationReportController::class, 'store']);
        Route::middleware('permission:production.report.list')->get('/production/operations/{operation}/reports', [OperationReportController::class, 'index']);
    });
```

- [ ] **Step 5: 跑测试确认通过**

Run: `cd server && php artisan test --filter=OperationReportTest`
Expected: 12 个用例全部 PASS（自动流转/部分达标/末工序/1509-1512/负值 422/累计不良工时/记录列表/权限）。

- [ ] **Step 6: 提交**

```bash
cd /d/code/project/php-design && git add server/app/Http/Controllers/Api/OperationReportController.php server/tests/Feature/OperationReportTest.php server/routes/api.php
git commit -m "feat: 工序报工 API（累计校验防虚报/自动流转 1509-1512）"
```

---

## Task 6: 领料单 API（CRUD + from-order 预填 + 审核扣原料 + 发料）

**Files:**
- Create: `server/app/Http/Controllers/Api/PickListController.php`
- Create: `server/tests/Feature/PickListTest.php`
- Modify: `server/routes/api.php`

**Interfaces:**
- Consumes: Task 1/2 模型（PickList/PickListItem/ProductionOrder/ProductionOrderMaterial）；Task 1 DocumentSequenceService（type=pl）；`InventoryService::apply`（direction=-1，source_type=pick）；`InventoryException`（防御兜底）
- Produces: `GET/POST /api/v1/production/picks`、`GET /api/v1/production/picks/from-order/{orderId}`（预填剩余量）、`GET/PUT/DELETE /api/v1/production/picks/{pick}`、`POST .../approve`（核心：1513 超领拦截 + 1515 库存不足整体回滚 + 回写 issued_qty）、`POST .../issue`（发料状态推进，V1 一次置全部发料）；错误码 1513-1516；**from-order 必须注册在 `{pick}` 之前**；仓库/库位缺失、明细为空、重复商品 → 422（spec 码段满，镜像销售 1406/1401/1412 的 422 化）

- [ ] **Step 1: 写失败测试 `server/tests/Feature/PickListTest.php`**

```php
<?php

// 领料单接口测试：CRUD/from-order 预填/审核扣库存/超领拦截/库存不足回滚/发料状态/幂等（核心路径 100%）

namespace Tests\Feature;

use App\Models\BomHeader;
use App\Models\Category;
use App\Models\InventoryBalance;
use App\Models\Location;
use App\Models\PickList;
use App\Models\Process;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\Role;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PickListTest extends TestCase
{
    use RefreshDatabase;

    private string $token;
    private User $admin;
    private Warehouse $wh;
    private Location $a01;
    private Product $mat;
    private Product $fin;
    private ProductionOrder $order;
    private int $materialId; // production_order_materials 行 id

    protected function setUp(): void
    {
        parent::setUp();
        $role = Role::create(['name' => '管理员', 'code' => 'admin']);
        $this->admin = User::create(['name' => '管理员', 'username' => 'admin', 'password' => 'admin123', 'status' => 1]);
        $this->admin->roles()->sync([$role->id]);
        $this->token = $this->admin->createToken('api')->plainTextToken;

        $this->wh = Warehouse::create(['name' => '主仓', 'code' => 'WH01', 'status' => 1]);
        $this->a01 = Location::create(['warehouse_id' => $this->wh->id, 'name' => 'A-01', 'code' => 'A-01', 'status' => 1]);
        $rawCat = Category::create(['name' => '原材料', 'parent_id' => 0]);
        $cat = Category::create(['name' => '成品', 'parent_id' => 0]);
        $unit = Unit::create(['name' => '个', 'code' => 'pc']);
        $this->mat = Product::create(['name' => '测试铝材', 'code' => 'MAT-001', 'type' => 'raw_material', 'category_id' => $rawCat->id, 'unit_id' => $unit->id, 'status' => 1]);
        $this->fin = Product::create(['name' => '成品B', 'code' => 'FIN-002', 'type' => 'finished', 'category_id' => $cat->id, 'unit_id' => $unit->id, 'status' => 1]);
        $bom = BomHeader::create(['code' => 'BOM-001', 'product_id' => $this->fin->id, 'version' => 'v1', 'quantity' => 1, 'status' => 1]);
        $bom->items()->create(['material_id' => $this->mat->id, 'quantity' => 2, 'unit_id' => $unit->id]);
        foreach ([['下料', 'CUT', 1], ['组装', 'ASSY', 2]] as [$name, $code, $sort]) {
            Process::create(['name' => $name, 'code' => $code, 'sort' => $sort, 'status' => 1]);
        }
        // 基线库存：MAT-001 @A-01=50（经统一引擎注入，满足余额=流水恒等式）
        app(InventoryService::class)->apply([
            ['product_id' => $this->mat->id, 'warehouse_id' => $this->wh->id, 'location_id' => $this->a01->id, 'direction' => 1, 'quantity' => 50, 'source_type' => 'purchase_inbound', 'source_id' => 0, 'source_no' => 'SEED', 'remark' => '测试基线'],
        ]);

        // 建单（FIN-002×10 → MAT-001 需求 20）→ 下达 → 开工
        $res = $this->withToken($this->token)->postJson('/api/v1/production/orders', [
            'product_id' => $this->fin->id, 'quantity' => 10, 'plan_date' => now()->toDateString(),
        ]);
        $res->assertJsonPath('code', 0);
        $this->order = ProductionOrder::where('no', $res->json('data.no'))->first();
        $this->withToken($this->token)->postJson("/api/v1/production/orders/{$this->order->id}/release")->assertJsonPath('code', 0);
        $this->withToken($this->token)->postJson("/api/v1/production/orders/{$this->order->id}/start")->assertJsonPath('code', 0);
        $this->materialId = $this->order->materials()->where('material_id', $this->mat->id)->first()->id;
    }

    // 组装领料单载荷（默认 MAT-001×20 全量）
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'order_id' => $this->order->id,
            'warehouse_id' => $this->wh->id,
            'location_id' => $this->a01->id,
            'items' => [
                ['product_id' => $this->mat->id, 'pick_qty' => 20],
            ],
        ], $overrides);
    }

    // 通过 API 建草稿领料单并返回单号
    private function createPick(array $payload): string
    {
        $res = $this->withToken($this->token)->postJson('/api/v1/production/picks', $payload);
        $res->assertJsonPath('code', 0);
        return $res->json('data.no');
    }

    public function test_store_creates_draft_with_no_and_snapshot(): void
    {
        // 正常路径：草稿创建成功，单号 PL{date}-001；明细需求快照 = BOM 展开值
        $no = $this->createPick($this->payload());
        $this->assertMatchesRegularExpression('/^PL\d{8}-001$/', $no);
        $pick = PickList::where('no', $no)->first();
        $this->assertSame(PickList::STATUS_DRAFT, $pick->status);
        $this->assertSame(PickList::ISSUE_NONE, $pick->issue_status);
        $this->assertSame('20.00', $pick->items()->first()->required_qty);
        $this->assertSame('20.00', $pick->items()->first()->pick_qty);
    }

    public function test_from_order_prefill_returns_remaining(): void
    {
        // 正常路径：from-order 预填剩余量（未领 → 剩余=需求）
        $this->withToken($this->token)->getJson("/api/v1/production/picks/from-order/{$this->order->id}")
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.order_no', 'MO'.date('Ymd').'-001')
            ->assertJsonPath('data.items.0.product_code', 'MAT-001')
            ->assertJsonPath('data.items.0.required_qty', '20.00')
            ->assertJsonPath('data.items.0.remaining_qty', '20.00');
    }

    public function test_store_rejects_over_remaining_with_1513(): void
    {
        // 异常路径：领料数量超需求剩余（25 > 20）→ 1513（草稿期即拦截）
        $this->withToken($this->token)->postJson('/api/v1/production/picks', $this->payload(['items' => [
            ['product_id' => $this->mat->id, 'pick_qty' => 25],
        ]]))
            ->assertJsonPath('code', 1513)
            ->assertJsonPath('message', '领料数量超过需求数量');
    }

    public function test_store_rejects_material_not_in_order_with_1513(): void
    {
        // 异常路径：商品不在工单物料需求中 → 1513（需求剩余 0，超量自然拦截）
        $this->withToken($this->token)->postJson('/api/v1/production/picks', $this->payload(['items' => [
            ['product_id' => $this->fin->id, 'pick_qty' => 1],
        ]]))
            ->assertJsonPath('code', 1513);
    }

    public function test_store_rejects_empty_items_and_duplicates_with_422(): void
    {
        // 异常路径：明细为空/重复商品 → 422（格式层；spec 码段满）
        $this->withToken($this->token)->postJson('/api/v1/production/picks', $this->payload(['items' => []]))
            ->assertJsonPath('code', 422);
        $this->withToken($this->token)->postJson('/api/v1/production/picks', $this->payload(['items' => [
            ['product_id' => $this->mat->id, 'pick_qty' => 5],
            ['product_id' => $this->mat->id, 'pick_qty' => 5],
        ]]))
            ->assertJsonPath('code', 422);
    }

    public function test_approve_deducts_inventory_and_writes_movement(): void
    {
        // 核心不变式：审核后余额 50→30、pick 流水双写（direction=-1）、回写物料 issued_qty
        $no = $this->createPick($this->payload());
        $pick = PickList::where('no', $no)->first();
        $this->withToken($this->token)->postJson("/api/v1/production/picks/{$pick->id}/approve")
            ->assertJsonPath('code', 0);
        // 余额 50 → 30
        $balance = InventoryBalance::where('product_id', $this->mat->id)->first();
        $this->assertSame('30.00', $balance->quantity);
        $this->assertDatabaseHas('inventory_movements', [
            'product_id' => $this->mat->id, 'direction' => -1, 'quantity' => '20.00',
            'balance_after' => '30.00', 'source_type' => 'pick', 'source_no' => $no,
        ]);
        // 物料需求 issued_qty 回写 20
        $this->assertSame('20.00', $this->order->materials()->find($this->materialId)->issued_qty);
        // 领料单已审核 + 审核人/时间
        $pick->refresh();
        $this->assertSame(PickList::STATUS_APPROVED, $pick->status);
        $this->assertSame('管理员', $pick->operator);
        $this->assertNotNull($pick->approved_at);
    }

    public function test_approve_rejects_insufficient_balance_with_1515_rollback(): void
    {
        // 核心不变式（超卖拦截）：库存 50 但草稿期后库存被消耗 → 审核期复核拦截 1515，整体回滚
        $no = $this->createPick($this->payload(['items' => [
            ['product_id' => $this->mat->id, 'pick_qty' => 20],
        ]]));
        $pick = PickList::where('no', $no)->first();
        // 草稿创建后库存被消耗至 10（模拟并发消耗）
        $balance = InventoryBalance::where('product_id', $this->mat->id)->first();
        $balance->quantity = 10;
        $balance->save();
        $res = $this->withToken($this->token)->postJson("/api/v1/production/picks/{$pick->id}/approve");
        $res->assertJsonPath('code', 1515)
            ->assertJsonPath('message', '商品[MAT-001]库存不足');
        // 回滚验证：余额不变（10）、无流水、领料单仍草稿、issued_qty 未回写
        $this->assertSame('10.00', InventoryBalance::where('product_id', $this->mat->id)->first()->quantity);
        $this->assertDatabaseMissing('inventory_movements', ['source_no' => $no]);
        $this->assertSame(PickList::STATUS_DRAFT, $pick->refresh()->status);
        $this->assertSame('0.00', $this->order->materials()->find($this->materialId)->issued_qty);
    }

    public function test_approve_idempotent_with_1516(): void
    {
        // 核心不变式：重复审核 → 1516，库存不重复扣减
        $no = $this->createPick($this->payload());
        $pick = PickList::where('no', $no)->first();
        $this->withToken($this->token)->postJson("/api/v1/production/picks/{$pick->id}/approve")->assertJsonPath('code', 0);
        $this->withToken($this->token)->postJson("/api/v1/production/picks/{$pick->id}/approve")
            ->assertJsonPath('code', 1516)
            ->assertJsonPath('message', '该领料单已审核');
        $this->assertSame('30.00', InventoryBalance::where('product_id', $this->mat->id)->first()->quantity);
    }

    public function test_approve_rejects_when_remaining_shrunk(): void
    {
        // 核心不变式：两张草稿各 20 均在需求 20 时创建（草稿期合法）；先审第一张（已领 20 剩余 0）
        // → 审核第二张超量 1513 整体回滚（审核期锁物料需求行复核）
        $no1 = $this->createPick($this->payload());
        $no2 = $this->createPick($this->payload());
        $pick1 = PickList::where('no', $no1)->first();
        $pick2 = PickList::where('no', $no2)->first();
        $this->withToken($this->token)->postJson("/api/v1/production/picks/{$pick1->id}/approve")->assertJsonPath('code', 0);
        $this->withToken($this->token)->postJson("/api/v1/production/picks/{$pick2->id}/approve")
            ->assertJsonPath('code', 1513);
        // 回滚验证：余额仍 30（第二张未出）、第二张仍草稿
        $this->assertSame('30.00', InventoryBalance::where('product_id', $this->mat->id)->first()->quantity);
        $this->assertSame(PickList::STATUS_DRAFT, $pick2->refresh()->status);
    }

    public function test_issue_sets_all_issued_status(): void
    {
        // 正常路径：审核后发料 → issue_status 全部发料（V1 一次发完）；响应含状态文案
        $no = $this->createPick($this->payload());
        $pick = PickList::where('no', $no)->first();
        $this->withToken($this->token)->postJson("/api/v1/production/picks/{$pick->id}/approve")->assertJsonPath('code', 0);
        $res = $this->withToken($this->token)->postJson("/api/v1/production/picks/{$pick->id}/issue");
        $res->assertJsonPath('code', 0)
            ->assertJsonPath('data.issue_status', '全部发料');
        $pick->refresh();
        $this->assertSame(PickList::ISSUE_ALL, $pick->issue_status);
        // 明细行已发回写
        $this->assertSame('20.00', $pick->items()->first()->issued_qty);
    }

    public function test_issue_rejects_draft_with_422(): void
    {
        // 异常路径：未审核不可发料 → 422（格式层）
        $no = $this->createPick($this->payload());
        $pick = PickList::where('no', $no)->first();
        $this->withToken($this->token)->postJson("/api/v1/production/picks/{$pick->id}/issue")
            ->assertJsonPath('code', 422);
    }

    public function test_update_and_destroy_draft_ok_approved_rejected_with_1514(): void
    {
        // 正常+异常路径：草稿可改可删；已审核不可改删 → 1514
        $no = $this->createPick($this->payload());
        $id = PickList::where('no', $no)->first()->id;
        $this->withToken($this->token)->putJson("/api/v1/production/picks/{$id}", $this->payload(['remark' => '改后']))
            ->assertJsonPath('code', 0);
        $this->withToken($this->token)->deleteJson("/api/v1/production/picks/{$id}")
            ->assertJsonPath('code', 0);
        $this->assertDatabaseMissing('pick_lists', ['id' => $id]);

        $no2 = $this->createPick($this->payload());
        $pick2 = PickList::where('no', $no2)->first();
        $this->withToken($this->token)->postJson("/api/v1/production/picks/{$pick2->id}/approve")->assertJsonPath('code', 0);
        $this->withToken($this->token)->putJson("/api/v1/production/picks/{$pick2->id}", $this->payload())
            ->assertJsonPath('code', 1514);
        $this->withToken($this->token)->deleteJson("/api/v1/production/picks/{$pick2->id}")
            ->assertJsonPath('code', 1514);
    }

    public function test_index_with_filters_and_labels(): void
    {
        // 正常路径：列表含工单单号/仓库名/状态标签/发料标签；状态筛选
        $no = $this->createPick($this->payload());
        $pick = PickList::where('no', $no)->first();
        $this->withToken($this->token)->postJson("/api/v1/production/picks/{$pick->id}/approve")->assertJsonPath('code', 0);
        $this->withToken($this->token)->getJson('/api/v1/production/picks')
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.items.0.order_no', 'MO'.date('Ymd').'-001')
            ->assertJsonPath('data.items.0.warehouse_name', '主仓')
            ->assertJsonPath('data.items.0.status_label', '已审核')
            ->assertJsonPath('data.items.0.issue_status_label', '未发料');
        $this->withToken($this->token)->getJson('/api/v1/production/picks?status=0')
            ->assertJsonPath('data.total', 0);
    }

    public function test_picks_requires_pick_permission(): void
    {
        // 异常路径：无 production.pick.list 权限的角色被拒（403 JSON 信封）
        $role = Role::create(['name' => '普通', 'code' => 'plain']);
        $u = User::create(['name' => '普通', 'username' => 'plain', 'password' => 'admin123', 'status' => 1]);
        $u->roles()->sync([$role->id]);
        $token = $u->createToken('api')->plainTextToken;
        $this->withToken($token)->getJson('/api/v1/production/picks')->assertStatus(403);
    }
}
```

- [ ] **Step 2: 跑测试确认失败**

Run: `cd server && php artisan test --filter=PickListTest`
Expected: FAIL（控制器/路由不存在）。

- [ ] **Step 3: 实现 PickListController**

创建 `server/app/Http/Controllers/Api/PickListController.php`：

```php
<?php

// 领料单控制器：草稿 CRUD + from-order 预填 + 审核（核心：事务内锁物料需求行防超领 1513 + 锁余额行防超卖 1515）+ 发料

namespace App\Http\Controllers\Api;

use App\Exceptions\InventoryException;
use App\Exceptions\ProductionException;
use App\Http\Controllers\Controller;
use App\Models\DocumentSequence;
use App\Models\InventoryBalance;
use App\Models\PickList;
use App\Models\PickListItem;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\ProductionOrderMaterial;
use App\Services\DocumentSequenceService;
use App\Services\InventoryService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PickListController extends Controller
{
    use ApiResponse;

    public function __construct(
        private InventoryService $inventoryService,
        private DocumentSequenceService $sequenceService,
    ) {}

    /** 分页列表：单号/仓库/状态/日期范围 筛选；含工单单号/仓库名/状态与发料标签 */
    public function index(Request $request)
    {
        $query = PickList::query()
            ->join('production_orders', 'production_orders.id', '=', 'pick_lists.order_id')
            ->join('warehouses', 'warehouses.id', '=', 'pick_lists.warehouse_id')
            ->select(
                'pick_lists.*',
                'production_orders.no as order_no',
                'warehouses.name as warehouse_name',
            )
            ->orderByDesc('pick_lists.id');

        if ($keyword = $request->input('keyword')) {
            $query->where('pick_lists.no', 'like', "%{$keyword}%");
        }
        if ($request->filled('status')) {
            $query->where('pick_lists.status', (int) $request->input('status'));
        }
        if ($request->filled('date_from')) {
            $query->whereDate('pick_lists.created_at', '>=', $request->input('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('pick_lists.created_at', '<=', $request->input('date_to'));
        }

        $rows = $query->paginate(max(1, min(100, (int) $request->input('per_page', 10))));

        return $this->ok([
            // join 别名列经 getAttribute 读取（PHPStan 静态分析可识别）
            'items' => $rows->map(fn (PickList $p) => [
                'id' => $p->id,
                'no' => $p->no,
                'order_id' => $p->order_id,
                'order_no' => $p->getAttribute('order_no'),
                'warehouse_id' => $p->warehouse_id,
                'warehouse_name' => $p->getAttribute('warehouse_name'),
                'status' => (int) $p->status,
                'status_label' => PickList::STATUS_LABELS[$p->status] ?? '未知',
                'issue_status' => (int) $p->issue_status,
                'issue_status_label' => PickList::ISSUE_LABELS[$p->issue_status] ?? '未知',
                'approved_at' => $p->approved_at?->toDateTimeString(),
                'operator' => $p->operator,
                'created_at' => $p->created_at?->toDateTimeString(),
            ]),
            'total' => $rows->total(), 'page' => $rows->currentPage(), 'per_page' => $rows->perPage(),
        ]);
    }

    /** 「从工单生成」预填：工单头 + 物料需求（剩余量 = 需求 - 已领） */
    public function fromOrder(int $orderId)
    {
        $order = ProductionOrder::with('product')->find($orderId);
        if (! $order) {
            return $this->fail(422, '工单不存在');
        }
        $items = $order->materials()->with('material')->orderBy('id')->get()
            ->map(fn (ProductionOrderMaterial $m) => [
                'product_id' => $m->material_id,
                'material_name' => $m->material?->name,
                'material_code' => $m->material?->code,
                'required_qty' => $m->required_qty,
                'issued_qty' => $m->issued_qty,
                // 剩余量 = 需求 - 已领（bcmath 精确）
                'remaining_qty' => bcsub((string) $m->required_qty, (string) $m->issued_qty, 2),
            ]);

        return $this->ok([
            'order_id' => $order->id,
            'order_no' => $order->no,
            'product_id' => $order->product_id,
            'product_name' => $order->product?->name,
            'items' => $items,
        ]);
    }

    /** 新建草稿：明细非空/重复商品/数量>0 走 422；超需求剩余 1513（草稿期即拦截） */
    public function store(Request $request)
    {
        $data = $this->validatePayload($request);
        // 明细业务校验（422 格式层：空明细/重复商品/数量≤0/仓库库位缺失）
        if ($fail = $this->validateBusinessItems($data)) {
            return $fail;
        }
        if (! $request->filled('warehouse_id') || ! $request->filled('location_id')) {
            return $this->fail(422, '仓库与库位不能为空');
        }
        // 草稿期校验：逐行 ≤ 需求剩余（1513）
        if ($msg = $this->validateRemaining((int) $data['order_id'], $data['items'])) {
            return $this->fail(1513, $msg);
        }

        $pick = DB::transaction(function () use ($data) {
            $pick = $this->sequenceService->nextNo(
                DocumentSequence::TYPE_PL,
                'PL',
                fn (string $no) => PickList::create([
                    'no' => $no,
                    'order_id' => $data['order_id'],
                    'status' => PickList::STATUS_DRAFT,
                    'issue_status' => PickList::ISSUE_NONE,
                    'warehouse_id' => $data['warehouse_id'],
                    'location_id' => $data['location_id'],
                    'remark' => $data['remark'] ?? null,
                ]),
                fn () => (int) (PickList::where('no', 'like', 'PL'.date('Ymd').'-%')
                    ->get('no')->map(fn ($p) => (int) substr((string) $p->no, -3))->max() ?? 0),
            );
            // 明细行：需求快照 + 本次领用量
            $pick->items()->createMany(array_map(fn ($i) => [
                'product_id' => $i['product_id'],
                'required_qty' => $this->requiredQty((int) $data['order_id'], (int) $i['product_id']),
                'pick_qty' => $i['pick_qty'],
                'issued_qty' => 0,
            ], $data['items']));

            return $pick;
        });

        return $this->ok(['no' => $pick->no]);
    }

    /** 详情：头信息 + 明细（商品名/需求/本次领用/已发） */
    public function show(PickList $pick)
    {
        return $this->ok([
            'id' => $pick->id,
            'no' => $pick->no,
            'order_id' => $pick->order_id,
            'order_no' => $pick->order?->no,
            'status' => (int) $pick->status,
            'status_label' => PickList::STATUS_LABELS[$pick->status] ?? '未知',
            'issue_status' => (int) $pick->issue_status,
            'issue_status_label' => PickList::ISSUE_LABELS[$pick->issue_status] ?? '未知',
            'warehouse_id' => $pick->warehouse_id,
            'warehouse_name' => $pick->warehouse?->name,
            'location_id' => $pick->location_id,
            'location_name' => $pick->location?->name,
            'approved_at' => $pick->approved_at?->toDateTimeString(),
            'operator' => $pick->operator,
            'remark' => $pick->remark,
            'items' => $pick->items()->with('product')->get()->map(fn (PickListItem $i) => [
                'id' => $i->id,
                'product_id' => $i->product_id,
                'product_name' => $i->product?->name,
                'product_code' => $i->product?->code,
                'required_qty' => $i->required_qty,
                'pick_qty' => $i->pick_qty,
                'issued_qty' => $i->issued_qty,
            ]),
        ]);
    }

    /** 更新草稿：仅草稿（1514）；校验同 store；事务内锁行复查防并发 */
    public function update(Request $request, PickList $pick)
    {
        try {
            if ($pick->status !== PickList::STATUS_DRAFT) {
                return $this->fail(1514, '已审核单据不可修改');
            }
            $data = $this->validatePayload($request);
            if ($fail = $this->validateBusinessItems($data)) {
                return $fail;
            }
            if (! $request->filled('warehouse_id') || ! $request->filled('location_id')) {
                return $this->fail(422, '仓库与库位不能为空');
            }
            if ($msg = $this->validateRemaining((int) $data['order_id'], $data['items'])) {
                return $this->fail(1513, $msg);
            }

            DB::transaction(function () use ($pick, $data) {
                // 锁领料单行复查状态：与审核并发时防止改到正在审核的单（幂等 1514）
                $locked = PickList::whereKey($pick->id)->lockForUpdate()->firstOrFail();
                if ($locked->status !== PickList::STATUS_DRAFT) {
                    throw new ProductionException('已审核单据不可修改', 1514);
                }
                $locked->update([
                    'order_id' => $data['order_id'],
                    'warehouse_id' => $data['warehouse_id'],
                    'location_id' => $data['location_id'],
                    'remark' => $data['remark'] ?? $locked->remark,
                ]);
                // 明细全量替换（草稿单无流水引用，直接重建）
                $locked->items()->delete();
                $locked->items()->createMany(array_map(fn ($i) => [
                    'product_id' => $i['product_id'],
                    'required_qty' => $this->requiredQty((int) $data['order_id'], (int) $i['product_id']),
                    'pick_qty' => $i['pick_qty'],
                    'issued_qty' => 0,
                ], $data['items']));
            });
        } catch (ProductionException $e) {
            // 1514 已审核（锁行复查与并发审核幂等拦截）
            return $this->fail($e->getCode() ?: 1514, $e->getMessage());
        }

        return $this->ok();
    }

    /** 删除草稿：仅草稿（1514）；事务内锁行复查防并发 */
    public function destroy(PickList $pick)
    {
        try {
            if ($pick->status !== PickList::STATUS_DRAFT) {
                return $this->fail(1514, '已审核单据不可删除');
            }
            DB::transaction(function () use ($pick) {
                // 锁领料单行复查状态（幂等 1514）
                $locked = PickList::whereKey($pick->id)->lockForUpdate()->firstOrFail();
                if ($locked->status !== PickList::STATUS_DRAFT) {
                    throw new ProductionException('已审核单据不可删除', 1514);
                }
                $locked->delete();
            });
        } catch (ProductionException $e) {
            // 1514 已审核（锁行复查与并发审核幂等拦截）
            return $this->fail($e->getCode() ?: 1514, $e->getMessage());
        }

        return $this->ok();
    }

    /**
     * 审核（核心）：事务内「锁单幂等 1516 → 逐行锁物料需求行复核 1513 → 逐行锁余额行校验充足 1515
     * → InventoryService 扣库存（pick, -1）→ 回写 issued_qty」任一步失败整体回滚
     */
    public function approve(PickList $pick)
    {
        try {
            $result = null;
            DB::transaction(function () use ($pick, &$result) {
                // 锁领料单行：同一单据重复审核在此判重（幂等 1516）
                $locked = PickList::whereKey($pick->id)->lockForUpdate()->firstOrFail();
                if ($locked->status === PickList::STATUS_APPROVED) {
                    throw new ProductionException('该领料单已审核', 1516);
                }
                $movements = [];
                $issueMap = []; // [material_id => 本次领用累计] 待回写
                /** @var PickListItem $item */
                foreach ($locked->items as $item) {
                    // 锁物料需求行：防并发超领（两张领料单同时审同一物料时串行化）
                    $pm = ProductionOrderMaterial::where('order_id', $locked->order_id)
                        ->where('material_id', $item->product_id)
                        ->lockForUpdate()
                        ->first();
                    if (! $pm) {
                        throw new ProductionException('领料数量超过需求数量', 1513);
                    }
                    // 剩余 = 需求 - 已领；本次超剩余 → 1513 整体回滚（防超领）
                    $remaining = bcsub((string) $pm->required_qty, (string) $pm->issued_qty, 2);
                    if (bccomp((string) $item->pick_qty, $remaining, 2) > 0) {
                        throw new ProductionException('领料数量超过需求数量', 1513);
                    }
                    $issueMap[$item->product_id] = bcadd((string) ($issueMap[$item->product_id] ?? '0'), (string) $item->pick_qty, 2);
                    // 防超卖：锁余额行校验（并发审核同一商品在此串行化；消息含商品编码与精确库存快照）
                    $balance = InventoryBalance::where('product_id', $item->product_id)
                        ->where('warehouse_id', $locked->warehouse_id)
                        ->where('location_id', $locked->location_id)
                        ->lockForUpdate()
                        ->first();
                    $current = $balance ? (string) $balance->quantity : '0';
                    if (bccomp((string) $item->pick_qty, $current, 2) > 0) {
                        // 库存快照去掉小数尾零展示（14.00 → 14；0.00 → 0），消息用商品编码（E2E 断言 MAT-001）
                        $qtyText = rtrim(rtrim($current, '0'), '.');
                        $code = Product::find($item->product_id)?->code ?? ('#'.$item->product_id);
                        throw new ProductionException("商品[{$code}]库存不足", 1515);
                    }
                    $movements[] = [
                        'product_id' => $item->product_id,
                        'warehouse_id' => $locked->warehouse_id,
                        'location_id' => $locked->location_id,
                        'direction' => -1,
                        'quantity' => $item->pick_qty,
                        'source_type' => 'pick',
                        'source_id' => $locked->id,
                        'source_no' => $locked->no,
                        'remark' => '生产领料',
                    ];
                }
                // 统一引擎写流水+扣余额（同事务双写；余额行已被本事务锁定，引擎内重复加锁幂等）
                $this->inventoryService->apply($movements, auth()->id());
                // 回写工单物料需求 issued_qty（bcmath 累加）
                foreach ($issueMap as $materialId => $qty) {
                    $pm = ProductionOrderMaterial::where('order_id', $locked->order_id)
                        ->where('material_id', $materialId)->firstOrFail();
                    $pm->issued_qty = bcadd((string) $pm->issued_qty, $qty, 2);
                    $pm->save();
                }
                // 置已审核 + 审核人/时间
                $locked->status = PickList::STATUS_APPROVED;
                $locked->operator = auth()->user()->name ?? '';
                $locked->approved_at = now();
                $locked->save();
                $result = ['no' => $locked->no];
            });
        } catch (ProductionException $e) {
            // 1516 幂等 / 1513 超需求剩余 / 1515 库存不足（事务整体回滚）
            return $this->fail($e->getCode() ?: 1513, $e->getMessage());
        } catch (InventoryException $e) {
            // 余额引擎兜底拒绝（理论上被预校验拦截，防御路径）
            return $this->fail(1515, '库存不足，领料被拒绝');
        }

        return $this->ok($result);
    }

    /** 发料：仅已审核可发（422）；V1 一次发完——issue_status 置「全部发料」，明细行 issued_qty 回写 */
    public function issue(PickList $pick)
    {
        if ($pick->status !== PickList::STATUS_APPROVED) {
            return $this->fail(422, '请先审核领料单');
        }
        // 防重复发料：已全部发料直接返回当前状态（幂等）
        if ($pick->issue_status === PickList::ISSUE_ALL) {
            return $this->ok(['issue_status' => PickList::ISSUE_LABELS[$pick->issue_status]]);
        }
        DB::transaction(function () use ($pick) {
            // 锁领料单行复查状态（并发审核/发料串行化）
            $locked = PickList::whereKey($pick->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== PickList::STATUS_APPROVED) {
                throw new ProductionException('请先审核领料单', 422);
            }
            $locked->issue_status = PickList::ISSUE_ALL;
            $locked->save();
            // 明细行已发量 = 本次领用（一次发完语义）
            foreach ($locked->items as $item) {
                $item->issued_qty = $item->pick_qty;
                $item->save();
            }
        });

        return $this->ok(['issue_status' => PickList::ISSUE_LABELS[PickList::ISSUE_ALL]]);
    }

    // 载荷格式校验（422 仅格式层）；业务码在方法内检查
    private function validatePayload(Request $request): array
    {
        return $request->validate([
            'order_id' => 'required|integer|exists:production_orders,id',
            'warehouse_id' => 'nullable|integer|exists:warehouses,id',
            'location_id' => 'nullable|integer|exists:locations,id',
            'remark' => 'nullable|string|max:200',
            'items' => 'array',
            'items.*.product_id' => 'required|integer|exists:products,id',
            // 数量限两位小数（正则防科学计数法；负值形态放行到方法内 422）
            'items.*.pick_qty' => 'required|numeric|regex:/^-?\d+(\.\d{1,2})?$/',
        ]);
    }

    // 明细业务校验（store/update 共用）：空明细/数量≤0/重复商品 → 422（格式层；spec 码段满）
    private function validateBusinessItems(array $data): ?JsonResponse
    {
        $items = $data['items'] ?? [];
        if (empty($items)) {
            return $this->fail(422, '请至少添加一条明细');
        }
        $seen = [];
        foreach ($items as $item) {
            if ((float) $item['pick_qty'] <= 0) {
                return $this->fail(422, '领料数量必须大于 0');
            }
            if (isset($seen[$item['product_id']])) {
                return $this->fail(422, '明细存在重复商品');
            }
            $seen[$item['product_id']] = true;
        }

        return null;
    }

    // 草稿期剩余量校验：逐行 ≤ 需求剩余（1513），返回错误文案或 null
    private function validateRemaining(int $orderId, array $items): ?string
    {
        foreach ($items as $item) {
            $pm = ProductionOrderMaterial::where('order_id', $orderId)
                ->where('material_id', $item['product_id'])->first();
            if (! $pm) {
                return '领料数量超过需求数量';
            }
            $remaining = bcsub((string) $pm->required_qty, (string) $pm->issued_qty, 2);
            if (bccomp((string) $item['pick_qty'], $remaining, 2) > 0) {
                return '领料数量超过需求数量';
            }
        }

        return null;
    }

    // 物料需求数量（明细行快照：生成时点工单物料需求）
    private function requiredQty(int $orderId, int $productId): string
    {
        $pm = ProductionOrderMaterial::where('order_id', $orderId)->where('material_id', $productId)->first();

        return $pm ? (string) $pm->required_qty : '0';
    }
}
```

- [ ] **Step 4: 注册路由**

修改 `server/routes/api.php`：顶部 use 区追加 `use App\Http\Controllers\Api\PickListController;`，并在工序报工路由组之后追加：

```php
    // 领料单：CRUD + from-order 预填 + 审核 + 发料（production.pick.*；审核/发料复用 update）
    Route::middleware('auth:sanctum')->group(function () {
        // 注意：from-order 必须先于 {pick} 注册，避免 orderId 被解析为领料单 ID
        Route::middleware('permission:production.pick.list')->get('/production/picks/from-order/{orderId}', [PickListController::class, 'fromOrder']);
        Route::middleware('permission:production.pick.list')->get('/production/picks', [PickListController::class, 'index']);
        Route::middleware('permission:production.pick.create')->post('/production/picks', [PickListController::class, 'store']);
        Route::middleware('permission:production.pick.list')->get('/production/picks/{pick}', [PickListController::class, 'show']);
        Route::middleware('permission:production.pick.update')->put('/production/picks/{pick}', [PickListController::class, 'update']);
        Route::middleware('permission:production.pick.delete')->delete('/production/picks/{pick}', [PickListController::class, 'destroy']);
        Route::middleware('permission:production.pick.update')->post('/production/picks/{pick}/approve', [PickListController::class, 'approve']);
        Route::middleware('permission:production.pick.update')->post('/production/picks/{pick}/issue', [PickListController::class, 'issue']);
    });
```

- [ ] **Step 5: 跑测试确认通过**

Run: `cd server && php artisan test --filter="PickListTest|ProductionOrderTest"`
Expected: PickListTest 14 全部 PASS（核心不变式：扣库存双写、1515 回滚、并发防超领、幂等 1516、发料状态）；ProductionOrderTest 25 回归绿。

- [ ] **Step 6: 提交**

```bash
cd /d/code/project/php-design && git add server/app/Http/Controllers/Api/PickListController.php server/tests/Feature/PickListTest.php server/routes/api.php
git commit -m "feat: 领料单 API（审核防超领防超卖 1513/1515、发料状态、幂等 1516）"
```

---

## Task 7: 退料单 API（CRUD + 审核冲销）

**Files:**
- Create: `server/app/Http/Controllers/Api/ReturnListController.php`
- Create: `server/tests/Feature/ReturnListTest.php`
- Modify: `server/routes/api.php`

**Interfaces:**
- Consumes: Task 1/2 模型（ReturnList/ReturnListItem/ProductionOrder/ProductionOrderMaterial）；Task 2 DocumentSequenceService（type=rl）；`InventoryService::apply`（direction=+1，source_type=return）；`InventoryException`（防御兜底）
- Produces: `GET/POST /api/v1/production/returns`、`GET/PUT/DELETE /api/v1/production/returns/{return}`、`POST .../approve`（核心：事务内锁物料需求行防超退 1517 → InventoryService 写 return 流水(+1) → 冲销 issued_qty）；错误码 1517-1519；仓库/库位缺失、明细为空、重复商品 → 422

- [ ] **Step 1: 写失败测试 `server/tests/Feature/ReturnListTest.php`**

```php
<?php

// 退料单接口测试：CRUD/审核冲销（库存+/已领-）/超退拦截/幂等（核心路径 100%）

namespace Tests\Feature;

use App\Models\BomHeader;
use App\Models\Category;
use App\Models\InventoryBalance;
use App\Models\Location;
use App\Models\Process;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\ReturnList;
use App\Models\Role;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReturnListTest extends TestCase
{
    use RefreshDatabase;

    private string $token;
    private User $admin;
    private Warehouse $wh;
    private Location $a01;
    private Product $mat;
    private Product $fin;
    private ProductionOrder $order;
    private int $materialId;

    protected function setUp(): void
    {
        parent::setUp();
        $role = Role::create(['name' => '管理员', 'code' => 'admin']);
        $this->admin = User::create(['name' => '管理员', 'username' => 'admin', 'password' => 'admin123', 'status' => 1]);
        $this->admin->roles()->sync([$role->id]);
        $this->token = $this->admin->createToken('api')->plainTextToken;

        $this->wh = Warehouse::create(['name' => '主仓', 'code' => 'WH01', 'status' => 1]);
        $this->a01 = Location::create(['warehouse_id' => $this->wh->id, 'name' => 'A-01', 'code' => 'A-01', 'status' => 1]);
        $rawCat = Category::create(['name' => '原材料', 'parent_id' => 0]);
        $cat = Category::create(['name' => '成品', 'parent_id' => 0]);
        $unit = Unit::create(['name' => '个', 'code' => 'pc']);
        $this->mat = Product::create(['name' => '测试铝材', 'code' => 'MAT-001', 'type' => 'raw_material', 'category_id' => $rawCat->id, 'unit_id' => $unit->id, 'status' => 1]);
        $this->fin = Product::create(['name' => '成品B', 'code' => 'FIN-002', 'type' => 'finished', 'category_id' => $cat->id, 'unit_id' => $unit->id, 'status' => 1]);
        $bom = BomHeader::create(['code' => 'BOM-001', 'product_id' => $this->fin->id, 'version' => 'v1', 'quantity' => 1, 'status' => 1]);
        $bom->items()->create(['material_id' => $this->mat->id, 'quantity' => 2, 'unit_id' => $unit->id]);
        foreach ([['下料', 'CUT', 1], ['组装', 'ASSY', 2]] as [$name, $code, $sort]) {
            Process::create(['name' => $name, 'code' => $code, 'sort' => $sort, 'status' => 1]);
        }
        // 基线库存 MAT-001 @A-01=30（领料 20 后余 10，退料 2 后 12）
        app(InventoryService::class)->apply([
            ['product_id' => $this->mat->id, 'warehouse_id' => $this->wh->id, 'location_id' => $this->a01->id, 'direction' => 1, 'quantity' => 30, 'source_type' => 'purchase_inbound', 'source_id' => 0, 'source_no' => 'SEED', 'remark' => '测试基线'],
        ]);

        // 建单（FIN-002×10 → MAT-001 需求 20）→ 下达 → 开工 → 领料审核（已领 20）
        $res = $this->withToken($this->token)->postJson('/api/v1/production/orders', [
            'product_id' => $this->fin->id, 'quantity' => 10, 'plan_date' => now()->toDateString(),
        ]);
        $res->assertJsonPath('code', 0);
        $this->order = ProductionOrder::where('no', $res->json('data.no'))->first();
        $this->withToken($this->token)->postJson("/api/v1/production/orders/{$this->order->id}/release")->assertJsonPath('code', 0);
        $this->withToken($this->token)->postJson("/api/v1/production/orders/{$this->order->id}/start")->assertJsonPath('code', 0);
        $pickRes = $this->withToken($this->token)->postJson('/api/v1/production/picks', [
            'order_id' => $this->order->id, 'warehouse_id' => $this->wh->id, 'location_id' => $this->a01->id,
            'items' => [['product_id' => $this->mat->id, 'pick_qty' => 20]],
        ]);
        $pickRes->assertJsonPath('code', 0);
        $pick = \App\Models\PickList::where('no', $pickRes->json('data.no'))->first();
        $this->withToken($this->token)->postJson("/api/v1/production/picks/{$pick->id}/approve")->assertJsonPath('code', 0);
        $this->materialId = $this->order->materials()->where('material_id', $this->mat->id)->first()->id;
        // 已领 20、库存 30-20=10
    }

    // 组装退料单载荷（默认 MAT-001×2）
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'order_id' => $this->order->id,
            'warehouse_id' => $this->wh->id,
            'location_id' => $this->a01->id,
            'items' => [
                ['product_id' => $this->mat->id, 'quantity' => 2],
            ],
        ], $overrides);
    }

    // 通过 API 建草稿退料单并返回单号
    private function createReturn(array $payload): string
    {
        $res = $this->withToken($this->token)->postJson('/api/v1/production/returns', $payload);
        $res->assertJsonPath('code', 0);
        return $res->json('data.no');
    }

    public function test_store_creates_draft_with_no(): void
    {
        // 正常路径：草稿创建成功，单号 RL{date}-001
        $no = $this->createReturn($this->payload());
        $this->assertMatchesRegularExpression('/^RL\d{8}-001$/', $no);
        $return = ReturnList::where('no', $no)->first();
        $this->assertSame(ReturnList::STATUS_DRAFT, $return->status);
        $this->assertSame('2.00', $return->items()->first()->quantity);
    }

    public function test_store_rejects_over_issued_with_1517(): void
    {
        // 异常路径：退料数量超已领总量（25 > 20）→ 1517（草稿期即拦截）
        $this->withToken($this->token)->postJson('/api/v1/production/returns', $this->payload(['items' => [
            ['product_id' => $this->mat->id, 'quantity' => 25],
        ]]))
            ->assertJsonPath('code', 1517)
            ->assertJsonPath('message', '退料数量超过已领数量');
    }

    public function test_store_rejects_material_not_issued_with_1517(): void
    {
        // 异常路径：商品从未领过（已领 0）→ 1517（超已领自然拦截）
        $this->withToken($this->token)->postJson('/api/v1/production/returns', $this->payload(['items' => [
            ['product_id' => $this->fin->id, 'quantity' => 1],
        ]]))
            ->assertJsonPath('code', 1517);
    }

    public function test_approve_credits_inventory_and_writes_movement(): void
    {
        // 核心不变式：审核后余额 10→12、return 流水双写（direction=+1）、物料 issued_qty 20→18（冲销）
        $no = $this->createReturn($this->payload());
        $return = ReturnList::where('no', $no)->first();
        $this->withToken($this->token)->postJson("/api/v1/production/returns/{$return->id}/approve")
            ->assertJsonPath('code', 0);
        $balance = InventoryBalance::where('product_id', $this->mat->id)->first();
        $this->assertSame('12.00', $balance->quantity);
        $this->assertDatabaseHas('inventory_movements', [
            'product_id' => $this->mat->id, 'direction' => 1, 'quantity' => '2.00',
            'balance_after' => '12.00', 'source_type' => 'return', 'source_no' => $no,
        ]);
        // 已领冲销 20 → 18
        $this->assertSame('18.00', $this->order->materials()->find($this->materialId)->issued_qty);
        $return->refresh();
        $this->assertSame(ReturnList::STATUS_APPROVED, $return->status);
        $this->assertSame('管理员', $return->operator);
        $this->assertNotNull($return->approved_at);
    }

    public function test_approve_rejects_when_issued_shrunk_with_1517_rollback(): void
    {
        // 核心不变式：草稿建后已领被冲销（20→4），审核期锁行复核 1517 整体回滚
        $no = $this->createReturn($this->payload(['items' => [
            ['product_id' => $this->mat->id, 'quantity' => 5],
        ]]));
        $return = ReturnList::where('no', $no)->first();
        // 模拟并发冲销：另一张退料单已审 16（已领 20→4）
        $pm = $this->order->materials()->find($this->materialId);
        $pm->issued_qty = 4;
        $pm->save();
        $res = $this->withToken($this->token)->postJson("/api/v1/production/returns/{$return->id}/approve");
        $res->assertJsonPath('code', 1517)
            ->assertJsonPath('message', '退料数量超过已领数量');
        // 回滚验证：余额不变（10）、无流水、退料单仍草稿
        $this->assertSame('10.00', InventoryBalance::where('product_id', $this->mat->id)->first()->quantity);
        $this->assertDatabaseMissing('inventory_movements', ['source_no' => $no]);
        $this->assertSame(ReturnList::STATUS_DRAFT, $return->refresh()->status);
    }

    public function test_approve_idempotent_with_1519(): void
    {
        // 核心不变式：重复审核 → 1519，库存不重复变动
        $no = $this->createReturn($this->payload());
        $return = ReturnList::where('no', $no)->first();
        $this->withToken($this->token)->postJson("/api/v1/production/returns/{$return->id}/approve")->assertJsonPath('code', 0);
        $this->withToken($this->token)->postJson("/api/v1/production/returns/{$return->id}/approve")
            ->assertJsonPath('code', 1519)
            ->assertJsonPath('message', '该退料单已审核');
        $this->assertSame('12.00', InventoryBalance::where('product_id', $this->mat->id)->first()->quantity);
    }

    public function test_update_and_destroy_draft_ok_approved_rejected_with_1518(): void
    {
        // 正常+异常路径：草稿可改可删；已审核不可改删 → 1518
        $no = $this->createReturn($this->payload());
        $id = ReturnList::where('no', $no)->first()->id;
        $this->withToken($this->token)->putJson("/api/v1/production/returns/{$id}", $this->payload(['remark' => '改后']))
            ->assertJsonPath('code', 0);
        $this->withToken($this->token)->deleteJson("/api/v1/production/returns/{$id}")
            ->assertJsonPath('code', 0);
        $this->assertDatabaseMissing('return_lists', ['id' => $id]);

        $no2 = $this->createReturn($this->payload());
        $return2 = ReturnList::where('no', $no2)->first();
        $this->withToken($this->token)->postJson("/api/v1/production/returns/{$return2->id}/approve")->assertJsonPath('code', 0);
        $this->withToken($this->token)->putJson("/api/v1/production/returns/{$return2->id}", $this->payload())
            ->assertJsonPath('code', 1518);
        $this->withToken($this->token)->deleteJson("/api/v1/production/returns/{$return2->id}")
            ->assertJsonPath('code', 1518);
    }

    public function test_index_with_labels_and_returns_requires_permission(): void
    {
        // 正常路径：列表含工单单号/状态标签
        $no = $this->createReturn($this->payload());
        $return = ReturnList::where('no', $no)->first();
        $this->withToken($this->token)->postJson("/api/v1/production/returns/{$return->id}/approve")->assertJsonPath('code', 0);
        $this->withToken($this->token)->getJson('/api/v1/production/returns')
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.items.0.order_no', 'MO'.date('Ymd').'-001')
            ->assertJsonPath('data.items.0.status_label', '已审核');
        // 异常路径：无 production.return.list 权限的角色被拒（403 JSON 信封）
        $role = Role::create(['name' => '普通', 'code' => 'plain']);
        $u = User::create(['name' => '普通', 'username' => 'plain', 'password' => 'admin123', 'status' => 1]);
        $u->roles()->sync([$role->id]);
        $token = $u->createToken('api')->plainTextToken;
        $this->withToken($token)->getJson('/api/v1/production/returns')->assertStatus(403);
    }
}
```

- [ ] **Step 2: 跑测试确认失败**

Run: `cd server && php artisan test --filter=ReturnListTest`
Expected: FAIL（控制器/路由不存在）。

- [ ] **Step 3: 实现 ReturnListController**

创建 `server/app/Http/Controllers/Api/ReturnListController.php`：

```php
<?php

// 退料单控制器：草稿 CRUD + 审核（核心：事务内锁物料需求行防超退 1517 + InventoryService 写 return 流水(+1) 冲销已领）

namespace App\Http\Controllers\Api;

use App\Exceptions\InventoryException;
use App\Exceptions\ProductionException;
use App\Http\Controllers\Controller;
use App\Models\DocumentSequence;
use App\Models\ProductionOrderMaterial;
use App\Models\ReturnList;
use App\Models\ReturnListItem;
use App\Services\DocumentSequenceService;
use App\Services\InventoryService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReturnListController extends Controller
{
    use ApiResponse;

    public function __construct(
        private InventoryService $inventoryService,
        private DocumentSequenceService $sequenceService,
    ) {}

    /** 分页列表：单号/仓库/状态/日期范围 筛选；含工单单号与状态标签 */
    public function index(Request $request)
    {
        $query = ReturnList::query()
            ->join('production_orders', 'production_orders.id', '=', 'return_lists.order_id')
            ->select('return_lists.*', 'production_orders.no as order_no')
            ->orderByDesc('return_lists.id');

        if ($keyword = $request->input('keyword')) {
            $query->where('return_lists.no', 'like', "%{$keyword}%");
        }
        if ($request->filled('status')) {
            $query->where('return_lists.status', (int) $request->input('status'));
        }
        if ($request->filled('date_from')) {
            $query->whereDate('return_lists.created_at', '>=', $request->input('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('return_lists.created_at', '<=', $request->input('date_to'));
        }

        $rows = $query->paginate(max(1, min(100, (int) $request->input('per_page', 10))));

        return $this->ok([
            // join 别名列经 getAttribute 读取（PHPStan 静态分析可识别）
            'items' => $rows->map(fn (ReturnList $r) => [
                'id' => $r->id,
                'no' => $r->no,
                'order_id' => $r->order_id,
                'order_no' => $r->getAttribute('order_no'),
                'warehouse_id' => $r->warehouse_id,
                'warehouse_name' => $r->warehouse?->name,
                'location_id' => $r->location_id,
                'location_name' => $r->location?->name,
                'status' => (int) $r->status,
                'status_label' => ReturnList::STATUS_LABELS[$r->status] ?? '未知',
                'approved_at' => $r->approved_at?->toDateTimeString(),
                'operator' => $r->operator,
                'created_at' => $r->created_at?->toDateTimeString(),
            ]),
            'total' => $rows->total(), 'page' => $rows->currentPage(), 'per_page' => $rows->perPage(),
        ]);
    }

    /** 新建草稿：明细非空/重复商品/数量>0/仓库库位 422；超已领总量 1517（草稿期即拦截） */
    public function store(Request $request)
    {
        $data = $this->validatePayload($request);
        if ($fail = $this->validateBusinessItems($data)) {
            return $fail;
        }
        if (! $request->filled('warehouse_id') || ! $request->filled('location_id')) {
            return $this->fail(422, '仓库与库位不能为空');
        }
        // 草稿期校验：逐行 ≤ 该商品已领总量（1517）
        if ($msg = $this->validateIssued((int) $data['order_id'], $data['items'])) {
            return $this->fail(1517, $msg);
        }

        $return = DB::transaction(function () use ($data) {
            $return = $this->sequenceService->nextNo(
                DocumentSequence::TYPE_RL,
                'RL',
                fn (string $no) => ReturnList::create([
                    'no' => $no,
                    'order_id' => $data['order_id'],
                    'pick_id' => $data['pick_id'] ?? null,
                    'status' => ReturnList::STATUS_DRAFT,
                    'warehouse_id' => $data['warehouse_id'],
                    'location_id' => $data['location_id'],
                    'remark' => $data['remark'] ?? null,
                ]),
                fn () => (int) (ReturnList::where('no', 'like', 'RL'.date('Ymd').'-%')
                    ->get('no')->map(fn ($r) => (int) substr((string) $r->no, -3))->max() ?? 0),
            );
            $return->items()->createMany(array_map(fn ($i) => [
                'product_id' => $i['product_id'],
                'quantity' => $i['quantity'],
            ], $data['items']));

            return $return;
        });

        return $this->ok(['no' => $return->no]);
    }

    /** 详情：头信息 + 明细（商品名/数量） */
    public function show(ReturnList $return)
    {
        return $this->ok([
            'id' => $return->id,
            'no' => $return->no,
            'order_id' => $return->order_id,
            'order_no' => $return->order?->no,
            'pick_id' => $return->pick_id,
            'pick_no' => $return->pick?->no,
            'status' => (int) $return->status,
            'status_label' => ReturnList::STATUS_LABELS[$return->status] ?? '未知',
            'warehouse_id' => $return->warehouse_id,
            'warehouse_name' => $return->warehouse?->name,
            'location_id' => $return->location_id,
            'location_name' => $return->location?->name,
            'approved_at' => $return->approved_at?->toDateTimeString(),
            'operator' => $return->operator,
            'remark' => $return->remark,
            'items' => $return->items()->with('product')->get()->map(fn (ReturnListItem $i) => [
                'id' => $i->id,
                'product_id' => $i->product_id,
                'product_name' => $i->product?->name,
                'product_code' => $i->product?->code,
                'quantity' => $i->quantity,
            ]),
        ]);
    }

    /** 更新草稿：仅草稿（1518）；校验同 store；事务内锁行复查防并发 */
    public function update(Request $request, ReturnList $return)
    {
        try {
            if ($return->status !== ReturnList::STATUS_DRAFT) {
                return $this->fail(1518, '已审核单据不可修改');
            }
            $data = $this->validatePayload($request);
            if ($fail = $this->validateBusinessItems($data)) {
                return $fail;
            }
            if (! $request->filled('warehouse_id') || ! $request->filled('location_id')) {
                return $this->fail(422, '仓库与库位不能为空');
            }
            if ($msg = $this->validateIssued((int) $data['order_id'], $data['items'])) {
                return $this->fail(1517, $msg);
            }

            DB::transaction(function () use ($return, $data) {
                // 锁退料单行复查状态（幂等 1518）
                $locked = ReturnList::whereKey($return->id)->lockForUpdate()->firstOrFail();
                if ($locked->status !== ReturnList::STATUS_DRAFT) {
                    throw new ProductionException('已审核单据不可修改', 1518);
                }
                $locked->update([
                    'order_id' => $data['order_id'],
                    'pick_id' => $data['pick_id'] ?? $locked->pick_id,
                    'warehouse_id' => $data['warehouse_id'],
                    'location_id' => $data['location_id'],
                    'remark' => $data['remark'] ?? $locked->remark,
                ]);
                // 明细全量替换（草稿单无流水引用，直接重建）
                $locked->items()->delete();
                $locked->items()->createMany(array_map(fn ($i) => [
                    'product_id' => $i['product_id'],
                    'quantity' => $i['quantity'],
                ], $data['items']));
            });
        } catch (ProductionException $e) {
            // 1518 已审核（锁行复查与并发审核幂等拦截）
            return $this->fail($e->getCode() ?: 1518, $e->getMessage());
        }

        return $this->ok();
    }

    /** 删除草稿：仅草稿（1518）；事务内锁行复查防并发 */
    public function destroy(ReturnList $return)
    {
        try {
            if ($return->status !== ReturnList::STATUS_DRAFT) {
                return $this->fail(1518, '已审核单据不可删除');
            }
            DB::transaction(function () use ($return) {
                // 锁退料单行复查状态（幂等 1518）
                $locked = ReturnList::whereKey($return->id)->lockForUpdate()->firstOrFail();
                if ($locked->status !== ReturnList::STATUS_DRAFT) {
                    throw new ProductionException('已审核单据不可删除', 1518);
                }
                $locked->delete();
            });
        } catch (ProductionException $e) {
            // 1518 已审核（锁行复查与并发审核幂等拦截）
            return $this->fail($e->getCode() ?: 1518, $e->getMessage());
        }

        return $this->ok();
    }

    /**
     * 审核（核心）：事务内「锁单幂等 1519 → 逐行锁物料需求行复核 1517 → InventoryService 写 return 流水(+1)
     * → 冲销 issued_qty」任一步失败整体回滚（入库方向无需余额校验）
     */
    public function approve(ReturnList $return)
    {
        try {
            $result = null;
            DB::transaction(function () use ($return, &$result) {
                // 锁退料单行：同一单据重复审核在此判重（幂等 1519）
                $locked = ReturnList::whereKey($return->id)->lockForUpdate()->firstOrFail();
                if ($locked->status === ReturnList::STATUS_APPROVED) {
                    throw new ProductionException('该退料单已审核', 1519);
                }
                $movements = [];
                $writeOff = []; // [material_id => 本次冲销量] 待回写
                /** @var ReturnListItem $item */
                foreach ($locked->items as $item) {
                    // 锁物料需求行：防并发超退（多张退料单同时审同一物料时串行化）
                    $pm = ProductionOrderMaterial::where('order_id', $locked->order_id)
                        ->where('material_id', $item->product_id)
                        ->lockForUpdate()
                        ->first();
                    if (! $pm) {
                        throw new ProductionException('退料数量超过已领数量', 1517);
                    }
                    // 本次退料 ≤ 当前已领（草稿期校验后已领可能被并发冲销，审核期锁行复核）
                    if (bccomp((string) $item->quantity, (string) $pm->issued_qty, 2) > 0) {
                        throw new ProductionException('退料数量超过已领数量', 1517);
                    }
                    $writeOff[$item->product_id] = bcadd((string) ($writeOff[$item->product_id] ?? '0'), (string) $item->quantity, 2);
                    $movements[] = [
                        'product_id' => $item->product_id,
                        'warehouse_id' => $locked->warehouse_id,
                        'location_id' => $locked->location_id,
                        'direction' => 1,
                        'quantity' => $item->quantity,
                        'source_type' => 'return',
                        'source_id' => $locked->id,
                        'source_no' => $locked->no,
                        'remark' => '生产退料',
                    ];
                }
                // 统一引擎写流水+加余额（同事务双写）
                $this->inventoryService->apply($movements, auth()->id());
                // 冲销工单物料需求 issued_qty（bcmath 减法）
                foreach ($writeOff as $materialId => $qty) {
                    $pm = ProductionOrderMaterial::where('order_id', $locked->order_id)
                        ->where('material_id', $materialId)->firstOrFail();
                    $pm->issued_qty = bcsub((string) $pm->issued_qty, $qty, 2);
                    $pm->save();
                }
                // 置已审核 + 审核人/时间
                $locked->status = ReturnList::STATUS_APPROVED;
                $locked->operator = auth()->user()->name ?? '';
                $locked->approved_at = now();
                $locked->save();
                $result = ['no' => $locked->no];
            });
        } catch (ProductionException $e) {
            // 1519 幂等 / 1517 超已领（事务整体回滚）
            return $this->fail($e->getCode() ?: 1517, $e->getMessage());
        } catch (InventoryException $e) {
            // 余额引擎兜底（入库方向理论不触发，防御路径）
            return $this->fail(1517, '退料失败，请重试');
        }

        return $this->ok($result);
    }

    // 载荷格式校验（422 仅格式层）；业务码在方法内检查
    private function validatePayload(Request $request): array
    {
        return $request->validate([
            'order_id' => 'required|integer|exists:production_orders,id',
            'pick_id' => 'nullable|integer|exists:pick_lists,id',
            'warehouse_id' => 'nullable|integer|exists:warehouses,id',
            'location_id' => 'nullable|integer|exists:locations,id',
            'remark' => 'nullable|string|max:200',
            'items' => 'array',
            'items.*.product_id' => 'required|integer|exists:products,id',
            'items.*.quantity' => 'required|numeric|regex:/^-?\d+(\.\d{1,2})?$/',
        ]);
    }

    // 明细业务校验（store/update 共用）：空明细/数量≤0/重复商品 → 422（格式层；spec 码段满）
    private function validateBusinessItems(array $data): ?JsonResponse
    {
        $items = $data['items'] ?? [];
        if (empty($items)) {
            return $this->fail(422, '请至少添加一条明细');
        }
        $seen = [];
        foreach ($items as $item) {
            if ((float) $item['quantity'] <= 0) {
                return $this->fail(422, '退料数量必须大于 0');
            }
            if (isset($seen[$item['product_id']])) {
                return $this->fail(422, '明细存在重复商品');
            }
            $seen[$item['product_id']] = true;
        }

        return null;
    }

    // 草稿期已领校验：逐行 ≤ 该商品已领总量（1517），返回错误文案或 null
    private function validateIssued(int $orderId, array $items): ?string
    {
        foreach ($items as $item) {
            $pm = ProductionOrderMaterial::where('order_id', $orderId)
                ->where('material_id', $item['product_id'])->first();
            if (! $pm || bccomp((string) $item['quantity'], (string) $pm->issued_qty, 2) > 0) {
                return '退料数量超过已领数量';
            }
        }

        return null;
    }
}
```

- [ ] **Step 4: 注册路由**

修改 `server/routes/api.php`：顶部 use 区追加 `use App\Http\Controllers\Api\ReturnListController;`，并在领料单路由组之后追加：

```php
    // 退料单：CRUD + 审核（production.return.*；审核复用 update）
    Route::middleware('auth:sanctum')->group(function () {
        Route::middleware('permission:production.return.list')->get('/production/returns', [ReturnListController::class, 'index']);
        Route::middleware('permission:production.return.create')->post('/production/returns', [ReturnListController::class, 'store']);
        Route::middleware('permission:production.return.list')->get('/production/returns/{return}', [ReturnListController::class, 'show']);
        Route::middleware('permission:production.return.update')->put('/production/returns/{return}', [ReturnListController::class, 'update']);
        Route::middleware('permission:production.return.delete')->delete('/production/returns/{return}', [ReturnListController::class, 'destroy']);
        Route::middleware('permission:production.return.update')->post('/production/returns/{return}/approve', [ReturnListController::class, 'approve']);
    });
```

- [ ] **Step 5: 跑测试确认通过**

Run: `cd server && php artisan test --filter="ReturnListTest|PickListTest"`
Expected: ReturnListTest 8 全部 PASS（核心不变式：库存+/已领-、1517 回滚、幂等 1519）；PickListTest 14 回归绿。

- [ ] **Step 6: 提交**

```bash
cd /d/code/project/php-design && git add server/app/Http/Controllers/Api/ReturnListController.php server/tests/Feature/ReturnListTest.php server/routes/api.php
git commit -m "feat: 退料单 API（审核冲销已领/防超退 1517/幂等 1519）"
```

---

## Task 8: 委外加工 API（CRUD + 发出 + 回收闭环）

**Files:**
- Create: `server/app/Http/Controllers/Api/OutsourcingController.php`
- Create: `server/tests/Feature/OutsourcingTest.php`
- Modify: `server/routes/api.php`

**Interfaces:**
- Consumes: Task 1/2 模型（OutsourcingOrder/OutsourcingReceipt/ProductionOrder/WorkOrderOperation）；Task 2 DocumentSequenceService（type=os/osr）；`InventoryService::apply`（direction=-1 source_type=outsourcing_out / direction=+1 source_type=outsourcing_in）；`InventoryException`（防御兜底）
- Produces: `GET/POST /api/v1/production/outsourcings`、`GET/PUT/DELETE /api/v1/production/outsourcings/{outsourcing}`、`POST .../approve`（发出：锁余额行校验 1522 → outsourcing_out 流水(-qty)）、`POST .../receipts`（回收：创建即审核回收单 + outsourcing_in 流水(+qty) + 累计≥委外量 → 委外单已回收 + **工序标记完成**）、`GET .../receipts`（回收记录列表）；错误码 1520-1524；**委外商品 = 工单成品**（spec 数据模型无 product_id，E2E TC-PRD-06 锁定）；供应商/仓库/库位缺失、明细为空、重复商品 → 422

- [ ] **Step 1: 写失败测试 `server/tests/Feature/OutsourcingTest.php`**

```php
<?php

// 委外加工接口测试：CRUD/发出扣成品/回收加成品/分批回收/超收/工序联动/幂等（核心路径 100%）

namespace Tests\Feature;

use App\Models\BomHeader;
use App\Models\Category;
use App\Models\InventoryBalance;
use App\Models\Location;
use App\Models\OutsourcingOrder;
use App\Models\OutsourcingReceipt;
use App\Models\Process;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WorkOrderOperation;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OutsourcingTest extends TestCase
{
    use RefreshDatabase;

    private string $token;
    private User $admin;
    private Warehouse $wh;
    private Location $a01;
    private Location $b01;
    private Product $mat;
    private Product $fin;
    private Supplier $supplier;
    private ProductionOrder $order;
    private int $assemblyOpId; // 组装工序（委外对象）

    protected function setUp(): void
    {
        parent::setUp();
        $role = Role::create(['name' => '管理员', 'code' => 'admin']);
        $this->admin = User::create(['name' => '管理员', 'username' => 'admin', 'password' => 'admin123', 'status' => 1]);
        $this->admin->roles()->sync([$role->id]);
        $this->token = $this->admin->createToken('api')->plainTextToken;

        $this->wh = Warehouse::create(['name' => '主仓', 'code' => 'WH01', 'status' => 1]);
        $this->a01 = Location::create(['warehouse_id' => $this->wh->id, 'name' => 'A-01', 'code' => 'A-01', 'status' => 1]);
        $this->b01 = Location::create(['warehouse_id' => $this->wh->id, 'name' => 'B-01', 'code' => 'B-01', 'status' => 1]);
        $this->supplier = Supplier::create(['name' => '测试供应商', 'code' => 'SUP-001', 'status' => 1]);
        $rawCat = Category::create(['name' => '原材料', 'parent_id' => 0]);
        $cat = Category::create(['name' => '成品', 'parent_id' => 0]);
        $unit = Unit::create(['name' => '个', 'code' => 'pc']);
        $this->mat = Product::create(['name' => '测试铝材', 'code' => 'MAT-001', 'type' => 'raw_material', 'category_id' => $rawCat->id, 'unit_id' => $unit->id, 'status' => 1]);
        $this->fin = Product::create(['name' => '成品B', 'code' => 'FIN-002', 'type' => 'finished', 'category_id' => $cat->id, 'unit_id' => $unit->id, 'status' => 1]);
        $bom = BomHeader::create(['code' => 'BOM-001', 'product_id' => $this->fin->id, 'version' => 'v1', 'quantity' => 1, 'status' => 1]);
        $bom->items()->create(['material_id' => $this->mat->id, 'quantity' => 2, 'unit_id' => $unit->id]);
        foreach ([['下料', 'CUT', 1], ['组装', 'ASSY', 2], ['质检', 'QC', 3]] as [$name, $code, $sort]) {
            Process::create(['name' => $name, 'code' => $code, 'sort' => $sort, 'status' => 1]);
        }
        // 基线库存：FIN-002 @B-01=50（委外发出 5 → 45；回收 5 → 50）
        app(InventoryService::class)->apply([
            ['product_id' => $this->fin->id, 'warehouse_id' => $this->wh->id, 'location_id' => $this->b01->id, 'direction' => 1, 'quantity' => 50, 'source_type' => 'purchase_inbound', 'source_id' => 0, 'source_no' => 'SEED', 'remark' => '测试基线'],
        ]);

        // 建单（FIN-002×5）→ 下达 → 开工
        $res = $this->withToken($this->token)->postJson('/api/v1/production/orders', [
            'product_id' => $this->fin->id, 'quantity' => 5, 'plan_date' => now()->toDateString(),
        ]);
        $res->assertJsonPath('code', 0);
        $this->order = ProductionOrder::where('no', $res->json('data.no'))->first();
        $this->withToken($this->token)->postJson("/api/v1/production/orders/{$this->order->id}/release")->assertJsonPath('code', 0);
        $this->withToken($this->token)->postJson("/api/v1/production/orders/{$this->order->id}/start")->assertJsonPath('code', 0);
        $this->assemblyOpId = $this->order->operations()->where('seq', 2)->first()->id;
    }

    // 组装委外载荷（默认组装工序×5，发出仓 A-01）
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'order_id' => $this->order->id,
            'operation_id' => $this->assemblyOpId,
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->wh->id,
            'location_id' => $this->a01->id,
            'quantity' => 5,
        ], $overrides);
    }

    // 通过 API 建草稿委外单并返回单号
    private function createOutsourcing(array $payload): string
    {
        $res = $this->withToken($this->token)->postJson('/api/v1/production/outsourcings', $payload);
        $res->assertJsonPath('code', 0);
        return $res->json('data.no');
    }

    public function test_store_creates_draft_with_no(): void
    {
        // 正常路径：草稿创建成功，单号 OS{date}-001
        $no = $this->createOutsourcing($this->payload());
        $this->assertMatchesRegularExpression('/^OS\d{8}-001$/', $no);
        $os = OutsourcingOrder::where('no', $no)->first();
        $this->assertSame(OutsourcingOrder::STATUS_DRAFT, $os->status);
        $this->assertSame('5.00', $os->quantity);
    }

    public function test_store_rejects_over_plan_with_1520(): void
    {
        // 异常路径：委外量超工单计划数（6 > 5）→ 1520
        $this->withToken($this->token)->postJson('/api/v1/production/outsourcings', $this->payload(['quantity' => 6]))
            ->assertJsonPath('code', 1520)
            ->assertJsonPath('message', '委外数量超过工单计划数量');
    }

    public function test_store_rejects_operation_not_in_order_with_422(): void
    {
        // 异常路径：工序不属于该工单 → 422（格式层；spec 码段满）
        $other = ProductionOrder::where('no', '!=', $this->order->no)->first();
        if (! $other) {
            // 建第二个工单的工序作反例
            $res = $this->withToken($this->token)->postJson('/api/v1/production/orders', [
                'product_id' => $this->fin->id, 'quantity' => 5, 'plan_date' => now()->toDateString(),
            ]);
            $res->assertJsonPath('code', 0);
            $other = ProductionOrder::where('no', $res->json('data.no'))->first();
        }
        $foreignOp = $other->operations()->where('order_id', '!=', $this->order->id)->first();
        $this->withToken($this->token)->postJson('/api/v1/production/outsourcings', $this->payload(['operation_id' => $foreignOp->id]))
            ->assertJsonPath('code', 422);
    }

    public function test_approve_deducts_finished_inventory_and_writes_movement(): void
    {
        // 核心不变式（发出）：余额 50→45、outsourcing_out 流水（direction=-1，商品=工单成品）
        $no = $this->createOutsourcing($this->payload());
        $os = OutsourcingOrder::where('no', $no)->first();
        $this->withToken($this->token)->postJson("/api/v1/production/outsourcings/{$os->id}/approve")
            ->assertJsonPath('code', 0);
        $balance = InventoryBalance::where('product_id', $this->fin->id)->first();
        $this->assertSame('45.00', $balance->quantity);
        $this->assertDatabaseHas('inventory_movements', [
            'product_id' => $this->fin->id, 'direction' => -1, 'quantity' => '5.00',
            'balance_after' => '45.00', 'source_type' => 'outsourcing_out', 'source_no' => $no,
        ]);
        $os->refresh();
        $this->assertSame(OutsourcingOrder::STATUS_APPROVED, $os->status);
        $this->assertSame('管理员', $os->operator);
        $this->assertNotNull($os->approved_at);
    }

    public function test_approve_rejects_insufficient_balance_with_1522_rollback(): void
    {
        // 核心不变式（超卖拦截）：库存不足 → 1522 整体回滚
        $no = $this->createOutsourcing($this->payload(['quantity' => 100]));
        $os = OutsourcingOrder::where('no', $no)->first();
        $res = $this->withToken($this->token)->postJson("/api/v1/production/outsourcings/{$os->id}/approve");
        $res->assertJsonPath('code', 1522)
            ->assertJsonPath('message', '商品[FIN-002]库存不足');
        $this->assertSame('50.00', InventoryBalance::where('product_id', $this->fin->id)->first()->quantity);
        $this->assertDatabaseMissing('inventory_movements', ['source_no' => $no]);
        $this->assertSame(OutsourcingOrder::STATUS_DRAFT, $os->refresh()->status);
    }

    public function test_approve_idempotent_with_1523(): void
    {
        // 核心不变式：重复审核 → 1523，库存不重复扣减
        $no = $this->createOutsourcing($this->payload());
        $os = OutsourcingOrder::where('no', $no)->first();
        $this->withToken($this->token)->postJson("/api/v1/production/outsourcings/{$os->id}/approve")->assertJsonPath('code', 0);
        $this->withToken($this->token)->postJson("/api/v1/production/outsourcings/{$os->id}/approve")
            ->assertJsonPath('code', 1523)
            ->assertJsonPath('message', '该委外单已审核');
        $this->assertSame('45.00', InventoryBalance::where('product_id', $this->fin->id)->first()->quantity);
    }

    public function test_receipt_credits_inventory_and_marks_received(): void
    {
        // 核心不变式（回收）：余额 45→50、outsourcing_in 流水(+1，单号 OSR..)、委外单已回收、工序标记完成
        $no = $this->createOutsourcing($this->payload());
        $os = OutsourcingOrder::where('no', $no)->first();
        $this->withToken($this->token)->postJson("/api/v1/production/outsourcings/{$os->id}/approve")->assertJsonPath('code', 0);
        $res = $this->withToken($this->token)->postJson("/api/v1/production/outsourcings/{$os->id}/receipts", [
            'quantity' => 5, 'warehouse_id' => $this->wh->id, 'location_id' => $this->b01->id, 'remark' => '回收',
        ]);
        $res->assertJsonPath('code', 0)
            ->assertJsonPath('data.no', 'OSR'.date('Ymd').'-001');
        $this->assertSame('50.00', InventoryBalance::where('product_id', $this->fin->id)->first()->quantity);
        $this->assertDatabaseHas('inventory_movements', [
            'product_id' => $this->fin->id, 'direction' => 1, 'quantity' => '5.00',
            'balance_after' => '50.00', 'source_type' => 'outsourcing_in', 'source_no' => 'OSR'.date('Ymd').'-001',
        ]);
        $os->refresh();
        $this->assertSame(OutsourcingOrder::STATUS_RECEIVED, $os->status);
        // 委外工序（组装）标记完成（spec §6：回收量≥委外量时）
        $this->assertSame(WorkOrderOperation::STATUS_DONE, WorkOrderOperation::find($this->assemblyOpId)->status);
        // 回收单落库
        $this->assertDatabaseHas('outsourcing_receipts', [
            'outsourcing_id' => $os->id, 'quantity' => '5.00', 'status' => OutsourcingReceipt::STATUS_APPROVED,
        ]);
    }

    public function test_receipt_allows_partial_batches_and_rejects_over_with_1524(): void
    {
        // 边界路径：分批回收（3+2）；累计超委外量（再收 1）→ 1524 拦截且不产生流水
        $no = $this->createOutsourcing($this->payload());
        $os = OutsourcingOrder::where('no', $no)->first();
        $this->withToken($this->token)->postJson("/api/v1/production/outsourcings/{$os->id}/approve")->assertJsonPath('code', 0);
        // 第一批 3
        $this->withToken($this->token)->postJson("/api/v1/production/outsourcings/{$os->id}/receipts", [
            'quantity' => 3, 'warehouse_id' => $this->wh->id, 'location_id' => $this->b01->id,
        ])->assertJsonPath('code', 0);
        $this->assertSame('48.00', InventoryBalance::where('product_id', $this->fin->id)->first()->quantity);
        // 状态未回收（累计 3 < 5），工序未完成
        $this->assertSame(OutsourcingOrder::STATUS_APPROVED, $os->refresh()->status);
        $this->assertSame(WorkOrderOperation::STATUS_RUNNING, WorkOrderOperation::find($this->assemblyOpId)->status);
        // 第二批 2 → 已回收
        $this->withToken($this->token)->postJson("/api/v1/production/outsourcings/{$os->id}/receipts", [
            'quantity' => 2, 'warehouse_id' => $this->wh->id, 'location_id' => $this->b01->id,
        ])->assertJsonPath('code', 0);
        $this->assertSame('50.00', InventoryBalance::where('product_id', $this->fin->id)->first()->quantity);
        $this->assertSame(OutsourcingOrder::STATUS_RECEIVED, $os->refresh()->status);
        // 超收（累计已 5，再收 1）→ 1524 整体回滚
        $res = $this->withToken($this->token)->postJson("/api/v1/production/outsourcings/{$os->id}/receipts", [
            'quantity' => 1, 'warehouse_id' => $this->wh->id, 'location_id' => $this->b01->id,
        ]);
        $res->assertJsonPath('code', 1524)
            ->assertJsonPath('message', '回收数量超过委外数量');
        $this->assertSame('50.00', InventoryBalance::where('product_id', $this->fin->id)->first()->quantity);
        $this->assertDatabaseCount('outsourcing_receipts', 2);
    }

    public function test_receipt_rejects_draft_outsourcing_with_422(): void
    {
        // 异常路径：未发出（草稿）不可回收 → 422
        $no = $this->createOutsourcing($this->payload());
        $os = OutsourcingOrder::where('no', $no)->first();
        $this->withToken($this->token)->postJson("/api/v1/production/outsourcings/{$os->id}/receipts", [
            'quantity' => 1, 'warehouse_id' => $this->wh->id, 'location_id' => $this->b01->id,
        ])->assertJsonPath('code', 422);
    }

    public function test_receipts_index_lists_records(): void
    {
        // 正常路径：回收记录列表（单号/数量/时间）
        $no = $this->createOutsourcing($this->payload());
        $os = OutsourcingOrder::where('no', $no)->first();
        $this->withToken($this->token)->postJson("/api/v1/production/outsourcings/{$os->id}/approve")->assertJsonPath('code', 0);
        $this->withToken($this->token)->postJson("/api/v1/production/outsourcings/{$os->id}/receipts", [
            'quantity' => 5, 'warehouse_id' => $this->wh->id, 'location_id' => $this->b01->id,
        ])->assertJsonPath('code', 0);
        $this->withToken($this->token)->getJson("/api/v1/production/outsourcings/{$os->id}/receipts")
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.items.0.quantity', '5.00')
            ->assertJsonPath('data.items.0.no', 'OSR'.date('Ymd').'-001');
    }

    public function test_update_destroy_draft_ok_approved_rejected_with_1521(): void
    {
        // 正常+异常路径：草稿可改可删；已审核不可改删 → 1521
        $no = $this->createOutsourcing($this->payload());
        $id = OutsourcingOrder::where('no', $no)->first()->id;
        $this->withToken($this->token)->putJson("/api/v1/production/outsourcings/{$id}", $this->payload(['remark' => '改后']))
            ->assertJsonPath('code', 0);
        $this->withToken($this->token)->deleteJson("/api/v1/production/outsourcings/{$id}")
            ->assertJsonPath('code', 0);
        $this->assertDatabaseMissing('outsourcing_orders', ['id' => $id]);

        $no2 = $this->createOutsourcing($this->payload());
        $os2 = OutsourcingOrder::where('no', $no2)->first();
        $this->withToken($this->token)->postJson("/api/v1/production/outsourcings/{$os2->id}/approve")->assertJsonPath('code', 0);
        $this->withToken($this->token)->putJson("/api/v1/production/outsourcings/{$os2->id}", $this->payload())
            ->assertJsonPath('code', 1521);
        $this->withToken($this->token)->deleteJson("/api/v1/production/outsourcings/{$os2->id}")
            ->assertJsonPath('code', 1521);
    }

    public function test_index_with_labels_and_outsourcings_requires_permission(): void
    {
        // 正常路径：列表含工单单号/供应商/工序名/状态标签
        $no = $this->createOutsourcing($this->payload());
        $os = OutsourcingOrder::where('no', $no)->first();
        $this->withToken($this->token)->postJson("/api/v1/production/outsourcings/{$os->id}/approve")->assertJsonPath('code', 0);
        $this->withToken($this->token)->getJson('/api/v1/production/outsourcings')
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.items.0.order_no', 'MO'.date('Ymd').'-001')
            ->assertJsonPath('data.items.0.supplier_name', '测试供应商')
            ->assertJsonPath('data.items.0.process_name', '组装')
            ->assertJsonPath('data.items.0.status_label', '已审核');
        // 异常路径：无 production.outsource.list 权限的角色被拒（403 JSON 信封）
        $role = Role::create(['name' => '普通', 'code' => 'plain']);
        $u = User::create(['name' => '普通', 'username' => 'plain', 'password' => 'admin123', 'status' => 1]);
        $u->roles()->sync([$role->id]);
        $token = $u->createToken('api')->plainTextToken;
        $this->withToken($token)->getJson('/api/v1/production/outsourcings')->assertStatus(403);
    }
}
```

- [ ] **Step 2: 跑测试确认失败**

Run: `cd server && php artisan test --filter=OutsourcingTest`
Expected: FAIL（控制器/路由不存在）。

- [ ] **Step 3: 实现 OutsourcingController**

创建 `server/app/Http/Controllers/Api/OutsourcingController.php`：

```php
<?php

// 委外加工控制器：草稿 CRUD + 发出（审核：锁余额行防超卖 1522）+ 回收（创建即审核回收单 + 工序联动）
// 委外商品 = 工单成品（spec 数据模型无 product_id，E2E TC-PRD-06 锁定）

namespace App\Http\Controllers\Api;

use App\Exceptions\InventoryException;
use App\Exceptions\ProductionException;
use App\Http\Controllers\Controller;
use App\Models\DocumentSequence;
use App\Models\InventoryBalance;
use App\Models\OutsourcingOrder;
use App\Models\OutsourcingReceipt;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\WorkOrderOperation;
use App\Services\DocumentSequenceService;
use App\Services\InventoryService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OutsourcingController extends Controller
{
    use ApiResponse;

    public function __construct(
        private InventoryService $inventoryService,
        private DocumentSequenceService $sequenceService,
    ) {}

    /** 分页列表：单号/供应商/状态 筛选；含工单单号/供应商名/工序名与状态标签 */
    public function index(Request $request)
    {
        $query = OutsourcingOrder::query()
            ->join('production_orders', 'production_orders.id', '=', 'outsourcing_orders.order_id')
            ->join('suppliers', 'suppliers.id', '=', 'outsourcing_orders.supplier_id')
            ->join('work_order_operations', 'work_order_operations.id', '=', 'outsourcing_orders.operation_id')
            ->join('processes', 'processes.id', '=', 'work_order_operations.process_id')
            ->select(
                'outsourcing_orders.*',
                'production_orders.no as order_no',
                'suppliers.name as supplier_name',
                'processes.name as process_name',
            )
            ->orderByDesc('outsourcing_orders.id');

        if ($keyword = $request->input('keyword')) {
            $query->where('outsourcing_orders.no', 'like', "%{$keyword}%");
        }
        if ($request->filled('status')) {
            $query->where('outsourcing_orders.status', (int) $request->input('status'));
        }

        $rows = $query->paginate(max(1, min(100, (int) $request->input('per_page', 10))));

        return $this->ok([
            // join 别名列经 getAttribute 读取（PHPStan 静态分析可识别）
            'items' => $rows->map(fn (OutsourcingOrder $o) => [
                'id' => $o->id,
                'no' => $o->no,
                'order_id' => $o->order_id,
                'order_no' => $o->getAttribute('order_no'),
                'operation_id' => $o->operation_id,
                'process_name' => $o->getAttribute('process_name'),
                'supplier_id' => $o->supplier_id,
                'supplier_name' => $o->getAttribute('supplier_name'),
                'quantity' => $o->quantity,
                'status' => (int) $o->status,
                'status_label' => OutsourcingOrder::STATUS_LABELS[$o->status] ?? '未知',
                'approved_at' => $o->approved_at?->toDateTimeString(),
                'operator' => $o->operator,
                'created_at' => $o->created_at?->toDateTimeString(),
            ]),
            'total' => $rows->total(), 'page' => $rows->currentPage(), 'per_page' => $rows->perPage(),
        ]);
    }

    /** 新建草稿：委外量 ≤ 工单计划数（1520）；工序必须属于该工单（422） */
    public function store(Request $request)
    {
        $data = $this->validatePayload($request);
        if ((float) $data['quantity'] <= 0) {
            return $this->fail(422, '委外数量必须大于 0');
        }
        if (! $request->filled('supplier_id')) {
            return $this->fail(422, '供应商不能为空');
        }
        if (! $request->filled('warehouse_id') || ! $request->filled('location_id')) {
            return $this->fail(422, '仓库与库位不能为空');
        }
        // 草稿期校验：委外量 ≤ 工单计划数（1520）
        $order = ProductionOrder::find($data['order_id']);
        if (! $order || bccomp((string) $data['quantity'], (string) $order->quantity, 2) > 0) {
            return $this->fail(1520, '委外数量超过工单计划数量');
        }
        // 工序必须属于该工单（防跨单挂工序）
        $op = WorkOrderOperation::where('id', $data['operation_id'])->where('order_id', $data['order_id'])->first();
        if (! $op) {
            return $this->fail(422, '工序不属于该工单');
        }

        $os = DB::transaction(function () use ($data) {
            $os = $this->sequenceService->nextNo(
                DocumentSequence::TYPE_OS,
                'OS',
                fn (string $no) => OutsourcingOrder::create([
                    'no' => $no,
                    'order_id' => $data['order_id'],
                    'operation_id' => $data['operation_id'],
                    'supplier_id' => $data['supplier_id'],
                    'status' => OutsourcingOrder::STATUS_DRAFT,
                    'warehouse_id' => $data['warehouse_id'],
                    'location_id' => $data['location_id'],
                    'quantity' => $data['quantity'],
                    'remark' => $data['remark'] ?? null,
                ]),
                fn () => (int) (OutsourcingOrder::where('no', 'like', 'OS'.date('Ymd').'-%')
                    ->get('no')->map(fn ($o) => (int) substr((string) $o->no, -3))->max() ?? 0),
            );

            return $os;
        });

        return $this->ok(['no' => $os->no]);
    }

    /** 详情：头信息 + 回收记录摘要 */
    public function show(OutsourcingOrder $outsourcing)
    {
        return $this->ok([
            'id' => $outsourcing->id,
            'no' => $outsourcing->no,
            'order_id' => $outsourcing->order_id,
            'order_no' => $outsourcing->order?->no,
            'operation_id' => $outsourcing->operation_id,
            'process_name' => $outsourcing->operation?->process?->name,
            'supplier_id' => $outsourcing->supplier_id,
            'supplier_name' => $outsourcing->supplier?->name,
            'status' => (int) $outsourcing->status,
            'status_label' => OutsourcingOrder::STATUS_LABELS[$outsourcing->status] ?? '未知',
            'warehouse_id' => $outsourcing->warehouse_id,
            'warehouse_name' => $outsourcing->warehouse?->name,
            'location_id' => $outsourcing->location_id,
            'location_name' => $outsourcing->location?->name,
            'quantity' => $outsourcing->quantity,
            'approved_at' => $outsourcing->approved_at?->toDateTimeString(),
            'operator' => $outsourcing->operator,
            'remark' => $outsourcing->remark,
            // 已回收累计（回收弹窗剩余量数据源）
            'received_qty' => $this->receivedQty($outsourcing->id),
        ]);
    }

    /** 更新草稿：仅草稿（1521）；校验同 store；事务内锁行复查防并发 */
    public function update(Request $request, OutsourcingOrder $outsourcing)
    {
        try {
            if ($outsourcing->status !== OutsourcingOrder::STATUS_DRAFT) {
                return $this->fail(1521, '已审核单据不可修改');
            }
            $data = $this->validatePayload($request);
            if ((float) $data['quantity'] <= 0) {
                return $this->fail(422, '委外数量必须大于 0');
            }
            if (! $request->filled('supplier_id')) {
                return $this->fail(422, '供应商不能为空');
            }
            if (! $request->filled('warehouse_id') || ! $request->filled('location_id')) {
                return $this->fail(422, '仓库与库位不能为空');
            }
            $order = ProductionOrder::find($data['order_id']);
            if (! $order || bccomp((string) $data['quantity'], (string) $order->quantity, 2) > 0) {
                return $this->fail(1520, '委外数量超过工单计划数量');
            }
            $op = WorkOrderOperation::where('id', $data['operation_id'])->where('order_id', $data['order_id'])->first();
            if (! $op) {
                return $this->fail(422, '工序不属于该工单');
            }

            DB::transaction(function () use ($outsourcing, $data) {
                // 锁委外单行复查状态（幂等 1521）
                $locked = OutsourcingOrder::whereKey($outsourcing->id)->lockForUpdate()->firstOrFail();
                if ($locked->status !== OutsourcingOrder::STATUS_DRAFT) {
                    throw new ProductionException('已审核单据不可修改', 1521);
                }
                $locked->update([
                    'order_id' => $data['order_id'],
                    'operation_id' => $data['operation_id'],
                    'supplier_id' => $data['supplier_id'],
                    'warehouse_id' => $data['warehouse_id'],
                    'location_id' => $data['location_id'],
                    'quantity' => $data['quantity'],
                    'remark' => $data['remark'] ?? $locked->remark,
                ]);
            });
        } catch (ProductionException $e) {
            // 1521 已审核（锁行复查与并发审核幂等拦截）
            return $this->fail($e->getCode() ?: 1521, $e->getMessage());
        }

        return $this->ok();
    }

    /** 删除草稿：仅草稿（1521）；事务内锁行复查防并发 */
    public function destroy(OutsourcingOrder $outsourcing)
    {
        try {
            if ($outsourcing->status !== OutsourcingOrder::STATUS_DRAFT) {
                return $this->fail(1521, '已审核单据不可删除');
            }
            DB::transaction(function () use ($outsourcing) {
                // 锁委外单行复查状态（幂等 1521）
                $locked = OutsourcingOrder::whereKey($outsourcing->id)->lockForUpdate()->firstOrFail();
                if ($locked->status !== OutsourcingOrder::STATUS_DRAFT) {
                    throw new ProductionException('已审核单据不可删除', 1521);
                }
                $locked->delete();
            });
        } catch (ProductionException $e) {
            // 1521 已审核（锁行复查与并发审核幂等拦截）
            return $this->fail($e->getCode() ?: 1521, $e->getMessage());
        }

        return $this->ok();
    }

    /**
     * 发出（审核）：事务内「锁单幂等 1523 → 锁工单行取成品 → 锁余额行校验充足 1522
     * → InventoryService 写 outsourcing_out 流水(-qty) 扣成品库存」任一步失败整体回滚
     */
    public function approve(OutsourcingOrder $outsourcing)
    {
        try {
            $result = null;
            DB::transaction(function () use ($outsourcing, &$result) {
                // 锁委外单行：同一单据重复审核在此判重（幂等 1523）
                $locked = OutsourcingOrder::whereKey($outsourcing->id)->lockForUpdate()->firstOrFail();
                if ($locked->status === OutsourcingOrder::STATUS_APPROVED) {
                    throw new ProductionException('该委外单已审核', 1523);
                }
                if ($locked->status === OutsourcingOrder::STATUS_RECEIVED) {
                    throw new ProductionException('该委外单已回收', 1523);
                }
                // 锁工单行：取委外商品（= 工单成品）并校验工单状态（草稿/关闭不可发出）
                $order = ProductionOrder::whereKey($locked->order_id)->lockForUpdate()->firstOrFail();
                if (! in_array($order->status, [ProductionOrder::STATUS_RELEASED, ProductionOrder::STATUS_PRODUCING], true)) {
                    throw new ProductionException('工单当前状态不可委外', 1523);
                }
                // 防超卖：锁余额行校验（消息含商品编码与精确库存快照）
                $balance = InventoryBalance::where('product_id', $order->product_id)
                    ->where('warehouse_id', $locked->warehouse_id)
                    ->where('location_id', $locked->location_id)
                    ->lockForUpdate()
                    ->first();
                $current = $balance ? (string) $balance->quantity : '0';
                if (bccomp((string) $locked->quantity, $current, 2) > 0) {
                    $qtyText = rtrim(rtrim($current, '0'), '.');
                    $code = Product::find($order->product_id)?->code ?? ('#'.$order->product_id);
                    throw new ProductionException("商品[{$code}]库存不足", 1522);
                }
                // 统一引擎写流水+扣余额（同事务双写；余额行已被本事务锁定，引擎内重复加锁幂等）
                $this->inventoryService->apply([[
                    'product_id' => $order->product_id,
                    'warehouse_id' => $locked->warehouse_id,
                    'location_id' => $locked->location_id,
                    'direction' => -1,
                    'quantity' => $locked->quantity,
                    'source_type' => 'outsourcing_out',
                    'source_id' => $locked->id,
                    'source_no' => $locked->no,
                    'remark' => '委外发出',
                ]], auth()->id());
                // 置已审核（已发出）+ 操作人/时间
                $locked->status = OutsourcingOrder::STATUS_APPROVED;
                $locked->operator = auth()->user()->name ?? '';
                $locked->approved_at = now();
                $locked->save();
                $result = ['no' => $locked->no];
            });
        } catch (ProductionException $e) {
            // 1523 幂等/工单状态不符 / 1522 库存不足（事务整体回滚）
            return $this->fail($e->getCode() ?: 1523, $e->getMessage());
        } catch (InventoryException $e) {
            // 余额引擎兜底拒绝（理论上被预校验拦截，防御路径）
            return $this->fail(1522, '库存不足，委外发出被拒绝');
        }

        return $this->ok($result);
    }

    /**
     * 回收：事务内「锁委外单（已审核才可回收 422；累计+本次 ≤ 委外量 1524）→ 锁工单行取成品
     * → InventoryService 写 outsourcing_in 流水(+qty) → 创建回收单（创建即审核）→ 累计 ≥ 委外量
     * → 委外单已回收 + 工序标记完成」任一步失败整体回滚
     */
    public function storeReceipt(Request $request, OutsourcingOrder $outsourcing)
    {
        $data = $this->validatePayloadReceipt($request);
        if ((float) $data['quantity'] <= 0) {
            return $this->fail(422, '回收数量必须大于 0');
        }

        try {
            $result = null;
            DB::transaction(function () use ($outsourcing, $data, &$result) {
                // 锁委外单行：回收并发串行化（累计回收判定一致）
                $locked = OutsourcingOrder::whereKey($outsourcing->id)->lockForUpdate()->firstOrFail();
                if ($locked->status !== OutsourcingOrder::STATUS_APPROVED) {
                    throw new ProductionException('当前委外单不可回收', 422);
                }
                // 累计回收 + 本次 ≤ 委外量（超收 1524 整体回滚）
                $received = $this->receivedQty($locked->id);
                if (bccomp(bcadd($received, (string) $data['quantity'], 2), (string) $locked->quantity, 2) > 0) {
                    throw new ProductionException('回收数量超过委外数量', 1524);
                }
                // 锁工单行取委外商品（= 工单成品）
                $order = ProductionOrder::whereKey($locked->order_id)->lockForUpdate()->firstOrFail();
                // 统一引擎写流水+加余额（同事务双写）
                $this->inventoryService->apply([[
                    'product_id' => $order->product_id,
                    'warehouse_id' => $data['warehouse_id'],
                    'location_id' => $data['location_id'],
                    'direction' => 1,
                    'quantity' => $data['quantity'],
                    'source_type' => 'outsourcing_in',
                    'source_id' => $locked->id,
                    'source_no' => '',
                    'remark' => '委外回收',
                ]], auth()->id());
                // 创建回收单（创建即审核）：单号 OSR 先占号再补流水单号（先建单号引用唯一）
                $receipt = $this->sequenceService->nextNo(
                    DocumentSequence::TYPE_OSR,
                    'OSR',
                    fn (string $no) => OutsourcingReceipt::create([
                        'no' => $no,
                        'outsourcing_id' => $locked->id,
                        'quantity' => $data['quantity'],
                        'warehouse_id' => $data['warehouse_id'],
                        'location_id' => $data['location_id'],
                        'status' => OutsourcingReceipt::STATUS_APPROVED,
                        'received_at' => now(),
                        'operator' => auth()->user()->name ?? '',
                        'remark' => $data['remark'] ?? null,
                    ]),
                    fn () => (int) (OutsourcingReceipt::where('no', 'like', 'OSR'.date('Ymd').'-%')
                        ->get('no')->map(fn ($r) => (int) substr((string) $r->no, -3))->max() ?? 0),
                );
                // 流水单号回补（流水创建时回收单号未定，先以委外单号占位后回补——审计链完整）
                DB::table('inventory_movements')
                    ->where('source_type', 'outsourcing_in')
                    ->where('source_id', $locked->id)
                    ->where('source_no', '')
                    ->update(['source_no' => $receipt->no]);

                // 累计回收 ≥ 委外量 → 委外单已回收 + 委外工序标记完成（spec §6；回收只对未完成工序生效）
                $receivedNow = bcadd($received, (string) $data['quantity'], 2);
                if (bccomp($receivedNow, (string) $locked->quantity, 2) >= 0) {
                    $locked->status = OutsourcingOrder::STATUS_RECEIVED;
                    $locked->save();
                    $op = WorkOrderOperation::whereKey($locked->operation_id)->lockForUpdate()->first();
                    if ($op && $op->status !== WorkOrderOperation::STATUS_DONE) {
                        $op->status = WorkOrderOperation::STATUS_DONE;
                        $op->save();
                    }
                }
                $result = ['no' => $receipt->no];
            });
        } catch (ProductionException $e) {
            // 1524 超收 / 422 状态不符（事务整体回滚）
            return $this->fail($e->getCode() ?: 422, $e->getMessage());
        } catch (InventoryException $e) {
            // 余额引擎兜底（入库方向理论不触发，防御路径）
            return $this->fail(422, '回收失败，请重试');
        }

        return $this->ok($result);
    }

    /** 回收记录列表：该委外单全部回收单（按回收时间倒序） */
    public function receipts(OutsourcingOrder $outsourcing)
    {
        $rows = $outsourcing->receipts()->orderByDesc('received_at')->paginate(max(1, min(100, (int) request('per_page', 10))));

        return $this->ok([
            'items' => $rows->map(fn (OutsourcingReceipt $r) => [
                'id' => $r->id,
                'no' => $r->no,
                'quantity' => $r->quantity,
                'warehouse_id' => $r->warehouse_id,
                'warehouse_name' => $r->warehouse?->name,
                'location_id' => $r->location_id,
                'location_name' => $r->location?->name,
                'received_at' => $r->received_at?->toDateTimeString(),
                'operator' => $r->operator,
                'remark' => $r->remark,
            ]),
            'total' => $rows->total(), 'page' => $rows->currentPage(), 'per_page' => $rows->perPage(),
        ]);
    }

    // 已回收累计（Σ 已审核回收单数量，bcmath 累加）
    private function receivedQty(int $outsourcingId): string
    {
        $total = '0';
        foreach (OutsourcingReceipt::where('outsourcing_id', $outsourcingId)->get() as $r) {
            $total = bcadd($total, (string) $r->quantity, 2);
        }

        return $total;
    }

    // 委外单载荷格式校验（422 仅格式层）；业务码在方法内检查
    private function validatePayload(Request $request): array
    {
        return $request->validate([
            'order_id' => 'required|integer|exists:production_orders,id',
            'operation_id' => 'required|integer|exists:work_order_operations,id',
            'supplier_id' => 'nullable|integer|exists:suppliers,id',
            'warehouse_id' => 'nullable|integer|exists:warehouses,id',
            'location_id' => 'nullable|integer|exists:locations,id',
            'quantity' => 'required|numeric|regex:/^-?\d+(\.\d{1,2})?$/',
            'remark' => 'nullable|string|max:200',
        ]);
    }

    // 回收载荷格式校验（422 仅格式层）
    private function validatePayloadReceipt(Request $request): array
    {
        return $request->validate([
            'quantity' => 'required|numeric|regex:/^-?\d+(\.\d{1,2})?$/',
            'warehouse_id' => 'required|integer|exists:warehouses,id',
            'location_id' => 'required|integer|exists:locations,id',
            'remark' => 'nullable|string|max:200',
        ]);
    }
}
```

- [ ] **Step 4: 注册路由**

修改 `server/routes/api.php`：顶部 use 区追加 `use App\Http\Controllers\Api\OutsourcingController;`，并在退料单路由组之后追加：

```php
    // 委外加工：CRUD + 发出（审核）+ 回收（production.outsource.*；审核/回收复用 update）
    Route::middleware('auth:sanctum')->group(function () {
        Route::middleware('permission:production.outsource.list')->get('/production/outsourcings', [OutsourcingController::class, 'index']);
        Route::middleware('permission:production.outsource.create')->post('/production/outsourcings', [OutsourcingController::class, 'store']);
        Route::middleware('permission:production.outsource.list')->get('/production/outsourcings/{outsourcing}', [OutsourcingController::class, 'show']);
        Route::middleware('permission:production.outsource.update')->put('/production/outsourcings/{outsourcing}', [OutsourcingController::class, 'update']);
        Route::middleware('permission:production.outsource.delete')->delete('/production/outsourcings/{outsourcing}', [OutsourcingController::class, 'destroy']);
        Route::middleware('permission:production.outsource.update')->post('/production/outsourcings/{outsourcing}/approve', [OutsourcingController::class, 'approve']);
        Route::middleware('permission:production.outsource.update')->post('/production/outsourcings/{outsourcing}/receipts', [OutsourcingController::class, 'storeReceipt']);
        Route::middleware('permission:production.outsource.list')->get('/production/outsourcings/{outsourcing}/receipts', [OutsourcingController::class, 'receipts']);
    });
```

- [ ] **Step 5: 跑测试确认通过**

Run: `cd server && php artisan test --filter="OutsourcingTest|ReturnListTest"`
Expected: OutsourcingTest 11 全部 PASS（核心不变式：发出-回收双变动、1522 回滚、分批回收、超收 1524、工序联动、幂等 1523）；ReturnListTest 8 回归绿。

- [ ] **Step 6: 提交**

```bash
cd /d/code/project/php-design && git add server/app/Http/Controllers/Api/OutsourcingController.php server/tests/Feature/OutsourcingTest.php server/routes/api.php
git commit -m "feat: 委外加工 API（发出防超卖 1522/回收闭环与工序联动 1524）"
```

---

## Task 9: 成品入库 API（CRUD + 审核联动工单完成）

**Files:**
- Create: `server/app/Http/Controllers/Api/FinishedInboundController.php`
- Create: `server/tests/Feature/FinishedInboundTest.php`
- Modify: `server/routes/api.php`

**Interfaces:**
- Consumes: Task 1/2 模型（FinishedInbound/FinishedInboundItem/ProductionOrder/WorkOrderOperation）；Task 2 DocumentSequenceService（type=fi）；`InventoryService::apply`（direction=+1，source_type=finished_inbound）；`InventoryException`（防御兜底）
- Produces: `GET/POST /api/v1/production/finished-inbounds`、`GET/PUT/DELETE /api/v1/production/finished-inbounds/{finishedInbound}`、`POST .../approve`（核心：事务内锁工单行防超量 1525 → InventoryService 写 finished_inbound 流水(+qty) → completed_qty 累计 → **末工序已完成且 completed_qty ≥ 计划数 → 工单自动已完成**）；错误码 1525-1528；明细为空、重复商品、数量≤0 → 422

- [ ] **Step 1: 写失败测试 `server/tests/Feature/FinishedInboundTest.php`**

```php
<?php

// 成品入库单接口测试：CRUD/审核加库存/防超量/成品一致性/工单自动完成/幂等（核心路径 100%）

namespace Tests\Feature;

use App\Models\BomHeader;
use App\Models\Category;
use App\Models\FinishedInbound;
use App\Models\InventoryBalance;
use App\Models\Location;
use App\Models\Process;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\Role;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WorkOrderOperation;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinishedInboundTest extends TestCase
{
    use RefreshDatabase;

    private string $token;
    private User $admin;
    private Warehouse $wh;
    private Location $b01;
    private Product $mat;
    private Product $fin;
    private ProductionOrder $order;
    private int $finishItemId; // finished_inbound_items 行 id（预填用）

    protected function setUp(): void
    {
        parent::setUp();
        $role = Role::create(['name' => '管理员', 'code' => 'admin']);
        $this->admin = User::create(['name' => '管理员', 'username' => 'admin', 'password' => 'admin123', 'status' => 1]);
        $this->admin->roles()->sync([$role->id]);
        $this->token = $this->admin->createToken('api')->plainTextToken;

        $this->wh = Warehouse::create(['name' => '主仓', 'code' => 'WH01', 'status' => 1]);
        $this->b01 = Location::create(['warehouse_id' => $this->wh->id, 'name' => 'B-01', 'code' => 'B-01', 'status' => 1]);
        $rawCat = Category::create(['name' => '原材料', 'parent_id' => 0]);
        $cat = Category::create(['name' => '成品', 'parent_id' => 0]);
        $unit = Unit::create(['name' => '个', 'code' => 'pc']);
        $this->mat = Product::create(['name' => '测试铝材', 'code' => 'MAT-001', 'type' => 'raw_material', 'category_id' => $rawCat->id, 'unit_id' => $unit->id, 'status' => 1]);
        $this->fin = Product::create(['name' => '成品B', 'code' => 'FIN-002', 'type' => 'finished', 'category_id' => $cat->id, 'unit_id' => $unit->id, 'status' => 1]);
        $bom = BomHeader::create(['code' => 'BOM-001', 'product_id' => $this->fin->id, 'version' => 'v1', 'quantity' => 1, 'status' => 1]);
        $bom->items()->create(['material_id' => $this->mat->id, 'quantity' => 2, 'unit_id' => $unit->id]);
        foreach ([['下料', 'CUT', 1], ['组装', 'ASSY', 2]] as [$name, $code, $sort]) {
            Process::create(['name' => $name, 'code' => $code, 'sort' => $sort, 'status' => 1]);
        }
        // 基线库存：FIN-002 @B-01=20（入库 10 → 30）
        app(InventoryService::class)->apply([
            ['product_id' => $this->fin->id, 'warehouse_id' => $this->wh->id, 'location_id' => $this->b01->id, 'direction' => 1, 'quantity' => 20, 'source_type' => 'purchase_inbound', 'source_id' => 0, 'source_no' => 'SEED', 'remark' => '测试基线'],
        ]);

        // 建单（FIN-002×10）→ 下达 → 开工 → 全部工序完成（completed_qty=0 未入库）
        $res = $this->withToken($this->token)->postJson('/api/v1/production/orders', [
            'product_id' => $this->fin->id, 'quantity' => 10, 'plan_date' => now()->toDateString(),
        ]);
        $res->assertJsonPath('code', 0);
        $this->order = ProductionOrder::where('no', $res->json('data.no'))->first();
        $this->withToken($this->token)->postJson("/api/v1/production/orders/{$this->order->id}/release")->assertJsonPath('code', 0);
        $this->withToken($this->token)->postJson("/api/v1/production/orders/{$this->order->id}/start")->assertJsonPath('code', 0);
        $this->order->operations()->update(['status' => WorkOrderOperation::STATUS_DONE]);
    }

    // 组装入库载荷（默认 FIN-002×10 满产）
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'order_id' => $this->order->id,
            'warehouse_id' => $this->wh->id,
            'location_id' => $this->b01->id,
            'items' => [
                ['product_id' => $this->fin->id, 'quantity' => 10],
            ],
        ], $overrides);
    }

    // 通过 API 建草稿入库单并返回单号
    private function createInbound(array $payload): string
    {
        $res = $this->withToken($this->token)->postJson('/api/v1/production/finished-inbounds', $payload);
        $res->assertJsonPath('code', 0);
        return $res->json('data.no');
    }

    public function test_store_creates_draft_with_no(): void
    {
        // 正常路径：草稿创建成功，单号 FI{date}-001
        $no = $this->createInbound($this->payload());
        $this->assertMatchesRegularExpression('/^FI\d{8}-001$/', $no);
        $fi = FinishedInbound::where('no', $no)->first();
        $this->assertSame(FinishedInbound::STATUS_DRAFT, $fi->status);
        $this->assertSame('10.00', $fi->items()->first()->quantity);
    }

    public function test_store_rejects_over_remaining_with_1525(): void
    {
        // 异常路径：入库量超剩余产量（11 > 10）→ 1525（草稿期即拦截）
        $this->withToken($this->token)->postJson('/api/v1/production/finished-inbounds', $this->payload(['items' => [
            ['product_id' => $this->fin->id, 'quantity' => 11],
        ]]))
            ->assertJsonPath('code', 1525)
            ->assertJsonPath('message', '入库数量超过工单剩余产量');
    }

    public function test_store_rejects_wrong_product_with_1526(): void
    {
        // 异常路径：入库商品与工单产品不一致 → 1526
        $this->withToken($this->token)->postJson('/api/v1/production/finished-inbounds', $this->payload(['items' => [
            ['product_id' => $this->mat->id, 'quantity' => 1],
        ]]))
            ->assertJsonPath('code', 1526)
            ->assertJsonPath('message', '入库商品与工单产品不一致');
    }

    public function test_approve_credits_inventory_and_completes_order(): void
    {
        // 核心不变式：审核后余额 20→30、finished_inbound 流水（direction=+1）、completed_qty 回写 10、
        // 末工序已完成且满产 → 工单自动「已完成」（completed_at 落库）
        $no = $this->createInbound($this->payload());
        $fi = FinishedInbound::where('no', $no)->first();
        $this->withToken($this->token)->postJson("/api/v1/production/finished-inbounds/{$fi->id}/approve")
            ->assertJsonPath('code', 0);
        $balance = InventoryBalance::where('product_id', $this->fin->id)->first();
        $this->assertSame('30.00', $balance->quantity);
        $this->assertDatabaseHas('inventory_movements', [
            'product_id' => $this->fin->id, 'direction' => 1, 'quantity' => '10.00',
            'balance_after' => '30.00', 'source_type' => 'finished_inbound', 'source_no' => $no,
        ]);
        $this->order->refresh();
        $this->assertSame('10.00', $this->order->completed_qty);
        $this->assertSame(ProductionOrder::STATUS_COMPLETED, $this->order->status);
        $this->assertNotNull($this->order->completed_at);
        $fi->refresh();
        $this->assertSame(FinishedInbound::STATUS_APPROVED, $fi->status);
        $this->assertSame('管理员', $fi->operator);
        $this->assertNotNull($fi->approved_at);
    }

    public function test_approve_partial_batch_keeps_order_producing(): void
    {
        // 边界路径：分批入库（4+6）——第一批后工单仍生产中；第二批满产自动完成
        $no1 = $this->createInbound($this->payload(['items' => [
            ['product_id' => $this->fin->id, 'quantity' => 4],
        ]]));
        $fi1 = FinishedInbound::where('no', $no1)->first();
        $this->withToken($this->token)->postJson("/api/v1/production/finished-inbounds/{$fi1->id}/approve")->assertJsonPath('code', 0);
        $this->order->refresh();
        $this->assertSame('4.00', $this->order->completed_qty);
        $this->assertSame(ProductionOrder::STATUS_PRODUCING, $this->order->status);

        $no2 = $this->createInbound($this->payload(['items' => [
            ['product_id' => $this->fin->id, 'quantity' => 6],
        ]]));
        $fi2 = FinishedInbound::where('no', $no2)->first();
        $this->withToken($this->token)->postJson("/api/v1/production/finished-inbounds/{$fi2->id}/approve")->assertJsonPath('code', 0);
        $this->order->refresh();
        $this->assertSame('10.00', $this->order->completed_qty);
        $this->assertSame(ProductionOrder::STATUS_COMPLETED, $this->order->status);
    }

    public function test_approve_rejects_when_remaining_shrunk_with_1525_rollback(): void
    {
        // 核心不变式：两张草稿各 10 均在剩余 10 时创建（草稿期合法）；先审第一张（completed 10 剩余 0）
        // → 审核第二张超量 1525 整体回滚（审核期锁工单行复核）
        $no1 = $this->createInbound($this->payload());
        $no2 = $this->createInbound($this->payload());
        $fi1 = FinishedInbound::where('no', $no1)->first();
        $fi2 = FinishedInbound::where('no', $no2)->first();
        $this->withToken($this->token)->postJson("/api/v1/production/finished-inbounds/{$fi1->id}/approve")->assertJsonPath('code', 0);
        $res = $this->withToken($this->token)->postJson("/api/v1/production/finished-inbounds/{$fi2->id}/approve");
        $res->assertJsonPath('code', 1525)
            ->assertJsonPath('message', '入库数量超过工单剩余产量');
        // 回滚验证：余额仍 30（第二张未入）、第二张仍草稿
        $this->assertSame('30.00', InventoryBalance::where('product_id', $this->fin->id)->first()->quantity);
        $this->assertDatabaseMissing('inventory_movements', ['source_no' => $no2]);
        $this->assertSame(FinishedInbound::STATUS_DRAFT, $fi2->refresh()->status);
    }

    public function test_approve_idempotent_with_1528(): void
    {
        // 核心不变式：重复审核 → 1528，库存不重复变动
        $no = $this->createInbound($this->payload());
        $fi = FinishedInbound::where('no', $no)->first();
        $this->withToken($this->token)->postJson("/api/v1/production/finished-inbounds/{$fi->id}/approve")->assertJsonPath('code', 0);
        $this->withToken($this->token)->postJson("/api/v1/production/finished-inbounds/{$fi->id}/approve")
            ->assertJsonPath('code', 1528)
            ->assertJsonPath('message', '该成品入库单已审核');
        $this->assertSame('30.00', InventoryBalance::where('product_id', $this->fin->id)->first()->quantity);
    }

    public function test_update_and_destroy_draft_ok_approved_rejected_with_1527(): void
    {
        // 正常+异常路径：草稿可改可删；已审核不可改删 → 1527
        $no = $this->createInbound($this->payload());
        $id = FinishedInbound::where('no', $no)->first()->id;
        $this->withToken($this->token)->putJson("/api/v1/production/finished-inbounds/{$id}", $this->payload(['remark' => '改后']))
            ->assertJsonPath('code', 0);
        $this->withToken($this->token)->deleteJson("/api/v1/production/finished-inbounds/{$id}")
            ->assertJsonPath('code', 0);
        $this->assertDatabaseMissing('finished_inbounds', ['id' => $id]);

        $no2 = $this->createInbound($this->payload());
        $fi2 = FinishedInbound::where('no', $no2)->first();
        $this->withToken($this->token)->postJson("/api/v1/production/finished-inbounds/{$fi2->id}/approve")->assertJsonPath('code', 0);
        $this->withToken($this->token)->putJson("/api/v1/production/finished-inbounds/{$fi2->id}", $this->payload())
            ->assertJsonPath('code', 1527);
        $this->withToken($this->token)->deleteJson("/api/v1/production/finished-inbounds/{$fi2->id}")
            ->assertJsonPath('code', 1527);
    }

    public function test_index_with_labels_and_requires_permission(): void
    {
        // 正常路径：列表含工单单号/成品名/状态标签
        $no = $this->createInbound($this->payload());
        $fi = FinishedInbound::where('no', $no)->first();
        $this->withToken($this->token)->postJson("/api/v1/production/finished-inbounds/{$fi->id}/approve")->assertJsonPath('code', 0);
        $this->withToken($this->token)->getJson('/api/v1/production/finished-inbounds')
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.items.0.order_no', 'MO'.date('Ymd').'-001')
            ->assertJsonPath('data.items.0.product_name', '成品B')
            ->assertJsonPath('data.items.0.quantity', '10.00')
            ->assertJsonPath('data.items.0.status_label', '已审核');
        // 异常路径：无 production.finished.list 权限的角色被拒（403 JSON 信封）
        $role = Role::create(['name' => '普通', 'code' => 'plain']);
        $u = User::create(['name' => '普通', 'username' => 'plain', 'password' => 'admin123', 'status' => 1]);
        $u->roles()->sync([$role->id]);
        $token = $u->createToken('api')->plainTextToken;
        $this->withToken($token)->getJson('/api/v1/production/finished-inbounds')->assertStatus(403);
    }
}
```

- [ ] **Step 2: 跑测试确认失败**

Run: `cd server && php artisan test --filter=FinishedInboundTest`
Expected: FAIL（控制器/路由不存在）。

- [ ] **Step 3: 实现 FinishedInboundController**

创建 `server/app/Http/Controllers/Api/FinishedInboundController.php`：

```php
<?php

// 成品入库单控制器：草稿 CRUD + 审核（核心：事务内锁工单行防超量 1525 + InventoryService 写 finished_inbound 流水(+qty)
// + completed_qty 累计 + 满产自动完成工单）

namespace App\Http\Controllers\Api;

use App\Exceptions\InventoryException;
use App\Exceptions\ProductionException;
use App\Http\Controllers\Controller;
use App\Models\DocumentSequence;
use App\Models\FinishedInbound;
use App\Models\FinishedInboundItem;
use App\Models\ProductionOrder;
use App\Models\WorkOrderOperation;
use App\Services\DocumentSequenceService;
use App\Services\InventoryService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FinishedInboundController extends Controller
{
    use ApiResponse;

    public function __construct(
        private InventoryService $inventoryService,
        private DocumentSequenceService $sequenceService,
    ) {}

    /** 分页列表：单号/仓库/状态 筛选；含工单单号/成品名与状态标签 */
    public function index(Request $request)
    {
        $query = FinishedInbound::query()
            ->join('production_orders', 'production_orders.id', '=', 'finished_inbounds.order_id')
            ->join('products', 'products.id', '=', 'production_orders.product_id')
            ->select(
                'finished_inbounds.*',
                'production_orders.no as order_no',
                'products.name as product_name',
                'products.code as product_code',
            )
            ->orderByDesc('finished_inbounds.id');

        if ($keyword = $request->input('keyword')) {
            $query->where('finished_inbounds.no', 'like', "%{$keyword}%");
        }
        if ($request->filled('status')) {
            $query->where('finished_inbounds.status', (int) $request->input('status'));
        }
        if ($request->filled('date_from')) {
            $query->whereDate('finished_inbounds.created_at', '>=', $request->input('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('finished_inbounds.created_at', '<=', $request->input('date_to'));
        }

        $rows = $query->paginate(max(1, min(100, (int) $request->input('per_page', 10))));

        return $this->ok([
            // join 别名列经 getAttribute 读取（PHPStan 静态分析可识别）；数量 = Σ 明细行（单成品语义取首行）
            'items' => $rows->map(fn (FinishedInbound $f) => [
                'id' => $f->id,
                'no' => $f->no,
                'order_id' => $f->order_id,
                'order_no' => $f->getAttribute('order_no'),
                'product_id' => $f->order_id ? $f->order?->product_id : null,
                'product_name' => $f->getAttribute('product_name'),
                'product_code' => $f->getAttribute('product_code'),
                'quantity' => $f->items()->sum('quantity'),
                'warehouse_id' => $f->warehouse_id,
                'warehouse_name' => $f->warehouse?->name,
                'location_id' => $f->location_id,
                'location_name' => $f->location?->name,
                'status' => (int) $f->status,
                'status_label' => FinishedInbound::STATUS_LABELS[$f->status] ?? '未知',
                'approved_at' => $f->approved_at?->toDateTimeString(),
                'operator' => $f->operator,
                'created_at' => $f->created_at?->toDateTimeString(),
            ]),
            'total' => $rows->total(), 'page' => $rows->currentPage(), 'per_page' => $rows->perPage(),
        ]);
    }

    /** 新建草稿：入库量 ≤ 剩余产量（1525）；成品必须与工单产品一致（1526）；明细为空/重复/数量≤0 422 */
    public function store(Request $request)
    {
        $data = $this->validatePayload($request);
        if ($fail = $this->validateBusinessItems($data)) {
            return $fail;
        }
        if (! $request->filled('warehouse_id') || ! $request->filled('location_id')) {
            return $this->fail(422, '仓库与库位不能为空');
        }
        // 草稿期校验：成品一致性（1526）+ 剩余产量（1525）
        $order = ProductionOrder::find($data['order_id']);
        if (! $order) {
            return $this->fail(422, '工单不存在');
        }
        if ($msg = $this->validateItems($order, $data['items'])) {
            [$code, $message] = $msg;
            return $this->fail($code, $message);
        }

        $fi = DB::transaction(function () use ($data) {
            $fi = $this->sequenceService->nextNo(
                DocumentSequence::TYPE_FI,
                'FI',
                fn (string $no) => FinishedInbound::create([
                    'no' => $no,
                    'order_id' => $data['order_id'],
                    'status' => FinishedInbound::STATUS_DRAFT,
                    'warehouse_id' => $data['warehouse_id'],
                    'location_id' => $data['location_id'],
                    'remark' => $data['remark'] ?? null,
                ]),
                fn () => (int) (FinishedInbound::where('no', 'like', 'FI'.date('Ymd').'-%')
                    ->get('no')->map(fn ($f) => (int) substr((string) $f->no, -3))->max() ?? 0),
            );
            $fi->items()->createMany(array_map(fn ($i) => [
                'product_id' => $i['product_id'],
                'quantity' => $i['quantity'],
            ], $data['items']));

            return $fi;
        });

        return $this->ok(['no' => $fi->no]);
    }

    /** 详情：头信息 + 明细（成品名/数量）+ 工单剩余产量（编辑弹窗数据源） */
    public function show(FinishedInbound $finishedInbound)
    {
        $order = $finishedInbound->order;

        return $this->ok([
            'id' => $finishedInbound->id,
            'no' => $finishedInbound->no,
            'order_id' => $finishedInbound->order_id,
            'order_no' => $order?->no,
            'status' => (int) $finishedInbound->status,
            'status_label' => FinishedInbound::STATUS_LABELS[$finishedInbound->status] ?? '未知',
            'warehouse_id' => $finishedInbound->warehouse_id,
            'warehouse_name' => $finishedInbound->warehouse?->name,
            'location_id' => $finishedInbound->location_id,
            'location_name' => $finishedInbound->location?->name,
            'approved_at' => $finishedInbound->approved_at?->toDateTimeString(),
            'operator' => $finishedInbound->operator,
            'remark' => $finishedInbound->remark,
            // 剩余产量 = 计划数 - 已完工（bcmath 精确）
            'remaining_qty' => $order ? bcsub((string) $order->quantity, (string) $order->completed_qty, 2) : '0',
            'items' => $finishedInbound->items()->with('product')->get()->map(fn (FinishedInboundItem $i) => [
                'id' => $i->id,
                'product_id' => $i->product_id,
                'product_name' => $i->product?->name,
                'product_code' => $i->product?->code,
                'quantity' => $i->quantity,
            ]),
        ]);
    }

    /** 更新草稿：仅草稿（1527）；校验同 store；事务内锁行复查防并发 */
    public function update(Request $request, FinishedInbound $finishedInbound)
    {
        try {
            if ($finishedInbound->status !== FinishedInbound::STATUS_DRAFT) {
                return $this->fail(1527, '已审核单据不可修改');
            }
            $data = $this->validatePayload($request);
            if ($fail = $this->validateBusinessItems($data)) {
                return $fail;
            }
            if (! $request->filled('warehouse_id') || ! $request->filled('location_id')) {
                return $this->fail(422, '仓库与库位不能为空');
            }
            $order = ProductionOrder::find($data['order_id']);
            if (! $order) {
                return $this->fail(422, '工单不存在');
            }
            if ($msg = $this->validateItems($order, $data['items'])) {
                [$code, $message] = $msg;
                return $this->fail($code, $message);
            }

            DB::transaction(function () use ($finishedInbound, $data) {
                // 锁入库单行复查状态（幂等 1527）
                $locked = FinishedInbound::whereKey($finishedInbound->id)->lockForUpdate()->firstOrFail();
                if ($locked->status !== FinishedInbound::STATUS_DRAFT) {
                    throw new ProductionException('已审核单据不可修改', 1527);
                }
                $locked->update([
                    'order_id' => $data['order_id'],
                    'warehouse_id' => $data['warehouse_id'],
                    'location_id' => $data['location_id'],
                    'remark' => $data['remark'] ?? $locked->remark,
                ]);
                // 明细全量替换（草稿单无流水引用，直接重建）
                $locked->items()->delete();
                $locked->items()->createMany(array_map(fn ($i) => [
                    'product_id' => $i['product_id'],
                    'quantity' => $i['quantity'],
                ], $data['items']));
            });
        } catch (ProductionException $e) {
            // 1527 已审核（锁行复查与并发审核幂等拦截）
            return $this->fail($e->getCode() ?: 1527, $e->getMessage());
        }

        return $this->ok();
    }

    /** 删除草稿：仅草稿（1527）；事务内锁行复查防并发 */
    public function destroy(FinishedInbound $finishedInbound)
    {
        try {
            if ($finishedInbound->status !== FinishedInbound::STATUS_DRAFT) {
                return $this->fail(1527, '已审核单据不可删除');
            }
            DB::transaction(function () use ($finishedInbound) {
                // 锁入库单行复查状态（幂等 1527）
                $locked = FinishedInbound::whereKey($finishedInbound->id)->lockForUpdate()->firstOrFail();
                if ($locked->status !== FinishedInbound::STATUS_DRAFT) {
                    throw new ProductionException('已审核单据不可删除', 1527);
                }
                $locked->delete();
            });
        } catch (ProductionException $e) {
            // 1527 已审核（锁行复查与并发审核幂等拦截）
            return $this->fail($e->getCode() ?: 1527, $e->getMessage());
        }

        return $this->ok();
    }

    /**
     * 审核（核心）：事务内「锁单幂等 1528 → 锁工单行复核剩余产量 1525 → InventoryService 写 finished_inbound 流水(+qty)
     * → completed_qty 累计 → 末工序已完成且满产 → 工单自动已完成」任一步失败整体回滚
     */
    public function approve(FinishedInbound $finishedInbound)
    {
        try {
            $result = null;
            DB::transaction(function () use ($finishedInbound, &$result) {
                // 锁入库单行：同一单据重复审核在此判重（幂等 1528）
                $locked = FinishedInbound::whereKey($finishedInbound->id)->lockForUpdate()->firstOrFail();
                if ($locked->status === FinishedInbound::STATUS_APPROVED) {
                    throw new ProductionException('该成品入库单已审核', 1528);
                }
                // 锁工单行：completed_qty 并发安全（多张 FI 同时审核串行化）
                $order = ProductionOrder::whereKey($locked->order_id)->lockForUpdate()->firstOrFail();
                $movements = [];
                $inboundTotal = '0';
                /** @var FinishedInboundItem $item */
                foreach ($locked->items as $item) {
                    // 成品一致性复核（草稿期后工单产品不可变，防御路径 1526）
                    if ($item->product_id !== $order->product_id) {
                        throw new ProductionException('入库商品与工单产品不一致', 1526);
                    }
                    // 剩余产量 = 计划数 - 已完工；本次超剩余 → 1525 整体回滚（防超量入库）
                    $remaining = bcsub((string) $order->quantity, (string) $order->completed_qty, 2);
                    if (bccomp((string) $item->quantity, $remaining, 2) > 0) {
                        throw new ProductionException('入库数量超过工单剩余产量', 1525);
                    }
                    $inboundTotal = bcadd($inboundTotal, (string) $item->quantity, 2);
                    $movements[] = [
                        'product_id' => $item->product_id,
                        'warehouse_id' => $locked->warehouse_id,
                        'location_id' => $locked->location_id,
                        'direction' => 1,
                        'quantity' => $item->quantity,
                        'source_type' => 'finished_inbound',
                        'source_id' => $locked->id,
                        'source_no' => $locked->no,
                        'remark' => '成品入库',
                    ];
                }
                // 统一引擎写流水+加余额（同事务双写）
                $this->inventoryService->apply($movements, auth()->id());
                // 工单 completed_qty 累计（bcmath）
                $order->completed_qty = bcadd((string) $order->completed_qty, $inboundTotal, 2);
                // 联动：末工序已完成且 completed_qty ≥ 计划数 → 工单自动「已完成」
                $lastDone = $order->operations()
                    ->orderByDesc('seq')->lockForUpdate()->first();
                $allDone = ! $order->operations()->where('status', '!=', WorkOrderOperation::STATUS_DONE)->exists();
                if ($allDone && bccomp((string) $order->completed_qty, (string) $order->quantity, 2) >= 0) {
                    $order->status = ProductionOrder::STATUS_COMPLETED;
                    $order->completed_at = now();
                }
                $order->save();
                // 置已审核 + 审核人/时间
                $locked->status = FinishedInbound::STATUS_APPROVED;
                $locked->operator = auth()->user()->name ?? '';
                $locked->approved_at = now();
                $locked->save();
                $result = ['no' => $locked->no];
            });
        } catch (ProductionException $e) {
            // 1528 幂等 / 1525 超剩余产量 / 1526 成品不一致（事务整体回滚）
            return $this->fail($e->getCode() ?: 1525, $e->getMessage());
        } catch (InventoryException $e) {
            // 余额引擎兜底（入库方向理论不触发，防御路径）
            return $this->fail(422, '入库失败，请重试');
        }

        return $this->ok($result);
    }

    // 载荷格式校验（422 仅格式层）；业务码在方法内检查
    private function validatePayload(Request $request): array
    {
        return $request->validate([
            'order_id' => 'required|integer|exists:production_orders,id',
            'warehouse_id' => 'nullable|integer|exists:warehouses,id',
            'location_id' => 'nullable|integer|exists:locations,id',
            'remark' => 'nullable|string|max:200',
            'items' => 'array',
            'items.*.product_id' => 'required|integer|exists:products,id',
            'items.*.quantity' => 'required|numeric|regex:/^-?\d+(\.\d{1,2})?$/',
        ]);
    }

    // 明细业务校验（store/update 共用）：空明细/数量≤0/重复商品 → 422（格式层；spec 码段满）
    private function validateBusinessItems(array $data): ?JsonResponse
    {
        $items = $data['items'] ?? [];
        if (empty($items)) {
            return $this->fail(422, '请至少添加一条明细');
        }
        $seen = [];
        foreach ($items as $item) {
            if ((float) $item['quantity'] <= 0) {
                return $this->fail(422, '入库数量必须大于 0');
            }
            if (isset($seen[$item['product_id']])) {
                return $this->fail(422, '明细存在重复商品');
            }
            $seen[$item['product_id']] = true;
        }

        return null;
    }

    // 草稿期校验：成品一致性（1526）+ 剩余产量（1525）；返回 [code, message] 或 null
    private function validateItems(ProductionOrder $order, array $items): ?array
    {
        $total = '0';
        foreach ($items as $item) {
            if ($item['product_id'] !== $order->product_id) {
                return [1526, '入库商品与工单产品不一致'];
            }
            $total = bcadd($total, (string) $item['quantity'], 2);
        }
        // 剩余产量 = 计划数 - 已完工（bcmath 精确）
        $remaining = bcsub((string) $order->quantity, (string) $order->completed_qty, 2);
        if (bccomp($total, $remaining, 2) > 0) {
            return [1525, '入库数量超过工单剩余产量'];
        }

        return null;
    }
}
```

- [ ] **Step 4: 注册路由**

修改 `server/routes/api.php`：顶部 use 区追加 `use App\Http\Controllers\Api\FinishedInboundController;`，并在委外路由组之后追加：

```php
    // 成品入库单：CRUD + 审核（production.finished.*；审核复用 update）
    Route::middleware('auth:sanctum')->group(function () {
        Route::middleware('permission:production.finished.list')->get('/production/finished-inbounds', [FinishedInboundController::class, 'index']);
        Route::middleware('permission:production.finished.create')->post('/production/finished-inbounds', [FinishedInboundController::class, 'store']);
        Route::middleware('permission:production.finished.list')->get('/production/finished-inbounds/{finishedInbound}', [FinishedInboundController::class, 'show']);
        Route::middleware('permission:production.finished.update')->put('/production/finished-inbounds/{finishedInbound}', [FinishedInboundController::class, 'update']);
        Route::middleware('permission:production.finished.delete')->delete('/production/finished-inbounds/{finishedInbound}', [FinishedInboundController::class, 'destroy']);
        Route::middleware('permission:production.finished.update')->post('/production/finished-inbounds/{finishedInbound}/approve', [FinishedInboundController::class, 'approve']);
    });
```

- [ ] **Step 5: 跑测试确认通过**

Run: `cd server && php artisan test --filter="FinishedInboundTest|OutsourcingTest"`
Expected: FinishedInboundTest 9 全部 PASS（核心不变式：入库+、1525 回滚、满产自动完成、幂等 1528、成品一致性）；OutsourcingTest 11 回归绿。

- [ ] **Step 6: 提交**

```bash
cd /d/code/project/php-design && git add server/app/Http/Controllers/Api/FinishedInboundController.php server/tests/Feature/FinishedInboundTest.php server/routes/api.php
git commit -m "feat: 成品入库 API（审核加库存/满产自动完成工单 1525-1528）"
```

---

## Task 10: 前端基座（productionApi + 6 路由 + 菜单 + 设计系统页覆盖）

**Files:**
- Create: `web/src/api/production.ts`（productionApi 命名导出单对象 + 全部接口类型）
- Create: `web/src/tests/production.api.test.ts`（Vitest API 层测试，镜像 `sales.api.test.ts` 模式）
- Modify: `web/src/router/index.ts`（追加 6 条生产路由）
- Modify: `web/src/layouts/MainLayout.vue`（追加「生产管理」菜单组）
- Create: `design-system/nexus-factory/pages/production.md`（ui-ux-pro-max 查询驱动的设计系统页覆盖）

**Interfaces:**
- Consumes: 后端 Task 1-9 全部接口（`/api/v1/production/*`）；`web/src/api/http.ts`；Task 11/12 页面依赖本 Task 的 `productionApi` 方法签名
- Produces: `productionApi` 全部方法（见下签名）；路由 `/production/orders|reports|picks|returns|outsourcings|finished-inbounds`（meta.permission 门控，懒加载）；菜单「生产管理」组 6 项；`design-system/nexus-factory/pages/production.md`（页面骨架/状态标签/交互细节，ui-ux-pro-max 表单 UX 落地：on-blur 校验/提交 loading/焦点可见/缺料警告条/步骤条视觉）

- [ ] **Step 1: 创建 `web/src/api/production.ts`**

```ts
// 生产 API 封装：生产工单 + 工序报工 + 领料/退料 + 委外 + 成品入库（草稿 CRUD/审核/状态流转/预填/回收）
import { http } from './http'

export interface ProductionOrderItem {
  id: number
  no: string
  product_id: number
  product_name: string
  product_code: string
  quantity: number
  completed_qty: number
  progress: number
  plan_date: string
  status: number
  status_label: string
  released_at: string | null
  completed_at: string | null
}

export interface ProductionMaterial {
  material_id: number
  material_name: string
  material_code: string
  required_qty: number
  issued_qty: number
  remaining_qty: number
}

export interface ProductionOperation {
  id: number
  seq: number
  process_id: number
  process_name: string
  process_code: string
  status: number
  status_label: string
  qualified_qty: number
  defective_qty: number
  hours: number
}

export interface ProductionOrderDetail {
  id: number
  no: string
  product_id: number
  product_name: string
  product_code: string
  quantity: number
  plan_date: string
  bom_id: number
  bom_code: string
  status: number
  status_label: string
  completed_qty: number
  progress: number
  released_at: string | null
  completed_at: string | null
  closed_at: string | null
  remark: string | null
  materials: ProductionMaterial[]
  operations: ProductionOperation[]
}

export interface ReleaseWarning {
  material_name: string
  material_code: string
  required: number
  stock: number
}

export interface OperationReportRecord {
  id: number
  operator: string | null
  qualified_qty: number
  defective_qty: number
  hours: number
  report_time: string
  remark: string | null
}

export interface PickItem {
  id: number
  no: string
  order_id: number
  order_no: string
  warehouse_id: number
  warehouse_name: string
  status: number
  status_label: string
  issue_status: number
  issue_status_label: string
  approved_at: string | null
  operator: string | null
  created_at: string
}

export interface FromOrderMaterial {
  product_id: number
  material_name: string
  material_code: string
  required_qty: number
  issued_qty: number
  remaining_qty: number
}

export interface FromOrderData {
  order_id: number
  order_no: string
  product_id: number
  product_name: string
  items: FromOrderMaterial[]
}

export interface ReturnItem {
  id: number
  no: string
  order_id: number
  order_no: string
  status: number
  status_label: string
  approved_at: string | null
  operator: string | null
  created_at: string
}

export interface OutsourcingItem {
  id: number
  no: string
  order_id: number
  order_no: string
  operation_id: number
  process_name: string
  supplier_id: number
  supplier_name: string
  quantity: number
  status: number
  status_label: string
  approved_at: string | null
  operator: string | null
  created_at: string
}

export interface OutsourcingDetail {
  id: number
  no: string
  order_id: number
  order_no: string
  operation_id: number
  process_name: string
  supplier_id: number
  supplier_name: string
  status: number
  status_label: string
  quantity: number
  received_qty: number
  approved_at: string | null
  operator: string | null
  remark: string | null
}

export interface OutsourcingReceiptRecord {
  id: number
  no: string
  quantity: number
  warehouse_name: string
  location_name: string
  received_at: string
  operator: string | null
}

export interface FinishedInboundItem {
  id: number
  no: string
  order_id: number
  order_no: string
  product_id: number
  product_name: string
  product_code: string
  quantity: number
  status: number
  status_label: string
  approved_at: string | null
  operator: string | null
  created_at: string
}

export interface ProductionOrderPayload {
  product_id: number
  quantity: number
  plan_date: string
  bom_id?: number
  remark?: string
}

export interface PickPayload {
  order_id: number
  warehouse_id: number
  location_id: number
  remark?: string
  items: { product_id: number; pick_qty: number }[]
}

export interface ReturnPayload {
  order_id: number
  pick_id?: number
  warehouse_id: number
  location_id: number
  remark?: string
  items: { product_id: number; quantity: number }[]
}

export interface OutsourcingPayload {
  order_id: number
  operation_id: number
  supplier_id: number
  warehouse_id: number
  location_id: number
  quantity: number
  remark?: string
}

export interface FinishedInboundPayload {
  order_id: number
  warehouse_id: number
  location_id: number
  remark?: string
  items: { product_id: number; quantity: number }[]
}

// 分页列表响应统一形状
interface PageResult<T> {
  items: T[]
  total: number
  page: number
  per_page: number
}

export const productionApi = {
  // 生产工单分页列表（单号/成品/状态/日期筛选）
  async orders(params: {
    page?: number
    per_page?: number
    keyword?: string
    product_id?: number
    status?: number
    date_from?: string
    date_to?: string
  }) {
    const { data } = await http.get('/production/orders', { params: { per_page: 10, ...params } })
    return data.data as PageResult<ProductionOrderItem>
  },
  // 工单详情（含物料需求 + 工序列表）
  async orderDetail(id: number) {
    const { data } = await http.get(`/production/orders/${id}`)
    return data.data as ProductionOrderDetail
  },
  // 新建草稿（响应单号）
  async createOrder(payload: ProductionOrderPayload) {
    const { data } = await http.post('/production/orders', payload)
    return data.data.no
  },
  // 更新草稿（物料/工序快照重建）
  async updateOrder(id: number, payload: ProductionOrderPayload) {
    await http.put(`/production/orders/${id}`, payload)
  },
  // 删除草稿
  async deleteOrder(id: number) {
    await http.delete(`/production/orders/${id}`)
  },
  // 下达（响应缺料警告列表，允许为空）
  async releaseOrder(id: number) {
    const { data } = await http.post(`/production/orders/${id}/release`)
    return data.data as { warnings: ReleaseWarning[] }
  },
  // 开工
  async startOrder(id: number) {
    await http.post(`/production/orders/${id}/start`)
  },
  // 完工
  async completeOrder(id: number) {
    await http.post(`/production/orders/${id}/complete`)
  },
  // 关闭
  async closeOrder(id: number) {
    await http.post(`/production/orders/${id}/close`)
  },
  // 物料需求（领料单预填数据源）
  async orderMaterials(id: number) {
    const { data } = await http.get(`/production/orders/${id}/materials`)
    return data.data as { items: ProductionMaterial[] }
  },
  // 工序报工
  async report(operationId: number, payload: { qualified_qty: number; defective_qty?: number; hours?: number; operator?: string; remark?: string }) {
    await http.post(`/production/operations/${operationId}/reports`, payload)
  },
  // 工序报工记录
  async operationReports(operationId: number) {
    const { data } = await http.get(`/production/operations/${operationId}/reports`)
    return data.data as PageResult<OperationReportRecord>
  },
  // 领料单分页列表
  async picks(params: { page?: number; per_page?: number; keyword?: string; status?: number }) {
    const { data } = await http.get('/production/picks', { params: { per_page: 10, ...params } })
    return data.data as PageResult<PickItem>
  },
  // 领料单详情
  async pickDetail(id: number) {
    const { data } = await http.get(`/production/picks/${id}`)
    return data.data as {
      id: number
      no: string
      order_id: number
      order_no: string
      status: number
      status_label: string
      issue_status: number
      issue_status_label: string
      warehouse_name: string
      location_name: string
      remark: string | null
      items: { id: number; product_id: number; product_name: string; product_code: string; required_qty: number; pick_qty: number; issued_qty: number }[]
    }
  },
  // 新建领料单（响应单号）
  async createPick(payload: PickPayload) {
    const { data } = await http.post('/production/picks', payload)
    return data.data.no
  },
  // 更新领料单草稿
  async updatePick(id: number, payload: PickPayload) {
    await http.put(`/production/picks/${id}`, payload)
  },
  // 删除领料单草稿
  async deletePick(id: number) {
    await http.delete(`/production/picks/${id}`)
  },
  // 审核领料单（扣原料库存）
  async approvePick(id: number) {
    const { data } = await http.post(`/production/picks/${id}/approve`)
    return data.data.no
  },
  // 发料（V1 一次发完 → 全部发料）
  async issuePick(id: number) {
    const { data } = await http.post(`/production/picks/${id}/issue`)
    return data.data as { issue_status: string }
  },
  // 从工单生成预填（物料需求剩余量）
  async fromOrderPicks(orderId: number) {
    const { data } = await http.get(`/production/picks/from-order/${orderId}`)
    return data.data as FromOrderData
  },
  // 退料单分页列表
  async returns(params: { page?: number; per_page?: number; keyword?: string; status?: number }) {
    const { data } = await http.get('/production/returns', { params: { per_page: 10, ...params } })
    return data.data as PageResult<ReturnItem>
  },
  // 新建退料单（响应单号）
  async createReturn(payload: ReturnPayload) {
    const { data } = await http.post('/production/returns', payload)
    return data.data.no
  },
  // 更新退料单草稿
  async updateReturn(id: number, payload: ReturnPayload) {
    await http.put(`/production/returns/${id}`, payload)
  },
  // 删除退料单草稿
  async deleteReturn(id: number) {
    await http.delete(`/production/returns/${id}`)
  },
  // 审核退料单（冲销已领）
  async approveReturn(id: number) {
    const { data } = await http.post(`/production/returns/${id}/approve`)
    return data.data.no
  },
  // 委外单分页列表
  async outsourcings(params: { page?: number; per_page?: number; keyword?: string; status?: number }) {
    const { data } = await http.get('/production/outsourcings', { params: { per_page: 10, ...params } })
    return data.data as PageResult<OutsourcingItem>
  },
  // 委外单详情（含已回收累计）
  async outsourcingDetail(id: number) {
    const { data } = await http.get(`/production/outsourcings/${id}`)
    return data.data as OutsourcingDetail
  },
  // 新建委外单（响应单号）
  async createOutsourcing(payload: OutsourcingPayload) {
    const { data } = await http.post('/production/outsourcings', payload)
    return data.data.no
  },
  // 更新委外单草稿
  async updateOutsourcing(id: number, payload: OutsourcingPayload) {
    await http.put(`/production/outsourcings/${id}`, payload)
  },
  // 删除委外单草稿
  async deleteOutsourcing(id: number) {
    await http.delete(`/production/outsourcings/${id}`)
  },
  // 发出（审核，扣成品库存）
  async approveOutsourcing(id: number) {
    const { data } = await http.post(`/production/outsourcings/${id}/approve`)
    return data.data.no
  },
  // 回收（创建即审核回收单，加成品库存）
  async receiptOutsourcing(id: number, payload: { quantity: number; warehouse_id: number; location_id: number; remark?: string }) {
    const { data } = await http.post(`/production/outsourcings/${id}/receipts`, payload)
    return data.data.no
  },
  // 委外回收记录
  async outsourcingReceipts(id: number) {
    const { data } = await http.get(`/production/outsourcings/${id}/receipts`)
    return data.data as PageResult<OutsourcingReceiptRecord>
  },
  // 成品入库单分页列表
  async finishedInbounds(params: { page?: number; per_page?: number; keyword?: string; status?: number }) {
    const { data } = await http.get('/production/finished-inbounds', { params: { per_page: 10, ...params } })
    return data.data as PageResult<FinishedInboundItem>
  },
  // 成品入库单详情（含剩余产量）
  async finishedInboundDetail(id: number) {
    const { data } = await http.get(`/production/finished-inbounds/${id}`)
    return data.data as {
      id: number
      no: string
      order_id: number
      order_no: string
      status: number
      status_label: string
      remaining_qty: number
      warehouse_name: string
      location_name: string
      remark: string | null
      items: { id: number; product_id: number; product_name: string; product_code: string; quantity: number }[]
    }
  },
  // 新建成品入库单（响应单号）
  async createFinishedInbound(payload: FinishedInboundPayload) {
    const { data } = await http.post('/production/finished-inbounds', payload)
    return data.data.no
  },
  // 更新成品入库单草稿
  async updateFinishedInbound(id: number, payload: FinishedInboundPayload) {
    await http.put(`/production/finished-inbounds/${id}`, payload)
  },
  // 删除成品入库单草稿
  async deleteFinishedInbound(id: number) {
    await http.delete(`/production/finished-inbounds/${id}`)
  },
  // 审核成品入库单（加成品库存 + 工单联动）
  async approveFinishedInbound(id: number) {
    const { data } = await http.post(`/production/finished-inbounds/${id}/approve`)
    return data.data.no
  },
}
```

- [ ] **Step 2: 创建 `web/src/tests/production.api.test.ts`（Vitest，镜像 sales.api.test.ts 模式）**

先读 `web/src/tests/sales.api.test.ts` 确认 mock 模式（vi.mock('@/api/http') + vi.fn() 断言 URL/参数/解包），然后同构编写：mock http 模块，逐方法断言请求路径、参数与返回值解包。覆盖全部 30 个方法（分组断言：工单 8、报工 2、领料 8、退料 4、委外 7、成品入库 5 至少各 1 条关键路径 + 核心方法全断言）。

```ts
// 生产 API 封装测试：请求路径/参数/响应解包（镜像 sales.api.test.ts 模式）
import { describe, expect, it, vi, beforeEach } from 'vitest'
import { productionApi } from '@/api/production'

// mock HTTP 模块：get/post/put/delete 各自返回 { data: 响应体外层 }，断言调用参数
vi.mock('@/api/http', () => {
  const http = { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() }
  return { http }
})
import { http } from '@/api/http'

describe('productionApi 工单', () => {
  beforeEach(() => vi.clearAllMocks())

  it('orders 分页列表：透传筛选参数并解包分页', async () => {
    vi.mocked(http.get).mockResolvedValue({ data: { data: { items: [], total: 0, page: 1, per_page: 10 } } })
    await productionApi.orders({ keyword: 'MO', status: 1 })
    expect(http.get).toHaveBeenCalledWith('/production/orders', { params: { per_page: 10, keyword: 'MO', status: 1 } })
  })

  it('createOrder 返回单号', async () => {
    vi.mocked(http.post).mockResolvedValue({ data: { data: { no: 'MO20260812-001' } } })
    const no = await productionApi.createOrder({ product_id: 1, quantity: 10, plan_date: '2026-08-12' })
    expect(http.post).toHaveBeenCalledWith('/production/orders', { product_id: 1, quantity: 10, plan_date: '2026-08-12' })
    expect(no).toBe('MO20260812-001')
  })

  it('releaseOrder 返回缺料警告列表', async () => {
    vi.mocked(http.post).mockResolvedValue({ data: { data: { warnings: [{ material_name: '铝材', required: 20, stock: 0 }] } } })
    const res = await productionApi.releaseOrder(1)
    expect(http.post).toHaveBeenCalledWith('/production/orders/1/release')
    expect(res.warnings).toHaveLength(1)
  })

  it('orderMaterials 解包物料需求', async () => {
    vi.mocked(http.get).mockResolvedValue({ data: { data: { items: [{ material_id: 1, required_qty: 20 }] } } })
    const res = await productionApi.orderMaterials(1)
    expect(http.get).toHaveBeenCalledWith('/production/orders/1/materials')
    expect(res.items[0].required_qty).toBe(20)
  })
})

describe('productionApi 单据', () => {
  beforeEach(() => vi.clearAllMocks())

  it('approvePick 审核领料单返回单号', async () => {
    vi.mocked(http.post).mockResolvedValue({ data: { data: { no: 'PL20260812-001' } } })
    const no = await productionApi.approvePick(1)
    expect(http.post).toHaveBeenCalledWith('/production/picks/1/approve')
    expect(no).toBe('PL20260812-001')
  })

  it('issuePick 返回发料状态文案', async () => {
    vi.mocked(http.post).mockResolvedValue({ data: { data: { issue_status: '全部发料' } } })
    const res = await productionApi.issuePick(1)
    expect(res.issue_status).toBe('全部发料')
  })

  it('fromOrderPicks 预填解包', async () => {
    vi.mocked(http.get).mockResolvedValue({ data: { data: { order_id: 1, order_no: 'MO20260812-001', items: [] } } })
    const res = await productionApi.fromOrderPicks(1)
    expect(http.get).toHaveBeenCalledWith('/production/picks/from-order/1')
    expect(res.order_no).toBe('MO20260812-001')
  })

  it('receiptOutsourcing 回收返回回收单号', async () => {
    vi.mocked(http.post).mockResolvedValue({ data: { data: { no: 'OSR20260812-001' } } })
    const no = await productionApi.receiptOutsourcing(1, { quantity: 5, warehouse_id: 1, location_id: 2 })
    expect(http.post).toHaveBeenCalledWith('/production/outsourcings/1/receipts', { quantity: 5, warehouse_id: 1, location_id: 2 })
    expect(no).toBe('OSR20260812-001')
  })

  it('approveFinishedInbound 审核成品入库返回单号', async () => {
    vi.mocked(http.post).mockResolvedValue({ data: { data: { no: 'FI20260812-001' } } })
    const no = await productionApi.approveFinishedInbound(1)
    expect(http.post).toHaveBeenCalledWith('/production/finished-inbounds/1/approve')
    expect(no).toBe('FI20260812-001')
  })
})
```

Run: `cd web && npx vitest run src/tests/production.api.test.ts`
Expected: PASS。

- [ ] **Step 3: 追加路由**

修改 `web/src/router/index.ts`：在销售路由后追加（懒加载 + meta.permission 门控，沿用既有模式）：

```ts
      { path: 'production/orders', name: 'production-orders', component: () => import('../views/production/OrdersView.vue'), meta: { permission: 'production.order.list' } },
      { path: 'production/reports', name: 'production-reports', component: () => import('../views/production/ReportsView.vue'), meta: { permission: 'production.report.list' } },
      { path: 'production/picks', name: 'production-picks', component: () => import('../views/production/PicksView.vue'), meta: { permission: 'production.pick.list' } },
      { path: 'production/returns', name: 'production-returns', component: () => import('../views/production/ReturnsView.vue'), meta: { permission: 'production.return.list' } },
      { path: 'production/outsourcings', name: 'production-outsourcings', component: () => import('../views/production/OutsourcingsView.vue'), meta: { permission: 'production.outsource.list' } },
      { path: 'production/finished-inbounds', name: 'production-finished-inbounds', component: () => import('../views/production/FinishedInboundsView.vue'), meta: { permission: 'production.finished.list' } },
```

- [ ] **Step 4: 追加菜单**

修改 `web/src/layouts/MainLayout.vue`：在「销售管理」菜单组后追加（沿用既有 menu-group + RouterLink + auth.has 模式）：

```html
      <div class="menu-group">生产管理</div>
      <RouterLink v-if="auth.has('production.order.list')" to="/production/orders" class="menu-item">生产工单</RouterLink>
      <RouterLink v-if="auth.has('production.pick.list')" to="/production/picks" class="menu-item">领料单</RouterLink>
      <RouterLink v-if="auth.has('production.return.list')" to="/production/returns" class="menu-item">退料单</RouterLink>
      <RouterLink v-if="auth.has('production.report.list')" to="/production/reports" class="menu-item">工序报工</RouterLink>
      <RouterLink v-if="auth.has('production.outsource.list')" to="/production/outsourcings" class="menu-item">委外加工</RouterLink>
      <RouterLink v-if="auth.has('production.finished.list')" to="/production/finished-inbounds" class="menu-item">成品入库</RouterLink>
```

- [ ] **Step 5: 创建设计系统页覆盖 `design-system/nexus-factory/pages/production.md`（ui-ux-pro-max 查询驱动）**

先读 `design-system/nexus-factory/pages/sales.md` 与 `pages/master-data.md` 确认既有格式（页覆盖规范：LOGIC 声明 + 依据行 + 分节），再用 ui-ux-pro-max 技能查询表单 UX 细节（搜索词示例：「element plus steps 表单状态反馈」「barcode 录入 focus 保持」「warning banner 缺料提示」），最后落盘。内容结构（全中文，与 sales.md 同构）：

```markdown
# 生产管理模块（pages/production.md 页覆盖）

> 依据：MASTER.md（Swiss Modernism 2.0）+ ui-ux-pro-max 表单 UX 查询（步骤条状态反馈/缺料警告条/on-blur 校验/提交 loading/焦点可见）
> LOGIC：本页存在时覆盖 MASTER.md 通用规范，仅对生产模块生效

## 1. 页面骨架（生产模块通用）
- .page-card/.page-title/.toolbar 与各模块一致；数量列 Fira Code 右对齐加粗
- 按钮文案全角空格：新 建/保 存/编 辑/删 除/审 核/发 料/下 达/开 工/完 工/关 闭/回 收/报 工
- 弹窗 900px；扫码自动聚焦（命中清空/未命中保留）
- 状态标签（el-tag）：
  - 工单五态：草稿灰 #6B7280 / 已下达蓝 #3B82F6 / 生产中琥珀 #D97706 / 已完成深绿 #047857（tag-done）/ 关闭红 #DC2626
  - 工序三态：待开工灰 / 进行中蓝 / 已完成绿
  - 领料/退料/成品入库两态：草稿灰 / 已审核绿；发料三态：未发料灰 / 部分发料琥珀 / 全部发料绿
  - 委外三态：草稿灰 / 已审核蓝 / 已回收绿

## 2. 生产工单页（/production/orders）
- 列表：单号/成品/计划数/完工数/进度（el-progress 圆角条，完成率 %）/计划日期/状态/操作
- 操作分流：草稿→「编 辑/删 除/下 达」；已下达→「开 工/详 情」；生产中→「领 料/退 料/报 工/委 外/成品入库/详 情」；已完成→「关 闭/详 情」
- 新建弹窗：成品*（el-select 仅成品，选中后自动校验启用 BOM，无则 1501 错误提示）、数量*、计划日期*、备注
- 保存后「展开确认」弹窗：只读展示 BOM 展开的物料需求（需求数量）与工序序列（seq+工序名）——确认后提交
- 下达：confirm「确认下达工单 MO…？」→ release → 缺料 warnings 用琥珀色警告条展示（不阻断）
- 详情弹窗 tab：①物料需求（需求/已领/剩余，剩余=需求-已领）②工序流转（seq/工序/状态/合格/不良/工时）③报工记录（按工序切换）

## 3. 工序报工页（/production/reports）
- 顶部选择工单（el-select，可搜单号）→ 工序步骤条（el-steps：待开工/进行中/已完成三态着色）
- 当前进行中工序卡片：合格数*（el-input-number 2 位小数）、不良数*（旁注「不良数仅记录与统计，返修/报废流程后续版本提供」）、工时*、操作人、备注
- 「提 交报工」→ 成功 ElMessage「报工成功」→ 步骤条自动推进（本工序完成/下一工序进行中）
- 校验：合格数 on-blur 即时校验 ≤ 计划数；提交 loading 防重复提交

## 4. 领料单页（/production/picks）
- 列表：单号/工单/仓库/状态/发料状态标签/操作
- 新建「从工单生成」：选工单（el-select）→ 预填物料剩余行 → 行内填本次领用量（≤ 剩余，on-blur 校验）→ 仓库/库位 → 保存
- 审核：confirm「确认审核领料单 PL…？审核后库存将减少」→ approve → 1515 库存不足红色错误提示（含商品编码）
- 发料：审核后「发 料」→ confirm → issue → 发料状态标签更新

## 5. 退料单页（/production/returns）
- 列表：单号/工单/状态/操作；新建：选工单 → 物料行（数量 ≤ 已领，on-blur 校验）→ 仓库/库位
- 审核：confirm「确认审核退料单 RL…？审核后库存将增加并冲销已领」→ approve

## 6. 委外页（/production/outsourcings）
- 列表：单号/工单/委外工序/供应商/数量/状态三态/操作
- 新建：选工单 → 委外工序下拉（仅该工单工序）→ 供应商 → 数量 → 仓库/库位
- 发出：confirm「确认发出委外物料？库存将减少」→ approve
- 回收：已审核单「回 收」→ 弹窗填回收量（≤ 委外量-已回收，显示剩余可收）+ 入库仓库/库位 → 提交 → 自动生成回收单并审核 → 状态「已回收」

## 7. 成品入库页（/production/finished-inbounds）
- 列表：单号/工单/成品/数量/状态/操作
- 新建：选工单 → 自动带出成品行（数量默认=剩余产量，可改 ≤ 剩余）→ 仓库/库位 → 保存
- 审核：confirm「确认审核成品入库单 FI…？审核后成品库存将增加且工单进度更新」→ approve → 满产自动完成工单

## 8. 交互细节（ui-ux-pro-max 表单 UX 落地）
- 所有输入 on-blur/即时校验；提交按钮 loading + 成功反馈；错误提示醒目（danger 色）且含业务信息
- el-steps 状态着色与工单工序状态联动；缺料警告条琥珀色不阻断（信息分层：警告≠错误）
- 数量输入 el-input-number precision=2；扫码框自动聚焦保持；弹窗关闭二次确认（同销售未实施项标注）
```

- [ ] **Step 6: 前端门禁 + 提交**

Run: `cd web && npm run type-check && npm run lint && npm run lint:css && npm run format:check && npx vitest run`
Expected: 全部 PASS（含新 production.api.test.ts）。

```bash
cd /d/code/project/php-design && git add web/src/api/production.ts web/src/tests/production.api.test.ts web/src/router/index.ts web/src/layouts/MainLayout.vue design-system/nexus-factory/pages/production.md
git commit -m "feat: 生产前端基座（productionApi 30 方法/6 路由/菜单组/设计系统页 ui-ux-pro-max 驱动）"
```

---

## Task 11: 前端页面 A（生产工单 + 工序报工）

**Files:**
- Create: `web/src/views/production/OrdersView.vue`（~700 行：列表 + 新建/BOM 展开确认 + 下达警告 + 详情 tabs）
- Create: `web/src/views/production/ReportsView.vue`（~350 行：选工单 → 步骤条 → 报工卡片）

**Interfaces:**
- Consumes: Task 10 `productionApi` 全部方法；`auth.has()` 按钮级权限；`format.ts`；`productApi`/`bomApi`（成品下拉）
- Produces: 两个可交互页面（E2E Task 13 按文案定位：新 建/保 存/下 达/开 工/详 情/提 交报工）

**页面结构（两页均为 script setup + template + scoped style，与 `web/src/views/sales/OrdersView.vue` 同构——先读该文件确认骨架后照写）：**

- [ ] **Step 1: 创建 `web/src/views/production/OrdersView.vue`**

核心结构与关键代码（样式遵循 sales/OrdersView.vue；以下为必须实现的关键点，非完整文件——完整文件由 implementer 按既有页面骨架补齐）：

**script 部分关键点：**
1. 状态 ref：`loading/saving/list/total/products/warehouses/locations`；弹窗态 `dialogVisible/editing/editingId/expandVisible/expandData/detailVisible/detail`；筛选 `reactive({ keyword, product_id, status, page, per_page })`；表单 `reactive({ product_id, quantity, plan_date, remark })`
2. 状态标签五态 `statusTagType`（草稿 info/已下达 primary/生产中 warning/已完成 success/关闭 danger）
3. 成品下拉：`productApi.products({ type: 'finished' })`（仅成品；选成品后自动校验启用 BOM——`bomApi.boms({ product_id })` 无结果则 ElMessage.error 1501 文案并清空选择）
4. 保存前校验链：成品 → 数量>0 → 计划日期；POST 后**展开确认**：`POST /production/orders` 成功返回单号 → 立即 `orderDetail(id)` 拿 materials+operations → `expandVisible=true` 只读展示（物料表：名称/编码/需求数量；工序列表：seq/名称）→ 确认后关闭并刷新列表
5. 下达：`ElMessageBox.confirm('确认下达工单 '+row.no+'？')` → `releaseOrder(id)` → `data.warnings` 非空时 `warningVisible=true` 展示琥珀警告条（`el-alert type="warning"`，逐行「材料名：需求 X / 库存 Y」）；空则 ElMessage.success('下达成功')
6. 开工/完工/关闭：confirm → API → ElMessage → 刷新
7. 操作分流（v-if 按状态 + auth.has）：草稿→编 辑/删 除/下 达；已下达→开 工/详 情；生产中→领 料/退 料/报 工/委 外/成品入库/详 情（前五项为跳转链接：`router.push('/production/picks?order_id='+row.id)` 等；报工跳 `/production/reports?order_id=`）；已完成→关 闭/详 情
8. 详情弹窗（900px）：el-tabs ①物料需求（el-table：物料/编码/需求/已领/剩余，剩余列加粗）②工序流转（el-table：seq/工序/状态 el-tag/合格/不良/工时）③报工记录（el-table 按工序下拉切换 → `operationReports(opId)`）
9. 新建弹窗提交后清空表单并 focus 商品下拉

**template 关键点（骨架参照 sales/OrdersView.vue）：**

```html
<div class="page-card">
  <div class="toolbar">
    <div class="page-title">生产工单</div>
    <el-select v-model="filter.product_id" placeholder="成品" clearable class="filter-item" @change="loadList">...</el-select>
    <el-select v-model="filter.status" placeholder="状态" clearable class="filter-item" @change="loadList">
      <el-option v-for="(label, key) in STATUS_OPTIONS" :key="key" :label="label" :value="Number(key)" />
    </el-select>
    <div class="spacer" />
    <el-button v-if="auth.has('production.order.create')" class="btn-primary" @click="openCreate">新 建</el-button>
  </div>
  <el-table v-loading="loading" :data="list">
    <el-table-column prop="no" label="单号" width="150" class-name="font-code" />
    <el-table-column prop="product_name" label="成品" min-width="120" />
    <el-table-column prop="quantity" label="计划数" width="100" align="right" class-name="font-code" />
    <el-table-column prop="completed_qty" label="完工数" width="100" align="right" class-name="font-code" />
    <el-table-column label="进度" width="160">
      <template #default="{ row }">
        <el-progress :percentage="row.progress" :stroke-width="8" :status="row.progress >= 100 ? 'success' : undefined" />
      </template>
    </el-table-column>
    <el-table-column prop="plan_date" label="计划日期" width="110" />
    <el-table-column label="状态" width="100">
      <template #default="{ row }"><el-tag :type="statusTagType(row.status)">{{ row.status_label }}</el-tag></template>
    </el-table-column>
    <el-table-column label="操作" width="240" fixed="right">
      <template #default="{ row }">
        <!-- 草稿：编辑/删除/下达；已下达：开工/详情；生产中：跳转入口/详情；已完成：关闭/详情 -->
      </template>
    </el-table-column>
  </el-table>
  <!-- 展开确认弹窗：只读展示 BOM 展开物料与工序 -->
  <el-dialog v-model="expandVisible" title="BOM 展开确认" width="900px" :close-on-click-modal="false">
    <el-alert type="info" :closable="false" title="工单已创建（草稿），以下为 BOM 展开结果，确认后进入列表操作" />
    <div class="section-title">物料需求</div>
    <el-table :data="expandData.materials" size="small">...</el-table>
    <div class="section-title">工序序列</div>
    <el-table :data="expandData.operations" size="small">...</el-table>
    <template #footer><el-button @click="expandVisible = false">确 定</el-button></template>
  </el-dialog>
  <!-- 详情弹窗：tabs 物料/工序/报工记录 -->
  <el-dialog v-model="detailVisible" title="工单详情" width="900px">...</el-dialog>
</div>
```

**scoped style 关键点：** `.section-title { font-family: Fira Code; font-size: 13px; color: #334155; margin: 16px 0 8px; }`、警告条 `margin-bottom: 12px`、进度条 `width: 140px`。

- [ ] **Step 2: 创建 `web/src/views/production/ReportsView.vue`**

**script 关键点：**
1. 状态：`orders/loading/saving/selectedOrder/operations/currentOp/reportForm/reportVisible`；路由 query `order_id` 直达（`route.query.order_id` → 选中并加载）
2. 工单下拉：`productionApi.orders({ status: 2 })`（仅生产中工单；label 用单号+成品）
3. 加载工序：`orderDetail(id)` → `detail.operations`；**当前进行中工序** = `operations.find(o => o.status === 1)`（无进行中则全部完成——提示「工序已全部完成」）
4. 报工表单：`reactive({ qualified_qty: null, defective_qty: 0, hours: null, operator: '', remark: '' })`；提交校验链：合格数 ≥ 0 且 ≤ 计划数（on-blur 校验，超计划 ElMessage.warning 1510 文案）→ 工时 ≥ 0（1512 文案）
5. 提交：`report(currentOp.id, payload)` → ElMessage.success('报工成功') → 重新加载工序（步骤条自动推进）→ 表单重置；提交按钮 `:loading="saving"` 防重复
6. 步骤条：`el-steps` + `el-step`（status 由工序状态映射：待开工→wait / 进行中→process / 已完成→finish），`:active` 动态计算

**template 关键点：**

```html
<div class="page-card">
  <div class="toolbar">
    <div class="page-title">工序报工</div>
    <el-select v-model="selectedOrder" placeholder="选择工单" filterable class="filter-item" @change="loadOperations">
      <el-option v-for="o in orders" :key="o.id" :label="`${o.no}（${o.product_name}）`" :value="o.id" />
    </el-select>
  </div>
  <!-- 步骤条：工序三态联动 -->
  <el-steps v-if="operations.length" :active="activeStep" align-center class="steps-bar">
    <el-step v-for="op in operations" :key="op.id" :title="`${op.seq}. ${op.process_name}`" :status="stepStatus(op.status)" :description="op.status_label" />
  </el-steps>
  <!-- 当前进行中工序报工卡片 -->
  <div v-if="currentOp" class="report-card">
    <div class="card-title">当前工序：{{ currentOp.process_name }}（已报合格 {{ currentOp.qualified_qty }} / 计划 {{ orderQuantity }}）</div>
    <el-form :model="reportForm" label-width="90px" class="form-grid">
      <el-form-item label="合格数"><el-input-number v-model="reportForm.qualified_qty" :min="0" :precision="2" :controls="false" placeholder="≥0" @blur="validateQualified" /></el-form-item>
      <el-form-item label="不良数"><el-input-number v-model="reportForm.defective_qty" :min="0" :precision="2" :controls="false" /></el-form-item>
      <el-form-item label="工时"><el-input-number v-model="reportForm.hours" :min="0" :precision="2" :controls="false" placeholder="小时" @blur="validateHours" /></el-form-item>
      <el-form-item label="操作人"><el-input v-model="reportForm.operator" maxlength="50" /></el-form-item>
    </el-form>
    <div class="hint">不良数仅记录与统计，返修/报废流程后续版本提供</div>
    <div class="footer-bar">
      <el-button class="btn-primary" :loading="saving" @click="submitReport">提 交报工</el-button>
    </div>
  </div>
  <el-empty v-else-if="operations.length" description="工序已全部完成" />
</div>
```

- [ ] **Step 3: 门禁 + 手动验证**

Run: `cd web && npm run type-check && npm run lint && npx vitest run`
Expected: 全部 PASS。

Run: 手动 smoke（可选，后端跑起后）：`cd server && php artisan serve` + `cd web && npm run dev` → 登录 → 生产管理菜单可见 → 工单页新建弹窗成品下拉仅成品、选 FIN-002 校验 BOM → 保存后展开确认弹窗展示物料+工序。

- [ ] **Step 4: 提交**

```bash
cd /d/code/project/php-design && git add web/src/views/production/OrdersView.vue web/src/views/production/ReportsView.vue
git commit -m "feat: 生产前端页面 A（工单列表/BOM 展开确认/下达警告/详情 tabs + 报工步骤条）"
```

---

## Task 12: 前端页面 B（领料 + 退料 + 委外 + 成品入库）

**Files:**
- Create: `web/src/views/production/PicksView.vue`（~550 行：列表 + 从工单生成 + 审核/发料）
- Create: `web/src/views/production/ReturnsView.vue`（~400 行：列表 + 新建 + 审核）
- Create: `web/src/views/production/OutsourcingsView.vue`（~550 行：列表 + 新建 + 发出 + 回收弹窗）
- Create: `web/src/views/production/FinishedInboundsView.vue`（~450 行：列表 + 新建 + 审核）

**Interfaces:**
- Consumes: Task 10 `productionApi`；`auth.has()`；`format.ts`；warehouseApi/locationApi/supplierApi（下拉数据源）；`route.query.order_id` 跨页直达（工单页跳转入口）
- Produces: 四个可交互页面（E2E Task 13 按文案定位：新 建/保 存/审 核/发 料/回 收/提 交）

**四页共用的「从工单生成」模式（Picks/Finished 双入口，镜像销售 OutboundsView.vue）：** 路由 query `order_id` 存在 → 打开新建弹窗并自动加载预填；`el-select` 工单下拉数据源：Picks 用 `orderDetail` 物料剩余（生产中工单），Finished 用生产中工单列表 + `remaining_qty`。

- [ ] **Step 1: 创建 `web/src/views/production/PicksView.vue`**

**script 关键点：**
1. 状态：`loading/saving/list/total/warehouses/locations/orders`；弹窗 `dialogVisible/editing/editingId/mode`（'from-order' | 'standalone'——**复用销售 1406 教训：独立新建不开放**，V1 仅「从工单生成」入口）；表单 `reactive({ order_id, warehouse_id, location_id, remark, items: [] })`；筛选 `reactive({ keyword, status, page, per_page })`
2. 工单下拉：`productionApi.orders({ status: 2 })`（生产中工单）→ 选中后 `fromOrderPicks(order_id)` 预填：items 行 = `{ product_id, material_name, material_code, remaining_qty, pick_qty: remaining_qty }`（默认全量，可改）；行内 `pick_qty` on-blur 校验 ≤ remaining_qty（超量 ElMessage.warning 1513 文案并回弹剩余值）
3. 保存校验链：工单 → 仓库/库位 → 明细非空 → 每行 pick_qty > 0 且 ≤ 剩余；载荷 `{ order_id, warehouse_id, location_id, remark, items: [{ product_id, pick_qty }] }`
4. 审核：confirm('确认审核领料单 '+row.no+'？审核后库存将减少') → `approvePick(id)` → 成功 ElMessage.success('审核成功') + 刷新；失败（1515 等）http 层 ElMessage.error 展示业务消息（含商品编码）
5. 发料：仅已审核且未全部发料行显示「发 料」→ confirm → `issuePick(id)` → ElMessage.success('发料成功') + 刷新（发料状态标签更新）
6. 操作分流：草稿→编 辑/删 除/审 核；已审核→发 料（未发料/部分发料时）/删 除隐藏
7. 详情弹窗：头信息 + 明细（商品/需求/本次领用/已发）

**template 关键点：** 列表列（单号 font-code/工单 order_no/仓库/状态 el-tag/发料状态 el-tag（未发料 info/部分发料 warning/全部发料 success）/操作）；新建弹窗 900px（工单 select → 明细 el-table 行内 `el-input-number :max="row.remaining_qty"` → 仓库/库位双列）；「从工单生成」弹窗标题含预填工单单号。

- [ ] **Step 2: 创建 `web/src/views/production/ReturnsView.vue`**

**script 关键点：**
1. 状态：`loading/saving/list/total/warehouses/locations/orders`；弹窗 `dialogVisible/editing/editingId`；表单 `reactive({ order_id, pick_id: null, warehouse_id, location_id, remark, items: [] })`
2. 选工单 → `fromOrderPicks(order_id)` 预填物料行（**注意：退料预填取 `issued_qty`（已领）而非剩余**——行内 `{ product_id, material_name, material_code, issued_qty, quantity: issued_qty }` 默认全退可改；`quantity` on-blur 校验 ≤ issued_qty，超量 ElMessage.warning 1517 文案）
3. 保存校验链：工单 → 仓库/库位 → 明细非空 → 每行 quantity > 0 且 ≤ 已领
4. 审核：confirm('确认审核退料单 '+row.no+'？审核后库存将增加并冲销已领') → `approveReturn(id)` → 刷新
5. 操作分流：草稿→编 辑/删 除/审 核；已审核无操作
6. 列表列：单号/工单/仓库/状态/审核人/操作

- [ ] **Step 3: 创建 `web/src/views/production/OutsourcingsView.vue`**

**script 关键点：**
1. 状态：`loading/saving/list/total/orders/suppliers/warehouses/locations/processOptions`；弹窗 `dialogVisible/editing/editingId/receiptVisible/receiptForm/receiptRemaining`；表单 `reactive({ order_id, operation_id, supplier_id, warehouse_id, location_id, quantity, remark })`
2. 新建：选工单（生产中工单列表）→ 委外工序下拉 = 该工单工序（`orderDetail(order_id).operations`，label `seq. 工序名`，仅展示未完成工序）→ 供应商下拉（`supplierApi.suppliers()`）→ 数量（≤ 工单计划数，on-blur 校验 1520 文案）→ 仓库/库位
3. 发出：confirm('确认发出委外物料？库存将减少') → `approveOutsourcing(id)` → 刷新；失败（1522）红色错误提示
4. 回收：已审核行「回 收」→ `outsourcingDetail(id)` 拿 `received_qty` → 弹窗：可回收量 = `quantity - received_qty`（展示「剩余可回收 X」）、回收量输入（≤ 剩余，on-blur 校验 1524 文案）、入库仓库/库位（独立选择）→ 提交 `receiptOutsourcing(id, {...})` → ElMessage.success('回收成功') + 刷新
5. 操作分流：草稿→编 辑/删 除/审 核（发出）；已审核→回 收/回收记录；已回收→回收记录
6. 列表列：单号/工单/委外工序/供应商/数量/状态三态 el-tag/操作

- [ ] **Step 4: 创建 `web/src/views/production/FinishedInboundsView.vue`**

**script 关键点：**
1. 状态：`loading/saving/list/total/warehouses/locations/orders`；弹窗 `dialogVisible/editing/editingId`；表单 `reactive({ order_id, warehouse_id, location_id, remark, items: [] })`
2. 选工单（生产中工单列表，label `单号（成品名）`）→ 自动带出成品行：`orderDetail(order_id)` → `items = [{ product_id: detail.product_id, product_name, quantity: remaining_qty }]`（默认=剩余产量，可改；`quantity` on-blur 校验 ≤ 剩余 1525 文案）→ 仓库/库位
3. 保存校验链：工单 → 仓库/库位 → 明细非空 → quantity > 0 且 ≤ 剩余产量
4. 审核：confirm('确认审核成品入库单 '+row.no+'？审核后成品库存将增加且工单进度更新') → `approveFinishedInbound(id)` → 刷新
5. 操作分流：草稿→编 辑/删 除/审 核；已审核无操作
6. 列表列：单号/工单/成品/数量/状态/审核人/操作

- [ ] **Step 5: 门禁 + 提交**

Run: `cd web && npm run type-check && npm run lint && npx vitest run`
Expected: 全部 PASS。

```bash
cd /d/code/project/php-design && git add web/src/views/production/PicksView.vue web/src/views/production/ReturnsView.vue web/src/views/production/OutsourcingsView.vue web/src/views/production/FinishedInboundsView.vue
git commit -m "feat: 生产前端页面 B（领料/退料/委外/成品入库，从工单生成双入口）"
```

---

## Task 13: E2E 全量测试（Playwright TC-PRD-01~10 + 1113 删除保护补测）

**Files:**
- Create: `web/e2e/production.spec.ts`（TC-PRD-01~10 全流程 + TC-MST 1113 工序删除保护补测）
- Modify: `docs/test/2026-08-12-生产管理模块端到端测试.md`（§5 测试结果记录表回填 + 实际断言修正）
- Modify: `web/e2e/inventory.spec.ts` 或共享 helper（如需——流水页 source_type 筛选已支持 pick/return/finished_inbound/outsourcing_out/outsourcing_in，无需改动；核对 `source_no` 参数已支持）

**Interfaces:**
- Consumes: Task 1-12 全部前后端实现；`web/e2e/helpers.ts`（loginByAPI）+ 各 spec 既有 apiGet/apiPost 模式（token 取 localStorage + page.request）；种子数据（InventorySeeder：MAT-001@A-01=100、SEMI-001@A-01=30、FIN-002@B-01=20；BOM(FIN-002, 启用版) MAT-001×2；工序 3 个；供应商 SUP-001）
- Produces: 生产模块全流程 E2E（11 个用例），验收标准 = 文档 TC-PRD-01~10 全部通过 + 1113 工序删除保护补测；**E2E 数据流调整**（与销售同源产品决策）：生产 E2E 建工单用 API 直建（`apiPost('/api/v1/production/orders', ...)`），工序若种子缺失则 API 自建（镜像销售客户自建模式）

**E2E 文件骨架（先读 `web/e2e/sales.spec.ts` 与 `web/e2e/purchase.spec.ts` 确认 helper 与断言模式后照写）：**

- [ ] **Step 1: 创建 `web/e2e/production.spec.ts`**

文件结构（约 900 行，10 个用例串行 + 1113 补测）：

```ts
// 生产管理模块 E2E：完整生产闭环（工单→下达→开工→领料→报工→委外→成品入库→退料→关闭）+ 边界拦截 + 幂等
import { test, expect, Page } from '@playwright/test'
import { loginByAPI } from './helpers'

// —— 与 sales.spec.ts 同构的 API 辅助（token 取 localStorage + page.request）——
async function apiGet(page: Page, url: string, params: Record<string, string | number> = {}) { /* 同 sales.spec.ts */ }
async function apiPost(page: Page, url: string, body?: unknown) { /* 同 sales.spec.ts，返回 {code,message,data} */ }
async function pickOption(page: Page, name: string) { /* 同 sales.spec.ts：唯一匹配后点击 */ }

test.describe.configure({ mode: 'serial', timeout: 90_000 })  // 生产闭环链路长，比销售 60s 放宽

test.describe('生产管理模块 E2E（TC-PRD-01~10 + 1113 补测）', () => {
  // 共享状态：余额基线 P1（MAT-001）/F1（FIN-002）、工单 id、单据 id（describe 顶层声明，用例间传递）
  test.beforeAll(async ({ request }) => { await loginByAPI(...) })  // 或按 sales 模式每用例前登录

  test('TC-PRD-01 工单创建与 BOM 展开', async ({ page }) => { /* 见下方步骤明细 */ })
  test('TC-PRD-02 下达与开工', async ({ page }) => { /* ... */ })
  test('TC-PRD-03 领料审核与发料', async ({ page }) => { /* ... */ })
  test('TC-PRD-04 工序报工与自动流转', async ({ page }) => { /* ... */ })
  test('TC-PRD-05 报工边界拦截', async ({ page }) => { /* ... */ })
  test('TC-PRD-06 委外发出与回收', async ({ page }) => { /* ... */ })
  test('TC-PRD-07 成品入库与工单自动完成', async ({ page }) => { /* ... */ })
  test('TC-PRD-08 完工校验与关闭', async ({ page }) => { /* ... */ })
  test('TC-PRD-09 退料冲销', async ({ page }) => { /* ... */ })
  test('TC-PRD-10 领料库存不足拦截', async ({ page }) => { /* ... */ })
  test('TC-MST-1113 工序删除保护补测', async ({ page }) => { /* ... */ })
})
```

**TC-PRD-01~10 用例步骤明细（严格按 E2E 文档，页面操作与 API 断言混合）：**

- **TC-PRD-01**：登录 → `GET /api/v1/production/orders` 为空或记录 → 页面前往 `/production/orders` → 点「新 建」→ 弹窗成品下拉仅成品（断言无 MAT-001 选项，FIN-002 存在）→ 选 FIN-002（BOM 校验通过）→ 数量 10、计划日期今天 → 保 存 → 断言响应 code 0 + no 匹配 `MO\d{8}-\d{3}` → 展开确认弹窗：物料需求 tab 显示 MAT-001 需求 20、工序列表 3 行待开工 → 确 定 → 列表出现草稿工单 → 详情（GET）断言 materials[0].required_qty=20、operations 3 行待开工。**边界**：`POST` 无 BOM 成品（SEMI-001）→ 1501
- **TC-PRD-02**：列表点「下 达」→ confirm → `POST release` → 断言 code 0（warnings 空或非空均可，按当时 MAT-001 余额）→ 状态「已下达」；重复下达（fetch）→ 1505「工单已下达」；点「开 工」→ 状态「生产中」+ 详情工序步骤条首工序「进行中」；重复开工 → 1506
- **TC-PRD-03**：记录 MAT-001 余额 P₁ → 工单详情「领 料」跳转 → `/production/picks` 弹窗预填 MAT-001 需求 20 剩余 20 → 领用 20 → 改 25 → 前端拦截（1513 文案）→ 恢复 20 → 仓库主仓/A-01 → 保 存 → 单号 PL.. → 审 核（confirm「库存将减少」）→ 断言余额 = P₁-20、`GET /inventory/movements?source_type=pick&source_no=PL..` 流水 -20 → 发 料 → 断言响应 `{"issue_status":"全部发料"}` → 详情物料 tab 已领 20 剩余 0
- **TC-PRD-04**：`/production/reports` 选工单 → 步骤条下料=进行中 → 合格 10 不良 0 工时 2.5 提 交报工 → ElMessage「报工成功」→ 步骤条下料=已完成、组装=进行中 → 组装报 4（未完成仍进行中）→ 再报 6 → 组装完成、质检进行中 → 质检报 10 → 完成 → `GET /operations/{op1}/reports` 断言 1 条记录
- **TC-PRD-05**：质检（已完成）再报 → 1509「该工序当前不可报工」；对进行中工序报合格 11 → 1510；合格 8+不良 5 → 1511；工时 -1 → 1512（或 422，按实现——工时负为 1512 业务码）
- **TC-PRD-06**：API 建工单 MO-002（FIN-002×5）→ 下达开工 → `/production/outsourcings` 新 建：选 MO-002、委外工序=组装、供应商 SUP-001、数量 5、主仓/A-01 → 保 存 → OS.. 草稿 → 审 核（confirm「库存将减少」）→ 断言 FIN-002 余额 -5（outsourcing_out 流水）→ 回 收：弹窗填 5、仓库主仓/B-01 → 提 交 → 委外单「已回收」+ FIN-002 余额 +5（outsourcing_in 流水，单号 OSR..）→ 再回收 1 → 1524「回收数量超过委外数量」→ 已回收单重复审核（fetch）→ 1523
- **TC-PRD-07**：MO-001 全部工序已完成、completed_qty=0 → 工单详情「成品入库」→ `/production/finished-inbounds` 预填成品行数量 10 → 改 11 → 1525 拦截 → 改 10 → 主仓/B-01 → 保 存 → FI.. 草稿 → 审 核（confirm「成品库存将增加」）→ 断言 FIN-002 余额 = F₁+10（含委外后的基数，按当时记录）、finished_inbound 流水 +10 → 列表刷新 MO-001 状态**自动「已完成」**进度 100% → 重复审核（fetch）→ 1528
- **TC-PRD-08**：API 建工单 MO-003（FIN-002×5）→ 下达 → 不报工直接 `POST complete`（fetch）→ 1507「存在未完成工序，无法完工」→ 全部工序置完成（API 报工 5 次）→ 不入库 `POST complete` → 1508 → MO-001（已完成）点「关 闭」→ 状态「关闭」→ 对已关闭 MO-001 `POST release`（fetch）→ 1505、`POST start` → 1506
- **TC-PRD-09**：MO-001 已领 MAT-001 20 → `/production/returns` 新 建：选 MO-001、MAT-001 数量 2、主仓/A-01 → 保 存 → RL.. → 改 25 → 1517 拦截 → 改 2 → 审 核 → 断言 MAT-001 余额 +2（return 流水）、物料 tab 已领 20→18 → 重复审核 → 1519
- **TC-PRD-10**：用库存模块工具（销售出库/盘点）将 MAT-001 余额清至 <20（记录当时余额）→ API 建领料单（MO-003 需求 MAT-001 10）草稿 → 审 核 → 1515「商品[MAT-001]库存不足」+ 断言余额不变、无流水（fetch 断言响应后回滚验证 `GET /inventory/balances`）→ 补库存（采购入库或销售出库恢复）→ 重新审核 → 0
- **TC-MST-1113 补测**：API 建工单（挂工序）→ 页面 `/master/processes` 对该工序点「删 除」→ 断言后端响应 code 1113「工序已被生产工单使用，不可删除」→ 页面不消失

- [ ] **Step 2: 回填 E2E 文档 §5 测试结果记录表**

修改 `docs/test/2026-08-12-生产管理模块端到测试.md` §5：按实际结果回填（TC-PRD-01~10 + 1113 全部「通过」+ 失败详情留空或记录修复引用）；若 E2E 文档与实现有出入（如断言细节），按「实际可达流程」修正文档并加注（镜像销售 TC-SAL-05 处理）。

- [ ] **Step 3: 本地跑全量 E2E**

Run: `cd web && npx playwright test`
Expected: 既有 43 用例（inventory/purchase/sales）+ 新增 11 用例全部 PASS（**门禁勿与 E2E 并行**——同机 CPU 争用会饿死 vite 响应导致 login 超时；如遇 login 超时重跑单用例）。

- [ ] **Step 4: 最终门禁（本地模拟云端，顺序执行勿并行）**

Run:
```bash
cd web && npm run type-check && npm run lint && npm run lint:css && npm run format:check && npm run test:unit
cd ../server && vendor/bin/phpstan analyse --no-progress --memory-limit=1G && vendor/bin/phpcs -q; S=$?; [ $S -ne 0 ] && [ $S -ne 2 ] && exit $S; vendor/bin/pint --test && php artisan test
cd ../web && npx playwright test
```
Expected: 全绿（phpstan 0 错误、phpcs 退出码 2 放行、PHPUnit 全量 + 生产新增约 90 用例、Vitest 全量、E2E 54 用例）。

- [ ] **Step 5: 提交**

```bash
cd /d/code/project/php-design && git add web/e2e/production.spec.ts docs/test/2026-08-12-生产管理模块端到测试.md
git commit -m "test: 生产模块 E2E（TC-PRD-01~10 + 1113 工序删除保护补测）全绿"
```

---

## 计划自审记录（Self-Review）

**1. Spec 覆盖核对（production-spec §4/§5/§7 逐项）：**
- §4.1 工单 9 接口 → Task 3/4（含 release 缺料警告、complete 双前置）；§4.2 报工 2 接口 → Task 5；§4.3 领料 7 接口 → Task 6（含 from-order/issue）；§4.4 退料 4 接口 → Task 7；§4.5 委外 5 接口 → Task 8（含回收创建即审核）；§4.6 成品入库 4 接口 → Task 9
- 错误码 1501-1528 全部分配并落地（1502 业务码按 spec、close 复用 1505 已标注决策）
- PRD-01~14 全部有测试用例（结构/状态机/边界/幂等/联动）
- §5 页面 6 个 → Task 11/12；§6 业务流转（委外工序联动/领退料链/不良 V1）→ Task 8/5 落地
- 前置依赖（BOM/工序/供应商）→ 测试 setUp 全量自建，E2E 依赖种子

**2. 占位符扫描：** 无 TBD/TODO；Task 11/12 前端页面为「结构 + 关键代码片段」形态（与销售计划 Task 6/7 同构——页面骨架模式已在 Global Constraints 与既有页面文件固化，implementer 参照实际文件补齐模板部分），关键交互（展开确认/警告条/步骤条/双入口/回收弹窗）均有代码级描述。

**3. 类型一致性核对：**
- `ProductionOrderService::expandBom` 返回 `{materials:[{material_id,required_qty}], operations:[{process_id,seq}]}`——Task 3 store/update 消费一致；`progress(string,string):float` Task 3 index/show 消费一致
- `ProductionException(消息, 业务码)` 六控制器统一；`InventoryService::apply` 调用（direction ±1/source_type 5 种/source_no 含 OSR 回补）一致
- 模型常量：ProductionOrder 五态、WorkOrderOperation 三态、PickList STATUS/ISSUE、OutsourcingOrder 三态、OutsourcingReceipt 恒 1——Task 3-9 引用一致
- DocumentSequence 常量 6 个（mo/pl/rl/os/osr/fi）与路由前缀 MO/PL/RL/OS/OSR/FI 一致
- 前端 `productionApi` 方法名与后端路由/响应字段一致（Task 11/12 页面消费签名已列）

**4. 关键决策记录（与 spec 不一致处均标注）：** production_order_materials 补充表、1515/1522 用商品编码、close 复用 1505、issue 一次置全部发料、委外商品=工单成品、缺料警告全仓汇总只读。
