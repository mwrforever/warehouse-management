# 仪表盘模块 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 实现仪表盘模块（登录默认落地页 `/dashboard`，一屏聚合运营关键信息）：后端 4 个 `GET /api/v1/dashboard/*` 只读聚合接口（KPI 汇总/待审核单据/工单进度/库存预警，数据源为余额/流水/工单/各模块草稿单据既有业务表，零迁移零新表）+ 前端 DashboardView 完整页（4 KPI 卡 + 待审核列表 + 工单进度 + 库存预警，4 接口并行独立加载容错），通过全部 PHPUnit/Vitest 测试与 E2E 测试文档 `docs/test/2026-08-12-仪表盘模块端到端测试.md` 的 TC-DSH-01~08。

**Architecture:** 前后端分离，完全复用既有基座：统一响应 `{code,message,data}`、`ApiResponse` trait、`permission:` 中间件、Sanctum 认证、bcmath 精度约定。**零迁移零新表零新依赖**——仪表盘为纯读聚合，唯一新代码是 `DashboardService`（聚合查询）+ `DashboardController`（薄契约层）+ 前端 `dashboardApi` + DashboardView 重写。**权限设计刻意偏离「operator 自动持有全部 %.list」惯例的相反方向**：`dashboard.view` 一项权限**显式授予 operator**（仪表盘是登录默认落地页，TC-DSH-07 锁定 limited01 必须可见 KPI）——待审核数据的安全语义由接口内部按 9 个审核权限（审核复用 update）过滤保证，operator 无任何审核权限 → 待审核数为 0/列表为空。**前端复用报表模块 KPI 卡样式**（白底/8px 圆角/shadow-sm/16px padding/12px 标签/Fira Code 24px bold），无图表组件（spec §4 无 echarts 需求，不引入新依赖）。

**Tech Stack:** PHP 8.5.9、Laravel 13.25.0、MySQL 8.4（Docker）/SQLite（测试库）、bcmath（数量/金额字符串运算）、Vue 3 + TypeScript + Vite + Pinia + Vue Router + Element Plus 2.14.4 + @element-plus/icons-vue（已装）、PHPUnit、Vitest、@playwright/test（web/e2e/）。

## Global Constraints

以下约束对每个 Task 隐式生效（来自仪表盘细化 spec §3/§4/§5/§6/§7 + 前序模块沉淀约定，逐条原文/决策说明）：

- 统一响应：`{code, message, data}`；`code=0` 成功。**本模块无业务错误码**（4 接口全部无参数、无校验——只读聚合无冲突场景）；401 未登录/403 无权限由既有中间件产出（403 响应体 `{code:403,message:'无权限操作'}` 零改动），422 不可达
- **权限（TC-DSH-07 锁定，与前序模块的偏差点，勿「顺手对齐」）**：1 项权限 `dashboard.view`（group=仪表盘），追加 `RbacSeeder`；**operator 显式持有 dashboard.view**（修改 operator sync 为 `where('code','like','%.list')->orWhere('code','dashboard.view')`——仪表盘为登录默认落地页，limited01 必须能加载 KPI；若照抄报表模块「不带 .list 则 operator 不持有」，TC-DSH-07「KPI 卡片正常显示」必然失败）。admin 经全量 sync 自动持有。**待审核数据的安全语义靠接口内部过滤**：`pending-approvals` 与 `summary.pending_approvals` 仅统计当前用户持有对应审核权限（审核复用 update，9 个权限码）的模块单据——operator 无任何审核权限 → 恒为 0/空列表（TC-DSH-07「后端按权限过滤」据此断言）。**连带修改**：`ReportStructureTest::test_operator_does_not_hold_report_permissions` 的「operator 权限全以 .list 结尾」断言循环必须排除 `dashboard.view`（否则该既有测试失败——这是本模块权限设计的必然连带，非顺手重构）
- **待审核单据口径（9 类，锁定）**：`purchase_orders(采购/订单)/purchase_inbounds(采购/入库单)/sales_orders(销售/订单)/sales_outbounds(销售/出库单)/inventory_checks(库存/盘点单)/pick_lists(生产/领料单)/return_lists(生产/退料单)/outsourcing_orders(生产/委外单)/finished_inbounds(生产/成品入库单)`，各 status=草稿（STATUS_DRAFT=0），对应审核权限（审核复用 update）：`purchase.order.update/purchase.inbound.update/sales.order.update/sales.outbound.update/check.update/production.pick.update/production.return.update/production.outsource.update/production.finished.update`；对应前端路由（后端下发 url 字段）：`/purchase/orders、/purchase/inbounds、/sales/orders、/sales/outbounds、/inventory/checks、/production/picks、/production/returns、/production/outsourcings、/production/finished-inbounds`。**生产工单草稿不入待审核**——其流转动作是「下达」而非「审核」（spec 口径「待审核=草稿+有 approve 权限」，工单的 update 语义是 release；E2E 计数口径与后端一致，勿把 production_orders 计入）
- **KPI 口径（spec §5 + 报表模块同款，测试必须核对一致）**：
  - `inventory_total_qty` = 全部余额行 quantity Σ（bcadd 2 位）
  - `inventory_value` = Σ(余额 × 最近一次采购入库单价) ÷ 100 元（成本价估算与报表模块同口径：`purchase_inbound_items` 按 created_at DESC, id DESC 取每商品首条单价；**无任何已知成本价时 value=null**「未启用成本核算」，不显示 ¥0；部分商品有价时仅对已知行求和）
  - `today_inbound_qty`/`today_outbound_qty` = 流水 `created_at` 当天闭区间（Carbon::today() startOfDay~endOfDay）direction=1/-1 Σ
  - `pending_approvals` = 上述 9 类草稿**按权限过滤后**的总数（与列表接口同源）
  - `work_order_running` = `production_orders.status=STATUS_PRODUCING(2)` 计数
  - `alert_count` = 低库存（level=1：safety_min>0 且 quantity<safety_min）条数——**高库存不占仪表盘**（spec §7）
  - 口径「今日」= created_at 当天 00:00~23:59；工单进度 = 生产中(2)与已完成(3)工单
- **列表口径（spec §3）**：`pending-approvals` items=[{module,type,no,created_at(Y-m-d H:i:s),url}]，按创建时间倒序全局排序、**最多 20 条**（单类型先各取 20 再合并排序——某类型第 21 条不可能进入全局前 20）；`work-order-progress` items=[{no,product_name,quantity,completed_qty,progress,status,status_label}]（status_label 为响应扩展字段，前端展示状态标签用），status∈{2,3}，按 updated_at 倒序、**最多 10 条**，`progress = completed_qty/quantity×100`（4 位中间精度输出 2 位字符串，quantity=0 防御 → "0.00"）；`alerts` items=[{product_name,product_code,warehouse_name,quantity,safety_min}]，**仅 level=1 低库存、按 product_id 升序前 10 条**（与库存预警页 inventory/alerts 同排序，保证「前 10」两处一致）
- **数量/金额精度**：一切运算 bcmath（bcadd/bcmul/bcdiv/bccomp），比率 4 位中间精度输出 2 位；输出金额/数量/进度一律**字符串**（JSON 不丢尾零）；分转元后端完成（bcdiv(,100,2)）
- **SQLite 兼容**：phpunit/E2E 跑 sqlite（外键开启、DECIMAL 编译为 NUMERIC）——**禁用任何数据库方言函数**，日期边界 PHP 侧 Carbon 完成；测试插外键数据先建真实主数据；phpunit 权限用例须 `$this->app['auth']->forgetGuards()`（同 app 实例 guard 缓存）；测试中覆盖 created_at/updated_at 用「先 create 后直接属性赋值再 save」——fillable 不保证含时间戳字段，禁依赖 create 传时间戳
- 只读模块：全部 GET、无参数、无审核无事务无锁（无锁序风险）；不落快照、实时聚合、无缓存
- 中文注释（类级/方法级/关键行，业务意图非翻译）；UTF-8 无 BOM；LF 行尾（.gitattributes 已强制）；无死代码（未使用 import 零容忍）；聚合口径=数据一致性路径（核心功能）→ **DashboardService 单测 100% 覆盖**，测试命名表达业务意图，覆盖正常/边界/异常
- 前端：侧边栏深色 `#0F172A`（220px）、内容区 `#F8FAFC`、主色 `#334155`、强调绿 `#059669`、红 `#DC2626`、琥珀 `#D97706`、深绿 `#047857`；Fira Code+Fira Sans；页面骨架 `.page-card`；**无写操作按钮、无手动刷新按钮**（挂载即并行请求 4 接口）；KPI 卡复用报表模块样式（`design-system/nexus-factory/pages/report.md` §2）；方向色编码：入库绿 + 前缀 `+`、出库红 + 前缀 `-`、待审核琥珀；所有可点击元素 `cursor:pointer`；过渡 150-300ms；**禁止 emoji 图标**（绿勾/右箭头用 @element-plus/icons-vue 的 SVG 图标组件）；单号/数量/进度 Fira Code
- **并行容错（spec §7，TC-DSH-08 锁定）**：4 区独立加载状态（loading/error/data）——单区失败该区显示「加载失败 + 重 试」按钮（骨架屏换重试），**其余区照常渲染**；加载中 el-skeleton 占位（防 CLS）
- **空态（TC-DSH-06 锁定）**：待审核空 → 「全部单据已审核 ✓」（绿勾 SVG + 绿字 #059669）；预警空 → 「库存状态正常」；工单进度空 → el-empty「暂无进行中工单」
- **权限过滤展示（TC-DSH-07 锁定）**：前端按 auth store 判断——当前用户**不持有 9 个审核权限码中任意一个**时，隐藏待审核 KPI 卡与待审核区块（接口仍照常请求，后端过滤空列表——双端生效）；KPI 卡 1-3 恒显示
- **跳转白名单（spec §5「路由 url 由后端下发，前端按白名单放行」）**：前端 `ALLOWED_PATHS` = 9 个待审核路由 + `/inventory/alerts` + `/production/orders`；不在白名单内的 url 不跳转。工单行/预警卡跳转目标前端硬编码（spec 未给这两处下发 url 字段）：工单行 → `/production/orders`（**V1 无独立工单详情路由，列表页承载详情 tabs**——TC-DSH-03「跳转工单详情」以此落地）、预警卡 → `/inventory/alerts`
- **E2E 文件序（字典序，workers:1 串行依赖）——本模块最大的顺序陷阱**：自然命名 `dashboard.spec.ts` 字典序为 **auth < dashboard < inventory**，将排在 inventory.spec **之前**执行，届时仅种子数据存在，TC-DSH 需自建全部业务数据，且自建流水/余额/已审核单据/已下达工单（不可删）将**污染其后 inventory.spec 的硬编码基线断言**（`MAT-001=100`/`FIN-002=20`/盘点弹窗 `toHaveCount(3)`）。**必须命名 `zz-dashboard.spec.ts`**（`system < zz-dashboard`，字典序最后，与 E2E 文档 §1「仪表盘测试必须在全部业务模块测试通过后执行」一致）——此时上游 spec 遗留真实数据（生产模块完成态工单、今日流水、各模块草稿残留）可直接作为核对对象，自建残留（流水/已审核单/下达工单）不影响任何后续 spec
- **工程纪律**：严禁运行 pint/prettier 等格式化工具（污染全仓；pre-commit 钩子 lint-staged 自动修复属正常）；`git status` 精确暂存（禁止 `git add -A`）；每 Task 提交一次；**Task 提交前必跑全量 phpstan（0 错误）**；门禁勿与 E2E 并行（同机 CPU 争用饿死 vite → login 超时）；eslint 无 browser globals（禁用 setTimeout 用 nextTick；DOM 操作经模板 ref 或组件事件）；phpunit 权限用例须 forgetGuards；el-input 组件实例无 value 属性（扫码取值必须 v-model）；DashboardView 路由已存在（`/dashboard` 无 meta.permission 门控）——**本模块不改 router/index.ts 与 MainLayout.vue**（菜单「仪表盘」链接已存在且无权限门控）
- 端口：后端 `http://localhost:8000`、前端 `http://localhost:5173`、MySQL `3306`；本机命令 `php`/`composer`/`node` 已入 PATH；Python=`D:\code\envs\python\3.14.6\python.exe`（ui-ux-pro-max search.py 完整路径调用，本计划已检索完毕无需重跑）

---

## Task 1: DashboardService 聚合核心（KPI/待审核/工单进度/预警口径 + 单测）

**Files:**
- Create: `server/app/Services/DashboardService.php`
- Create: `server/tests/Feature/DashboardServiceTest.php`

**Interfaces:**
- Consumes: `inventory_balances/inventory_movements/production_orders/products/warehouses/purchase_inbound_items` + 9 类单据模型（`PurchaseOrder/PurchaseInbound/SalesOrder/SalesOutbound/InventoryCheck/PickList/ReturnList/OutsourcingOrder/FinishedInbound`）与各自 `STATUS_DRAFT` 常量；`User::permissions()`（角色合并去重权限码集合，已实现）；`ProductionOrder::STATUS_PRODUCING/STATUS_COMPLETED/STATUS_LABELS`；bcmath 扩展
- Produces（Task 2 控制器与 Task 3/4 前端消费，签名精确勿改）:
  - `summary(User $user): array` → `['inventory_total_qty'=>string,'inventory_value'=>?string,'today_inbound_qty'=>string,'today_outbound_qty'=>string,'pending_approvals'=>int,'work_order_running'=>int,'alert_count'=>int]`
  - `pendingApprovals(User $user): array` → `['items' => [['module'=>string,'type'=>string,'no'=>string,'created_at'=>string(Y-m-d H:i:s),'url'=>string]]]`（权限过滤，创建时间倒序，最多 20）
  - `workOrderProgress(): array` → `['items' => [['no'=>string,'product_name'=>string,'quantity'=>string,'completed_qty'=>string,'progress'=>string,'status'=>int,'status_label'=>string]]]`（status∈{2,3}，updated_at 倒序，最多 10）
  - `alerts(): array` → `['items' => [['product_name'=>string,'product_code'=>string,'warehouse_name'=>string,'quantity'=>string,'safety_min'=>int]]]`（低库存 level=1，product_id 升序前 10）

- [ ] **Step 1: 写失败测试 `server/tests/Feature/DashboardServiceTest.php`**

```php
<?php

// 仪表盘聚合服务测试：聚合口径=数据一致性核心路径 100% 覆盖
// 数据一律测试内自建（不依赖 InventorySeeder 数值），插外键数据先建真实主数据（sqlite 外键开启）

namespace Tests\Feature;

use App\Models\BomHeader;
use App\Models\Category;
use App\Models\InventoryBalance;
use App\Models\InventoryCheck;
use App\Models\InventoryMovement;
use App\Models\Location;
use App\Models\Permission;
use App\Models\PickList;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\PurchaseInbound;
use App\Models\PurchaseInboundItem;
use App\Models\PurchaseOrder;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\DashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->service = app(DashboardService::class);
        $this->warehouse = Warehouse::where('code', 'WH01')->first();
        $this->location = Location::where('code', 'A-01')->first();
        $this->admin = User::where('username', 'admin')->first();
    }

    private DashboardService $service;
    private Warehouse $warehouse;
    private Location $location;
    private User $admin;

    // 商品自建辅助（unit/category 用种子既有主数据）
    private function makeProduct(string $code, string $type, string $categoryName = '原材料', int $safetyMin = 0, int $safetyMax = 0): Product
    {
        return Product::create([
            'name' => $code, 'code' => $code, 'type' => $type,
            'category_id' => Category::where('name', $categoryName)->first()->id,
            'unit_id' => Unit::where('code', 'pc')->first()->id,
            'safety_min' => $safetyMin, 'safety_max' => $safetyMax, 'status' => 1,
        ]);
    }

    // 余额行自建辅助（聚合只读不写库存，直插即可）
    private function makeBalance(Product $p, string $quantity): void
    {
        InventoryBalance::create([
            'product_id' => $p->id, 'warehouse_id' => $this->warehouse->id,
            'location_id' => $this->location->id, 'quantity' => $quantity,
            'safety_min' => 0, 'safety_max' => 0,
        ]);
    }

    // 流水自建辅助（方向/数量/时间；聚合只读，直插即可）
    private function makeMovement(Product $p, int $direction, string $quantity, string $datetime): void
    {
        InventoryMovement::create([
            'product_id' => $p->id, 'warehouse_id' => $this->warehouse->id,
            'location_id' => $this->location->id, 'direction' => $direction,
            'quantity' => $quantity, 'balance_after' => $quantity,
            'source_type' => 'purchase_inbound', 'source_id' => 1, 'source_no' => 'PI20260813-001',
            'created_at' => $datetime, 'updated_at' => $datetime,
        ]);
    }

    // 工单自建辅助（bom_id 外键必须先建真实 BOM 头）
    private function makeOrder(string $no, int $status): ProductionOrder
    {
        $fin = $this->makeProduct('FIN-'.$no, 'finished', '成品');
        $bom = BomHeader::create([
            'code' => 'BOM-'.$no, 'product_id' => $fin->id, 'version' => 'v1',
            'quantity' => 1, 'status' => 1,
        ]);

        return ProductionOrder::create([
            'no' => $no, 'product_id' => $fin->id, 'quantity' => '10',
            'plan_date' => now()->toDateString(), 'bom_id' => $bom->id, 'status' => $status,
            'completed_qty' => '0', 'created_by' => 1,
        ]);
    }

    // 带指定权限的用户（角色 + 权限独立自建，验证权限过滤语义）
    private function makeUserWithPermissions(array $codes): User
    {
        $role = Role::create(['code' => 'ROLE-'.uniqid(), 'name' => '测试角色', 'remark' => '']);
        $role->permissions()->sync(Permission::whereIn('code', $codes)->pluck('id'));

        return User::create([
            'name' => '权限测试用户', 'username' => 'perm_'.uniqid(), 'email' => uniqid().'@php-design.local',
            'password' => 'Test@12345', 'status' => 1,
        ])->tap(fn ($u) => $u->roles()->sync([$role->id]));
    }

    public function test_summary_aggregates_inventory_qty_value_and_today_movements(): void
    {
        // 正常路径：库存总量=余额和；总值=Σ(余额×最近采购单价)转元；今日出入库=今日流水方向 Σ
        $p = $this->makeProduct('MAT-A', 'raw_material');
        $this->makeBalance($p, '10.00');
        // 种子无供应商 → 自建（supplier_id 外键）
        $sup = Supplier::create(['name' => '供应商A', 'code' => 'SUP-A', 'status' => 1]);
        $inbound = PurchaseInbound::create([
            'no' => 'PI20260813-001', 'supplier_id' => $sup->id,
            'warehouse_id' => $this->warehouse->id, 'location_id' => $this->location->id,
            'status' => 1, 'total_amount' => 0, 'inbound_at' => now()->toDateTimeString(),
        ]);
        PurchaseInboundItem::create([
            'inbound_id' => $inbound->id, 'product_id' => $p->id,
            'quantity' => 1, 'price' => 150, 'amount' => 150,
        ]);
        // 今日流水：入 5、出 3；昨日流水不计入
        $this->makeMovement($p, 1, '5.00', now()->toDateTimeString());
        $this->makeMovement($p, -1, '3.00', now()->toDateTimeString());
        $this->makeMovement($p, 1, '99.00', now()->subDay()->toDateTimeString());

        $res = $this->service->summary($this->admin);

        $this->assertSame('10.00', $res['inventory_total_qty']);
        $this->assertSame('15.00', $res['inventory_value']); // 10 × 150 分 = 15.00 元
        $this->assertSame('5.00', $res['today_inbound_qty']);
        $this->assertSame('3.00', $res['today_outbound_qty']);
    }

    public function test_summary_value_null_without_cost_price(): void
    {
        // 边界路径：无采购单价 → 总值 null（仅数量，不显示 ¥0）
        $p = $this->makeProduct('MAT-A', 'raw_material');
        $this->makeBalance($p, '10.00');

        $res = $this->service->summary($this->admin);

        $this->assertSame('10.00', $res['inventory_total_qty']);
        $this->assertNull($res['inventory_value']);
    }

    public function test_summary_pending_count_is_permission_filtered(): void
    {
        // 正常路径：待审核数按用户审核权限过滤（无采购权限 → 采购草稿不计）
        $sup = Supplier::create(['name' => '供应商A', 'code' => 'SUP-A', 'status' => 1]);
        PurchaseOrder::create([
            'no' => 'PO20260813-001', 'supplier_id' => $sup->id,
            'order_date' => now()->toDateString(), 'status' => PurchaseOrder::STATUS_DRAFT,
            'total_amount' => 100,
        ]);

        // admin 全权限：计入
        $this->assertSame(1, $this->service->summary($this->admin)['pending_approvals']);
        // 仅盘点审核权限用户：采购草稿不计入 → 0
        $limited = $this->makeUserWithPermissions(['check.update']);
        $this->assertSame(0, $this->service->summary($limited)['pending_approvals']);
    }

    public function test_summary_work_order_running_and_alert_count(): void
    {
        // 正常路径：生产中工单数 + 低库存预警数（草稿工单不计；高库存不计——spec §7）
        $this->makeOrder('MO20260813-001', ProductionOrder::STATUS_PRODUCING);
        $this->makeOrder('MO20260813-002', ProductionOrder::STATUS_DRAFT);
        // 低库存：低于下限（预警）；高库存：高于上限（不计入仪表盘）
        $low = $this->makeProduct('MAT-A', 'raw_material', '原材料', 10, 0);
        $this->makeBalance($low, '3.00');
        $high = $this->makeProduct('MAT-B', 'raw_material', '原材料', 0, 5);
        $this->makeBalance($high, '20.00');

        $res = $this->service->summary($this->admin);

        $this->assertSame(1, $res['work_order_running']);
        $this->assertSame(1, $res['alert_count']);
    }

    public function test_pending_approvals_lists_permission_filtered_drafts_sorted_desc(): void
    {
        // 正常路径：列表含模块/类型/单号/时间/路由；创建时间倒序；无权限类型不出现
        $sup = Supplier::create(['name' => '供应商A', 'code' => 'SUP-A', 'status' => 1]);
        $po1 = PurchaseOrder::create([
            'no' => 'PO20260813-001', 'supplier_id' => $sup->id,
            'order_date' => now()->toDateString(), 'status' => PurchaseOrder::STATUS_DRAFT,
            'total_amount' => 100,
        ]);
        $po1->created_at = now()->subMinute(); // fillable 不保证含时间戳 → 属性赋值后 save
        $po1->save();
        PurchaseOrder::create([
            'no' => 'PO20260813-002', 'supplier_id' => $sup->id,
            'order_date' => now()->toDateString(), 'status' => PurchaseOrder::STATUS_DRAFT,
            'total_amount' => 100,
        ]);
        $check = InventoryCheck::create([
            'no' => 'CK20260813-001', 'warehouse_id' => $this->warehouse->id,
            'status' => InventoryCheck::STATUS_DRAFT,
        ]);
        $check->created_at = now()->addMinute();
        $check->save();

        $res = $this->service->pendingApprovals($this->admin);

        $this->assertCount(3, $res['items']);
        $this->assertSame('CK20260813-001', $res['items'][0]['no']); // 最新在前
        $this->assertSame('库存', $res['items'][0]['module']);
        $this->assertSame('盘点单', $res['items'][0]['type']);
        $this->assertSame('/inventory/checks', $res['items'][0]['url']);
        $this->assertSame('PO20260813-002', $res['items'][1]['no']);
        $this->assertSame('采购', $res['items'][1]['module']);
        $this->assertSame('订单', $res['items'][1]['type']);
        $this->assertSame('/purchase/orders', $res['items'][1]['url']);
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/',
            $res['items'][0]['created_at']
        );

        // 权限过滤：仅盘点审核权限用户只看到盘点草稿
        $limited = $this->makeUserWithPermissions(['check.update']);
        $filtered = $this->service->pendingApprovals($limited);
        $this->assertCount(1, $filtered['items']);
        $this->assertSame('CK20260813-001', $filtered['items'][0]['no']);
    }

    public function test_pending_approvals_excludes_draft_production_orders(): void
    {
        // 边界路径：工单草稿不入待审核（流转动作是「下达」而非「审核」）
        $this->makeOrder('MO20260813-001', ProductionOrder::STATUS_DRAFT);

        $res = $this->service->pendingApprovals($this->admin);

        $this->assertSame([], $res['items']);
    }

    public function test_pending_approvals_limits_to_20_newest(): void
    {
        // 边界路径：跨类型超过 20 条 → 仅返回最新 20 条（最新在前）
        $sup = Supplier::create(['name' => '供应商A', 'code' => 'SUP-A', 'status' => 1]);
        $base = now()->startOfDay();
        for ($i = 1; $i <= 25; $i++) {
            $po = PurchaseOrder::create([
                'no' => 'PO20260813-'.str_pad((string) $i, 3, '0', STR_PAD_LEFT),
                'supplier_id' => $sup->id,
                'order_date' => now()->toDateString(), 'status' => PurchaseOrder::STATUS_DRAFT,
                'total_amount' => 100,
            ]);
            $po->created_at = $base->copy()->addMinutes($i);
            $po->save();
        }

        $res = $this->service->pendingApprovals($this->admin);

        $this->assertCount(20, $res['items']);
        // 第 25 张单在最前，第 1 张（最早）被截断
        $this->assertSame('PO20260813-025', $res['items'][0]['no']);
        $this->assertSame('PO20260813-006', $res['items'][19]['no']);
    }

    public function test_work_order_progress_lists_producing_and_completed_with_progress(): void
    {
        // 正常路径：仅生产中/已完成工单；进度=完工/计划×100（2 位字符串）；状态标签正确
        $this->makeOrder('MO20260813-001', ProductionOrder::STATUS_PRODUCING);
        $done = $this->makeOrder('MO20260813-002', ProductionOrder::STATUS_COMPLETED);
        $done->completed_qty = '2.5';
        $done->save();
        $this->makeOrder('MO20260813-003', ProductionOrder::STATUS_DRAFT);    // 不入
        $this->makeOrder('MO20260813-004', ProductionOrder::STATUS_RELEASED); // 不入
        $this->makeOrder('MO20260813-005', ProductionOrder::STATUS_CLOSED);   // 不入

        $res = $this->service->workOrderProgress();

        $this->assertCount(2, $res['items']);
        $byNo = collect($res['items'])->keyBy('no');
        $this->assertSame('0.00', $byNo['MO20260813-001']['progress']);
        $this->assertSame(2, $byNo['MO20260813-001']['status']);
        $this->assertSame('生产中', $byNo['MO20260813-001']['status_label']);
        $this->assertSame('25.00', $byNo['MO20260813-002']['progress']); // 2.5/10
        $this->assertSame(3, $byNo['MO20260813-002']['status']);
        $this->assertSame('已完成', $byNo['MO20260813-002']['status_label']);
        $this->assertSame('FIN-MO20260813-002', $byNo['MO20260813-002']['product_name']);
    }

    public function test_work_order_progress_limits_10_by_updated_at_desc(): void
    {
        // 边界路径：超过 10 条 → 最新更新在前，取前 10
        for ($i = 1; $i <= 12; $i++) {
            $o = $this->makeOrder('MO20260813-'.str_pad((string) $i, 3, '0', STR_PAD_LEFT), ProductionOrder::STATUS_PRODUCING);
            $o->updated_at = now()->addMinutes($i);
            $o->save();
        }

        $res = $this->service->workOrderProgress();

        $this->assertCount(10, $res['items']);
        $this->assertSame('MO20260813-012', $res['items'][0]['no']);
    }

    public function test_work_order_progress_zero_quantity_defends_zero(): void
    {
        // 边界路径：计划数量 0（防御）→ 进度 0.00 不除零
        $o = $this->makeOrder('MO20260813-001', ProductionOrder::STATUS_PRODUCING);
        $o->quantity = '0';
        $o->save();

        $res = $this->service->workOrderProgress();

        $this->assertSame('0.00', $res['items'][0]['progress']);
    }

    public function test_alerts_lists_low_stock_only_top_10(): void
    {
        // 正常路径：仅低库存（低于下限）；高库存不计；按商品排序前 10
        for ($i = 1; $i <= 12; $i++) {
            $p = $this->makeProduct('MAT-'.str_pad((string) $i, 3, '0', STR_PAD_LEFT), 'raw_material', '原材料', 10, 0);
            $this->makeBalance($p, '3.00');
        }
        $high = $this->makeProduct('MAT-HIGH', 'raw_material', '原材料', 0, 5);
        $this->makeBalance($high, '20.00');

        $res = $this->service->alerts();

        $this->assertCount(10, $res['items']);
        $this->assertSame('MAT-001', $res['items'][0]['product_code']); // 商品 id 升序前 10
        $this->assertSame('主仓', $res['items'][0]['warehouse_name']);
        $this->assertSame('3.00', $res['items'][0]['quantity']);
        $this->assertSame(10, $res['items'][0]['safety_min']);
        foreach ($res['items'] as $row) {
            $this->assertNotSame('MAT-HIGH', $row['product_code']);
        }
    }

    public function test_alerts_empty_when_no_low_stock(): void
    {
        // 边界路径：无低库存（高于下限 / 下限 0=不预警该侧）→ items 空
        $p = $this->makeProduct('MAT-A', 'raw_material', '原材料', 10, 0);
        $this->makeBalance($p, '50.00');

        $res = $this->service->alerts();

        $this->assertSame([], $res['items']);
    }
}
```

- [ ] **Step 2: 跑测试确认失败**

Run: `cd server && php artisan test --filter=DashboardServiceTest`
Expected: FAIL（DashboardService 类不存在）。

- [ ] **Step 3: 创建 `server/app/Services/DashboardService.php`**

```php
<?php

// 仪表盘聚合服务：4 类只读实时聚合（KPI/待审核/工单进度/预警），零迁移零新表
// 全部口径与业务模块事实一致；数量/金额 bcmath 字符串运算；不落快照、无缓存；
// 待审核单据按当前用户审核权限过滤（审核复用各模块 update 权限——安全语义所在）

namespace App\Services;

use App\Models\FinishedInbound;
use App\Models\InventoryBalance;
use App\Models\InventoryCheck;
use App\Models\InventoryMovement;
use App\Models\OutsourcingOrder;
use App\Models\PickList;
use App\Models\ProductionOrder;
use App\Models\PurchaseInbound;
use App\Models\PurchaseInboundItem;
use App\Models\PurchaseOrder;
use App\Models\ReturnList;
use App\Models\SalesOrder;
use App\Models\SalesOutbound;
use App\Models\User;
use Illuminate\Support\Carbon;

class DashboardService
{
    /** 待审核列表条数上限（spec §3：最多 20 条，按创建时间倒序） */
    public const MAX_PENDING = 20;

    /** 工单进度列表条数上限（spec §3：最多 10 条，按更新时间倒序） */
    public const MAX_ORDERS = 10;

    /** 预警列表条数上限（spec §3：低库存前 10 条） */
    public const MAX_ALERTS = 10;

    /**
     * 待审核数据统计：9 类草稿单据按审核权限过滤（rows 供列表 / count 供 KPI）
     *
     * 9 类单据显式逐类收集（全静态类型 Eloquent 查询，禁动态类名访问）；
     * rows 每类先取 MAX_PENDING 条最新再合并全局排序（某类型第 21 条不可能进入全局前 20）；
     * count 不受 20 条上限影响——KPI 必须为真实总数。
     *
     * @param  User  $user  当前登录用户（permissions() 返回角色合并去重权限码集合）
     * @return array{rows: array<int, array{module: string, type: string, no: string, created_at: string, url: string}>, count: int}
     */
    private function pendingData(User $user): array
    {
        $perms = $user->permissions();
        $rows = [];
        $count = 0;

        // 9 类草稿单据逐类收集：审核权限过滤（无权限整类不可见，TC-DSH-07）+ 草稿状态过滤；
        // 审核动作复用各模块 update 权限（全局约定）；生产工单草稿不入待审核——
        // 其流转动作是「下达」而非「审核」
        if ($perms->contains('purchase.order.update')) {
            $q = PurchaseOrder::where('status', PurchaseOrder::STATUS_DRAFT);
            $count += $q->count();
            $this->appendPending($q, '采购', '订单', '/purchase/orders', $rows);
        }
        if ($perms->contains('purchase.inbound.update')) {
            $q = PurchaseInbound::where('status', PurchaseInbound::STATUS_DRAFT);
            $count += $q->count();
            $this->appendPending($q, '采购', '入库单', '/purchase/inbounds', $rows);
        }
        if ($perms->contains('sales.order.update')) {
            $q = SalesOrder::where('status', SalesOrder::STATUS_DRAFT);
            $count += $q->count();
            $this->appendPending($q, '销售', '订单', '/sales/orders', $rows);
        }
        if ($perms->contains('sales.outbound.update')) {
            $q = SalesOutbound::where('status', SalesOutbound::STATUS_DRAFT);
            $count += $q->count();
            $this->appendPending($q, '销售', '出库单', '/sales/outbounds', $rows);
        }
        if ($perms->contains('check.update')) {
            $q = InventoryCheck::where('status', InventoryCheck::STATUS_DRAFT);
            $count += $q->count();
            $this->appendPending($q, '库存', '盘点单', '/inventory/checks', $rows);
        }
        if ($perms->contains('production.pick.update')) {
            $q = PickList::where('status', PickList::STATUS_DRAFT);
            $count += $q->count();
            $this->appendPending($q, '生产', '领料单', '/production/picks', $rows);
        }
        if ($perms->contains('production.return.update')) {
            $q = ReturnList::where('status', ReturnList::STATUS_DRAFT);
            $count += $q->count();
            $this->appendPending($q, '生产', '退料单', '/production/returns', $rows);
        }
        if ($perms->contains('production.outsource.update')) {
            $q = OutsourcingOrder::where('status', OutsourcingOrder::STATUS_DRAFT);
            $count += $q->count();
            $this->appendPending($q, '生产', '委外单', '/production/outsourcings', $rows);
        }
        if ($perms->contains('production.finished.update')) {
            $q = FinishedInbound::where('status', FinishedInbound::STATUS_DRAFT);
            $count += $q->count();
            $this->appendPending($q, '生产', '成品入库单', '/production/finished-inbounds', $rows);
        }

        // 跨类型按创建时间倒序全局排序（Y-m-d H:i:s 字典序=时间序；PHP 8 usort 稳定，同秒保持登记序）
        usort($rows, fn ($a, $b) => strcmp($b['created_at'], $a['created_at']));

        return ['rows' => $rows, 'count' => $count];
    }

    /**
     * 追加单类草稿单据行（每类先取 MAX_PENDING 条最新；全局排序与截断由 pendingData 完成）
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\App\Models\PurchaseOrder>|\Illuminate\Database\Eloquent\Builder<\App\Models\PurchaseInbound>|\Illuminate\Database\Eloquent\Builder<\App\Models\SalesOrder>|\Illuminate\Database\Eloquent\Builder<\App\Models\SalesOutbound>|\Illuminate\Database\Eloquent\Builder<\App\Models\InventoryCheck>|\Illuminate\Database\Eloquent\Builder<\App\Models\PickList>|\Illuminate\Database\Eloquent\Builder<\App\Models\ReturnList>|\Illuminate\Database\Eloquent\Builder<\App\Models\OutsourcingOrder>|\Illuminate\Database\Eloquent\Builder<\App\Models\FinishedInbound>  $query  草稿查询（status=STATUS_DRAFT 已过滤）
     * @param  string  $module  模块名（采购/销售/库存/生产）
     * @param  string  $type  单据类型（订单/入库单/出库单/盘点单/领料单/退料单/委外单/成品入库单）
     * @param  string  $url  前端路由（列表行点击跳转目标，前端白名单放行）
     * @param  array<int, array{module: string, type: string, no: string, created_at: string, url: string}>  $rows  追加目标（引用传递）
     */
    private function appendPending($query, string $module, string $type, string $url, array &$rows): void
    {
        foreach ($query->select('no', 'created_at')
            ->orderByDesc('created_at')
            ->limit(self::MAX_PENDING)
            ->cursor() as $doc) {
            $rows[] = [
                'module' => $module,
                'type' => $type,
                'no' => $doc->no,
                // created_at 为 Carbon（模型默认 cast），统一输出 Y-m-d H:i:s
                'created_at' => $doc->created_at->format('Y-m-d H:i:s'),
                'url' => $url,
            ];
        }
    }

    /**
     * KPI 汇总：库存总量/总值/今日出入库/待审核数/生产中工单数/预警数
     *
     * 库存总量=全部余额行求和；总值=Σ(余额×最近一次采购入库单价)÷100 元（无任何已知成本价→null）；
     * 今日出入库=流水 created_at 当天闭区间按方向求和；待审核数=9 类草稿按审核权限过滤后计数；
     * 预警数=低库存条数（高库存不占仪表盘，spec §7）。
     *
     * @param  User  $user  当前登录用户（待审核数按其所持审核权限过滤）
     */
    public function summary(User $user): array
    {
        $balances = InventoryBalance::query()->select('product_id', 'quantity')->get();

        // 成本价估算：每商品取最近一次采购入库单价（created_at DESC, id DESC 首条生效——与报表模块同口径）
        $prices = [];
        foreach (PurchaseInboundItem::query()
            ->select('product_id', 'price')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->cursor() as $item) {
            $prices[$item->product_id] = $prices[$item->product_id] ?? $item->price;
        }

        $totalQty = '0';
        $totalValue = '0';
        $valueKnown = false;
        foreach ($balances as $row) {
            $totalQty = bcadd($totalQty, $row->quantity, 2);
            if (isset($prices[$row->product_id])) {
                // 行金额 = 余额 × 单价（分）→ 元（2 位）；bcmath 全程无浮点
                $totalValue = bcadd($totalValue, bcdiv(bcmul($row->quantity, (string) $prices[$row->product_id], 2), '100', 2), 2);
                $valueKnown = true;
            }
        }

        // 今日出入库：流水 created_at 当天闭区间（Carbon 本地时区边界，方言无关）
        $today = Carbon::today();
        $inbound = '0';
        $outbound = '0';
        foreach (InventoryMovement::query()
            ->whereBetween('created_at', [$today->startOfDay(), $today->copy()->endOfDay()])
            ->select('direction', 'quantity')
            ->cursor() as $m) {
            if ((int) $m->direction === 1) {
                $inbound = bcadd($inbound, $m->quantity, 2);
            } else {
                $outbound = bcadd($outbound, $m->quantity, 2);
            }
        }

        return [
            'inventory_total_qty' => $totalQty,
            'inventory_value' => $valueKnown ? $totalValue : null,
            'today_inbound_qty' => $inbound,
            'today_outbound_qty' => $outbound,
            'pending_approvals' => $this->pendingData($user)['count'],
            'work_order_running' => ProductionOrder::where('status', ProductionOrder::STATUS_PRODUCING)->count(),
            'alert_count' => $this->alertQuery()->count(),
        ];
    }

    /**
     * 待审核单据列表：9 类草稿按审核权限过滤，创建时间倒序，最多 MAX_PENDING 条
     *
     * 单类型先各取 MAX_PENDING 条再合并全局排序（某类型第 21 条不可能进入全局前 20）；
     * created_at 输出 Y-m-d H:i:s 字符串；url 为前端路由（前端白名单放行）。
     *
     * @param  User  $user  当前登录用户（无对应审核权限的类型整类不可见）
     */
    public function pendingApprovals(User $user): array
    {
        return ['items' => array_slice($this->pendingData($user)['rows'], 0, self::MAX_PENDING)];
    }

    /**
     * 工单进度列表：生产中/已完成工单，更新时间倒序，最多 MAX_ORDERS 条
     *
     * progress = completed_qty/quantity×100（4 位中间精度输出 2 位字符串；计划 0 防御 0.00）；
     * status_label 为展示扩展字段（前端状态标签）。
     */
    public function workOrderProgress(): array
    {
        $orders = ProductionOrder::query()
            ->join('products', 'products.id', '=', 'production_orders.product_id')
            ->whereIn('production_orders.status', [ProductionOrder::STATUS_PRODUCING, ProductionOrder::STATUS_COMPLETED])
            ->select(
                'production_orders.no',
                'production_orders.quantity',
                'production_orders.completed_qty',
                'production_orders.status',
                'products.name as product_name'
            )
            ->orderByDesc('production_orders.updated_at')
            ->limit(self::MAX_ORDERS)
            ->get();

        $items = $orders->map(function ($order) {
            $progress = bccomp($order->quantity, '0', 2) === 0
                ? '0.00'
                : number_format((float) bcmul(bcdiv($order->completed_qty, $order->quantity, 4), '100', 2), 2, '.', '');

            return [
                'no' => $order->no,
                // join 别名列经 getAttribute 读取（PHPStan 静态分析可识别，同 InventoryController 模式）
                'product_name' => $order->getAttribute('product_name'),
                'quantity' => $order->quantity,
                'completed_qty' => $order->completed_qty,
                'progress' => $progress,
                'status' => (int) $order->status,
                'status_label' => ProductionOrder::STATUS_LABELS[(int) $order->status] ?? '未知',
            ];
        })->all();

        return ['items' => $items];
    }

    /**
     * 库存预警列表：仅低库存（level=1：低于下限），product_id 升序前 MAX_ALERTS 条
     *
     * 与库存预警页 /api/v1/inventory/alerts 同口径同排序（保证两处「前 10」一致）；
     * 高库存（level=2）不占仪表盘（spec §7）。
     */
    public function alerts(): array
    {
        $items = $this->alertQuery()
            ->select(
                'products.name as product_name',
                'products.code as product_code',
                'warehouses.name as warehouse_name',
                'inventory_balances.quantity',
                'products.safety_min as safety_min'
            )
            ->limit(self::MAX_ALERTS)
            ->get()
            ->map(fn ($r) => [
                'product_name' => $r->getAttribute('product_name'),
                'product_code' => $r->getAttribute('product_code'),
                'warehouse_name' => $r->getAttribute('warehouse_name'),
                'quantity' => $r->quantity,
                'safety_min' => (int) $r->getAttribute('safety_min'),
            ])->all();

        return ['items' => $items];
    }

    /**
     * 低库存预警查询基座（alerts 列表与 summary 计数共用；spec §7：仅 level=1 低于下限，0=不预警该侧）
     */
    private function alertQuery()
    {
        return InventoryBalance::query()
            ->join('products', 'products.id', '=', 'inventory_balances.product_id')
            ->join('warehouses', 'warehouses.id', '=', 'inventory_balances.warehouse_id')
            ->whereRaw('products.safety_min > 0 AND inventory_balances.quantity < products.safety_min')
            ->orderBy('inventory_balances.product_id');
    }
}
```

**Step 3 附注（implementer 必读）**：待审核 9 类单据**显式逐类 Eloquent 查询**（`pendingData` 9 个 if 块 + `appendPending` 辅助），禁用 `$class::query()`/`$class::STATUS_DRAFT` 动态类名访问——phpstan 无法对动态 class-string 解析模型常量与属性类型；`appendPending` 的 `$query` 参数 docblock 为 9 路 Builder 联合泛型（保持 `$doc->no`/`$doc->created_at` 全静态类型，否则 `->format()` 报「调用 mixed 方法」）。`User::permissions()` 已存在（合并角色权限码集合）。`alerts()` 中 `safety_min` 经 select 别名覆盖 balance 表同名字段（与 InventoryController::alerts 同款模式，产物为商品实时下限）。

- [ ] **Step 4: 跑测试确认通过**

Run: `cd server && php artisan test --filter=DashboardServiceTest`
Expected: PASS（12 用例全绿）。

- [ ] **Step 5: phpstan + 提交**

```bash
cd server && vendor/bin/phpstan analyse --no-progress --memory-limit=1G
git add server/app/Services/DashboardService.php server/tests/Feature/DashboardServiceTest.php
git commit -m "feat: 仪表盘聚合服务（KPI/待审核/工单进度/预警聚合口径）"
```

---

## Task 2: DashboardController + 路由 + 权限种子（结构/权限/HTTP 测试）

**Files:**
- Create: `server/app/Http/Controllers/Api/DashboardController.php`
- Create: `server/tests/Feature/DashboardStructureTest.php`
- Create: `server/tests/Feature/DashboardApiTest.php`
- Modify: `server/routes/api.php`（追加 4 条仪表盘路由）
- Modify: `server/database/seeders/RbacSeeder.php`（权限数组追加 dashboard.view + operator sync 例外）
- Modify: `server/tests/Feature/ReportStructureTest.php`（operator「%.list」断言循环排除 dashboard.view）

**Interfaces:**
- Consumes: Task 1 `DashboardService` 4 方法；`ApiResponse` trait；`EnsurePermission` 中间件（403 行为零改动）；`Permission/Role` 模型
- Produces: 4 个 GET 接口——`/api/v1/dashboard/summary`、`/api/v1/dashboard/pending-approvals`、`/api/v1/dashboard/work-order-progress`、`/api/v1/dashboard/alerts`（全部挂 `permission:dashboard.view`）；权限 `dashboard.view`（group=仪表盘，**operator 显式持有**）；Task 3/4 前端消费

- [ ] **Step 1: 写失败测试 `server/tests/Feature/DashboardStructureTest.php`**

```php
<?php

// 仪表盘模块结构测试：权限注册/角色持有边界（operator 持有 dashboard.view = E2E TC-DSH-07 语义锁）

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardStructureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_dashboard_permission_seeded_with_group(): void
    {
        // 正常路径：dashboard.view 注册且 group=仪表盘
        $p = Permission::where('code', 'dashboard.view')->first();
        $this->assertNotNull($p, '权限 dashboard.view 未注册');
        $this->assertSame('仪表盘', $p->group);
        $this->assertSame('仪表盘查看', $p->name);
    }

    public function test_admin_holds_dashboard_view(): void
    {
        // 正常路径：admin 角色全量持有
        $admin = Role::where('code', 'admin')->first();
        $this->assertSame(1, $admin->permissions()->where('code', 'dashboard.view')->count());
    }

    public function test_operator_holds_dashboard_view(): void
    {
        // 关键边界（TC-DSH-07 语义锁）：operator 持有 dashboard.view——
        // 仪表盘为登录默认落地页，limited01 必须能加载 KPI；待审核数据由接口内部按审核权限过滤，
        // 不构成数据泄露（若实现者遗漏 operator 例外本用例立即失败）
        $operator = Role::where('code', 'operator')->first();
        $this->assertSame(1, $operator->permissions()->where('code', 'dashboard.view')->count());
    }
}
```

- [ ] **Step 2: 写失败测试 `server/tests/Feature/DashboardApiTest.php`**

```php
<?php

// 仪表盘 HTTP 层测试：响应形状/权限 403/未登录 401/operator 可见但待审核空（核心接口 100% 覆盖）

namespace Tests\Feature;

use App\Models\PurchaseOrder;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->admin = User::where('username', 'admin')->first();
        $this->app['auth']->forgetGuards(); // 同 app 实例 guard 缓存清理（权限用例约定）
    }

    private User $admin;

    public function test_summary_ok_shape(): void
    {
        // 正常路径：200 + 7 字段形状完整
        $res = $this->actingAs($this->admin)->getJson('/api/v1/dashboard/summary');
        $res->assertOk()->assertJsonPath('code', 0);
        $data = $res->json('data');
        foreach ([
            'inventory_total_qty', 'inventory_value', 'today_inbound_qty', 'today_outbound_qty',
            'pending_approvals', 'work_order_running', 'alert_count',
        ] as $key) {
            $this->assertArrayHasKey($key, $data, "字段 {$key} 缺失");
        }
    }

    public function test_pending_approvals_ok_shape(): void
    {
        // 正常路径：200 + items 数组
        $res = $this->actingAs($this->admin)->getJson('/api/v1/dashboard/pending-approvals');
        $res->assertOk()->assertJsonPath('code', 0);
        $this->assertIsArray($res->json('data.items'));
    }

    public function test_work_order_progress_ok_shape(): void
    {
        // 正常路径：200 + items 数组
        $this->actingAs($this->admin)
            ->getJson('/api/v1/dashboard/work-order-progress')
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonStructure(['data' => ['items']]);
    }

    public function test_alerts_ok_shape(): void
    {
        // 正常路径：200 + items 数组
        $this->actingAs($this->admin)
            ->getJson('/api/v1/dashboard/alerts')
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonStructure(['data' => ['items']]);
    }

    public function test_dashboard_routes_require_authentication(): void
    {
        // 边界路径：未登录访问 4 接口 → 401（auth:sanctum）
        foreach (['summary', 'pending-approvals', 'work-order-progress', 'alerts'] as $path) {
            $this->getJson("/api/v1/dashboard/{$path}")->assertStatus(401);
        }
    }

    public function test_role_without_dashboard_view_gets_403(): void
    {
        // 边界路径：无 dashboard.view 的角色访问 4 接口 → 403
        $role = Role::create(['code' => 'NO-DASH', 'name' => '无仪表盘角色', 'remark' => '']);
        $user = User::create([
            'name' => '无仪表盘用户', 'username' => 'nodash01', 'email' => 'nodash01@php-design.local',
            'password' => 'Test@12345', 'status' => 1,
        ]);
        $user->roles()->sync([$role->id]);
        $this->app['auth']->forgetGuards();

        foreach (['summary', 'pending-approvals', 'work-order-progress', 'alerts'] as $path) {
            $this->actingAs($user)
                ->getJson("/api/v1/dashboard/{$path}")
                ->assertStatus(403)
                ->assertJsonPath('code', 403);
            $this->app['auth']->forgetGuards();
        }
    }

    public function test_operator_can_access_dashboard_but_pending_is_empty(): void
    {
        // 边界路径（TC-DSH-07 后端语义）：operator 可访问 4 接口；无审核权限 → 待审核 0/空列表
        $operator = Role::where('code', 'operator')->first();
        $user = User::create([
            'name' => '只读用户', 'username' => 'limited01', 'email' => 'limited01@php-design.local',
            'password' => 'Test@12345', 'status' => 1,
        ]);
        $user->roles()->sync([$operator->id]);
        $this->app['auth']->forgetGuards();

        // 先以 admin 造一张采购草稿（operator 应看不到）
        $sup = Supplier::create(['name' => '供应商A', 'code' => 'SUP-A', 'status' => 1]);
        PurchaseOrder::create([
            'no' => 'PO20260813-001', 'supplier_id' => $sup->id,
            'order_date' => now()->toDateString(), 'status' => PurchaseOrder::STATUS_DRAFT,
            'total_amount' => 100,
        ]);

        $summary = $this->actingAs($user)->getJson('/api/v1/dashboard/summary');
        $summary->assertOk()->assertJsonPath('data.pending_approvals', 0);

        $pending = $this->actingAs($user)->getJson('/api/v1/dashboard/pending-approvals');
        $pending->assertOk()->assertJsonPath('data.items', []);
        $this->app['auth']->forgetGuards();

        $this->actingAs($user)->getJson('/api/v1/dashboard/work-order-progress')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->actingAs($user)->getJson('/api/v1/dashboard/alerts')->assertOk();
    }
}
```

- [ ] **Step 3: 跑测试确认失败**

Run: `cd server && php artisan test --filter='DashboardStructureTest|DashboardApiTest'`
Expected: FAIL（路由不存在 404 / 权限未注册）。

- [ ] **Step 4: 修改 `RbacSeeder.php`（权限数组末尾追加 + operator sync 例外）**

在 `RbacSeeder::run()` 的 `$permissions` 数组末尾（报表权限之后）追加：

```php
            // 仪表盘模块权限（1 项只读查看权限，group=仪表盘）
            // 决策（TC-DSH-07 锁定）：仪表盘为登录默认落地页，所有角色可见——operator 也显式持有（下方 sync 例外）；
            // 待审核单据由接口内部按审核权限过滤（operator 无审核权限 → 恒为 0/空列表），不构成数据泄露
            ['name' => '仪表盘查看', 'code' => 'dashboard.view', 'group' => '仪表盘'],
```

将 operator 角色同步两行：

```php
        $operator = Role::firstOrCreate(['code' => 'operator'], ['name' => '操作员', 'remark' => '只读操作员']);
        $operator->permissions()->sync(Permission::where('code', 'like', '%.list')->pluck('id'));
```

改为：

```php
        $operator = Role::firstOrCreate(['code' => 'operator'], ['name' => '操作员', 'remark' => '只读操作员']);
        // operator 挂全部 list 权限 + dashboard.view 例外（仪表盘为全角色默认落地页，TC-DSH-07 锁定）
        $operator->permissions()->sync(
            Permission::where('code', 'like', '%.list')->orWhere('code', 'dashboard.view')->pluck('id')
        );
```

- [ ] **Step 5: 修改 `server/tests/Feature/ReportStructureTest.php`（既有断言循环排除例外）**

`test_operator_does_not_hold_report_permissions` 方法末尾的循环：

```php
        // operator 持有的全部权限仍以 .list 结尾（既有同步逻辑不变）
        foreach ($operator->permissions as $p) {
            $this->assertStringEndsWith('.list', $p->code);
        }
```

改为：

```php
        // operator 持有的权限以 .list 结尾，唯一例外 dashboard.view（仪表盘全角色默认落地页，TC-DSH-07 锁定）
        foreach ($operator->permissions as $p) {
            if ($p->code === 'dashboard.view') {
                continue;
            }
            $this->assertStringEndsWith('.list', $p->code);
        }
```

- [ ] **Step 6: 创建 `server/app/Http/Controllers/Api/DashboardController.php`**

```php
<?php

// 仪表盘控制器：4 个只读聚合接口（无参数、无校验、无业务码）
// 聚合逻辑全部在 DashboardService（Task 1），本控制器仅做契约层

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DashboardService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly DashboardService $service) {}

    /**
     * KPI 汇总：库存总量/总值/今日出入库/待审核数/生产中工单数/预警数
     * 权限 dashboard.view（operator 亦持有——默认落地页）
     */
    public function summary(Request $request)
    {
        return $this->ok($this->service->summary($request->user()));
    }

    /**
     * 待审核单据列表：按当前用户审核权限过滤（最多 20 条，创建时间倒序）
     * 权限 dashboard.view
     */
    public function pendingApprovals(Request $request)
    {
        return $this->ok($this->service->pendingApprovals($request->user()));
    }

    /**
     * 工单进度列表：生产中/已完成工单（最多 10 条，更新时间倒序）
     * 权限 dashboard.view
     */
    public function workOrderProgress()
    {
        return $this->ok($this->service->workOrderProgress());
    }

    /**
     * 库存预警列表：低库存（level=1）前 10 条，与库存预警页同口径
     * 权限 dashboard.view
     */
    public function alerts()
    {
        return $this->ok($this->service->alerts());
    }
}
```

**Step 6 附注**：`$request->user()` 在 auth:sanctum 中间件之后必非 null，但 PHPStan 仍可能推断为 `?User`——若 phpstan 报「参数类型不匹配」，按仓库既有惯例加空安全兜底（先查看仓库其他控制器是否有先例；Level 5 下 phpstan-laravel 通常将中间件后的 user() 视为 User，若报错再处理，不要预改）。

- [ ] **Step 7: 修改 `server/routes/api.php`（报表路由组之后追加）**

import 区 `use App\Http\Controllers\Api\CustomerController;` 之后（字母序）加：

```php
use App\Http\Controllers\Api\DashboardController;
```

文件末尾 `});` 之前（统计报表路由组之后）追加：

```php
    // 仪表盘：4 个只读聚合接口（dashboard.view——operator 亦持有，默认落地页全角色可见）
    Route::middleware('auth:sanctum')->group(function () {
        Route::middleware('permission:dashboard.view')
            ->get('/dashboard/summary', [DashboardController::class, 'summary']);
        Route::middleware('permission:dashboard.view')
            ->get('/dashboard/pending-approvals', [DashboardController::class, 'pendingApprovals']);
        Route::middleware('permission:dashboard.view')
            ->get('/dashboard/work-order-progress', [DashboardController::class, 'workOrderProgress']);
        Route::middleware('permission:dashboard.view')
            ->get('/dashboard/alerts', [DashboardController::class, 'alerts']);
    });
```

- [ ] **Step 8: 跑测试确认通过**

Run: `cd server && php artisan test --filter='DashboardStructureTest|DashboardApiTest|ReportStructureTest'`
Expected: PASS（结构 3 + HTTP 7 + 报表结构既有 3 全绿）。

- [ ] **Step 9: phpstan + 提交**

```bash
cd server && vendor/bin/phpstan analyse --no-progress --memory-limit=1G
git add server/app/Http/Controllers/Api/DashboardController.php server/tests/Feature/DashboardStructureTest.php server/tests/Feature/DashboardApiTest.php server/routes/api.php server/database/seeders/RbacSeeder.php server/tests/Feature/ReportStructureTest.php
git commit -m "feat: 仪表盘控制器+路由+权限（dashboard.view 全角色可见，待审核按审核权限过滤）"
```

---

## Task 3: 前端基座（dashboardApi + API 测试 + 设计系统页覆盖）

**Files:**
- Create: `web/src/api/dashboard.ts`
- Create: `web/src/tests/dashboard.api.test.ts`
- Create: `design-system/nexus-factory/pages/dashboard.md`（本 Task 完整落地，Task 4 页面遵循）

**Interfaces:**
- Consumes: Task 2 四个接口契约（字段形状见 Task 1 Produces）；`web/src/api/http.ts`（baseURL `/api/v1`、解包 `{code,message,data}`、业务失败抛错）；设计系统 `design-system/nexus-factory/MASTER.md` + `pages/report.md`（KPI 卡样式）
- Produces: `dashboardApi` 4 方法（无参，签名见 Step 2）+ TS 类型 `DashboardSummary/PendingApprovalItem/WorkOrderProgressItem/DashboardAlertItem`；设计系统页 `pages/dashboard.md`；Task 4 页面消费

- [ ] **Step 1: 创建 `web/src/api/dashboard.ts`（完整文件，勿截断）**

```ts
// 仪表盘 API 封装：4 个只读聚合接口（无参数；金额/数量/进度均为后端输出的字符串，前端仅格式化）
import { http } from './http'

export interface DashboardSummary {
  inventory_total_qty: string
  inventory_value: string | null
  today_inbound_qty: string
  today_outbound_qty: string
  pending_approvals: number
  work_order_running: number
  alert_count: number
}

export interface PendingApprovalItem {
  module: string
  type: string
  no: string
  created_at: string
  url: string
}

export interface WorkOrderProgressItem {
  no: string
  product_name: string
  quantity: string
  completed_qty: string
  progress: string
  status: number
  status_label: string
}

export interface DashboardAlertItem {
  product_name: string
  product_code: string
  warehouse_name: string
  quantity: string
  safety_min: string
}

export const dashboardApi = {
  // KPI 汇总：库存总量/总值/今日出入库/待审核数/生产中工单数/预警数
  async summary() {
    const { data } = await http.get('/dashboard/summary')
    return data.data as DashboardSummary
  },
  // 待审核单据列表：按当前用户审核权限过滤（最多 20 条，创建时间倒序）
  async pendingApprovals() {
    const { data } = await http.get('/dashboard/pending-approvals')
    return data.data as { items: PendingApprovalItem[] }
  },
  // 工单进度列表：生产中/已完成工单（最多 10 条，更新时间倒序）
  async workOrderProgress() {
    const { data } = await http.get('/dashboard/work-order-progress')
    return data.data as { items: WorkOrderProgressItem[] }
  },
  // 库存预警列表：低库存 level=1 前 10 条（与库存预警页同口径）
  async alerts() {
    const { data } = await http.get('/dashboard/alerts')
    return data.data as { items: DashboardAlertItem[] }
  },
}
```

- [ ] **Step 2: 创建 `web/src/tests/dashboard.api.test.ts`（完整文件）**

```ts
// 仪表盘 API 封装测试：4 接口路径与响应解包（mock http，与 report.api.test.ts 同构）
import { describe, it, expect, vi, beforeEach, type Mock } from 'vitest'

vi.mock('../api/http', () => ({
  http: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() },
}))
import { http } from '../api/http'
import { dashboardApi } from '../api/dashboard'

// mock 句柄：运行时为 vi.fn()，静态类型用 vitest Mock（替代 any）
const mockGet = http.get as Mock

describe('dashboard api', () => {
  beforeEach(() => vi.clearAllMocks())

  it('summary 请求 /dashboard/summary 并解包 data', async () => {
    // 正常路径：路径正确 + 解包统一响应
    const payload = {
      inventory_total_qty: '100.00',
      inventory_value: '150.00',
      today_inbound_qty: '5.00',
      today_outbound_qty: '3.00',
      pending_approvals: 2,
      work_order_running: 1,
      alert_count: 1,
    }
    mockGet.mockResolvedValue({ data: { code: 0, data: payload } })
    await expect(dashboardApi.summary()).resolves.toEqual(payload)
    expect(http.get).toHaveBeenCalledWith('/dashboard/summary')
  })

  it('pendingApprovals 请求 /dashboard/pending-approvals 并解包 items', async () => {
    // 正常路径
    mockGet.mockResolvedValue({ data: { code: 0, data: { items: [] } } })
    await expect(dashboardApi.pendingApprovals()).resolves.toEqual({ items: [] })
    expect(http.get).toHaveBeenCalledWith('/dashboard/pending-approvals')
  })

  it('workOrderProgress 请求 /dashboard/work-order-progress 并解包 items', async () => {
    // 正常路径
    mockGet.mockResolvedValue({ data: { code: 0, data: { items: [] } } })
    await expect(dashboardApi.workOrderProgress()).resolves.toEqual({ items: [] })
    expect(http.get).toHaveBeenCalledWith('/dashboard/work-order-progress')
  })

  it('alerts 请求 /dashboard/alerts 并解包 items', async () => {
    // 正常路径
    mockGet.mockResolvedValue({ data: { code: 0, data: { items: [] } } })
    await expect(dashboardApi.alerts()).resolves.toEqual({ items: [] })
    expect(http.get).toHaveBeenCalledWith('/dashboard/alerts')
  })
})
```

- [ ] **Step 3: 创建 `design-system/nexus-factory/pages/dashboard.md`（完整落地，Task 4 页面遵循）**

```markdown
# 仪表盘模块（pages/dashboard.md 页覆盖）

> 依据：MASTER.md（Swiss Modernism 2.0）+ pages/report.md（KPI 卡样式复用）+ ui-ux-pro-max 检索
> （ux 域：Loading States「骨架屏或 spinner，禁无反馈冻结」/ Empty States「无内容时给出引导文案与动作」/
> Progress Indicators「多步流程给进度指示」；color 域：Analytics Dashboard 蓝色数据+琥珀高亮——
> 本项目沿用 nexus 语义令牌，方向色为入库绿/出库红/待审核琥珀）
> LOGIC：本页存在时覆盖 MASTER.md 通用规范，仅对仪表盘模块生效

## 1. 页面骨架
- .page-card 骨架与各模块一致；本模块只读、无写操作按钮、无弹窗、无手动刷新按钮
- 结构顺序：KPI 卡区（4 张一行，grid 12 布局）→ 中部双栏（左 2/3 待审核、右 1/3 工单进度）→ 底部库存预警
- 单号/数量/进度/金额一律 Fira Code；数字千分位（formatThousand；库存总值后端已转元）
- 所有可点击元素 cursor:pointer；hover/状态变化 150-300ms transition；页面永不整页空白

## 2. KPI 卡片（4 张/行，复用 report.md §2 KPI 卡规范）
- 白底卡片、border 1px #E6E8EA、border-radius 8px、shadow-sm、padding 16px
- 标签：12px #64748B（库存总量/今日入库/今日出库/待审核单据）；数值：Fira Code 24px bold #0F172A + 千分位
- 次级文案（kpi-sub）：12px #64748B——卡 1「库存总值 ¥xx」或「未启用成本核算」（value=null 时，禁显 ¥0）；
  卡 2 次级「出库 Σxx」（spec §4 原文）
- 方向色：今日入库数值 #059669 前缀 `+`；今日出库数值 #DC2626 前缀 `-`；待审核数值 #D97706（琥珀，无前缀）
- 网格：repeat(4, 1fr)、gap 16px（1440 断点一行 4 张；窄屏自动降列不横向滚动）
- 待审核卡可点击：点击平滑滚动到待审核区（scrollIntoView）；hover shadow-md

## 3. 中部双栏
- 网格 2fr 1fr、gap 24px；面板 .panel（白底/border/8px 圆角/16px padding），标题 .panel-title 14px 600 #0F172A
- 左：待审核单据——按 module 分组（分组标签 .pending-tag：灰底 #F2F3F4 12px #475569）；
  行 .pending-row：类型标签（12px 描边 #334155）+ 单号 Fira Code 13px + 时间 12px #94A3B8 右对齐 + 右箭头 SVG；
  hover 背景 #F8FAFC；行点击按后端 url 跳转（前端 ALLOWED_PATHS 白名单放行，不在白名单不跳）
- 右：工单进度——行 .order-row：单号 Fira Code + 状态标签（生产中琥珀 el-tag type=warning / 已完成绿 el-tag type=success，
  复用生产模块五态语义色）；商品名 13px #475569；el-progress 8px + 进度文本 Fira Code 12px 右对齐「xx.xx%」；
  行点击跳 /production/orders（V1 无独立工单详情路由，列表页承载详情 tabs）

## 4. 底部库存预警
- 红色低库存卡片网格 repeat(auto-fill, minmax(240px, 1fr))、gap 16px
- 卡片：border #FECACA + 左边框 4px #DC2626 + 浅红底 #FEF2F2 + 8px 圆角 + 12px padding
- 内容：商品名 13px 600 + 编码 Fira Code 12px #64748B + 仓库名 12px #64748B + 「当前 xx / 下限 xx」Fira Code 12px #DC2626
- 卡片点击跳 /inventory/alerts；hover shadow-md

## 5. 加载/错误/空态（spec §7 + ux 域）
- 加载中：各区 el-skeleton 占位（固定行数，防 CLS）；挂载即并行请求 4 接口（无刷新按钮）
- 单区失败：该区「加载失败 + 重 试」按钮（骨架屏换重试），**其余区照常渲染**（并行容错，TC-DSH-08）
- 空态：待审核「全部单据已审核 ✓」（SVG 绿勾图标组件 Check + 绿字 #059669）；预警「库存状态正常」；
  工单进度 el-empty「暂无进行中工单」（image-size 60 小尺寸）
- 权限：无任一审核权限的用户隐藏待审核 KPI 卡与待审核区（TC-DSH-07）

## 6. 反模式（禁止）
- emoji 当图标（绿勾/箭头必须 @element-plus/icons-vue SVG 组件）
- 单区失败连带整页错误提示（必须区级隔离）
- 待审核时间/单号裸字体（必须 Fira Code）
- 预警卡无 hover 反馈/cursor:pointer
- 空态整页空白；KPI 数值 hover 才显示（数值恒文本可见）
```

- [ ] **Step 4: 前端门禁验证**

Run: `cd web && npm run type-check && npm run lint && npm run lint:css && npm run format:check && npm run test:unit`
Expected: 全过（新增 dashboard.api.test.ts 绿；`type-check` 覆盖 dashboard.ts；设计系统 md 不参与门禁）。

- [ ] **Step 5: 提交**

```bash
git add web/src/api/dashboard.ts web/src/tests/dashboard.api.test.ts design-system/nexus-factory/pages/dashboard.md
git commit -m "feat: 仪表盘 API 封装+测试+设计系统页覆盖"
```

---

## Task 4: DashboardView.vue 重写 + 视图测试

**Files:**
- Modify: `web/src/views/DashboardView.vue`（占位页 → 完整仪表盘；**路由已存在无需改 router**）
- Create: `web/src/tests/dashboard-view.test.ts`

**Interfaces:**
- Consumes: Task 3 `dashboardApi` 4 方法与类型；`useAuthStore().has()`；`formatThousand`；`@element-plus/icons-vue`（Check/ArrowRight，已装）；vue-router `useRouter`
- Produces: 完整仪表盘页（4 KPI 卡 + 双栏 + 预警）；Task 5 E2E 消费（页面结构与文案即 E2E 定位契约）

- [ ] **Step 1: 重写 `web/src/views/DashboardView.vue`（完整文件，勿截断）**

```vue
<!-- 仪表盘页：登录默认落地页——4 KPI 卡 + 待审核列表 + 工单进度 + 库存预警
     4 接口并行独立加载：单区失败只影响该区（骨架屏换重 试），其余照常渲染（spec §7 并行容错） -->
<template>
  <div class="page-card dashboard">
    <!-- KPI 卡区：一行 4 张（待审核卡仅对持有审核权限者显示，TC-DSH-07） -->
    <div class="kpi-grid">
      <template v-if="summary.loading">
        <el-skeleton v-for="i in 4" :key="i" class="kpi-card" :rows="2" animated />
      </template>
      <div v-else-if="summary.error" class="kpi-card">
        <div class="section-error">
          <span class="section-error-text">KPI 数据加载失败</span>
          <el-button size="small" @click="loadSummary">重 试</el-button>
        </div>
      </div>
      <template v-else-if="summary.data">
        <div class="kpi-card">
          <div class="kpi-label">库存总量</div>
          <div class="kpi-value font-code">{{ formatThousand(summary.data.inventory_total_qty) }}</div>
          <div v-if="summary.data.inventory_value === null" class="kpi-sub">未启用成本核算</div>
          <div v-else class="kpi-sub">库存总值 ¥{{ formatThousand(summary.data.inventory_value) }}</div>
        </div>
        <div class="kpi-card">
          <div class="kpi-label">今日入库</div>
          <div class="kpi-value kpi-in font-code">+{{ formatThousand(summary.data.today_inbound_qty) }}</div>
          <div class="kpi-sub">出库 Σ{{ formatThousand(summary.data.today_outbound_qty) }}</div>
        </div>
        <div class="kpi-card">
          <div class="kpi-label">今日出库</div>
          <div class="kpi-value kpi-out font-code">-{{ formatThousand(summary.data.today_outbound_qty) }}</div>
        </div>
        <div v-if="canApprove" class="kpi-card kpi-clickable" @click="scrollToPending">
          <div class="kpi-label">待审核单据</div>
          <div class="kpi-value kpi-warn font-code">{{ summary.data.pending_approvals }}</div>
        </div>
      </template>
    </div>

    <!-- 中部双栏：左 2/3 待审核列表、右 1/3 工单进度 -->
    <div class="dash-grid">
      <section v-if="canApprove" id="pending-panel" ref="pendingPanel" class="panel">
        <h3 class="panel-title">待审核单据</h3>
        <el-skeleton v-if="pending.loading" :rows="4" animated />
        <div v-else-if="pending.error" class="section-error">
          <span class="section-error-text">待审核单据加载失败</span>
          <el-button size="small" @click="loadPending">重 试</el-button>
        </div>
        <template v-else-if="pending.data">
          <div v-if="pending.data.items.length === 0" class="empty-ok">
            <el-icon class="ok-icon" color="#059669"><Check /></el-icon>
            <span>全部单据已审核 ✓</span>
          </div>
          <template v-else>
            <div v-for="group in pendingGroups" :key="group.module" class="pending-group">
              <div class="pending-tag">{{ group.module }}</div>
              <div v-for="row in group.items" :key="row.no" class="pending-row" @click="go(row.url)">
                <span class="type-tag">{{ row.type }}</span>
                <span class="font-code pending-no">{{ row.no }}</span>
                <span class="pending-time">{{ row.created_at }}</span>
                <el-icon class="row-arrow"><ArrowRight /></el-icon>
              </div>
            </div>
          </template>
        </template>
      </section>

      <section class="panel">
        <h3 class="panel-title">
          工单进度
          <el-tag v-if="summary.data" size="small" type="warning" class="title-badge"
            >生产中 {{ summary.data.work_order_running }}</el-tag
          >
        </h3>
        <el-skeleton v-if="progress.loading" :rows="3" animated />
        <div v-else-if="progress.error" class="section-error">
          <span class="section-error-text">工单进度加载失败</span>
          <el-button size="small" @click="loadProgress">重 试</el-button>
        </div>
        <template v-else-if="progress.data">
          <el-empty v-if="progress.data.items.length === 0" description="暂无进行中工单" :image-size="60" />
          <div
            v-for="row in progress.data.items"
            v-else
            :key="row.no"
            class="order-row"
            @click="go('/production/orders')"
          >
            <div class="order-head">
              <span class="font-code">{{ row.no }}</span>
              <el-tag size="small" :type="row.status === 3 ? 'success' : 'warning'">{{
                row.status_label
              }}</el-tag>
            </div>
            <div class="order-name">{{ row.product_name }}</div>
            <div class="order-progress">
              <el-progress :percentage="Number(row.progress)" :stroke-width="8" :show-text="false" />
              <span class="font-code progress-text">{{ row.progress }}%</span>
            </div>
          </div>
        </template>
      </section>
    </div>

    <!-- 底部：库存预警（低库存前 10，与库存预警页同口径） -->
    <section class="panel">
      <h3 class="panel-title">
        库存预警
        <el-tag v-if="summary.data" size="small" type="danger" class="title-badge"
          >{{ summary.data.alert_count }}</el-tag
        >
      </h3>
      <el-skeleton v-if="alerts.loading" :rows="2" animated />
      <div v-else-if="alerts.error" class="section-error">
        <span class="section-error-text">库存预警加载失败</span>
        <el-button size="small" @click="loadAlerts">重 试</el-button>
      </div>
      <template v-else-if="alerts.data">
        <div v-if="alerts.data.items.length === 0" class="empty-ok"><span>库存状态正常</span></div>
        <div v-else class="alert-grid">
          <div
            v-for="(row, idx) in alerts.data.items"
            :key="idx"
            class="alert-card"
            @click="go('/inventory/alerts')"
          >
            <div class="alert-name">
              {{ row.product_name }} <span class="font-code alert-code">{{ row.product_code }}</span>
            </div>
            <div class="alert-wh">{{ row.warehouse_name }}</div>
            <div class="alert-nums font-code">
              当前 {{ formatThousand(row.quantity) }} / 下限 {{ formatThousand(row.safety_min) }}
            </div>
          </div>
        </div>
      </template>
    </section>
  </div>
</template>

<script setup lang="ts">
// 仪表盘：4 区独立加载状态（loading/error/data），挂载并行请求 4 接口；无手动刷新按钮（V1）
import { computed, onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { ArrowRight, Check } from '@element-plus/icons-vue'
import {
  dashboardApi,
  type DashboardAlertItem,
  type DashboardSummary,
  type PendingApprovalItem,
  type WorkOrderProgressItem,
} from '../api/dashboard'
import { useAuthStore } from '../stores/auth'
import { formatThousand } from '../utils/format'

const auth = useAuthStore()
const router = useRouter()

// 各区独立状态：loading 骨架 / error 失败重试 / data 数据——单区失败不影响其余（spec §7）
const summary = reactive<{ loading: boolean; error: boolean; data: DashboardSummary | null }>({
  loading: true,
  error: false,
  data: null,
})
const pending = reactive<{ loading: boolean; error: boolean; data: { items: PendingApprovalItem[] } | null }>({
  loading: true,
  error: false,
  data: null,
})
const progress = reactive<{ loading: boolean; error: boolean; data: { items: WorkOrderProgressItem[] } | null }>({
  loading: true,
  error: false,
  data: null,
})
const alerts = reactive<{ loading: boolean; error: boolean; data: { items: DashboardAlertItem[] } | null }>({
  loading: true,
  error: false,
  data: null,
})

// 各模块审核权限码（审核复用 update；无任一审核权限 → 隐藏待审核卡与区块，TC-DSH-07）
const APPROVE_PERMISSIONS = [
  'purchase.order.update',
  'purchase.inbound.update',
  'sales.order.update',
  'sales.outbound.update',
  'check.update',
  'production.pick.update',
  'production.return.update',
  'production.outsource.update',
  'production.finished.update',
]
const canApprove = computed(() => APPROVE_PERMISSIONS.some((p) => auth.has(p)))

// 路由白名单：仅放行后端下发的已知模块路径（spec §5 白名单契约）
const ALLOWED_PATHS = [
  '/purchase/orders',
  '/purchase/inbounds',
  '/sales/orders',
  '/sales/outbounds',
  '/inventory/checks',
  '/inventory/alerts',
  '/production/orders',
  '/production/picks',
  '/production/returns',
  '/production/outsourcings',
  '/production/finished-inbounds',
]

const pendingPanel = ref<HTMLElement | null>(null)

async function loadSummary() {
  summary.loading = true
  summary.error = false
  try {
    summary.data = await dashboardApi.summary()
  } catch {
    // 单区失败：仅本区转错误态（骨架换重试按钮），其余区不受影响
    summary.error = true
  } finally {
    summary.loading = false
  }
}

async function loadPending() {
  pending.loading = true
  pending.error = false
  try {
    pending.data = await dashboardApi.pendingApprovals()
  } catch {
    pending.error = true
  } finally {
    pending.loading = false
  }
}

async function loadProgress() {
  progress.loading = true
  progress.error = false
  try {
    progress.data = await dashboardApi.workOrderProgress()
  } catch {
    progress.error = true
  } finally {
    progress.loading = false
  }
}

async function loadAlerts() {
  alerts.loading = true
  alerts.error = false
  try {
    alerts.data = await dashboardApi.alerts()
  } catch {
    alerts.error = true
  } finally {
    alerts.loading = false
  }
}

// 待审核列表按模块分组（Map 保持模块首次出现序；组内行保持全局倒序）
const pendingGroups = computed(() => {
  const items = pending.data?.items ?? []
  const map = new Map<string, PendingApprovalItem[]>()
  for (const row of items) {
    const list = map.get(row.module)
    if (list) {
      list.push(row)
    } else {
      map.set(row.module, [row])
    }
  }
  return [...map.entries()].map(([module, items]) => ({ module, items }))
})

// 白名单跳转：后端下发的路由仅允许已知模块路径
function go(url: string) {
  if (!ALLOWED_PATHS.includes(url)) return
  router.push(url)
}

// 待审核 KPI 卡点击 → 平滑滚动到待审核区（spec §4 卡片联动）
function scrollToPending() {
  pendingPanel.value?.scrollIntoView({ behavior: 'smooth' })
}

// 挂载即并行发起 4 区请求（各自独立 catch，互不影响）
onMounted(() => {
  loadSummary()
  loadPending()
  loadProgress()
  loadAlerts()
})
</script>

<style scoped>
/* 样式遵循 design-system/nexus-factory/pages/dashboard.md（KPI 卡/双栏/预警卡/空态） */
.dashboard {
  display: flex;
  flex-direction: column;
  gap: var(--space-2xl);
}
.kpi-grid {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: var(--space-xl);
}
.kpi-card {
  background: #fff;
  border: 1px solid var(--color-border);
  border-radius: 8px;
  padding: var(--space-xl);
  box-shadow: var(--shadow-sm);
  transition: box-shadow 200ms ease;
}
.kpi-clickable {
  cursor: pointer;
}
.kpi-clickable:hover {
  box-shadow: var(--shadow-md);
}
.kpi-label {
  font-size: 12px;
  color: #64748b;
  margin-bottom: var(--space-md);
}
.kpi-value {
  font-size: 24px;
  font-weight: 700;
  color: var(--color-foreground);
}
.kpi-in {
  color: #059669;
}
.kpi-out {
  color: #dc2626;
}
.kpi-warn {
  color: #d97706;
}
.kpi-sub {
  font-size: 12px;
  color: #64748b;
  margin-top: var(--space-md);
}
.dash-grid {
  display: grid;
  grid-template-columns: 2fr 1fr;
  gap: var(--space-2xl);
}
.panel {
  background: #fff;
  border: 1px solid var(--color-border);
  border-radius: 8px;
  padding: var(--space-xl);
}
.panel-title {
  font-size: 14px;
  font-weight: 600;
  color: var(--color-foreground);
  margin: 0 0 var(--space-xl);
}
.title-badge {
  margin-left: var(--space-md);
}
.section-error {
  display: flex;
  align-items: center;
  gap: var(--space-lg);
  padding: var(--space-md) 0;
}
.section-error-text {
  font-size: 13px;
  color: #64748b;
}
.empty-ok {
  display: flex;
  align-items: center;
  gap: var(--space-md);
  color: #059669;
  padding: var(--space-lg) 0;
}
.ok-icon {
  font-size: 18px;
}
.pending-group {
  margin-bottom: var(--space-lg);
}
.pending-tag {
  display: inline-block;
  font-size: 12px;
  color: #475569;
  background: var(--color-muted);
  border-radius: 4px;
  padding: 2px 8px;
  margin-bottom: var(--space-md);
}
.pending-row {
  display: flex;
  align-items: center;
  gap: var(--space-lg);
  padding: var(--space-md) var(--space-sm);
  border-radius: 6px;
  cursor: pointer;
  transition: background 150ms ease;
}
.pending-row:hover {
  background: #f8fafc;
}
.type-tag {
  font-size: 12px;
  color: #334155;
  border: 1px solid var(--color-border);
  border-radius: 4px;
  padding: 1px 6px;
  white-space: nowrap;
}
.pending-no {
  font-size: 13px;
  color: var(--color-foreground);
}
.pending-time {
  flex: 1;
  text-align: right;
  font-size: 12px;
  color: #94a3b8;
}
.row-arrow {
  color: #94a3b8;
}
.order-row {
  border-top: 1px solid var(--color-border);
  padding: var(--space-lg) 0;
  cursor: pointer;
  transition: background 150ms ease;
}
.order-head {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: var(--space-sm);
}
.order-name {
  font-size: 13px;
  color: #475569;
  margin-bottom: var(--space-md);
}
.order-progress {
  display: flex;
  align-items: center;
  gap: var(--space-md);
}
.order-progress .el-progress {
  flex: 1;
}
.progress-text {
  font-size: 12px;
  color: var(--color-foreground);
  min-width: 56px;
  text-align: right;
}
.alert-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
  gap: var(--space-lg);
}
.alert-card {
  border: 1px solid #fecaca;
  border-left: 4px solid #dc2626;
  background: #fef2f2;
  border-radius: 8px;
  padding: var(--space-lg);
  cursor: pointer;
  transition: box-shadow 150ms ease;
}
.alert-card:hover {
  box-shadow: var(--shadow-md);
}
.alert-name {
  font-size: 13px;
  font-weight: 600;
  color: var(--color-foreground);
}
.alert-code {
  color: #64748b;
  font-size: 12px;
}
.alert-wh {
  font-size: 12px;
  color: #64748b;
  margin: var(--space-sm) 0;
}
.alert-nums {
  font-size: 12px;
  color: #dc2626;
}
</style>
```

**Step 1 附注（implementer 必读）**：el-skeleton 骨架卡已用 template 包裹 v-if（el-skeleton v-for 在 template 内——规避 vue/no-use-v-if-with-v-for 规则）。`el-empty` 的 `:image-size` 为小尺寸空态（60px，防大图撑破右侧窄栏）。状态标签 el-tag 用 Element Plus 语义 type（warning=琥珀/success=绿/danger=红），与设计系统语义色一致。

- [ ] **Step 2: 创建 `web/src/tests/dashboard-view.test.ts`（完整文件）**

```ts
// 仪表盘页组件测试：KPI 渲染/并行请求/空态/单区失败重试/权限隐藏/白名单跳转
// （mock dashboardApi + auth store + vue-router）
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import ElementPlus from 'element-plus'

const summaryMock = vi.fn()
const pendingMock = vi.fn()
const progressMock = vi.fn()
const alertsMock = vi.fn()
vi.mock('../api/dashboard', () => ({
  dashboardApi: {
    summary: (...args: unknown[]) => summaryMock(...args),
    pendingApprovals: (...args: unknown[]) => pendingMock(...args),
    workOrderProgress: (...args: unknown[]) => progressMock(...args),
    alerts: (...args: unknown[]) => alertsMock(...args),
  },
}))

// 权限开关：控制 auth.has 返回值（默认持有全部审核权限）
let hasAll = true
vi.mock('../stores/auth', () => ({
  useAuthStore: () => ({
    has: (p: string) =>
      hasAll &&
      [
        'purchase.order.update',
        'purchase.inbound.update',
        'sales.order.update',
        'sales.outbound.update',
        'check.update',
        'production.pick.update',
        'production.return.update',
        'production.outsource.update',
        'production.finished.update',
      ].includes(p),
  }),
}))

// 路由 mock：捕获白名单跳转
const push = vi.fn()
vi.mock('vue-router', () => ({ useRouter: () => ({ push }) }))

import DashboardView from '../views/DashboardView.vue'

// 默认 KPI 响应（字段形状与后端契约一致）
function okSummary() {
  return {
    inventory_total_qty: '1234.50',
    inventory_value: '567.80',
    today_inbound_qty: '10.00',
    today_outbound_qty: '3.00',
    pending_approvals: 2,
    work_order_running: 1,
    alert_count: 1,
  }
}

describe('DashboardView', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
    hasAll = true
    summaryMock.mockResolvedValue(okSummary())
    pendingMock.mockResolvedValue({ items: [] })
    progressMock.mockResolvedValue({ items: [] })
    alertsMock.mockResolvedValue({ items: [] })
  })

  it('挂载即并行请求 4 接口', async () => {
    // 正常路径：4 区接口各一次
    mount(DashboardView, { global: { plugins: [ElementPlus] } })
    await flushPromises()
    expect(summaryMock).toHaveBeenCalledTimes(1)
    expect(pendingMock).toHaveBeenCalledTimes(1)
    expect(progressMock).toHaveBeenCalledTimes(1)
    expect(alertsMock).toHaveBeenCalledTimes(1)
  })

  it('渲染 4 KPI 卡（千分位/方向色前缀/次级文案）', async () => {
    // 正常路径：数值格式化与语义文案
    const wrapper = mount(DashboardView, { global: { plugins: [ElementPlus] } })
    await flushPromises()
    expect(wrapper.text()).toContain('1,234.50')
    expect(wrapper.text()).toContain('库存总值 ¥567.80')
    expect(wrapper.find('.kpi-in').text()).toBe('+10.00')
    expect(wrapper.find('.kpi-out').text()).toBe('-3.00')
    expect(wrapper.find('.kpi-warn').text()).toBe('2')
    expect(wrapper.text()).toContain('生产中 1')
  })

  it('库存总值 null 显示未启用成本核算', async () => {
    // 边界路径：无成本价 → 不显示 ¥0
    summaryMock.mockResolvedValue({ ...okSummary(), inventory_value: null })
    const wrapper = mount(DashboardView, { global: { plugins: [ElementPlus] } })
    await flushPromises()
    expect(wrapper.text()).toContain('未启用成本核算')
    expect(wrapper.text()).not.toContain('库存总值 ¥')
  })

  it('空态：待审核全部已审核/预警库存正常/工单暂无', async () => {
    // 边界路径：三类空态文案
    const wrapper = mount(DashboardView, { global: { plugins: [ElementPlus] } })
    await flushPromises()
    expect(wrapper.text()).toContain('全部单据已审核 ✓')
    expect(wrapper.text()).toContain('库存状态正常')
    expect(wrapper.text()).toContain('暂无进行中工单')
  })

  it('工单进度单区失败：显示重试按钮且其余区正常渲染', async () => {
    // 边界路径（TC-DSH-08 并行容错）：单区失败不影响其他区
    progressMock.mockRejectedValue(new Error('网络错误'))
    const wrapper = mount(DashboardView, { global: { plugins: [ElementPlus] } })
    await flushPromises()
    expect(wrapper.text()).toContain('工单进度加载失败')
    expect(wrapper.text()).toContain('重 试')
    expect(wrapper.text()).toContain('库存总量') // KPI 区正常
    expect(wrapper.text()).toContain('库存状态正常') // 预警区不受影响
  })

  it('无审核权限：待审核 KPI 卡与区块隐藏（TC-DSH-07）', async () => {
    // 边界路径（权限过滤展示）：接口仍请求（后端过滤），仅前端隐藏
    hasAll = false
    const wrapper = mount(DashboardView, { global: { plugins: [ElementPlus] } })
    await flushPromises()
    expect(pendingMock).toHaveBeenCalledTimes(1)
    expect(wrapper.text()).not.toContain('待审核单据')
    expect(wrapper.text()).toContain('库存总量')
    expect(wrapper.text()).toContain('今日入库')
  })

  it('点击待审核行：白名单内路径跳转', async () => {
    // 正常路径：后端下发 url 在白名单内 → 跳转
    pendingMock.mockResolvedValue({
      items: [
        {
          module: '采购',
          type: '订单',
          no: 'PO20260813-001',
          created_at: '2026-08-13 10:00:00',
          url: '/purchase/orders',
        },
      ],
    })
    const wrapper = mount(DashboardView, { global: { plugins: [ElementPlus] } })
    await flushPromises()
    await wrapper.find('.pending-row').trigger('click')
    expect(push).toHaveBeenCalledWith('/purchase/orders')
  })

  it('点击预警卡：跳转库存预警页', async () => {
    // 正常路径：预警卡固定跳 /inventory/alerts
    alertsMock.mockResolvedValue({
      items: [
        {
          product_name: '低库存原料',
          product_code: 'MAT-001',
          warehouse_name: '主仓',
          quantity: '3.00',
          safety_min: '10.00',
        },
      ],
    })
    const wrapper = mount(DashboardView, { global: { plugins: [ElementPlus] } })
    await flushPromises()
    await wrapper.find('.alert-card').trigger('click')
    expect(push).toHaveBeenCalledWith('/inventory/alerts')
  })

  it('点击工单行：跳转生产工单页（V1 详情由列表页承载）', async () => {
    // 正常路径：工单行固定跳 /production/orders
    progressMock.mockResolvedValue({
      items: [
        {
          no: 'MO20260813-001',
          product_name: '测试成品',
          quantity: '10',
          completed_qty: '2.5',
          progress: '25.00',
          status: 2,
          status_label: '生产中',
        },
      ],
    })
    const wrapper = mount(DashboardView, { global: { plugins: [ElementPlus] } })
    await flushPromises()
    expect(wrapper.text()).toContain('MO20260813-001')
    expect(wrapper.text()).toContain('25.00%')
    expect(wrapper.text()).toContain('生产中')
    await wrapper.find('.order-row').trigger('click')
    expect(push).toHaveBeenCalledWith('/production/orders')
  })
})
```

- [ ] **Step 3: 跑测试确认通过**

Run: `cd web && npx vitest run src/tests/dashboard-view.test.ts src/tests/dashboard.api.test.ts`
Expected: PASS（视图 9 + API 4 全绿）。

- [ ] **Step 4: 前端门禁验证**

Run: `cd web && npm run type-check && npm run lint && npm run lint:css && npm run format:check && npm run test:unit`
Expected: 全过（DashboardView 重写后 vue-tsc/eslint/stylelint/prettier/vitest 全绿；路由已存在不触发 TS2307）。

- [ ] **Step 5: 提交**

```bash
git add web/src/views/DashboardView.vue web/src/tests/dashboard-view.test.ts
git commit -m "feat: 仪表盘页（4 KPI 卡+待审核+工单进度+预警，并行容错+权限过滤）"
```

---

## Task 5: E2E 全量测试（Playwright TC-DSH-01~08 + 文档回填 + 全量门禁）

**Files:**
- Create: `web/e2e/zz-dashboard.spec.ts`（**文件名必须为 zz-dashboard.spec.ts**——字典序 `system < zz-dashboard` 跑在全部业务 spec 之后；见 Global Constraints「E2E 文件序」决策说明）
- Modify: `docs/test/2026-08-12-仪表盘模块端到端测试.md`（§5 测试结果记录表回填）
- Modify: `docs/progress/2026-08-13-项目进度交接.md`（§1 追加仪表盘完成小节、§2.1 移除、§3/§4 E2E 文件序行更新为含 zz-dashboard、错误码/权限约定补充）

**Interfaces:**
- Consumes: Task 1-4 全部前后端实现；`web/e2e/helpers.ts`（loginByAPI/loginByUI）+ 既有 spec 的 apiGet/apiPost 模式（token 取 localStorage + page.request）；上游 spec 遗留数据（生产模块完成态工单=TC-DSH-03 前置、今日流水=TC-DSH-01、草稿残留=计数分母）
- Produces: 仪表盘模块全流程 E2E（8 用例串行），验收标准 = 文档 TC-DSH-01~08 全部通过；自建数据（草稿采购单/预警商品/limited01 用户）全部按需清理，**永久残留（已审核单据/流水）不影响任何后续 spec（本文件为字典序最后一篇）**

**E2E 设计原则（implementer 必读）**：上游 spec 遗留数据随运行日期/上游用例变化——**一律「API 交叉核对」而非硬编码期望值**：用 apiGet 调上游接口（balances/movements/各模块列表/production orders/inventory alerts）算出期望，再与仪表盘接口响应逐项比对；UI 断言只锁定确定性元素（KPI 数值/空态文案/重试按钮/跳转 URL/权限隐藏）。本 spec 自建数据集中在「必须存在」的场景（草稿采购单、预警商品、limited01），其余（工单进度/今日流水/库存总量）依赖上游遗留并运行时核对。

- [ ] **Step 1: 创建 `web/e2e/zz-dashboard.spec.ts`（完整文件，勿截断）**

```ts
// 仪表盘模块 E2E：TC-DSH-01~08（KPI 核对/待审核跳转/工单进度/预警/联动刷新/空态/权限过滤/接口容错）
// 数据依赖：上游 7 个业务模块 spec 遗留的真实数据（生产模块完成态工单、今日流水等），
// 本 spec 自建数据（草稿采购单/预警商品/limited01）全部按需清理——期望值一律 API 交叉核对，零硬编码
// 文件命名锁定 zz-dashboard.spec.ts：字典序 system < zz-dashboard（必须跑在全部业务 spec 之后）——
// 自然命名 dashboard.spec.ts 会排在 inventory.spec 之前（d < i），届时仅种子数据、自建流水/余额
// 将破坏 inventory.spec 硬编码基线断言（MAT-001=100/FIN-002=20/盘点弹窗 toHaveCount(3)），
// 且已下达工单/已审核单据不可删——本文件为最后一篇，残留不影响任何后续 spec
import { expect, test, type Page } from '@playwright/test'
import { loginByAPI } from './helpers'

// 已登录页面的认证请求辅助：token 取自 localStorage（与 stats-report.spec 同构）
async function apiGet(page: Page, url: string, params: Record<string, string | number> = {}) {
  const token = await page.evaluate(() => localStorage.getItem('token'))
  const res = await page.request.get(url, { headers: { Authorization: `Bearer ${token}` }, params })
  expect(res.ok()).toBeTruthy()
  return (await res.json()).data
}
async function apiPost(page: Page, url: string, body?: unknown) {
  const token = await page.evaluate(() => localStorage.getItem('token'))
  const res = await page.request.post(url, {
    headers: { Authorization: `Bearer ${token}` },
    data: body,
  })
  return (await res.json()) as { code: number; message?: string; data?: unknown }
}
async function apiDelete(page: Page, url: string) {
  const token = await page.evaluate(() => localStorage.getItem('token'))
  const res = await page.request.delete(url, { headers: { Authorization: `Bearer ${token}` } })
  return (await res.json()) as { code: number; message?: string }
}

// 本地日期 YYYY-MM-DD（toISOString 为 UTC 会偏移一天，上游 spec 同款辅助）
function todayStr(): string {
  const d = new Date()
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

// 千分位（与前端 formatThousand 的 toLocaleString('zh-CN') 输出一致，KPI 文本断言用）
function thousand(s: string): string {
  return s.replace(/\B(?=(\d{3})+(?!\d))/g, ',')
}

// 待审核草稿总数：9 类单据列表接口 status=0 累加（与后端 pendingData 9 类同口径；
// 列表客户端过滤 status 而非传参——各模块 status 参数行为差异的防御）
const DRAFT_ENDPOINTS = [
  '/api/v1/purchase/orders',
  '/api/v1/purchase/inbounds',
  '/api/v1/sales/orders',
  '/api/v1/sales/outbounds',
  '/api/v1/inventory/checks',
  '/api/v1/production/picks',
  '/api/v1/production/returns',
  '/api/v1/production/outsourcings',
  '/api/v1/production/finished-inbounds',
]
async function countDrafts(page: Page): Promise<number> {
  let n = 0
  for (const url of DRAFT_ENDPOINTS) {
    const data = await apiGet(page, url, { per_page: 100 })
    n += (data.items as { status?: number }[]).filter((i) => i.status === 0).length
  }
  return n
}

// 供应商确保存在（种子无供应商；不存在则自建 DSH 供应商）
async function ensureSupplier(page: Page): Promise<{ id: number }> {
  const list = await apiGet(page, '/api/v1/suppliers', { per_page: 100 })
  const items = list.items as { id: number }[]
  if (items.length > 0) return { id: items[0]!.id }
  const res = await apiPost(page, '/api/v1/suppliers', { name: 'DSH 供应商', code: 'SUP-DSH', status: 1 })
  expect(res.code).toBe(0)
  return { id: (res.data as { id: number }).id }
}

// 新建采购订单草稿（payload 与 PurchaseOrderController validatePayload 对齐：supplier_id/order_date/items）
async function createDraftPo(
  page: Page,
  supplierId: number,
  productId: number,
): Promise<{ id: number; no: string }> {
  const res = await apiPost(page, '/api/v1/purchase/orders', {
    supplier_id: supplierId,
    order_date: todayStr(),
    items: [{ product_id: productId, quantity: 1, price: 100 }],
  })
  expect(res.code).toBe(0)
  return res.data as { id: number; no: string }
}

test.describe('仪表盘模块 E2E（TC-DSH-01~08）', () => {
  // 用例间共享登录态与自建数据；串行执行保证确定性（文件本身字典序最后一篇）
  test.describe.configure({ mode: 'serial' })

  test('TC-DSH-01 KPI 卡片数字核对', async ({ page }) => {
    await loginByAPI(page, 'admin', 'admin123')

    // 挂载并行 4 请求收集（E2E 文档 step 1：页面挂载即发 4 个 dashboard 请求）
    const seen = new Set<string>()
    page.on('request', (r) => {
      const path = new URL(r.url()).pathname
      if (path.startsWith('/api/v1/dashboard/')) seen.add(path)
    })
    await page.goto('/dashboard')
    await expect(page.getByText('库存总量')).toBeVisible()
    await expect.poll(() => seen.size).toBe(4)

    // —— 期望值计算：全部运行时 API 交叉核对（零硬编码） ——
    // 库存总量 = 余额页全部行求和
    const balances = await apiGet(page, '/api/v1/inventory/balances', { per_page: 100 })
    const totalQty = (balances.items as { quantity: number }[]).reduce(
      (s, r) => s + Number(r.quantity),
      0,
    )
    // 今日出入库 = 流水页今日方向 Σ（date_from/date_to 闭区间）
    const movs = await apiGet(page, '/api/v1/inventory/movements', {
      date_from: todayStr(),
      date_to: todayStr(),
      per_page: 100,
    })
    let inQty = 0
    let outQty = 0
    for (const m of movs.items as { direction: number; quantity: number }[]) {
      if (m.direction === 1) inQty += Number(m.quantity)
      else outQty += Number(m.quantity)
    }
    // 待审核 = 9 类草稿总数
    const drafts = await countDrafts(page)

    // —— 与 summary 接口逐项比对 ——
    const summary = await apiGet(page, '/api/v1/dashboard/summary')
    expect(Number(summary.inventory_total_qty)).toBeCloseTo(totalQty, 2)
    expect(Number(summary.today_inbound_qty)).toBeCloseTo(inQty, 2)
    expect(Number(summary.today_outbound_qty)).toBeCloseTo(outQty, 2)
    expect(summary.pending_approvals).toBe(drafts)

    // —— UI 文本核对（千分位格式 + 方向色前缀） ——
    await expect(page.locator('.kpi-card', { hasText: '库存总量' }).locator('.kpi-value')).toHaveText(
      thousand(totalQty.toFixed(2)),
    )
    await expect(page.locator('.kpi-card', { hasText: '今日入库' }).locator('.kpi-value')).toHaveText(
      `+${thousand(inQty.toFixed(2))}`,
    )
    await expect(page.locator('.kpi-card', { hasText: '今日出库' }).locator('.kpi-value')).toHaveText(
      `-${thousand(outQty.toFixed(2))}`,
    )
    await expect(
      page.locator('.kpi-card', { hasText: '待审核单据' }).locator('.kpi-value'),
    ).toHaveText(String(drafts))
  })

  test('TC-DSH-02 待审核列表与跳转', async ({ page }) => {
    await loginByAPI(page, 'admin', 'admin123')

    // 前置：自建 1 张草稿采购订单（列表/点击断言对象）
    const sup = await ensureSupplier(page)
    const products = await apiGet(page, '/api/v1/products', { per_page: 100 })
    const draft = await createDraftPo(page, sup.id, (products.items as { id: number }[])[0]!.id)

    await page.goto('/dashboard')
    const pending = await apiGet(page, '/api/v1/dashboard/pending-approvals')
    const rows = pending.items as {
      no: string
      module: string
      type: string
      url: string
      created_at: string
    }[]
    // 接口形状：≤20 条、含自建单、创建时间倒序（字符串字典序=时间序）
    expect(rows.length).toBeLessThanOrEqual(20)
    expect(rows.some((r) => r.no === draft.no)).toBeTruthy()
    for (let i = 1; i < rows.length; i++) {
      expect(rows[i - 1]!.created_at >= rows[i]!.created_at).toBeTruthy()
    }
    // UI：行内类型标签 + 单号（Fira Code）+ 时间
    const row = page.locator('.pending-row', { hasText: draft.no })
    await expect(row).toBeVisible()
    await expect(row.locator('.type-tag')).toHaveText('订单')
    await expect(row.locator('.pending-no')).toHaveText(draft.no)
    // 点击 → 跳转 url 字段路由（采购订单页）
    await row.click()
    await expect(page).toHaveURL(/\/purchase\/orders/)
    // 返回仪表盘：列表正常
    await page.goto('/dashboard')
    await expect(page.locator('.pending-row', { hasText: draft.no })).toBeVisible()

    // 清理：删除自建草稿（删除守卫仅拦已审核/被引用，草稿可删）
    const del = await apiDelete(page, `/api/v1/purchase/orders/${draft.id}`)
    expect(del.code).toBe(0)
  })

  test('TC-DSH-03 工单进度', async ({ page }) => {
    await loginByAPI(page, 'admin', 'admin123')

    // 前置数据：上游生产模块遗留的生产中/已完成工单（E2E 文档 §2 前置条件）
    const expected: {
      no: string
      product_name: string
      quantity: string
      completed_qty: string
      status: number
    }[] = []
    for (const status of [2, 3]) {
      const data = await apiGet(page, '/api/v1/production/orders', { status, per_page: 100 })
      for (const o of data.items as typeof expected) expected.push(o)
    }
    expect(expected.length).toBeGreaterThan(0) // 生产 spec 是既定前置依赖，若为空说明上游数据被破坏

    await page.goto('/dashboard')
    const data = await apiGet(page, '/api/v1/dashboard/work-order-progress')
    const items = data.items as {
      no: string
      product_name: string
      quantity: string
      completed_qty: string
      progress: string
      status: number
      status_label: string
    }[]
    expect(items.length).toBeLessThanOrEqual(10)
    // 交叉核对：仪表盘行 ⊆ 生产模块工单列表
    const expectedNos = new Set(expected.map((o) => o.no))
    for (const row of items) {
      expect(expectedNos.has(row.no)).toBeTruthy()
      const src = expected.find((o) => o.no === row.no)!
      expect(row.product_name).toBe(src.product_name)
      expect(row.quantity).toBe(String(src.quantity))
      expect(row.completed_qty).toBe(String(src.completed_qty))
      // 进度口径 = completed/quantity×100（容忍 0.01 浮点/舍入差）
      const want = (Number(src.completed_qty) / Number(src.quantity)) * 100
      expect(Number(row.progress)).toBeCloseTo(want, 2)
    }
    // UI：进度条 + 进度文本 + 状态标签
    const first = items[0]!
    const orderRow = page.locator('.order-row', { hasText: first.no })
    await expect(orderRow).toBeVisible()
    await expect(orderRow.locator('.progress-text')).toHaveText(`${first.progress}%`)
    await expect(orderRow.locator('.el-tag')).toHaveText(first.status_label)
    // 点击 → 跳转生产工单页（V1 无独立详情路由，列表页承载详情 tabs）
    await orderRow.click()
    await expect(page).toHaveURL(/\/production\/orders/)
  })

  test('TC-DSH-04 预警列表联动', async ({ page }) => {
    await loginByAPI(page, 'admin', 'admin123')

    // 自建低库存预警商品：有下限无余额（0 < safety_min）→ level=1 预警
    const catTree = (await apiGet(page, '/api/v1/categories')) as { id: number }[]
    const units = await apiGet(page, '/api/v1/units', { per_page: 100 })
    const created = await apiPost(page, '/api/v1/products', {
      name: 'DSH 预警原料',
      code: 'DSH-MAT-001',
      type: 'raw_material',
      category_id: catTree[0]!.id,
      unit_id: (units.items as { id: number }[])[0]!.id,
      safety_min: 10,
      safety_max: 0,
      status: 1,
    })
    expect(created.code).toBe(0)
    const alertProductId = (created.data as { id: number }).id

    await page.goto('/dashboard')
    // 交叉核对：仪表盘 alerts = 库存预警接口 level=1（低库存）过滤前 10（两处同为 product_id 升序）
    const src = await apiGet(page, '/api/v1/inventory/alerts')
    const lowOnly = (src.items as { level: number }[]).filter((a) => a.level === 1).slice(0, 10)
    const data = await apiGet(page, '/api/v1/dashboard/alerts')
    const items = data.items as { product_code: string }[]
    expect(items.length).toBe(lowOnly.length)
    for (let i = 0; i < items.length; i++) {
      expect(items[i]!.product_code).toBe((lowOnly[i] as { product_code: string }).product_code)
    }
    // 自建商品必在列表内（上游无其他低库存预警）
    expect(items.some((i) => i.product_code === 'DSH-MAT-001')).toBeTruthy()
    // UI：预警卡渲染
    await expect(page.locator('.alert-card', { hasText: 'DSH-MAT-001' })).toBeVisible()
    // 点击 → 跳转库存预警页
    await page.locator('.alert-card', { hasText: 'DSH-MAT-001' }).click()
    await expect(page).toHaveURL(/\/inventory\/alerts/)

    // 清理：删除自建预警商品（无引用可删；预警列表复原）
    const del = await apiDelete(page, `/api/v1/products/${alertProductId}`)
    expect(del.code).toBe(0)
  })

  test('TC-DSH-05 联动刷新（构造草稿 → 审核 → 计数变化）', async ({ page }) => {
    await loginByAPI(page, 'admin', 'admin123')
    const before = await apiGet(page, '/api/v1/dashboard/summary')
    const n = before.pending_approvals as number

    const sup = await ensureSupplier(page)
    const products = await apiGet(page, '/api/v1/products', { per_page: 100 })
    const draft = await createDraftPo(page, sup.id, (products.items as { id: number }[])[0]!.id)

    // 刷新仪表盘：KPI 显示 N+1；列表顶部新增该单
    await page.goto('/dashboard')
    await expect(
      page.locator('.kpi-card', { hasText: '待审核单据' }).locator('.kpi-value'),
    ).toHaveText(String(n + 1))
    const pending = await apiGet(page, '/api/v1/dashboard/pending-approvals')
    expect((pending.items as { no: string }[])[0]!.no).toBe(draft.no)

    // 回采购订单页审核该单（UI 流程与 purchase.spec TC-PUR-02 同构：审 核 → 确认 → 成功提示）
    await page.goto('/purchase/orders')
    const target = page.locator('.el-table__row', { hasText: draft.no })
    await expect(target).toBeVisible()
    await target.getByRole('button', { name: /审\s*核/ }).click()
    await expect(page.locator('.el-message-box')).toContainText('确认审核订单')
    await page.locator('.el-message-box').getByRole('button', { name: '确定' }).click()
    await expect(page.locator('.el-message--success')).toContainText('审核成功')

    // 刷新仪表盘：待审核数回到 N；该单从列表消失
    await page.goto('/dashboard')
    await expect(
      page.locator('.kpi-card', { hasText: '待审核单据' }).locator('.kpi-value'),
    ).toHaveText(String(n))
    await expect(page.locator('.pending-row', { hasText: draft.no })).toHaveCount(0)
  })

  test('TC-DSH-06 空态', async ({ page }) => {
    await loginByAPI(page, 'admin', 'admin123')

    // 前置 1：防御性清理自建预警商品（TC-DSH-04 已删；若 04 中断残留则此处再删）
    const leftover = await apiGet(page, '/api/v1/products', { keyword: 'DSH-MAT-001', per_page: 100 })
    for (const p of (leftover.items as { id: number }[])) {
      await apiDelete(page, `/api/v1/products/${p.id}`)
    }
    // 前置 2：临时清空草稿数据（E2E 文档允许「审核或临时清空」；删除比审核更稳健——审核受库存校验约束）
    for (const url of DRAFT_ENDPOINTS) {
      const data = await apiGet(page, url, { per_page: 100 })
      const ids = (data.items as { id: number; status: number }[])
        .filter((i) => i.status === 0)
        .map((i) => i.id)
      for (const id of ids) {
        const del = await apiDelete(page, `${url}/${id}`)
        expect(del.code).toBe(0)
      }
    }

    // 空态断言：待审核「全部单据已审核 ✓」；KPI 待审核 = 0
    await page.goto('/dashboard')
    await expect(page.locator('.empty-ok', { hasText: '全部单据已审核' })).toBeVisible()
    await expect(
      page.locator('.kpi-card', { hasText: '待审核单据' }).locator('.kpi-value'),
    ).toHaveText('0')
    // 预警空态：上游无低库存预警（种子/上游 spec 均不制造）→ 硬断言无预警后 UI 显示「库存状态正常」
    const alerts = await apiGet(page, '/api/v1/inventory/alerts')
    expect((alerts.items as { level: number }[]).filter((a) => a.level === 1).length).toBe(0)
    await expect(page.locator('.empty-ok', { hasText: '库存状态正常' })).toBeVisible()

    // 恢复现场：重建 1 张草稿采购订单（E2E 文档「重建草稿单等」；预警商品无需恢复——本文件无后续用例依赖）
    const sup = await ensureSupplier(page)
    const products = await apiGet(page, '/api/v1/products', { per_page: 100 })
    await createDraftPo(page, sup.id, (products.items as { id: number }[])[0]!.id)
  })

  test('TC-DSH-07 权限过滤（limited01 仅 *.list）', async ({ page }) => {
    await loginByAPI(page, 'admin', 'admin123')

    // 自建 limited01（挂 operator 角色——仅持有 %.list + dashboard.view 例外）
    const roles = await apiGet(page, '/api/v1/roles', { per_page: 100 })
    const opId = (roles.items as { id: number; code: string }[]).find((r) => r.code === 'operator')!.id
    const created = await apiPost(page, '/api/v1/users', {
      name: '只读用户',
      username: 'limited01',
      email: 'limited01@php-design.local',
      password: 'Test@12345',
      status: 1,
      role_ids: [opId],
    })
    expect(created.code).toBe(0)
    const limitedId = (created.data as { id: number }).id

    // 越权前置：确保存在一张草稿采购订单（06 已恢复一张；此处自建一张确定性对象）
    const sup = await ensureSupplier(page)
    const products = await apiGet(page, '/api/v1/products', { per_page: 100 })
    const draft = await createDraftPo(page, sup.id, (products.items as { id: number }[])[0]!.id)

    // limited01 登录：KPI 卡（库存总量/今日出入库）正常；待审核卡与区块隐藏
    await loginByAPI(page, 'limited01', 'Test@12345')
    await expect(page.locator('.kpi-card', { hasText: '库存总量' })).toBeVisible()
    await expect(page.locator('.kpi-card', { hasText: '今日入库' })).toBeVisible()
    await expect(page.locator('.kpi-card', { hasText: '今日出库' })).toBeVisible()
    await expect(page.locator('.kpi-card', { hasText: '待审核单据' })).toHaveCount(0)
    await expect(page.locator('#pending-panel')).toHaveCount(0)

    // 后端过滤：pending-approvals 返回空 items（无审核权限 → 看不到任何草稿）
    const pending = await apiGet(page, '/api/v1/dashboard/pending-approvals')
    expect(pending.items).toEqual([])

    // 越权：带 approve 动作的单据接口 → 403（审核复用 update 权限，operator 不持有）
    const attempt = await apiPost(page, `/api/v1/purchase/orders/${draft.id}/approve`)
    expect(attempt.code).toBe(403)

    // 清理：admin 删除 limited01 与自建草稿
    await loginByAPI(page, 'admin', 'admin123')
    const delUser = await apiDelete(page, `/api/v1/users/${limitedId}`)
    expect(delUser.code).toBe(0)
    const delDraft = await apiDelete(page, `/api/v1/purchase/orders/${draft.id}`)
    expect(delDraft.code).toBe(0)
  })

  test('TC-DSH-08 接口容错（单接口 500 不影响其余区域）', async ({ page }) => {
    await loginByAPI(page, 'admin', 'admin123')

    // 拦截工单进度接口 → 500（E2E 文档 route 方式；fulfill 统一响应体形状）
    await page.route('**/dashboard/work-order-progress', (route) =>
      route.fulfill({
        status: 500,
        contentType: 'application/json',
        body: JSON.stringify({ code: 500, message: '服务异常' }),
      }),
    )
    await page.goto('/dashboard')
    // 工单进度区：失败 → 重试按钮；其余区正常渲染
    await expect(
      page.locator('.panel', { hasText: '工单进度' }).getByRole('button', { name: '重 试' }),
    ).toBeVisible()
    await expect(page.locator('.kpi-card', { hasText: '库存总量' }).locator('.kpi-value')).toBeVisible()
    await expect(page.locator('.panel', { hasText: '库存预警' })).toBeVisible()

    // 恢复：解除拦截后重载正常
    await page.unroute('**/dashboard/work-order-progress')
    await page.goto('/dashboard')
    await expect(page.locator('.panel-title', { hasText: '工单进度' })).toBeVisible()
    await expect(page.getByRole('button', { name: '重 试' })).toHaveCount(0)
  })
})
```

**Step 1 附注（implementer 必读）**：`createDraftPo` 的 `order_date` 用 `todayStr()`（本地日期，`toISOString` 会偏移）；审核按钮/确认框文案若与 purchase.spec TC-PUR-02 有出入**以 purchase.spec 现行为准**（`/审\s*核/`、`.el-message-box` 含「确认审核订单」、确定按钮）；TC-DSH-08 的 `page.route` 拦截浏览器侧请求（axios 经 vite 代理同源），`apiGet` 的 `page.request` 不受 route 影响——勿用 apiGet 验证拦截状态；`page.unroute` 恢复后重载断言无「重 试」按钮。

- [ ] **Step 2: 跑全量 E2E（本 spec 依赖上游 spec 数据，单文件跑不可行）**

Run: `cd web && npx playwright test`
Expected: 68 用例全绿（60 既有 + 8 新增）。**注意**：`npx playwright test e2e/zz-dashboard.spec.ts` 单文件跑**必然失败 TC-DSH-03**——上游生产 spec 未执行、无完成态工单（E2E 文档 §2 前置条件「存在 ≥1 个生产中工单」由上游遗留数据满足）；单文件跑仅可用于 TC-DSH-01/02/05/08 等自给用例的快速验证。TC-DSH-03 失败信息「上游数据被破坏」即提示此前提。

- [ ] **Step 3: 回填 `docs/test/2026-08-12-仪表盘模块端到端测试.md` §5 测试结果记录表**

按实际运行结果填写：

```markdown
| 用例 | 结果（通过/失败） | 失败层级 | 失败详情 | 修复引用 |
|---|---|---|---|---|
| TC-DSH-01 | 通过 | | | |
| TC-DSH-02 | 通过 | | | |
| TC-DSH-03 | 通过 | | | |
| TC-DSH-04 | 通过 | | | |
| TC-DSH-05 | 通过 | | | |
| TC-DSH-06 | 通过 | | | |
| TC-DSH-07 | 通过 | | | |
| TC-DSH-08 | 通过 | | | |
```

- [ ] **Step 4: 回填 `docs/progress/2026-08-13-项目进度交接.md`**

1. §1 追加 `### 1.5 仪表盘模块（2026-08-13，PR 号回填，N commits）` 小节（内容与本计划 Goal/决策一致：4 接口+权限设计+前端页+E2E 8 用例+验证数字——**数字以实际运行结果为准**，勿抄本计划估算）
2. §2.1 删除（仪表盘已完成），§2.3 若新增技术债则补记
3. §3 E2E 文件序行更新为 `auth < dashboard(zz-dashboard 在 system 之后) < ... < system < zz-dashboard`（写清 zz-dashboard 命名原因）
4. §4 接口契约追加 `dashboard.view` 权限决策（operator 例外）与「仪表盘纯读无业务码」；错误码区间说明仪表盘无新错误码
5. §1 元信息 `版本控制提交号` 更新为最终合入提交号

- [ ] **Step 5: 全量门禁（后端 + 前端，勿与 E2E 并行）**

Run（后端）：

```bash
cd server && vendor/bin/phpstan analyse --no-progress --memory-limit=1G && vendor/bin/phpcs -q; S=$?; [ $S -ne 0 ] && [ $S -ne 2 ] && exit $S; vendor/bin/pint --test && php artisan test
```

Run（前端）：

```bash
cd web && npm run type-check && npm run lint && npm run lint:css && npm run format:check && npm run test:unit
```

Expected: 后端 phpstan 0、phpcs 退出码 ≤2、pint PASS、phpunit 全绿（原 412 + 本模块新增约 24）；前端五门禁全绿（Vitest 原 73 + 本模块 13）。

- [ ] **Step 6: 全量 E2E（后端/前端门禁通过后单独跑）**

Run: `cd web && npx playwright test`
Expected: 60 + 8 = 68 用例全绿（zz-dashboard 排最后，顺序符合字典序）。

- [ ] **Step 7: 提交 + PR 合入 main（分支保护流程，参照 Global 约定）**

```bash
git add web/e2e/zz-dashboard.spec.ts docs/test/2026-08-12-仪表盘模块端到端测试.md docs/progress/2026-08-13-项目进度交接.md
git commit -m "test: 仪表盘模块 E2E（TC-DSH-01~08）+ 测试文档与进度文档回填"
# 随后按仓库流程：git checkout -b feat/dashboard → push → gh pr create --base main
# → gh pr checks --watch 云端 CI 四门禁全绿 → gh pr merge --rebase --delete-branch
# → git fetch && git reset --hard origin/main
```

---

## 计划自审记录（writing-plans Self-Review）

**1. Spec 覆盖核对**（`docs/superpowers/specs/2026-08-12-dashboard-spec.md` 逐节）：

- §1 只读聚合/默认落地页 → Task 1/2（零写操作）；§2 依赖 → 前置模块已完成，E2E 文件序放最后
- §3 四接口字段 → Task 1 Produces 逐字段对齐（`inventory_value` null 语义、`pending_approvals` 权限过滤、`work_order_running`、`alert_count`；work-order-progress 响应增加 status_label 扩展字段，见 Task 1 Produces 注释）；`url` 字段 → Task 1 `pendingData` 9 类登记
- §4 页面与交互 → Task 4（4 KPI 卡 grid/中部 2:1 双栏/底栏/空态/无手动刷新/并行 Promise 加载）；「待审核卡点击跳转待审核区」→ scrollToPending；「单位说明」——库存总量为跨单位聚合，**不加单位**（设计决策，见 dashboard.md 设计系统页 §2 未列单位项）
- §5 口径约定 → Task 1（今日=当天闭区间、待审核=草稿+审核权限、工单进度=2/3 态）；url 白名单 → Task 4 ALLOWED_PATHS
- §6 DSH-01~07 → Task 5 E2E 逐用例（DSH-06 空态用「临时清空草稿数据」路径——E2E 文档明确允许）；DSH-07 的 403 拦截 → DashboardApiTest + E2E step 3
- §7 边界与异常 → value=null（Task 1 测试 + Task 4 视图测试）；单接口失败容错（Task 4 分区状态 + TC-DSH-08）；空列表（空态）；level=1 仅低库存（Task 1 alertQuery + 单测）
- **E2E 文档 TC-DSH-01~08 全覆盖**；TC-DSH-08 的 `playwright-cli route` 以 `page.route` 落地（测试内等价物）

**2. 占位符扫描**：无 TBD/TODO/「类似 Task N」/「补充错误处理」类条目；所有代码块完整。Task 2 Step 6 附注、Task 4 Step 1 附注、Task 5 Step 1 附注为「实施时若有出入的裁决指引」，非占位。

**3. 类型一致性核对**：
- `DashboardService` 方法签名在 Task 1 Produces 与 Task 2 控制器调用完全一致（`summary(User)/pendingApprovals(User)/workOrderProgress()/alerts()`）
- 响应字段名跨 Task 一致：`inventory_total_qty/inventory_value/today_inbound_qty/today_outbound_qty/pending_approvals/work_order_running/alert_count`；`items[].module/type/no/created_at/url`；`items[].no/product_name/quantity/completed_qty/progress/status/status_label`；`items[].product_name/product_code/warehouse_name/quantity/safety_min`
- 前端 `dashboardApi` 类型（Task 3）与 Task 1 Produces 逐字段一致；DashboardView（Task 4）引用 `dashboardApi/formatThousand/useAuthStore/useRouter` 均来自 Task 3/既有代码
- E2E（Task 5）定位类名与 Task 4 模板一致：`.kpi-card/.kpi-value/.kpi-in/.kpi-out/.kpi-warn/.pending-row/.type-tag/.pending-no/.order-row/.progress-text/.alert-card/.empty-ok/#pending-panel/.panel/.panel-title`
- 权限码 9 项在三处一致：`DashboardService::pendingData` 9 个 if 块（Task 1）、`DashboardView::APPROVE_PERMISSIONS`（Task 4）、E2E 无硬编码（交叉核对）

**4. 与既有测试的冲突核对**：唯一受影响的既有测试为 `ReportStructureTest::test_operator_does_not_hold_report_permissions`（operator「%.list」循环）——Task 2 Step 5 已列精确修改；其余既有测试不受影响（仪表盘零迁移零新表，不改任何既有控制器/服务/前端组件）。
