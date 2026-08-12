# 库存管理模块 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 实现库存管理模块（库存余额/流水引擎、盘点单全链路、库存预警）的 Laravel 13 后端 API 与 Vue 3 前端页面，通过全部 PHPUnit/Vitest 测试与 E2E 测试文档 `docs/test/2026-08-12-库存管理模块端到端测试.md` 的 TC-INV-01~12（含基础资料 TC-MST-03 的 1106 删除保护补测）。

**Architecture:** 前后端分离，复用系统管理/基础资料模块全部基座（统一响应 `{code,message,data}`、`ApiResponse` trait、`permission:` 中间件、Sanctum 认证、前端 http/auth store/路由守卫/主布局）。后端 4 张新表；`InventoryService` 是全系统唯一库存变动入口（采购/销售/生产模块后续复用），一切变动事务内「写 `inventory_movements` 流水 + 更新 `inventory_balances`」，余额=流水求和为核心不变式；盘点审核走事务+行锁防并发。前端 4 个页面（余额/流水/盘点/预警）复用「工具栏 + el-table + el-dialog」模式，样式遵循 nexus-factory 设计系统（本计划 Task 5 落地 `design-system/nexus-factory/pages/inventory.md` 页覆盖，ui-ux-pro-max）。

**Tech Stack:** PHP 8.5.9、Laravel 13.25.0、MySQL 8.4（Docker）、Vue 3 + TypeScript + Vite + Pinia + Vue Router + Element Plus、PHPUnit、Vitest、@playwright/test（web/e2e/ 自动化框架）。

## Global Constraints

以下约束对每个 Task 隐式生效（来自主 spec §6/§8/§10 与库存细化 spec，逐条原文）：

- 统一响应：`{code, message, data}`；`code=0` 成功；错误码：1201 实盘数量不能为负数、1202 已审核单据不可修改、1203 已审核单据不可删除、1204 该盘点单已审核、1205 商品在该仓库无库存，无需盘点、1206 库存已变动，请重新盘点；422 仅用于格式校验，业务冲突一律走上述业务码
- **核心不变式（测试必须验证）**：① 每笔业务变动事务内同时写 `inventory_movements` + 更新 `inventory_balances.quantity`；② 任一商品×仓库×库位的余额恒等于其全部流水 `direction*quantity` 之和；③ 盘点审核后差异≠0 的项生成 `check_in`(+)/`check_out`(-) 流水；④ 同一单据审核幂等：重复审核被拒绝（1204）
- 余额 quantity 允许为 0 但不允许为负（出库超卖被业务层拒绝）；流水只增不改不删（审计要求），V1 不做红冲
- 预警为查询时计算（不落库），上下限修改后立即生效：`min>0 且 quantity<min` → level=1（低于下限）；`max>0 且 quantity>max` → level=2（高于上限）；否则 0（0=不预警该侧）
- 单号规则：`CK{yyyyMMdd}-{3位流水}`（如 CK20260812-001，当日已有单号数+1，撞 unique 冲突重试最多 3 次，参照 BOM）
- API 前缀 `/api/v1`；权限中间件 `permission:{资源}.{动作}`；新权限 6 项追加到 `RbacSeeder`（group=库存管理）：`inventory.list`、`check.list/create/update/delete`（盘点审核复用 `check.update`，与 BOM toggle 模式一致）；admin 自动全量持有，operator 自动持有全部 `%.list`
- 分页统一 `{items,total,page,per_page}`；per_page 钳制 `max(1, min(100, (int) $request->input('per_page', 10)))`
- 数量一律 decimal(12,2)；`source_type` 枚举 9 值：purchase_inbound/sales_outbound/pick/return/finished_inbound/outsourcing_out/outsourcing_in/check_in/check_out
- 盘点商品仅限该仓库存在余额的商品（无余额商品 1205）；审核时若该商品已被并发审核（账面快照与当前余额不一致）→ 1206 整体回滚（事务 + `SELECT ... FOR UPDATE` 行锁保证）
- 中文注释（类级/方法级/关键行）；UTF-8 无 BOM；LF 行尾（.gitattributes 已强制）；无死代码
- 核心路径（库存一致性、事务双写、审核并发防重、恒等式）单元测试 100% 覆盖；测试命名表达业务意图，覆盖正常/边界/异常
- 前端：侧边栏深色 `#0F172A`（220px），内容区 `#F8FAFC`；主色 `#334155`、强调绿 `#059669`、危险 `#DC2626`、琥珀 `#D97706`、蓝 `#3B82F6`；Fira Code + Fira Sans；所有可点击元素 `cursor:pointer`；按钮文案「新 建/保 存/编 辑/删 除/导 出/审 核/查 看/加 载」带全角空格（E2E 按文案定位）；数量列 Fira Code 加粗；方向 +绿 `#059669` / -红 `#DC2626`；状态标签：草稿灰/已审核绿；预警语义色：低库存红 `#DC2626`、超上限琥珀 `#D97706`
- 端口：后端 `http://localhost:8000`、前端 `http://localhost:5173`、MySQL `3306`；本机命令 `php`/`composer` 已入 PATH；Python=`D:\code\envs\python\3.14.6\python.exe`
- **工程纪律**：严禁运行 pint/prettier 等格式化工具（会污染全仓）；提交前 `git status` 精确暂存（禁止 `git add -A`）；每 Task 提交一次
- **E2E 基线数据（InventorySeeder 注入，数字精确勿改）**：MAT-001 测试铝材(原料,min50,max500,条码100001)@主仓 A-01=100；SEMI-001 半成品A(半成品,min10,max200,条码100002)@主仓 A-01=30；FIN-002 成品B(成品,min0,max0,条码888888)@主仓 B-01=20；流水来源=占位采购单号（`PO{date}-SEED`），采购模块实施后由真实单据取代

---

## Task 1: 库存数据模型（4 表迁移 + 4 模型 + 权限种子）

**Files:**
- Create: `server/database/migrations/2026_08_12_090000_create_inventory_tables.php`
- Create: `server/app/Models/{InventoryBalance,InventoryMovement,InventoryCheck,InventoryCheckItem}.php`
- Create: `server/tests/Feature/InventoryStructureTest.php`
- Modify: `server/database/seeders/RbacSeeder.php`（权限数组追加 6 项库存管理权限）

**Interfaces:**
- Consumes: 基础资料模块的 products/warehouses/locations 表与模型；RbacSeeder 结构
- Produces: 4 张表（字段见下）；4 个模型；`InventoryMovement::SOURCE_TYPES`（9 值枚举数组）与 `SOURCE_TYPE_LABELS`（中文标签映射，Task 3 流水列表用）；`InventoryCheck::STATUS_DRAFT=0/STATUS_APPROVED=1`（Task 4 状态机用）；权限 6 项（group=库存管理）。**inventory_balances/inventory_movements 表落地后，基础资料模块 DeletionGuard 的商品（1116）/仓库（1106）/库位（1107）删除保护自动生效**

- [ ] **Step 1: 写失败测试 `server/tests/Feature/InventoryStructureTest.php`**

```php
<?php
// 库存数据模型测试：表结构、权限种子、流水类型枚举、联合唯一索引（核心数据结构，100% 覆盖）
namespace Tests\Feature;

use App\Models\InventoryBalance;
use App\Models\InventoryMovement;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class InventoryStructureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // 种子：RBAC + 基础资料主数据（本模块种子 InventorySeeder 在 Task 2 注册）
        $this->seed();
    }

    public function test_all_inventory_tables_exist(): void
    {
        // 正常路径：4 张库存表全部建立
        foreach (['inventory_balances', 'inventory_movements', 'inventory_checks', 'inventory_check_items'] as $table) {
            $this->assertTrue(Schema::hasTable($table), "表 {$table} 不存在");
        }
    }

    public function test_inventory_permissions_seeded_for_admin(): void
    {
        // 正常路径：库存管理 5 项权限已注册且 admin 角色全量持有（盘点审核复用 check.update）
        $this->assertSame(5, Permission::where('group', '库存管理')->count());
        $admin = Role::where('code', 'admin')->first();
        $this->assertSame(2, $admin->permissions()->whereIn('code', ['inventory.list', 'check.update'])->count());
    }

    public function test_movement_source_types_cover_spec_enum(): void
    {
        // 正常路径：9 种来源类型与中文标签一一映射（采购/销售/生产模块将复用）
        $this->assertSame(
            ['purchase_inbound', 'sales_outbound', 'pick', 'return', 'finished_inbound', 'outsourcing_out', 'outsourcing_in', 'check_in', 'check_out'],
            InventoryMovement::SOURCE_TYPES
        );
        $this->assertSame('采购入库', InventoryMovement::SOURCE_TYPE_LABELS['purchase_inbound']);
        $this->assertSame('盘盈', InventoryMovement::SOURCE_TYPE_LABELS['check_in']);
        $this->assertSame('盘亏', InventoryMovement::SOURCE_TYPE_LABELS['check_out']);
    }

    public function test_balance_unique_index_blocks_duplicate_row(): void
    {
        // 边界路径：联合唯一索引兜底并发首次入库（重复行插入被 DB 拒绝）
        InventoryBalance::create(['product_id' => 1, 'warehouse_id' => 1, 'location_id' => 1, 'quantity' => 1]);
        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('inventory_balances')->insert(['product_id' => 1, 'warehouse_id' => 1, 'location_id' => 1, 'quantity' => 2, 'created_at' => now(), 'updated_at' => now()]);
    }
}
```

- [ ] **Step 2: 跑测试确认失败**

Run: `cd server && php artisan test --filter=InventoryStructureTest`
Expected: FAIL（表/模型/权限不存在）。

- [ ] **Step 3: 创建迁移**

创建 `server/database/migrations/2026_08_12_090000_create_inventory_tables.php`：

```php
<?php
// 库存核心表：余额/流水/盘点（头+明细），一切库存变动唯一事实来源
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete()->comment('商品');
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete()->comment('仓库');
            $table->foreignId('location_id')->constrained('locations')->restrictOnDelete()->comment('库位');
            $table->decimal('quantity', 12, 2)->default(0)->comment('当前余额，允许0不允许负');
            $table->decimal('safety_min', 12, 2)->default(0)->comment('安全库存下限冗余（自商品，查询用快照）');
            $table->decimal('safety_max', 12, 2)->default(0)->comment('安全库存上限冗余（自商品，查询用快照）');
            $table->timestamps();
            // 商品×仓库×库位唯一：并发首次入库靠此约束兜底（Service 捕获 1062 重查加锁）
            $table->unique(['product_id', 'warehouse_id', 'location_id'], 'balance_unique');
        });

        Schema::create('inventory_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete()->comment('商品');
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete()->comment('仓库');
            $table->foreignId('location_id')->constrained('locations')->restrictOnDelete()->comment('库位');
            $table->tinyInteger('direction')->comment('1=入库 -1=出库');
            $table->decimal('quantity', 12, 2)->comment('变动数量（恒正）');
            $table->decimal('balance_after', 12, 2)->comment('变动后余额快照');
            $table->string('source_type', 30)->comment('来源类型枚举：purchase_inbound/sales_outbound/pick/return/finished_inbound/outsourcing_out/outsourcing_in/check_in/check_out');
            $table->unsignedBigInteger('source_id')->comment('来源单据ID');
            $table->string('source_no', 30)->comment('来源单号，如 PO20260812-001');
            $table->string('remark')->nullable()->comment('备注');
            $table->unsignedBigInteger('operator_id')->nullable()->comment('操作人ID');
            $table->index(['product_id', 'warehouse_id'], 'movement_product_wh');
            $table->index('source_type', 'movement_source_type');
            $table->timestamps();
        });

        Schema::create('inventory_checks', function (Blueprint $table) {
            $table->id();
            $table->string('no', 30)->unique()->comment('盘点单号，如 CK20260812-001');
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete()->comment('盘点仓库');
            $table->tinyInteger('status')->default(0)->comment('0草稿 1已审核');
            $table->string('checker', 50)->nullable()->comment('审核人');
            $table->timestamp('check_time')->nullable()->comment('审核时间');
            $table->string('remark')->nullable()->comment('备注');
            $table->timestamps();
        });

        Schema::create('inventory_check_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('check_id')->constrained('inventory_checks')->cascadeOnDelete()->comment('所属盘点单');
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete()->comment('商品');
            $table->foreignId('location_id')->constrained('locations')->restrictOnDelete()->comment('库位');
            $table->decimal('book_qty', 12, 2)->comment('账面数（创建时余额快照）');
            $table->decimal('actual_qty', 12, 2)->comment('实盘数');
            $table->decimal('diff_qty', 12, 2)->default(0)->comment('差异=实盘-账面（审核时计算）');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_check_items');
        Schema::dropIfExists('inventory_checks');
        Schema::dropIfExists('inventory_movements');
        Schema::dropIfExists('inventory_balances');
    }
};
```

Run: `cd server && php artisan migrate`
Expected: 4 张表创建成功。

- [ ] **Step 4: 创建 4 个模型**

`server/app/Models/InventoryBalance.php`:

```php
<?php
// 库存余额模型：按 商品×仓库×库位 唯一；quantity 为流水求和结果，禁止旁路修改
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventoryBalance extends Model
{
    protected $fillable = ['product_id', 'warehouse_id', 'location_id', 'quantity', 'safety_min', 'safety_max'];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'safety_min' => 'decimal:2',
            'safety_max' => 'decimal:2',
        ];
    }
}
```

`server/app/Models/InventoryMovement.php`:

```php
<?php
// 库存流水模型：一切库存变动的唯一事实来源，只增不改不删（审计要求）
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryMovement extends Model
{
    /** 来源类型枚举：采购/销售/生产模块审核单据时复用 */
    public const SOURCE_TYPES = ['purchase_inbound', 'sales_outbound', 'pick', 'return', 'finished_inbound', 'outsourcing_out', 'outsourcing_in', 'check_in', 'check_out'];

    /** 来源类型中文标签（流水列表展示） */
    public const SOURCE_TYPE_LABELS = [
        'purchase_inbound' => '采购入库',
        'sales_outbound' => '销售出库',
        'pick' => '领料出库',
        'return' => '退料入库',
        'finished_inbound' => '成品入库',
        'outsourcing_out' => '委外发出',
        'outsourcing_in' => '委外回收',
        'check_in' => '盘盈',
        'check_out' => '盘亏',
    ];

    protected $fillable = ['product_id', 'warehouse_id', 'location_id', 'direction', 'quantity', 'balance_after', 'source_type', 'source_id', 'source_no', 'remark', 'operator_id'];

    protected function casts(): array
    {
        return [
            'direction' => 'integer',
            'quantity' => 'decimal:2',
            'balance_after' => 'decimal:2',
        ];
    }

    // 流水商品
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    // 操作人
    public function operator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'operator_id');
    }
}
```

`server/app/Models/InventoryCheck.php`:

```php
<?php
// 库存盘点单模型：草稿→已审核 两级状态；审核后生成盘盈/盘亏流水
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InventoryCheck extends Model
{
    public const STATUS_DRAFT = 0;
    public const STATUS_APPROVED = 1;

    protected $fillable = ['no', 'warehouse_id', 'status', 'checker', 'check_time', 'remark'];

    protected function casts(): array
    {
        return [
            'status' => 'integer',
            'check_time' => 'datetime',
        ];
    }

    // 盘点仓库
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    // 明细行（随单级联删除）
    public function items(): HasMany
    {
        return $this->hasMany(InventoryCheckItem::class, 'check_id');
    }
}
```

`server/app/Models/InventoryCheckItem.php`:

```php
<?php
// 盘点明细模型：账面数快照 + 实盘数；差异审核时计算
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryCheckItem extends Model
{
    protected $fillable = ['check_id', 'product_id', 'location_id', 'book_qty', 'actual_qty', 'diff_qty'];

    protected function casts(): array
    {
        return [
            'book_qty' => 'decimal:2',
            'actual_qty' => 'decimal:2',
            'diff_qty' => 'decimal:2',
        ];
    }

    // 盘点商品
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    // 盘点库位
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }
}
```

- [ ] **Step 5: 追加权限种子**

修改 `server/database/seeders/RbacSeeder.php`：在 `$permissions` 数组末尾（基础资料权限之后）追加：

```php
        // 库存管理模块权限（inventory 查询 + check 四动作，group=库存管理；盘点审核复用 check.update）
        ['name' => '库存查询', 'code' => 'inventory.list', 'group' => '库存管理'],
        ['name' => '盘点单列表', 'code' => 'check.list', 'group' => '库存管理'],
        ['name' => '盘点单创建', 'code' => 'check.create', 'group' => '库存管理'],
        ['name' => '盘点单更新', 'code' => 'check.update', 'group' => '库存管理'],
        ['name' => '盘点单删除', 'code' => 'check.delete', 'group' => '库存管理'],
```

- [ ] **Step 6: 跑测试确认通过**

Run: `cd server && php artisan test --filter=InventoryStructureTest`
Expected: 4 个测试全部 PASS。

- [ ] **Step 7: 提交**

```bash
cd /d/code/project/php-design && git add server/database/migrations/2026_08_12_090000_create_inventory_tables.php server/app/Models/InventoryBalance.php server/app/Models/InventoryMovement.php server/app/Models/InventoryCheck.php server/app/Models/InventoryCheckItem.php server/database/seeders/RbacSeeder.php server/tests/Feature/InventoryStructureTest.php
git commit -m "feat: 库存数据模型（余额/流水/盘点）与权限种子"
```

---

## Task 2: InventoryService 库存引擎 + 基线种子（100% 单测）

**Files:**
- Create: `server/app/Exceptions/InventoryException.php`
- Create: `server/app/Services/InventoryService.php`
- Create: `server/database/seeders/InventorySeeder.php`
- Create: `server/tests/Feature/InventoryServiceTest.php`
- Modify: `server/database/seeders/DatabaseSeeder.php`（注册 InventorySeeder）

**Interfaces:**
- Consumes: Task 1 的 InventoryBalance/InventoryMovement 模型；基础资料 Product 模型
- Produces: `InventoryService::apply(array $movements, ?int $operatorId = null): void`（全系统唯一库存变动入口，采购/销售/生产模块复用；`$movements` 每条含 `product_id/warehouse_id/location_id`(int)、`direction`(1|-1)、`quantity`(float>0)、`source_type`(枚举)、`source_id`(int)、`source_no`(string)、`remark`(?string)）；`InventoryException extends RuntimeException`（第二参数=业务码，默认 0；Task 4 盘点审核捕获转 1204/1206）；种子基线库存（MAT-001=100/SEMI-001=30/FIN-002=20，流水完整，E2E 数据基线）

- [ ] **Step 1: 写失败测试 `server/tests/Feature/InventoryServiceTest.php`**

```php
<?php
// 库存引擎测试：核心不变式（事务双写/恒等式/超卖拒绝/首次入库建行/冗余同步）100% 覆盖
namespace Tests\Feature;

use App\Exceptions\InventoryException;
use App\Models\Category;
use App\Models\InventoryBalance;
use App\Models\InventoryMovement;
use App\Models\Location;
use App\Models\Product;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryServiceTest extends TestCase
{
    use RefreshDatabase;

    private InventoryService $svc;
    private Product $product;
    private Warehouse $warehouse;
    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = app(InventoryService::class);
        $cat = Category::create(['name' => '原材料', 'parent_id' => 0]);
        $unit = Unit::create(['name' => '个', 'code' => 'pc']);
        // 商品含安全上下限（验证冗余同步）
        $this->product = Product::create([
            'name' => '测试铝材', 'code' => 'MAT-001', 'type' => 'raw_material',
            'category_id' => $cat->id, 'unit_id' => $unit->id, 'safety_min' => 50, 'safety_max' => 500, 'status' => 1,
        ]);
        $this->warehouse = Warehouse::create(['name' => '主仓', 'code' => 'WH01', 'status' => 1]);
        $this->location = Location::create(['warehouse_id' => $this->warehouse->id, 'name' => 'A-01', 'code' => 'A-01', 'status' => 1]);
    }

    // 组装单笔流水入参
    private function movement(array $overrides = []): array
    {
        return array_merge([
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouse->id,
            'location_id' => $this->location->id,
            'direction' => 1,
            'quantity' => 100,
            'source_type' => 'purchase_inbound',
            'source_id' => 1,
            'source_no' => 'PO20260812-001',
        ], $overrides);
    }

    public function test_inbound_writes_movement_and_updates_balance(): void
    {
        // 正常路径：入库在事务内双写（流水 + 余额）——核心不变式 1
        $this->svc->apply([$this->movement()]);
        $this->assertDatabaseHas('inventory_balances', ['product_id' => $this->product->id, 'quantity' => '100.00']);
        $this->assertDatabaseHas('inventory_movements', [
            'product_id' => $this->product->id, 'direction' => 1, 'quantity' => '100.00', 'balance_after' => '100.00',
            'source_type' => 'purchase_inbound', 'source_no' => 'PO20260812-001',
        ]);
    }

    public function test_outbound_decreases_balance(): void
    {
        // 正常路径：出库余额减少且流水快照正确
        $this->svc->apply([$this->movement()]);
        $this->svc->apply([$this->movement(['direction' => -1, 'quantity' => 40, 'source_type' => 'pick', 'source_id' => 2, 'source_no' => 'PL20260812-001'])]);
        $this->assertDatabaseHas('inventory_balances', ['quantity' => '60.00']);
        $this->assertDatabaseHas('inventory_movements', ['direction' => -1, 'quantity' => '40.00', 'balance_after' => '60.00']);
    }

    public function test_outbound_exceeding_balance_rolls_back(): void
    {
        // 异常路径：出库超卖抛异常且事务回滚（无流水残留）——余额不允许为负
        $this->svc->apply([$this->movement(['quantity' => 50])]);
        try {
            $this->svc->apply([$this->movement(['direction' => -1, 'quantity' => 60, 'source_type' => 'sales_outbound', 'source_id' => 2, 'source_no' => 'SO20260812-001'])]);
            $this->fail('出库超卖应抛出异常');
        } catch (InventoryException $e) {
            $this->assertStringContainsString('库存不足', $e->getMessage());
        }
        // 回滚验证：余额仍 50，流水仍只有入库那条
        $this->assertDatabaseHas('inventory_balances', ['quantity' => '50.00']);
        $this->assertSame(1, InventoryMovement::count());
    }

    public function test_balance_equals_sum_of_directions(): void
    {
        // 核心不变式 2：余额恒等于全部流水 direction*quantity 之和
        $this->svc->apply([$this->movement(['quantity' => 100])]);
        $this->svc->apply([$this->movement(['direction' => -1, 'quantity' => 30, 'source_type' => 'pick', 'source_id' => 2, 'source_no' => 'PL1'])]);
        $this->svc->apply([$this->movement(['quantity' => 20, 'source_id' => 3, 'source_no' => 'PO2'])]);
        $sum = InventoryMovement::sum(\Illuminate\Support\Facades\DB::raw('direction * quantity'));
        $balance = InventoryBalance::first();
        $this->assertSame(90.0, (float) $balance->quantity);
        $this->assertSame(90.0, (float) $sum);
    }

    public function test_outbound_without_balance_row_rejected(): void
    {
        // 异常路径：余额行不存在直接出库被拒
        $this->expectException(InventoryException::class);
        $this->svc->apply([$this->movement(['direction' => -1, 'quantity' => 5, 'source_type' => 'sales_outbound', 'source_id' => 1, 'source_no' => 'SO1'])]);
    }

    public function test_first_inbound_creates_row_then_updates(): void
    {
        // 正常路径：首次入库创建余额行，再次入库复用同一行
        $this->svc->apply([$this->movement(['quantity' => 10, 'source_no' => 'PO1'])]);
        $this->svc->apply([$this->movement(['quantity' => 5, 'source_id' => 2, 'source_no' => 'PO2'])]);
        $this->assertSame(1, InventoryBalance::count());
        $this->assertSame(15.0, (float) InventoryBalance::first()->quantity);
        $this->assertSame(2, InventoryMovement::count());
        $this->assertSame('15.00', InventoryBalance::first()->quantity);
    }

    public function test_safety_limits_synced_from_product(): void
    {
        // 正常路径：余额冗余上下限自商品同步
        $this->svc->apply([$this->movement()]);
        $balance = InventoryBalance::first();
        $this->assertSame('50.00', $balance->safety_min);
        $this->assertSame('500.00', $balance->safety_max);
    }

    public function test_multiple_movements_rollback_together(): void
    {
        // 异常路径：多笔一次提交，任一失败整体回滚（无部分成功）
        try {
            $this->svc->apply([
                $this->movement(['quantity' => 100, 'source_no' => 'PO1']),
                $this->movement(['direction' => -1, 'quantity' => 999, 'source_type' => 'sales_outbound', 'source_id' => 2, 'source_no' => 'SO1']),
            ]);
            $this->fail('第二笔超卖应整体回滚');
        } catch (InventoryException $e) {
            // 预期异常
        }
        $this->assertSame(0, InventoryBalance::count());
        $this->assertSame(0, InventoryMovement::count());
    }

    public function test_operator_id_recorded_in_movement(): void
    {
        // 正常路径：操作人写入流水
        $user = User::create(['name' => '操作员', 'username' => 'op1', 'password' => 'admin123', 'status' => 1]);
        $this->svc->apply([$this->movement()], $user->id);
        $this->assertDatabaseHas('inventory_movements', ['operator_id' => $user->id]);
    }
}
```

- [ ] **Step 2: 跑测试确认失败**

Run: `cd server && php artisan test --filter=InventoryServiceTest`
Expected: FAIL（Service/异常类不存在）。

- [ ] **Step 3: 创建 InventoryException**

创建 `server/app/Exceptions/InventoryException.php`：

```php
<?php
// 库存业务异常：出库超卖/审核并发冲突等，由调用方捕获后转业务码（第二参数=业务码，默认 0）
namespace App\Exceptions;

use RuntimeException;

class InventoryException extends RuntimeException {}
```

- [ ] **Step 4: 实现 InventoryService**

创建 `server/app/Services/InventoryService.php`：

```php
<?php
// 库存引擎：全系统唯一库存变动入口（采购入库/销售出库/领退料/成品入库/委外收发/盘点统一调用）
namespace App\Services;

use App\Exceptions\InventoryException;
use App\Models\InventoryBalance;
use App\Models\InventoryMovement;
use App\Models\Product;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class InventoryService
{
    /**
     * 统一库存变动入口：事务内写流水 + 更新余额 + 冗余上下限同步
     *
     * @param array $movements 变动列表，每条含：product_id/warehouse_id/location_id(int)、
     *   direction(int 1=入库 -1=出库)、quantity(float 恒正)、
     *   source_type(InventoryMovement::SOURCE_TYPES 枚举)、source_id(int 来源单据ID)、
     *   source_no(string 来源单号)、remark(?string 备注)
     * @param int|null $operatorId 操作人ID（写入流水 operator_id）
     * @throws InventoryException 出库时余额行不存在或余额不足；任一失败整体回滚
     */
    public function apply(array $movements, ?int $operatorId = null): void
    {
        DB::transaction(function () use ($movements, $operatorId) {
            foreach ($movements as $m) {
                $this->applyOne($m, $operatorId);
            }
        });
    }

    // 单笔变动：行锁余额行 → 出库校验 → 写流水 + 更新余额（与调用方同事务）
    private function applyOne(array $m, ?int $operatorId): void
    {
        $direction = (int) $m['direction'];
        $quantity = (float) $m['quantity'];

        // 行锁：同商品×仓库×库位的并发变动在此串行化
        $balance = InventoryBalance::where('product_id', $m['product_id'])
            ->where('warehouse_id', $m['warehouse_id'])
            ->where('location_id', $m['location_id'])
            ->lockForUpdate()
            ->first();

        // 出库：余额行必须存在且充足（余额允许 0 不允许负，超卖被业务层拒绝）
        if ($direction === -1 && (! $balance || (float) $balance->quantity < $quantity)) {
            throw new InventoryException('库存不足：商品 ' . $m['product_id'] . ' 当前余额 ' . ($balance->quantity ?? 0) . '，出库 ' . $quantity);
        }

        // 入库且余额行不存在：创建（并发首次入库靠联合唯一索引兜底，冲突后重查加锁）
        if (! $balance) {
            try {
                $balance = InventoryBalance::create([
                    'product_id' => $m['product_id'],
                    'warehouse_id' => $m['warehouse_id'],
                    'location_id' => $m['location_id'],
                    'quantity' => 0,
                    'safety_min' => 0,
                    'safety_max' => 0,
                ]);
            } catch (QueryException $e) {
                // 唯一索引冲突（1062）：并发创建同一余额行，重查并加锁串行化
                if (($e->errorInfo[1] ?? null) === 1062) {
                    $balance = InventoryBalance::where('product_id', $m['product_id'])
                        ->where('warehouse_id', $m['warehouse_id'])
                        ->where('location_id', $m['location_id'])
                        ->lockForUpdate()
                        ->firstOrFail();
                } else {
                    throw $e;
                }
            }
        }

        $product = Product::findOrFail($m['product_id']);
        // 余额累加 + 上下限冗余同步（预警计算以商品实时值为准，此冗余仅作快照）
        $balance->quantity = (float) $balance->quantity + $direction * $quantity;
        $balance->safety_min = $product->safety_min;
        $balance->safety_max = $product->safety_max;
        $balance->save();

        // 流水只增不改不删（审计要求）：每笔变动完整落库
        InventoryMovement::create([
            'product_id' => $m['product_id'],
            'warehouse_id' => $m['warehouse_id'],
            'location_id' => $m['location_id'],
            'direction' => $direction,
            'quantity' => $quantity,
            'balance_after' => $balance->quantity,
            'source_type' => $m['source_type'],
            'source_id' => $m['source_id'],
            'source_no' => $m['source_no'],
            'remark' => $m['remark'] ?? null,
            'operator_id' => $operatorId,
        ]);
    }
}
```

- [ ] **Step 5: 创建 InventorySeeder 并注册**

创建 `server/database/seeders/InventorySeeder.php`：

```php
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
        $wh = Warehouse::firstOrCreate(['code' => 'WH01'], ['name' => '主仓', 'address' => '厂区A', 'manager' => '张三', 'status' => 1]);
        // 库位：A-01（原料/半成品）、B-01（成品）
        $a01 = Location::firstOrCreate(['code' => 'A-01'], ['warehouse_id' => $wh->id, 'name' => 'A-01', 'status' => 1]);
        $b01 = Location::firstOrCreate(['code' => 'B-01'], ['warehouse_id' => $wh->id, 'name' => 'B-01', 'status' => 1]);

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
            'source_no' => 'PO' . date('Ymd') . '-SEED',
            'remark' => '测试基线库存（采购模块实施后由真实单据取代）',
        ]], null);
    }
}
```

修改 `server/database/seeders/DatabaseSeeder.php`：

```php
        // 基础资料主数据：分类/单位/仓库/商品/工序
        // 库存基线：E2E 与演示用的商品/库位/已知库存（经 InventoryService 注入）
        $this->call([RbacSeeder::class, MasterDataSeeder::class, InventorySeeder::class]);
```

Run: `cd server && php artisan migrate:fresh --seed`
Expected: 种子成功；`inventory_balances` 3 行（100/30/20）、`inventory_movements` 3 条、`inventory_checks` 空。

- [ ] **Step 6: 跑测试确认通过**

Run: `cd server && php artisan test --filter=InventoryServiceTest`
Expected: 9 个测试全部 PASS。

- [ ] **Step 7: 提交**

```bash
cd /d/code/project/php-design && git add server/app/Exceptions/InventoryException.php server/app/Services/InventoryService.php server/database/seeders/InventorySeeder.php server/database/seeders/DatabaseSeeder.php server/tests/Feature/InventoryServiceTest.php
git commit -m "feat: 库存引擎 InventoryService（事务双写/超卖拒绝）与基线种子"
```

---

## Task 3: 余额/流水/预警 API + CSV 导出

**Files:**
- Create: `server/app/Http/Controllers/Api/InventoryController.php`
- Create: `server/tests/Feature/InventoryBalanceTest.php`
- Create: `server/tests/Feature/InventoryMovementTest.php`
- Modify: `server/routes/api.php`

**Interfaces:**
- Consumes: Task 1 模型（InventoryBalance/InventoryMovement 常量）；基础资料 Product/Warehouse/Location 模型
- Produces: `GET /api/v1/inventory/balances`（Query: page/per_page/keyword/warehouse_id/type/alert；items 含 `{id, product_id, product_name, product_code, type, warehouse_name, location_name, quantity, safety_min, safety_max, alert_level}`）；`GET /api/v1/inventory/balances/export`（UTF-8 BOM CSV，表头 `商品编码,商品名称,仓库,库位,数量,下限,上限,状态`）；`GET /api/v1/inventory/movements`（Query: page/per_page/product_id/warehouse_id/source_type/direction/date_from/date_to；items 含 `{id, product_name, product_code, warehouse_name, location_name, direction, quantity, balance_after, source_type, source_type_label, source_no, operator_name, created_at}`）；`GET /api/v1/inventory/alerts`（`data.items:[{product_name, product_code, warehouse_name, quantity, safety_min, safety_max, level}]`）；权限 `inventory.list`；预警计算规则：`min>0 && qty<min` → 1、`max>0 && qty>max` → 2、否则 0

- [ ] **Step 1: 写失败测试**

创建 `server/tests/Feature/InventoryBalanceTest.php`：

```php
<?php
// 库存余额接口测试：列表筛选/预警计算/CSV 导出（正常+边界+异常）
namespace Tests\Feature;

use App\Models\Category;
use App\Models\InventoryBalance;
use App\Models\Location;
use App\Models\Product;
use App\Models\Role;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryBalanceTest extends TestCase
{
    use RefreshDatabase;

    private string $token;
    private Warehouse $wh;
    private Location $a01;
    private Product $mat;

    protected function setUp(): void
    {
        parent::setUp();
        // admin 用户挂 admin 角色（权限中间件放行）
        $role = Role::create(['name' => '管理员', 'code' => 'admin']);
        $u = User::create(['name' => '管理员', 'username' => 'admin', 'password' => 'admin123', 'status' => 1]);
        $u->roles()->sync([$role->id]);
        $this->token = $u->createToken('api')->plainTextToken;

        // 基础数据：主仓 + A-01 库位 + 两个商品（MAT-001 有库存、FIN-001 无库存）
        $this->wh = Warehouse::create(['name' => '主仓', 'code' => 'WH01', 'status' => 1]);
        $this->a01 = Location::create(['warehouse_id' => $this->wh->id, 'name' => 'A-01', 'code' => 'A-01', 'status' => 1]);
        $cat = Category::create(['name' => '原材料', 'parent_id' => 0]);
        $unit = Unit::create(['name' => '个', 'code' => 'pc']);
        $this->mat = Product::create([
            'name' => '测试铝材', 'code' => 'MAT-001', 'type' => 'raw_material', 'category_id' => $cat->id,
            'unit_id' => $unit->id, 'barcode' => '100001', 'safety_min' => 50, 'safety_max' => 500, 'status' => 1,
        ]);
        InventoryBalance::create([
            'product_id' => $this->mat->id, 'warehouse_id' => $this->wh->id, 'location_id' => $this->a01->id,
            'quantity' => 100, 'safety_min' => 50, 'safety_max' => 500,
        ]);
    }

    public function test_index_returns_balance_fields(): void
    {
        // 正常路径：列表返回商品/仓库/库位/上下限/预警级别完整字段
        $this->withToken($this->token)->getJson('/api/v1/inventory/balances')
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.items.0.product_code', 'MAT-001')
            ->assertJsonPath('data.items.0.warehouse_name', '主仓')
            ->assertJsonPath('data.items.0.location_name', 'A-01')
            ->assertJsonPath('data.items.0.quantity', '100.00')
            ->assertJsonPath('data.items.0.alert_level', 0);
    }

    public function test_keyword_filters_by_code_name_barcode(): void
    {
        // 正常路径：关键字按编码/名称/条码模糊过滤
        $this->withToken($this->token)->getJson('/api/v1/inventory/balances?keyword=MAT')
            ->assertJsonPath('data.total', 1);
        $this->withToken($this->token)->getJson('/api/v1/inventory/balances?keyword=100001')
            ->assertJsonPath('data.total', 1);
        $this->withToken($this->token)->getJson('/api/v1/inventory/balances?keyword=不存在')
            ->assertJsonPath('data.total', 0);
    }

    public function test_warehouse_and_type_filters(): void
    {
        // 正常路径：仓库/类型筛选生效
        $this->withToken($this->token)->getJson('/api/v1/inventory/balances?warehouse_id=' . $this->wh->id)
            ->assertJsonPath('data.total', 1);
        $this->withToken($this->token)->getJson('/api/v1/inventory/balances?type=finished')
            ->assertJsonPath('data.total', 0);
        $this->withToken($this->token)->getJson('/api/v1/inventory/balances?type=raw_material')
            ->assertJsonPath('data.total', 1);
    }

    public function test_alert_filter_returns_only_warned_rows(): void
    {
        // 边界路径：低于下限预警（alert=1 只返回预警行）
        InventoryBalance::query()->update(['quantity' => 40]);
        $this->withToken($this->token)->getJson('/api/v1/inventory/balances?alert=1')
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.items.0.alert_level', 1);
    }

    public function test_alert_level_above_max_when_max_positive(): void
    {
        // 边界路径：高于上限预警（max>0 才检查该侧）
        InventoryBalance::query()->update(['quantity' => 600]);
        $this->withToken($this->token)->getJson('/api/v1/inventory/balances?alert=1')
            ->assertJsonPath('data.items.0.alert_level', 2);
    }

    public function test_alert_level_zero_when_limits_disabled(): void
    {
        // 边界路径：上下限为 0 不预警该侧（quantity 超 0 也不触发）
        $this->mat->update(['safety_min' => 0, 'safety_max' => 0]);
        $this->withToken($this->token)->getJson('/api/v1/inventory/balances')
            ->assertJsonPath('data.items.0.alert_level', 0);
    }

    public function test_export_csv_has_bom_header_and_rows(): void
    {
        // 正常路径：导出 CSV 含 UTF-8 BOM、表头、行数一致（中文无乱码）
        $res = $this->withToken($this->token)->get('/api/v1/inventory/balances/export');
        $res->assertOk();
        $csv = $res->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $lines = explode("\n", trim($csv));
        $this->assertSame('商品编码,商品名称,仓库,库位,数量,下限,上限,状态', $lines[0]);
        $this->assertCount(2, $lines); // 表头 + 1 行数据
        $this->assertStringContainsString('MAT-001', $lines[1]);
        $this->assertStringContainsString('测试铝材', $lines[1]);
    }

    public function test_export_csv_status_column_reflects_alert(): void
    {
        // 边界路径：导出状态列与预警一致（低库存）
        InventoryBalance::query()->update(['quantity' => 40]);
        $csv = $this->withToken($this->token)->get('/api/v1/inventory/balances/export')->streamedContent();
        $this->assertStringContainsString('低库存', $csv);
    }

    public function test_balances_requires_inventory_permission(): void
    {
        // 异常路径：无 inventory.list 权限的角色被拒（403 JSON 信封）
        $role = Role::create(['name' => '普通', 'code' => 'plain']);
        $u = User::create(['name' => '普通', 'username' => 'plain', 'password' => 'admin123', 'status' => 1]);
        $u->roles()->sync([$role->id]);
        $token = $u->createToken('api')->plainTextToken;
        $this->withToken($token)->getJson('/api/v1/inventory/balances')->assertStatus(403);
    }
}
```

创建 `server/tests/Feature/InventoryMovementTest.php`：

```php
<?php
// 库存流水接口测试：倒序/筛选/标签映射（正常+边界+异常）
namespace Tests\Feature;

use App\Models\Category;
use App\Models\InventoryMovement;
use App\Models\Location;
use App\Models\Product;
use App\Models\Role;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryMovementTest extends TestCase
{
    use RefreshDatabase;

    private string $token;
    private Product $mat;

    protected function setUp(): void
    {
        parent::setUp();
        $role = Role::create(['name' => '管理员', 'code' => 'admin']);
        $u = User::create(['name' => '管理员', 'username' => 'admin', 'password' => 'admin123', 'status' => 1]);
        $u->roles()->sync([$role->id]);
        $this->token = $u->createToken('api')->plainTextToken;

        $wh = Warehouse::create(['name' => '主仓', 'code' => 'WH01', 'status' => 1]);
        $loc = Location::create(['warehouse_id' => $wh->id, 'name' => 'A-01', 'code' => 'A-01', 'status' => 1]);
        $cat = Category::create(['name' => '原材料', 'parent_id' => 0]);
        $unit = Unit::create(['name' => '个', 'code' => 'pc']);
        $this->mat = Product::create([
            'name' => '测试铝材', 'code' => 'MAT-001', 'type' => 'raw_material', 'category_id' => $cat->id,
            'unit_id' => $unit->id, 'status' => 1,
        ]);
        // 两条流水：采购入库（最早）+ 盘盈（最新）
        InventoryMovement::create([
            'product_id' => $this->mat->id, 'warehouse_id' => $wh->id, 'location_id' => $loc->id,
            'direction' => 1, 'quantity' => 100, 'balance_after' => 100,
            'source_type' => 'purchase_inbound', 'source_id' => 1, 'source_no' => 'PO20260812-001',
            'remark' => null, 'operator_id' => null, 'created_at' => now()->subDay(),
        ]);
        InventoryMovement::create([
            'product_id' => $this->mat->id, 'warehouse_id' => $wh->id, 'location_id' => $loc->id,
            'direction' => 1, 'quantity' => 5, 'balance_after' => 105,
            'source_type' => 'check_in', 'source_id' => 2, 'source_no' => 'CK20260812-001',
            'remark' => '盘盈', 'operator_id' => $u->id, 'created_at' => now(),
        ]);
    }

    public function test_index_ordered_desc_with_labels(): void
    {
        // 正常路径：时间倒序 + 中文类型标签 + 操作人名称
        $this->withToken($this->token)->getJson('/api/v1/inventory/movements')
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.total', 2)
            ->assertJsonPath('data.items.0.source_type_label', '盘盈')
            ->assertJsonPath('data.items.0.source_no', 'CK20260812-001')
            ->assertJsonPath('data.items.0.operator_name', '管理员')
            ->assertJsonPath('data.items.1.source_type_label', '采购入库');
    }

    public function test_filters_by_product_source_type_direction(): void
    {
        // 正常路径：商品/类型/方向筛选
        $this->withToken($this->token)->getJson('/api/v1/inventory/movements?product_id=' . $this->mat->id)
            ->assertJsonPath('data.total', 2);
        $this->withToken($this->token)->getJson('/api/v1/inventory/movements?source_type=check_in')
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.items.0.source_type', 'check_in');
        $this->withToken($this->token)->getJson('/api/v1/inventory/movements?direction=-1')
            ->assertJsonPath('data.total', 0);
    }

    public function test_filters_by_date_range(): void
    {
        // 边界路径：日期范围筛选（date_from/date_to 闭区间）
        $this->withToken($this->token)->getJson('/api/v1/inventory/movements?date_from=' . now()->toDateString() . '&date_to=' . now()->toDateString())
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.items.0.source_no', 'CK20260812-001');
    }
}
```

- [ ] **Step 2: 跑测试确认失败**

Run: `cd server && php artisan test --filter="InventoryBalanceTest|InventoryMovementTest"`
Expected: FAIL（控制器/路由不存在）。

- [ ] **Step 3: 实现 InventoryController**

创建 `server/app/Http/Controllers/Api/InventoryController.php`：

```php
<?php
// 库存查询控制器：余额列表/导出、流水列表、预警列表（只读接口，权限 inventory.list）
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InventoryBalance;
use App\Models\InventoryMovement;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class InventoryController extends Controller
{
    use ApiResponse;

    /** 余额分页列表：关键字(编码/名称/条码)/仓库/类型/仅预警 筛选 */
    public function balances(Request $request)
    {
        $rows = $this->balanceQuery($request)->paginate(max(1, min(100, (int) $request->input('per_page', 10))));
        return $this->ok([
            'items' => $rows->map(fn ($r) => $this->balanceItem($r)),
            'total' => $rows->total(), 'page' => $rows->currentPage(), 'per_page' => $rows->perPage(),
        ]);
    }

    /** 余额导出 CSV：UTF-8 BOM + 中文表头（与当前筛选一致的全量行） */
    public function exportBalances(Request $request)
    {
        $rows = $this->balanceQuery($request)->get();
        $csv = "\xEF\xBB\xBF" . "商品编码,商品名称,仓库,库位,数量,下限,上限,状态\n";
        foreach ($rows as $r) {
            $level = $this->alertLevel($r);
            $status = $level === 1 ? '低库存' : ($level === 2 ? '超上限' : '正常');
            // CSV 字段统一加引号转义（防中文逗号破坏列结构）
            $fields = [$r->product_code, $r->product_name, $r->warehouse_name, $r->location_name, $r->quantity, $r->safety_min, $r->safety_max, $status];
            $csv .= implode(',', array_map(fn ($f) => '"' . str_replace('"', '""', (string) $f) . '"', $fields)) . "\n";
        }
        return response($csv)
            ->header('Content-Type', 'text/csv; charset=UTF-8')
            ->header('Content-Disposition', 'attachment; filename="balances_' . date('YmdHis') . '.csv"');
    }

    /** 流水分页列表：时间倒序；商品/仓库/类型/方向/日期范围 筛选 */
    public function movements(Request $request)
    {
        $query = InventoryMovement::query()
            ->join('products', 'products.id', '=', 'inventory_movements.product_id')
            ->join('warehouses', 'warehouses.id', '=', 'inventory_movements.warehouse_id')
            ->join('locations', 'locations.id', '=', 'inventory_movements.location_id')
            ->leftJoin('users', 'users.id', '=', 'inventory_movements.operator_id')
            ->select(
                'inventory_movements.*',
                'products.name as product_name', 'products.code as product_code',
                'warehouses.name as warehouse_name', 'locations.name as location_name',
                'users.name as operator_name'
            )
            ->orderByDesc('inventory_movements.created_at')
            ->orderByDesc('inventory_movements.id');

        if ($request->filled('product_id')) {
            $query->where('inventory_movements.product_id', $request->input('product_id'));
        }
        if ($request->filled('warehouse_id')) {
            $query->where('inventory_movements.warehouse_id', $request->input('warehouse_id'));
        }
        if ($request->filled('source_type')) {
            $query->where('inventory_movements.source_type', $request->input('source_type'));
        }
        if ($request->filled('direction')) {
            $query->where('inventory_movements.direction', (int) $request->input('direction'));
        }
        // 日期范围闭区间筛选（created_at 流水时间）
        if ($request->filled('date_from')) {
            $query->whereDate('inventory_movements.created_at', '>=', $request->input('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('inventory_movements.created_at', '<=', $request->input('date_to'));
        }

        $rows = $query->paginate(max(1, min(100, (int) $request->input('per_page', 10))));
        return $this->ok([
            'items' => $rows->map(fn ($r) => [
                'id' => $r->id,
                'product_name' => $r->product_name,
                'product_code' => $r->product_code,
                'warehouse_name' => $r->warehouse_name,
                'location_name' => $r->location_name,
                'direction' => (int) $r->direction,
                'quantity' => $r->quantity,
                'balance_after' => $r->balance_after,
                'source_type' => $r->source_type,
                'source_type_label' => InventoryMovement::SOURCE_TYPE_LABELS[$r->source_type] ?? $r->source_type,
                'source_id' => $r->source_id,
                'source_no' => $r->source_no,
                'operator_name' => $r->operator_name,
                'created_at' => $r->created_at?->toDateTimeString(),
            ]),
            'total' => $rows->total(), 'page' => $rows->currentPage(), 'per_page' => $rows->perPage(),
        ]);
    }

    /** 预警列表：查询时计算（上下限自商品实时读取，修改后立即生效） */
    public function alerts()
    {
        $rows = InventoryBalance::query()
            ->join('products', 'products.id', '=', 'inventory_balances.product_id')
            ->join('warehouses', 'warehouses.id', '=', 'inventory_balances.warehouse_id')
            ->select(
                'inventory_balances.*',
                'products.name as product_name', 'products.code as product_code',
                'products.type', 'warehouses.name as warehouse_name'
            )
            // 预警 SQL 条件：低于下限或高于上限（0=不预警该侧）
            ->whereRaw('(products.safety_min > 0 AND inventory_balances.quantity < products.safety_min) OR (products.safety_max > 0 AND inventory_balances.quantity > products.safety_max)')
            ->get();

        $items = $rows->map(fn ($r) => [
            'product_name' => $r->product_name,
            'product_code' => $r->product_code,
            'warehouse_name' => $r->warehouse_name,
            'quantity' => $r->quantity,
            'safety_min' => $r->safety_min,
            'safety_max' => $r->safety_max,
            'level' => $this->alertLevel($r),
        ])->values();
        return $this->ok(['items' => $items]);
    }

    // 余额查询基座：join 商品/仓库/库位 + 公共筛选（列表与导出复用）
    private function balanceQuery(Request $request)
    {
        $query = InventoryBalance::query()
            ->join('products', 'products.id', '=', 'inventory_balances.product_id')
            ->join('warehouses', 'warehouses.id', '=', 'inventory_balances.warehouse_id')
            ->join('locations', 'locations.id', '=', 'inventory_balances.location_id')
            ->select(
                'inventory_balances.id', 'inventory_balances.product_id', 'inventory_balances.warehouse_id',
                'inventory_balances.location_id', 'inventory_balances.quantity',
                // 上下限取商品实时值（预警计算依赖，修改后立即生效）
                'products.name as product_name', 'products.code as product_code', 'products.type',
                'products.safety_min', 'products.safety_max',
                'warehouses.name as warehouse_name', 'locations.name as location_name'
            )
            ->orderBy('inventory_balances.product_id')
            ->orderBy('inventory_balances.location_id');

        if ($keyword = $request->input('keyword')) {
            $query->where(fn ($q) => $q->where('products.code', 'like', "%{$keyword}%")
                ->orWhere('products.name', 'like', "%{$keyword}%")
                ->orWhere('products.barcode', 'like', "%{$keyword}%"));
        }
        if ($request->filled('warehouse_id')) {
            $query->where('inventory_balances.warehouse_id', $request->input('warehouse_id'));
        }
        if ($request->filled('type')) {
            $query->where('products.type', $request->input('type'));
        }
        // 仅看预警：SQL 层过滤（与 alerts 同规则）
        if ($request->input('alert') == 1) {
            $query->whereRaw('(products.safety_min > 0 AND inventory_balances.quantity < products.safety_min) OR (products.safety_max > 0 AND inventory_balances.quantity > products.safety_max)');
        }
        return $query;
    }

    // 行对象转响应条目（上下限/预警级别字段统一）
    private function balanceItem($r): array
    {
        return [
            'id' => $r->id,
            'product_id' => $r->product_id,
            'product_name' => $r->product_name,
            'product_code' => $r->product_code,
            'type' => $r->type,
            'warehouse_id' => $r->warehouse_id,
            'warehouse_name' => $r->warehouse_name,
            'location_id' => $r->location_id,
            'location_name' => $r->location_name,
            'quantity' => $r->quantity,
            'safety_min' => $r->safety_min,
            'safety_max' => $r->safety_max,
            'alert_level' => $this->alertLevel($r),
        ];
    }

    // 预警级别：min>0 且 quantity<min → 1；max>0 且 quantity>max → 2；否则 0
    private function alertLevel($r): int
    {
        $qty = (float) $r->quantity;
        if ((float) $r->safety_min > 0 && $qty < (float) $r->safety_min) {
            return 1;
        }
        if ((float) $r->safety_max > 0 && $qty > (float) $r->safety_max) {
            return 2;
        }
        return 0;
    }
}
```

- [ ] **Step 4: 注册路由**

修改 `server/routes/api.php`（use 追加 `InventoryController`；`auth:sanctum` 组内追加）：

```php
use App\Http\Controllers\Api\InventoryController;

    // 库存查询：余额/导出/流水/预警（inventory.list）
    Route::middleware('auth:sanctum')->group(function () {
        Route::middleware('permission:inventory.list')->get('/inventory/balances', [InventoryController::class, 'balances']);
        Route::middleware('permission:inventory.list')->get('/inventory/balances/export', [InventoryController::class, 'exportBalances']);
        Route::middleware('permission:inventory.list')->get('/inventory/movements', [InventoryController::class, 'movements']);
        Route::middleware('permission:inventory.list')->get('/inventory/alerts', [InventoryController::class, 'alerts']);
    });
```

- [ ] **Step 5: 跑测试确认通过**

Run: `cd server && php artisan test --filter="InventoryBalanceTest|InventoryMovementTest"`
Expected: InventoryBalanceTest 9 + InventoryMovementTest 3 = 12 个测试全部 PASS。

- [ ] **Step 6: 提交**

```bash
cd /d/code/project/php-design && git add server/app/Http/Controllers/Api/InventoryController.php server/tests/Feature/InventoryBalanceTest.php server/tests/Feature/InventoryMovementTest.php server/routes/api.php
git commit -m "feat: 库存余额/流水/预警 API 与 CSV 导出"
```

---

## Task 4: 盘点单 API（CRUD + auto-books + 审核）

**Files:**
- Create: `server/app/Http/Controllers/Api/CheckController.php`
- Create: `server/tests/Feature/CheckTest.php`
- Modify: `server/routes/api.php`

**Interfaces:**
- Consumes: Task 1 模型（InventoryCheck/InventoryCheckItem 常量与状态机）；Task 2 InventoryService/InventoryException；Task 3 无
- Produces: `GET /api/v1/checks`（分页；Query: page/per_page/keyword(单号)/status/warehouse_id；items 含 `{id, no, warehouse_name, status, checker, check_time, remark, created_at}`）；`POST /api/v1/checks`（草稿；请求 `{warehouse_id, remark, items:[{product_id, location_id, actual_qty}]}`；响应 `{no}`）；`GET /api/v1/checks/{id}`（含 items 的 book_qty/actual_qty/diff_qty）；`PUT /api/v1/checks/{id}`（仅草稿 1202；items 全量替换）；`DELETE /api/v1/checks/{id}`（仅草稿 1203）；`POST /api/v1/checks/{id}/approve`（响应 `{changed_items, increased, decreased, increased_items, decreased_items}`——前三字段为 spec 定义（changed_items=有差异项数、increased=盘盈数量合计、decreased=盘亏数量合计），后两字段为前端「盘盈 X 项 +N」弹窗所需的盘盈/盘亏项数；幂等 1204；并发 1206）；`GET /api/v1/checks/auto-books?warehouse_id=x`（该仓库有余额的 商品×库位 行：`{product_id, product_name, product_code, location_id, location_name, book_qty}`）；权限 `check.*`（approve 复用 check.update）

- [ ] **Step 1: 写失败测试 `server/tests/Feature/CheckTest.php`**

```php
<?php
// 盘点单接口测试：CRUD/校验/审核（盘盈盘亏/幂等/并发 1206）/账面预填（核心路径 100%）
namespace Tests\Feature;

use App\Models\Category;
use App\Models\InventoryBalance;
use App\Models\InventoryCheck;
use App\Models\InventoryMovement;
use App\Models\Location;
use App\Models\Product;
use App\Models\Role;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CheckTest extends TestCase
{
    use RefreshDatabase;

    private string $token;
    private User $admin;
    private Warehouse $wh;
    private Location $a01;
    private Location $b01;
    private Product $mat;
    private Product $semi;
    private Product $fin;

    protected function setUp(): void
    {
        parent::setUp();
        // admin 角色（check.* 全量放行）
        $role = Role::create(['name' => '管理员', 'code' => 'admin']);
        $this->admin = User::create(['name' => '管理员', 'username' => 'admin', 'password' => 'admin123', 'status' => 1]);
        $this->admin->roles()->sync([$role->id]);
        $this->token = $this->admin->createToken('api')->plainTextToken;

        // 主仓 + A-01/B-01 库位 + 3 商品（MAT-001=100、SEMI-001=30、FIN-002=20）
        $this->wh = Warehouse::create(['name' => '主仓', 'code' => 'WH01', 'status' => 1]);
        $this->a01 = Location::create(['warehouse_id' => $this->wh->id, 'name' => 'A-01', 'code' => 'A-01', 'status' => 1]);
        $this->b01 = Location::create(['warehouse_id' => $this->wh->id, 'name' => 'B-01', 'code' => 'B-01', 'status' => 1]);
        $cat = Category::create(['name' => '原材料', 'parent_id' => 0]);
        $unit = Unit::create(['name' => '个', 'code' => 'pc']);
        $this->mat = Product::create(['name' => '测试铝材', 'code' => 'MAT-001', 'type' => 'raw_material', 'category_id' => $cat->id, 'unit_id' => $unit->id, 'status' => 1]);
        $this->semi = Product::create(['name' => '半成品A', 'code' => 'SEMI-001', 'type' => 'semi_finished', 'category_id' => $cat->id, 'unit_id' => $unit->id, 'status' => 1]);
        $this->fin = Product::create(['name' => '成品B', 'code' => 'FIN-002', 'type' => 'finished', 'category_id' => $cat->id, 'unit_id' => $unit->id, 'status' => 1]);
        $this->balance($this->mat, $this->a01, 100);
        $this->balance($this->semi, $this->a01, 30);
        $this->balance($this->fin, $this->b01, 20);
    }

    // 直接建余额行（账面快照）
    private function balance(Product $p, Location $l, float $qty): void
    {
        InventoryBalance::create([
            'product_id' => $p->id, 'warehouse_id' => $this->wh->id, 'location_id' => $l->id,
            'quantity' => $qty, 'safety_min' => 0, 'safety_max' => 0,
        ]);
    }

    // 组装盘点单载荷（默认 3 行全量）
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'warehouse_id' => $this->wh->id,
            'remark' => '月度盘点',
            'items' => [
                ['product_id' => $this->mat->id, 'location_id' => $this->a01->id, 'actual_qty' => 100],
                ['product_id' => $this->semi->id, 'location_id' => $this->a01->id, 'actual_qty' => 30],
                ['product_id' => $this->fin->id, 'location_id' => $this->b01->id, 'actual_qty' => 20],
            ],
        ], $overrides);
    }

    // 通过 API 建草稿单并返回单号
    private function createCheck(array $payload): string
    {
        $res = $this->withToken($this->token)->postJson('/api/v1/checks', $payload);
        $res->assertJsonPath('code', 0);
        return $res->json('data.no');
    }

    public function test_store_creates_draft_with_no_and_auto_book(): void
    {
        // 正常路径：草稿创建成功，单号 CK{date}-001，账面数自动带出
        $no = $this->createCheck($this->payload());
        $this->assertMatchesRegularExpression('/^CK\d{8}-001$/', $no);
        $check = InventoryCheck::where('no', $no)->first();
        $this->assertSame(InventoryCheck::STATUS_DRAFT, $check->status);
        $this->assertDatabaseHas('inventory_check_items', ['check_id' => $check->id, 'product_id' => $this->mat->id, 'book_qty' => '100.00', 'actual_qty' => '100.00']);
    }

    public function test_store_rejects_negative_actual_with_1201(): void
    {
        // 异常路径：实盘数为负 → 1201
        $items = $this->payload()['items'];
        $items[0]['actual_qty'] = -5;
        $this->withToken($this->token)->postJson('/api/v1/checks', ['warehouse_id' => $this->wh->id, 'items' => $items])
            ->assertJsonPath('code', 1201);
    }

    public function test_store_rejects_product_without_balance_with_1205(): void
    {
        // 异常路径：该仓库无余额的商品不可录盘 → 1205
        $items = $this->payload()['items'];
        // FIN-002 在 A-01 无余额（余额在 B-01）
        $items[] = ['product_id' => $this->fin->id, 'location_id' => $this->a01->id, 'actual_qty' => 1];
        $this->withToken($this->token)->postJson('/api/v1/checks', ['warehouse_id' => $this->wh->id, 'items' => $items])
            ->assertJsonPath('code', 1205);
    }

    public function test_store_rejects_empty_items_with_422(): void
    {
        // 异常路径：明细为空 → 422 格式层校验
        $this->withToken($this->token)->postJson('/api/v1/checks', ['warehouse_id' => $this->wh->id, 'items' => []])
            ->assertStatus(422);
    }

    public function test_update_draft_and_reject_approved_with_1202(): void
    {
        // 正常路径：草稿可改（items 全量替换）
        $no = $this->createCheck($this->payload());
        $check = InventoryCheck::where('no', $no)->first();
        $items = $this->payload()['items'];
        $items[0]['actual_qty'] = 105;
        $this->withToken($this->token)->putJson("/api/v1/checks/{$check->id}", ['warehouse_id' => $this->wh->id, 'remark' => '改后', 'items' => $items])
            ->assertJsonPath('code', 0);
        $this->assertDatabaseHas('inventory_check_items', ['check_id' => $check->id, 'product_id' => $this->mat->id, 'actual_qty' => '105.00']);
        // 异常路径：已审核不可改 → 1202
        $check->update(['status' => InventoryCheck::STATUS_APPROVED]);
        $this->withToken($this->token)->putJson("/api/v1/checks/{$check->id}", ['warehouse_id' => $this->wh->id, 'items' => $items])
            ->assertJsonPath('code', 1202);
    }

    public function test_destroy_draft_and_reject_approved_with_1203(): void
    {
        // 正常路径：草稿可删
        $no = $this->createCheck($this->payload());
        $check = InventoryCheck::where('no', $no)->first();
        $this->withToken($this->token)->deleteJson("/api/v1/checks/{$check->id}")->assertJsonPath('code', 0);
        $this->assertDatabaseMissing('inventory_checks', ['id' => $check->id]);
        // 异常路径：已审核不可删 → 1203
        $no2 = $this->createCheck($this->payload());
        $check2 = InventoryCheck::where('no', $no2)->first();
        $check2->update(['status' => InventoryCheck::STATUS_APPROVED]);
        $this->withToken($this->token)->deleteJson("/api/v1/checks/{$check2->id}")->assertJsonPath('code', 1203);
        $this->assertDatabaseHas('inventory_checks', ['id' => $check2->id]);
    }

    public function test_auto_books_returns_balance_rows_per_location(): void
    {
        // 正常路径：按商品×库位返回该仓库有余额的行（账面数=当前余额）
        $this->withToken($this->token)->getJson('/api/v1/checks/auto-books?warehouse_id=' . $this->wh->id)
            ->assertJsonPath('code', 0)
            ->assertJsonCount(3, 'data.items')
            ->assertJsonPath('data.items.0.product_code', 'MAT-001')
            ->assertJsonPath('data.items.0.book_qty', '100.00');
    }

    public function test_approve_gain_creates_check_in_movement(): void
    {
        // 核心不变式 3（盘盈）：diff=+5 → check_in 流水 + 余额 105 + diff_qty 落库
        $no = $this->createCheck($this->payload(['items' => [
            ['product_id' => $this->mat->id, 'location_id' => $this->a01->id, 'actual_qty' => 105],
        ]]));
        $check = InventoryCheck::where('no', $no)->first();
        $this->withToken($this->token)->postJson("/api/v1/checks/{$check->id}/approve")
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.changed_items', 1)
            ->assertJsonPath('data.increased', 5)
            ->assertJsonPath('data.decreased', 0)
            ->assertJsonPath('data.increased_items', 1)
            ->assertJsonPath('data.decreased_items', 0);
        // 余额 +5、check_in 流水（来源=盘点单号、快照 105）
        $this->assertDatabaseHas('inventory_balances', ['product_id' => $this->mat->id, 'quantity' => '105.00']);
        $this->assertDatabaseHas('inventory_movements', [
            'product_id' => $this->mat->id, 'direction' => 1, 'quantity' => '5.00', 'balance_after' => '105.00',
            'source_type' => 'check_in', 'source_no' => $no,
        ]);
        $this->assertDatabaseHas('inventory_check_items', ['check_id' => $check->id, 'product_id' => $this->mat->id, 'diff_qty' => '5.00']);
        // 单据状态 + 审核人
        $this->assertDatabaseHas('inventory_checks', ['id' => $check->id, 'status' => InventoryCheck::STATUS_APPROVED, 'checker' => '管理员']);
        $this->assertNotNull(InventoryCheck::find($check->id)->check_time);
    }

    public function test_approve_loss_creates_check_out_movement(): void
    {
        // 核心不变式 3（盘亏）：diff=-2 → check_out 流水 + 余额 28
        $no = $this->createCheck($this->payload(['items' => [
            ['product_id' => $this->semi->id, 'location_id' => $this->a01->id, 'actual_qty' => 28],
        ]]));
        $check = InventoryCheck::where('no', $no)->first();
        $this->withToken($this->token)->postJson("/api/v1/checks/{$check->id}/approve")
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.changed_items', 1)
            ->assertJsonPath('data.increased', 0)
            ->assertJsonPath('data.decreased', 2)
            ->assertJsonPath('data.increased_items', 0)
            ->assertJsonPath('data.decreased_items', 1);
        $this->assertDatabaseHas('inventory_balances', ['product_id' => $this->semi->id, 'quantity' => '28.00']);
        $this->assertDatabaseHas('inventory_movements', [
            'product_id' => $this->semi->id, 'direction' => -1, 'quantity' => '2.00', 'balance_after' => '28.00',
            'source_type' => 'check_out', 'source_no' => $no,
        ]);
    }

    public function test_approve_zero_diff_generates_no_movement(): void
    {
        // 边界路径：实盘=账面（diff=0）不生成流水，changed_items=0
        $no = $this->createCheck($this->payload());
        $check = InventoryCheck::where('no', $no)->first();
        $this->withToken($this->token)->postJson("/api/v1/checks/{$check->id}/approve")
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.changed_items', 0);
        $this->assertSame(0, InventoryMovement::count());
        $this->assertSame(100.0, (float) InventoryBalance::where('product_id', $this->mat->id)->value('quantity'));
    }

    public function test_approve_is_idempotent_with_1204(): void
    {
        // 核心不变式 4（幂等）：重复审核被拒 1204，余额不二次变动
        $no = $this->createCheck($this->payload(['items' => [
            ['product_id' => $this->mat->id, 'location_id' => $this->a01->id, 'actual_qty' => 105],
        ]]));
        $check = InventoryCheck::where('no', $no)->first();
        $this->withToken($this->token)->postJson("/api/v1/checks/{$check->id}/approve")->assertJsonPath('code', 0);
        $this->withToken($this->token)->postJson("/api/v1/checks/{$check->id}/approve")
            ->assertJsonPath('code', 1204)
            ->assertJsonPath('message', '该盘点单已审核');
        $this->assertSame(105.0, (float) InventoryBalance::where('product_id', $this->mat->id)->value('quantity'));
        $this->assertSame(1, InventoryMovement::count());
    }

    public function test_approve_conflict_after_concurrent_change_rolls_back_with_1206(): void
    {
        // 核心不变式 4（并发）：同商品先被其他盘点单审核（余额已变）→ 后审者 1206 整体回滚
        $noA = $this->createCheck($this->payload(['items' => [
            ['product_id' => $this->mat->id, 'location_id' => $this->a01->id, 'actual_qty' => 105],
        ]]));
        // 单据 B 仍以账面 100 录入（快照旧值）
        $noB = $this->createCheck($this->payload(['items' => [
            ['product_id' => $this->mat->id, 'location_id' => $this->a01->id, 'actual_qty' => 90],
        ]]));
        $a = InventoryCheck::where('no', $noA)->first();
        $b = InventoryCheck::where('no', $noB)->first();
        // 先审 A：余额 100 → 105
        $this->withToken($this->token)->postJson("/api/v1/checks/{$a->id}/approve")->assertJsonPath('code', 0);
        // 再审 B：账面快照 100 ≠ 当前余额 105 → 1206 回滚
        $this->withToken($this->token)->postJson("/api/v1/checks/{$b->id}/approve")
            ->assertJsonPath('code', 1206)
            ->assertJsonPath('message', '库存已变动，请重新盘点');
        $this->assertSame(105.0, (float) InventoryBalance::where('product_id', $this->mat->id)->value('quantity'));
        $this->assertSame(InventoryCheck::STATUS_DRAFT, InventoryCheck::find($b->id)->status);
        $this->assertSame(1, InventoryMovement::count());
    }

    public function test_show_returns_items_with_diff(): void
    {
        // 正常路径：详情含明细与差异
        $no = $this->createCheck($this->payload(['items' => [
            ['product_id' => $this->mat->id, 'location_id' => $this->a01->id, 'actual_qty' => 105],
        ]]));
        $check = InventoryCheck::where('no', $no)->first();
        $this->withToken($this->token)->getJson("/api/v1/checks/{$check->id}")
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.no', $no)
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.book_qty', '100.00')
            ->assertJsonPath('data.items.0.actual_qty', '105.00');
    }

    public function test_approve_requires_check_update_permission(): void
    {
        // 异常路径：无 check.update 权限 → 403
        $plain = Role::create(['name' => '普通', 'code' => 'plain']);
        $u = User::create(['name' => '普通', 'username' => 'plain', 'password' => 'admin123', 'status' => 1]);
        $u->roles()->sync([$plain->id]);
        $no = $this->createCheck($this->payload());
        $check = InventoryCheck::where('no', $no)->first();
        $this->withToken($u->createToken('api')->plainTextToken)
            ->postJson("/api/v1/checks/{$check->id}/approve")
            ->assertStatus(403);
    }
}
```

- [ ] **Step 2: 跑测试确认失败**

Run: `cd server && php artisan test --filter=CheckTest`
Expected: FAIL（控制器/路由不存在）。

- [ ] **Step 3: 实现 CheckController**

创建 `server/app/Http/Controllers/Api/CheckController.php`：

```php
<?php
// 盘点单控制器：草稿 CRUD + 账面预填 + 审核（事务+行锁，盘盈盘亏走统一库存引擎）
namespace App\Http\Controllers\Api;

use App\Exceptions\InventoryException;
use App\Http\Controllers\Controller;
use App\Models\InventoryBalance;
use App\Models\InventoryCheck;
use App\Models\InventoryCheckItem;
use App\Services\InventoryService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CheckController extends Controller
{
    use ApiResponse;

    public function __construct(private InventoryService $inventoryService) {}

    /** 盘点单分页列表：单号关键字/状态/仓库 筛选 */
    public function index(Request $request)
    {
        $query = InventoryCheck::query()->orderByDesc('id');
        if ($keyword = $request->input('keyword')) {
            $query->where('no', 'like', "%{$keyword}%");
        }
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->filled('warehouse_id')) {
            $query->where('warehouse_id', $request->input('warehouse_id'));
        }
        $rows = $query->paginate(max(1, min(100, (int) $request->input('per_page', 10))));
        return $this->ok([
            'items' => $rows->map(fn ($c) => [
                'id' => $c->id,
                'no' => $c->no,
                'warehouse_name' => $c->warehouse?->name,
                'status' => $c->status,
                'checker' => $c->checker,
                'check_time' => $c->check_time?->toDateTimeString(),
                'remark' => $c->remark,
                'created_at' => $c->created_at?->toDateTimeString(),
            ]),
            'total' => $rows->total(), 'page' => $rows->currentPage(), 'per_page' => $rows->perPage(),
        ]);
    }

    /** 账面预填：该仓库全部有余额的 商品×库位 行（盘点弹窗加载用） */
    public function autoBooks(Request $request)
    {
        $data = $request->validate(['warehouse_id' => 'required|integer|exists:warehouses,id']);
        $items = InventoryBalance::where('inventory_balances.warehouse_id', $data['warehouse_id'])
            ->join('products', 'products.id', '=', 'inventory_balances.product_id')
            ->join('locations', 'locations.id', '=', 'inventory_balances.location_id')
            ->select(
                'inventory_balances.product_id', 'inventory_balances.location_id',
                'inventory_balances.quantity as book_qty',
                'products.name as product_name', 'products.code as product_code',
                'locations.name as location_name'
            )
            ->orderBy('inventory_balances.product_id')
            ->orderBy('inventory_balances.location_id')
            ->get();
        return $this->ok(['items' => $items]);
    }

    /** 新建草稿：账面数服务端快照；实盘负数 1201；无余额商品 1205 */
    public function store(Request $request)
    {
        $data = $this->validatePayload($request);
        $items = [];
        foreach ($data['items'] as $item) {
            // 实盘数不能为负
            if ((float) $item['actual_qty'] < 0) {
                return $this->fail(1201, '实盘数量不能为负数');
            }
            // 仅限该仓库存在余额的商品×库位（无余额商品不可录盘）
            $balance = InventoryBalance::where('product_id', $item['product_id'])
                ->where('warehouse_id', $data['warehouse_id'])
                ->where('location_id', $item['location_id'])
                ->first();
            if (! $balance) {
                return $this->fail(1205, '商品在该仓库无库存，无需盘点');
            }
            // 账面数=创建时余额快照（审核时以此校验并发变动）
            $items[] = [
                'product_id' => $item['product_id'],
                'location_id' => $item['location_id'],
                'book_qty' => $balance->quantity,
                'actual_qty' => $item['actual_qty'],
            ];
        }
        $check = DB::transaction(function () use ($data, $items) {
            $check = InventoryCheck::create([
                'no' => $this->nextNo(),
                'warehouse_id' => $data['warehouse_id'],
                'status' => InventoryCheck::STATUS_DRAFT,
                'remark' => $data['remark'] ?? null,
            ]);
            foreach ($items as $i) {
                InventoryCheckItem::create(['check_id' => $check->id] + $i);
            }
            return $check;
        });
        return $this->ok(['no' => $check->no]);
    }

    /** 详情：含明细（book_qty/actual_qty/diff_qty） */
    public function show(InventoryCheck $check)
    {
        return $this->ok([
            'id' => $check->id,
            'no' => $check->no,
            'warehouse_id' => $check->warehouse_id,
            'warehouse_name' => $check->warehouse?->name,
            'status' => $check->status,
            'checker' => $check->checker,
            'check_time' => $check->check_time?->toDateTimeString(),
            'remark' => $check->remark,
            'created_at' => $check->created_at?->toDateTimeString(),
            'items' => $check->items()->with(['product', 'location'])->get()->map(fn ($i) => [
                'id' => $i->id,
                'product_id' => $i->product_id,
                'location_id' => $i->location_id,
                'product_name' => $i->product?->name,
                'product_code' => $i->product?->code,
                'location_name' => $i->location?->name,
                'book_qty' => $i->book_qty,
                'actual_qty' => $i->actual_qty,
                'diff_qty' => $i->diff_qty,
            ]),
        ]);
    }

    /** 更新草稿：仅 status=草稿 可改（1202）；items 全量替换 */
    public function update(Request $request, InventoryCheck $check)
    {
        if ($check->status === InventoryCheck::STATUS_APPROVED) {
            return $this->fail(1202, '已审核单据不可修改');
        }
        $data = $this->validatePayload($request);
        $items = [];
        foreach ($data['items'] as $item) {
            if ((float) $item['actual_qty'] < 0) {
                return $this->fail(1201, '实盘数量不能为负数');
            }
            $balance = InventoryBalance::where('product_id', $item['product_id'])
                ->where('warehouse_id', $data['warehouse_id'])
                ->where('location_id', $item['location_id'])
                ->first();
            if (! $balance) {
                return $this->fail(1205, '商品在该仓库无库存，无需盘点');
            }
            $items[] = [
                'product_id' => $item['product_id'],
                'location_id' => $item['location_id'],
                'book_qty' => $balance->quantity,
                'actual_qty' => $item['actual_qty'],
            ];
        }
        DB::transaction(function () use ($check, $data, $items) {
            $check->update(['warehouse_id' => $data['warehouse_id'], 'remark' => $data['remark'] ?? $check->remark]);
            // 明细全量替换（旧行随头级联或先删后插）
            $check->items()->delete();
            foreach ($items as $i) {
                InventoryCheckItem::create(['check_id' => $check->id] + $i);
            }
        });
        return $this->ok();
    }

    /** 删除草稿：已审核不可删（1203） */
    public function destroy(InventoryCheck $check)
    {
        if ($check->status === InventoryCheck::STATUS_APPROVED) {
            return $this->fail(1203, '已审核单据不可删除');
        }
        $check->delete();
        return $this->ok();
    }

    /** 审核：事务内逐项生成 check_in/check_out 流水并更新余额；幂等 1204；并发 1206 */
    public function approve(InventoryCheck $check)
    {
        try {
            $result = null;
            DB::transaction(function () use ($check, &$result) {
                // 锁盘点单行：同一单据重复审核在此判重（幂等）
                $locked = InventoryCheck::whereKey($check->id)->lockForUpdate()->firstOrFail();
                if ($locked->status === InventoryCheck::STATUS_APPROVED) {
                    throw new InventoryException('该盘点单已审核', 1204);
                }
                $changed = 0;
                $increased = 0;      // 盘盈数量合计
                $decreased = 0;      // 盘亏数量合计
                $increasedItems = 0; // 盘盈项数（前端「盘盈 X 项 +N」文案用）
                $decreasedItems = 0; // 盘亏项数
                foreach ($locked->items as $item) {
                    // 差异 = 实盘 - 账面（审核时计算并落库）
                    $diff = round((float) $item->actual_qty - (float) $item->book_qty, 2);
                    $item->diff_qty = $diff;
                    $item->save();
                    if (abs($diff) < 0.005) {
                        continue; // 零差异不生成流水
                    }
                    // 锁余额行：账面快照已被并发变动（其他盘点单先审）→ 整体回滚 1206
                    $balance = InventoryBalance::where('product_id', $item->product_id)
                        ->where('warehouse_id', $locked->warehouse_id)
                        ->where('location_id', $item->location_id)
                        ->lockForUpdate()
                        ->first();
                    if (! $balance || abs((float) $balance->quantity - (float) $item->book_qty) > 0.005) {
                        throw new InventoryException('库存已变动，请重新盘点', 1206);
                    }
                    $direction = $diff > 0 ? 1 : -1;
                    // 盘盈/盘亏走统一引擎（同事务，双写一致）
                    $this->inventoryService->apply([[
                        'product_id' => $item->product_id,
                        'warehouse_id' => $locked->warehouse_id,
                        'location_id' => $item->location_id,
                        'direction' => $direction,
                        'quantity' => abs($diff),
                        'source_type' => $direction > 0 ? 'check_in' : 'check_out',
                        'source_id' => $locked->id,
                        'source_no' => $locked->no,
                        'remark' => $direction > 0 ? '盘盈' : '盘亏',
                    ]], auth()->id());
                    $changed++;
                    if ($direction > 0) {
                        $increased += abs($diff);
                        $increasedItems++;
                    } else {
                        $decreased += abs($diff);
                        $decreasedItems++;
                    }
                }
                $locked->status = InventoryCheck::STATUS_APPROVED;
                $locked->checker = auth()->user()->name ?? '';
                $locked->check_time = now();
                $locked->save();
                $result = [
                    'changed_items' => $changed,
                    'increased' => $increased,
                    'decreased' => $decreased,
                    'increased_items' => $increasedItems,
                    'decreased_items' => $decreasedItems,
                ];
            });
        } catch (InventoryException $e) {
            // 1204 已审核 / 1206 并发变动（余额不足等防御性场景同样归 1206）
            return $this->fail($e->getCode() ?: 1206, $e->getMessage());
        }
        return $this->ok($result);
    }

    // 载荷格式校验（422 仅格式层）：warehouse 必填、items 非空数组
    private function validatePayload(Request $request): array
    {
        return $request->validate([
            'warehouse_id' => 'required|integer|exists:warehouses,id',
            'remark' => 'nullable|string|max:200',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|integer|exists:products,id',
            'items.*.location_id' => 'required|integer|exists:locations,id',
            'items.*.actual_qty' => 'required|numeric',
        ]);
    }

    // 单号生成：CK{yyyyMMdd}-{3位流水}；撞唯一冲突重试最多 3 次
    private function nextNo(): string
    {
        $date = date('Ymd');
        for ($i = 0; $i < 3; $i++) {
            $seq = InventoryCheck::where('no', 'like', "CK{$date}-%")->count() + 1;
            $no = sprintf('CK%s-%03d', $date, $seq);
            try {
                return $no;
            } catch (\Illuminate\Database\QueryException $e) {
                // 唯一冲突（1062）：并发建单撞号，重试
                if (($e->errorInfo[1] ?? null) !== 1062) {
                    throw $e;
                }
            }
        }
        // 极端并发下 3 次仍撞号：记录日志并抛错（理论不可达）
        Log::warning('盘点单号生成连续冲突 3 次，请人工检查并发');
        throw new \RuntimeException('单号生成失败，请重试');
    }
}
```

- [ ] **Step 4: 注册路由**

修改 `server/routes/api.php`（use 追加 `CheckController`；`auth:sanctum` 组内追加；**auto-books 必须注册在 `checks/{check}` 之前**）：

```php
use App\Http\Controllers\Api\CheckController;

    // 盘点单：CRUD + 账面预填 + 审核（check.list/create/update/delete；审核复用 check.update）
    Route::middleware('auth:sanctum')->group(function () {
        Route::middleware('permission:check.list')->get('/checks/auto-books', [CheckController::class, 'autoBooks']);
        Route::middleware('permission:check.list')->get('/checks', [CheckController::class, 'index']);
        Route::middleware('permission:check.create')->post('/checks', [CheckController::class, 'store']);
        Route::middleware('permission:check.list')->get('/checks/{check}', [CheckController::class, 'show']);
        Route::middleware('permission:check.update')->put('/checks/{check}', [CheckController::class, 'update']);
        Route::middleware('permission:check.delete')->delete('/checks/{check}', [CheckController::class, 'destroy']);
        Route::middleware('permission:check.update')->post('/checks/{check}/approve', [CheckController::class, 'approve']);
    });
```

- [ ] **Step 5: 跑测试确认通过**

Run: `cd server && php artisan test --filter=CheckTest`
Expected: 13 个测试全部 PASS。

- [ ] **Step 6: 提交**

```bash
cd /d/code/project/php-design && git add server/app/Http/Controllers/Api/CheckController.php server/tests/Feature/CheckTest.php server/routes/api.php
git commit -m "feat: 盘点单 API（CRUD/账面预填/事务审核幂等与并发防重）"
```

---

## Task 5: 前端基座（API 封装 + 路由 + 菜单 + 设计系统页覆盖）

**Files:**
- Create: `web/src/api/inventory.ts`
- Create: `web/src/tests/inventory.api.test.ts`
- Create: `design-system/nexus-factory/pages/inventory.md`（ui-ux-pro-max 页覆盖：库存页面设计规范）
- Modify: `web/src/router/index.ts`、`web/src/layouts/MainLayout.vue`

**Interfaces:**
- Consumes: Task 3/4 后端 API；系统管理模块的 `http` 封装与 `auth` store
- Produces: `inventoryApi`（balances/exportBalances/movements/alerts + checks 五方法 + autoBooks/approveCheck，签名见下）；4 条路由 `/inventory/*`（meta.permission）；侧边栏「库存管理」菜单组（4 项按权限过滤）；`design-system/nexus-factory/pages/inventory.md`（页面骨架/表格/标签/预警卡片/扫码交互规范，Task 6/7 页面样式依据）

- [ ] **Step 1: 写失败测试 `web/src/tests/inventory.api.test.ts`**

```ts
// 库存 API 封装测试：查询参数/创建载荷/审核与导出路径
import { describe, it, expect, vi, beforeEach } from 'vitest'

vi.mock('../api/http', () => ({ http: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() } }))
import { http } from '../api/http'
import { inventoryApi } from '../api/inventory'

describe('inventory api', () => {
  beforeEach(() => vi.clearAllMocks())

  it('balances 携带分页/关键字/仓库/类型/预警参数', async () => {
    // 正常路径：余额查询参数完整传递
    ;(http.get as any).mockResolvedValue({ data: { code: 0, data: { items: [], total: 0, page: 1, per_page: 10 } } })
    await inventoryApi.balances({ page: 2, keyword: 'MAT', warehouse_id: 1, type: 'raw_material', alert: 1 })
    expect(http.get).toHaveBeenCalledWith('/inventory/balances', {
      params: { page: 2, per_page: 10, keyword: 'MAT', warehouse_id: 1, type: 'raw_material', alert: 1 },
    })
  })

  it('exportBalances 以 blob 形式请求导出', async () => {
    // 正常路径：导出走 blob 响应（前端触发下载）
    ;(http.get as any).mockResolvedValue({ data: new Blob() })
    await inventoryApi.exportBalances({ keyword: 'MAT' })
    expect(http.get).toHaveBeenCalledWith('/inventory/balances/export', {
      params: { keyword: 'MAT' },
      responseType: 'blob',
    })
  })

  it('movements 携带类型/方向/日期筛选', async () => {
    // 正常路径：流水筛选参数
    ;(http.get as any).mockResolvedValue({ data: { code: 0, data: { items: [], total: 0, page: 1, per_page: 10 } } })
    await inventoryApi.movements({ source_type: 'check_in', direction: 1, date_from: '2026-08-01', date_to: '2026-08-12' })
    expect(http.get).toHaveBeenCalledWith('/inventory/movements', {
      params: { source_type: 'check_in', direction: 1, date_from: '2026-08-01', date_to: '2026-08-12' },
    })
  })

  it('createCheck 提交盘点单载荷', async () => {
    // 正常路径：草稿创建请求体
    ;(http.post as any).mockResolvedValue({ data: { code: 0, data: { no: 'CK20260812-001' } } })
    const no = await inventoryApi.createCheck({
      warehouse_id: 1,
      remark: '盘点',
      items: [{ product_id: 5, location_id: 2, actual_qty: 105 }],
    })
    expect(no).toBe('CK20260812-001')
    expect(http.post).toHaveBeenCalledWith('/checks', {
      warehouse_id: 1,
      remark: '盘点',
      items: [{ product_id: 5, location_id: 2, actual_qty: 105 }],
    })
  })

  it('approveCheck 走审核路径并返回汇总', async () => {
    // 正常路径：审核响应汇总
    ;(http.post as any).mockResolvedValue({ data: { code: 0, data: { changed_items: 2, increased: 1, decreased: 1 } } })
    const res = await inventoryApi.approveCheck(9)
    expect(res).toEqual({ changed_items: 2, increased: 1, decreased: 1 })
    expect(http.post).toHaveBeenCalledWith('/checks/9/approve')
  })

  it('autoBooks 按仓库查询账面', async () => {
    // 正常路径：账面预填路径
    ;(http.get as any).mockResolvedValue({ data: { code: 0, data: { items: [] } } })
    await inventoryApi.autoBooks(1)
    expect(http.get).toHaveBeenCalledWith('/checks/auto-books', { params: { warehouse_id: 1 } })
  })
})
```

- [ ] **Step 2: 跑测试确认失败**

Run: `cd web && npx vitest run src/tests/inventory.api.test.ts`
Expected: FAIL（文件/模块不存在）。

- [ ] **Step 3: 实现 `web/src/api/inventory.ts`**

```ts
// 库存 API 封装：余额/流水/预警查询 + 盘点单 CRUD/账面预填/审核
import { http } from './http'

export type ProductType = 'raw_material' | 'semi_finished' | 'finished'

export interface BalanceItem {
  id: number
  product_id: number
  product_name: string
  product_code: string
  type: ProductType
  warehouse_id: number
  warehouse_name: string
  location_id: number
  location_name: string
  quantity: number
  safety_min: number
  safety_max: number
  alert_level: number
}

export interface MovementItem {
  id: number
  product_name: string
  product_code: string
  warehouse_name: string
  location_name: string
  direction: number
  quantity: number
  balance_after: number
  source_type: string
  source_type_label: string
  source_id: number
  source_no: string
  operator_name: string | null
  created_at: string
}

export interface CheckItem {
  id: number
  no: string
  warehouse_name: string
  status: number
  checker: string | null
  check_time: string | null
  remark: string | null
  created_at: string
}

export interface CheckDetailItem {
  id: number
  product_id: number
  location_id: number
  product_name: string
  product_code: string
  location_name: string
  book_qty: number
  actual_qty: number
  diff_qty: number
}

export interface AlertItem {
  product_name: string
  product_code: string
  warehouse_name: string
  quantity: number
  safety_min: number
  safety_max: number
  level: number
}

export interface AutoBookItem {
  product_id: number
  product_name: string
  product_code: string
  location_id: number
  location_name: string
  book_qty: number
}

export const inventoryApi = {
  // 余额分页列表（关键字/仓库/类型/仅预警筛选）
  async balances(params: {
    page?: number
    per_page?: number
    keyword?: string
    warehouse_id?: number
    type?: ProductType
    alert?: number
  }) {
    const { data } = await http.get('/inventory/balances', { params: { per_page: 10, ...params } })
    return data.data as { items: BalanceItem[]; total: number; page: number; per_page: number }
  },
  // 余额导出（blob 供前端下载）
  async exportBalances(params: { keyword?: string; warehouse_id?: number; type?: ProductType; alert?: number }) {
    const { data } = await http.get('/inventory/balances/export', { params, responseType: 'blob' })
    return data as Blob
  },
  // 流水分页列表（商品/仓库/类型/方向/日期范围筛选）
  async movements(params: {
    page?: number
    per_page?: number
    product_id?: number
    warehouse_id?: number
    source_type?: string
    direction?: number
    date_from?: string
    date_to?: string
  }) {
    const { data } = await http.get('/inventory/movements', { params: { per_page: 10, ...params } })
    return data.data as { items: MovementItem[]; total: number; page: number; per_page: number }
  },
  // 预警列表（level=1 低于下限 / 2 高于上限）
  async alerts() {
    const { data } = await http.get('/inventory/alerts')
    return data.data as { items: AlertItem[] }
  },
  // 盘点单分页列表（单号/状态/仓库筛选）
  async checks(params: { page?: number; per_page?: number; keyword?: string; status?: number; warehouse_id?: number }) {
    const { data } = await http.get('/checks', { params: { per_page: 10, ...params } })
    return data.data as { items: CheckItem[]; total: number; page: number; per_page: number }
  },
  // 盘点单详情（含明细差异）
  async checkDetail(id: number) {
    const { data } = await http.get(`/checks/${id}`)
    return data.data as { id: number; no: string; warehouse_id: number; warehouse_name: string; status: number; checker: string | null; check_time: string | null; remark: string | null; created_at: string; items: CheckDetailItem[] }
  },
  // 新建草稿（响应单号）
  async createCheck(payload: { warehouse_id: number; remark?: string; items: { product_id: number; location_id: number; actual_qty: number }[] }) {
    const { data } = await http.post('/checks', payload)
    return data.data as { no: string }
  },
  // 更新草稿（items 全量替换）
  async updateCheck(id: number, payload: { warehouse_id: number; remark?: string; items: { product_id: number; location_id: number; actual_qty: number }[] }) {
    await http.put(`/checks/${id}`, payload)
  },
  // 删除草稿
  async deleteCheck(id: number) {
    await http.delete(`/checks/${id}`)
  },
  // 审核（响应盘盈/盘亏汇总）
  async approveCheck(id: number) {
    const { data } = await http.post(`/checks/${id}/approve`)
    return data.data as { changed_items: number; increased: number; decreased: number }
  },
  // 账面预填：某仓库全部有余额的商品×库位
  async autoBooks(warehouseId: number) {
    const { data } = await http.get('/checks/auto-books', { params: { warehouse_id: warehouseId } })
    return data.data as { items: AutoBookItem[] }
  },
}
```

- [ ] **Step 4: 注册路由**

修改 `web/src/router/index.ts`：在 `master/products` 路由之后追加 4 条：

```ts
        {
          path: 'inventory/balances',
          name: 'inventory-balances',
          component: () => import('../views/inventory/BalancesView.vue'),
          meta: { permission: 'inventory.list' },
        },
        {
          path: 'inventory/movements',
          name: 'inventory-movements',
          component: () => import('../views/inventory/MovementsView.vue'),
          meta: { permission: 'inventory.list' },
        },
        {
          path: 'inventory/checks',
          name: 'inventory-checks',
          component: () => import('../views/inventory/ChecksView.vue'),
          meta: { permission: 'check.list' },
        },
        {
          path: 'inventory/alerts',
          name: 'inventory-alerts',
          component: () => import('../views/inventory/AlertsView.vue'),
          meta: { permission: 'inventory.list' },
        },
```

- [ ] **Step 5: 侧边栏菜单组**

修改 `web/src/layouts/MainLayout.vue`：在「仪表盘」链接之后、「系统管理」组之前插入「库存管理」组（4 项按权限过滤）：

```html
        <div class="menu-group">库存管理</div>
        <RouterLink v-if="auth.has('inventory.list')" to="/inventory/balances" class="menu-item"
          >库存余额</RouterLink
        >
        <RouterLink v-if="auth.has('inventory.list')" to="/inventory/movements" class="menu-item"
          >库存流水</RouterLink
        >
        <RouterLink v-if="auth.has('check.list')" to="/inventory/checks" class="menu-item"
          >库存盘点</RouterLink
        >
        <RouterLink v-if="auth.has('inventory.list')" to="/inventory/alerts" class="menu-item"
          >库存预警</RouterLink
        >
```

- [ ] **Step 6: 设计系统页覆盖 `design-system/nexus-factory/pages/inventory.md`**

创建该文件（ui-ux-pro-max 落地的库存模块页面设计规范，Task 6/7 页面样式依据）：

```markdown
# 库存管理页设计规范（nexus-factory / inventory）

> 依据：`design-system/nexus-factory/MASTER.md`（Swiss Modernism 2.0）；覆盖库存 4 页：
> 余额 `/inventory/balances`、流水 `/inventory/movements`、盘点 `/inventory/checks`、预警 `/inventory/alerts`。
> 前置规范（页面骨架/按钮/弹窗/表格）见 MASTER.md 与 `pages/master-data.md`，本节只定义库存特有样式。

## 1. 页面骨架

- 4 页统一 `.page-card` 容器 + `.page-title` + `.toolbar`（左标题右操作）
- 筛选区 `.toolbar` 内 el-select/el-input/el-switch 保持 260px 内宽，间距 `--space-md`
- 数量/单号列统一 `class-name="font-code"`（Fira Code），数字右对齐

## 2. 余额页（balances）

- 数量列：Fira Code + `font-weight: 700` + `color: var(--color-foreground)`（数据强调）
- 状态列（只对预警显示标签）：
  - level=1 低于下限：`el-tag type="danger"`（红 `#DC2626`）文案「低库存」
  - level=2 高于上限：`el-tag type="warning"`（琥珀 `#D97706`）文案「超上限」
  - level=0：不渲染标签（正常无标签，视觉安静）
- 行点击（展开流水）：整行 `cursor: pointer`，hover 背景 `#F1F5F9`，点击跳流水页并预填筛选
- 工具栏：「仅看预警」switch（active-text 空）+「导 出」btn-secondary 按钮

## 3. 流水页（movements）

- 方向列：`+` 绿 `#059669` / `-` 红 `#DC2626`，前缀符号 + Fira Code，粗体
- 单号列：Fira Code 链接样式（`color: var(--color-primary)` + 下划线 hover），点击行为：
  - check_in/check_out → 跳盘点详情 `/inventory/checks/{id}`
  - 其他来源 → `ElMessage.info` 提示「{类型}单据页随对应模块实施后开放」
- 单据类型列：el-tag（默认灰底 `#F1F5F9` 文字 `#334155`）
- 日期筛选：el-date-picker `type="daterange"` + 快捷项（今天/近 7 天/近 30 天）

## 4. 盘点页（checks）

- 状态标签：草稿 `el-tag type="info"`（灰）、已审核 `el-tag type="success"`（绿）
- 操作列：草稿 →「编 辑」「删 除」「审 核」；已审核 →「查 看」（仅查看，无编辑/删除入口）
- 新建弹窗：`width="900px"`；顶部仓库下拉 +「加 载账面数」按钮
- 明细表格列：商品（含编码）、库位、账面数（只读灰字）、实盘数（el-input-number min=0，前端拦截负数）、差异（仅详情展示）
- 扫码交互：明细区条码输入框自动聚焦（`v-focus` 或 onMounted focus），扫枪回车 → `byBarcode` 校验 → 命中自动追加行并回填账面数；未命中 `ElMessage.error` 展示后端 1117 文案，输入保留便于重扫
- 审核确认：`ElMessageBox.confirm` 文案「确认审核？差异将生成盘盈/盘亏流水并更新库存」；确认按钮主色绿
- 审核结果弹窗：`ElMessageBox.alert` 文案「盘盈 {increased} 项 +{增加数}、盘亏 {decreased} 项 -{减少数}」；changed_items=0 时提示「本次无差异，未生成流水」

## 5. 预警页（alerts）

- 顶部汇总条：`.summary-bar`（浅灰底 `#F8FAFC` 圆角 8px）文案「低于下限 N 项 / 高于上限 M 项」
- 卡片网格：`grid-template-columns: repeat(auto-fill, minmax(280px, 1fr))`，间距 `--space-lg`
- 卡片样式：白底、8px 圆角、`--shadow-card` 阴影；左侧 4px 色条（level=1 红 `#DC2626`、level=2 琥珀 `#D97706`）
- 卡片内容：商品名（加粗）+ 编码（Fira Code 灰）+ 仓库/库位 + 当前量（Fira Code 大号 20px）+ 下限或上限 + 超额幅度（如「低于下限 10」红字 /「高于上限 5」琥珀字）
```

- [ ] **Step 7: 跑测试确认通过**

Run: `cd web && npx vitest run src/tests/inventory.api.test.ts`
Expected: 6 个测试全部 PASS。

- [ ] **Step 8: 提交**

```bash
cd /d/code/project/php-design && git add web/src/api/inventory.ts web/src/tests/inventory.api.test.ts web/src/router/index.ts web/src/layouts/MainLayout.vue design-system/nexus-factory/pages/inventory.md
git commit -m "feat: 库存前端基座（API 封装/路由/菜单/设计系统页覆盖）"
```

---

## Task 6: 前端页面 A（库存余额 + 库存流水）

**Files:**
- Create: `web/src/views/inventory/BalancesView.vue`
- Create: `web/src/views/inventory/MovementsView.vue`

**Interfaces:**
- Consumes: Task 5 `inventoryApi`（balances/exportBalances/movements）与类型；设计系统页 `pages/inventory.md`
- Produces: 余额页（筛选/预警标签/行点击跳流水/CSV 导出下载）；流水页（筛选/方向色/单号链接跳转与提示）；E2E TC-INV-01/02/03/04 的 UI 载体

- [ ] **Step 1: 实现 `web/src/views/inventory/BalancesView.vue`**

```vue
<!-- 库存余额页：筛选 + 预警标签 + 行点击跳流水 + CSV 导出 -->
<template>
  <div class="page-card">
    <div class="toolbar">
      <span class="page-title">库存余额</span>
      <el-input
        v-model="query.keyword"
        placeholder="商品编码/名称/条码"
        clearable
        style="width: 220px"
        @keyup.enter="reload"
        @clear="reload"
      />
      <el-select v-model="query.warehouse_id" placeholder="仓库" clearable style="width: 160px" @change="reload">
        <el-option v-for="w in warehouses" :key="w.id" :label="w.name" :value="w.id" />
      </el-select>
      <el-select v-model="query.type" placeholder="类型" clearable style="width: 130px" @change="reload">
        <el-option label="原料" value="raw_material" />
        <el-option label="半成品" value="semi_finished" />
        <el-option label="成品" value="finished" />
      </el-select>
      <el-switch v-model="query.alert" :active-value="1" :inactive-value="0" active-text="仅看预警" @change="reload" />
      <el-button class="btn-secondary" :disabled="loading" @click="doExport">导 出</el-button>
      <el-button class="btn-secondary" @click="reload">查 询</el-button>
    </div>

    <el-table v-loading="loading" :data="rows" @row-click="gotoMovements">
      <el-table-column prop="product_code" label="商品编码" width="130" class-name="font-code" />
      <el-table-column prop="product_name" label="商品名称" min-width="140" />
      <el-table-column label="类型" width="90">
        <template #default="{ row }">
          <el-tag :type="typeTagType(row.type)" size="small">{{ typeLabel(row.type) }}</el-tag>
        </template>
      </el-table-column>
      <el-table-column prop="warehouse_name" label="仓库" width="100" />
      <el-table-column prop="location_name" label="库位" width="90" />
      <el-table-column label="数量" width="110" align="right">
        <template #default="{ row }">
          <span class="qty-cell">{{ row.quantity }}</span>
        </template>
      </el-table-column>
      <el-table-column label="下限" width="90" align="right">
        <template #default="{ row }">{{ row.safety_min }}</template>
      </el-table-column>
      <el-table-column label="上限" width="90" align="right">
        <template #default="{ row }">{{ row.safety_max }}</template>
      </el-table-column>
      <el-table-column label="状态" width="100">
        <template #default="{ row }">
          <el-tag v-if="row.alert_level === 1" type="danger" size="small">低库存</el-tag>
          <el-tag v-else-if="row.alert_level === 2" type="warning" size="small">超上限</el-tag>
        </template>
      </el-table-column>
    </el-table>
    <el-pagination
      v-model:current-page="query.page"
      :total="total"
      :page-size="10"
      layout="total, prev, pager, next"
      @current-change="load"
    />
  </div>
</template>

<script setup lang="ts">
// 库存余额页：列表/筛选/预警标签；点击行跳流水页并预填筛选；导出 CSV
import { onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { ElMessage } from 'element-plus'
import { inventoryApi, type BalanceItem, type ProductType } from '../../api/inventory'
import { warehouseApi } from '../../api/warehouse'

const router = useRouter()
const rows = ref<BalanceItem[]>([])
const total = ref(0)
const loading = ref(false)
const warehouses = ref<{ id: number; name: string }[]>([])
const query = reactive<{ page: number; keyword: string; warehouse_id?: number; type?: ProductType; alert: number }>({
  page: 1,
  keyword: '',
  warehouse_id: undefined,
  type: undefined,
  alert: 0,
})

// 类型标签语义色（原料蓝/半成品琥珀/成品绿）
function typeLabel(type: ProductType): string {
  return type === 'raw_material' ? '原料' : type === 'semi_finished' ? '半成品' : '成品'
}
function typeTagType(type: ProductType): 'primary' | 'warning' | 'success' {
  return type === 'raw_material' ? 'primary' : type === 'semi_finished' ? 'warning' : 'success'
}

// 加载列表（筛选变化后重置页码）
async function load() {
  loading.value = true
  try {
    const res = await inventoryApi.balances({
      page: query.page,
      keyword: query.keyword || undefined,
      warehouse_id: query.warehouse_id,
      type: query.type,
      alert: query.alert,
    })
    rows.value = res.items
    total.value = res.total
  } catch (e) {
    ElMessage.error((e as Error).message)
  } finally {
    loading.value = false
  }
}
function reload() {
  query.page = 1
  load()
}

// 点击行：跳流水页并预填商品×仓库筛选
function gotoMovements(row: BalanceItem) {
  router.push({
    path: '/inventory/movements',
    query: { product_id: String(row.product_id), warehouse_id: String(row.warehouse_id) },
  })
}

// 导出 CSV：blob 下载（中文文件名）
async function doExport() {
  try {
    const blob = await inventoryApi.exportBalances({
      keyword: query.keyword || undefined,
      warehouse_id: query.warehouse_id,
      type: query.type,
      alert: query.alert,
    })
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = `balances_${new Date().toISOString().slice(0, 10)}.csv`
    a.click()
    URL.revokeObjectURL(url)
    ElMessage.success('导出成功')
  } catch (e) {
    ElMessage.error((e as Error).message)
  }
}

onMounted(async () => {
  load()
  // 仓库下拉（全量）
  try {
    const res = await warehouseApi.list({ per_page: 100 })
    warehouses.value = res.items.map((w) => ({ id: w.id, name: w.name }))
  } catch {
    // 下拉加载失败不阻断列表
  }
})
</script>

<style scoped>
/* 数量列强调：Fira Code 加粗（设计系统 inventory.md §2） */
.qty-cell {
  font-family: 'Fira Code', monospace;
  font-weight: 700;
}
.el-table :deep(.el-table__row) {
  cursor: pointer;
}
.el-table :deep(.el-table__row:hover td) {
  background: #f1f5f9;
}
</style>
```

- [ ] **Step 2: 实现 `web/src/views/inventory/MovementsView.vue`**

```vue
<!-- 库存流水页：筛选 + 方向色 + 单号链接跳转/提示 -->
<template>
  <div class="page-card">
    <div class="toolbar">
      <span class="page-title">库存流水</span>
      <el-select
        v-model="query.product_id"
        placeholder="商品（可搜索）"
        clearable
        filterable
        style="width: 200px"
        @change="reload"
      >
        <el-option v-for="p in products" :key="p.id" :label="`${p.code} ${p.name}`" :value="p.id" />
      </el-select>
      <el-select v-model="query.warehouse_id" placeholder="仓库" clearable style="width: 130px" @change="reload">
        <el-option v-for="w in warehouses" :key="w.id" :label="w.name" :value="w.id" />
      </el-select>
      <el-select v-model="query.source_type" placeholder="单据类型" clearable style="width: 140px" @change="reload">
        <el-option v-for="(label, key) in sourceTypeLabels" :key="key" :label="label" :value="key" />
      </el-select>
      <el-select v-model="query.direction" placeholder="方向" clearable style="width: 110px" @change="reload">
        <el-option label="入库 +" :value="1" />
        <el-option label="出库 -" :value="-1" />
      </el-select>
      <el-date-picker
        v-model="dateRange"
        type="daterange"
        range-separator="至"
        start-placeholder="开始日期"
        end-placeholder="结束日期"
        :shortcuts="dateShortcuts"
        style="width: 250px"
        @change="reload"
      />
      <el-button class="btn-secondary" @click="reload">查 询</el-button>
    </div>

    <el-table v-loading="loading" :data="rows">
      <el-table-column label="时间" width="170">
        <template #default="{ row }">{{ row.created_at }}</template>
      </el-table-column>
      <el-table-column label="单号" width="170">
        <template #default="{ row }">
          <a class="source-no" @click.prevent="gotoSource(row)">{{ row.source_no }}</a>
        </template>
      </el-table-column>
      <el-table-column prop="product_name" label="商品" min-width="130" />
      <el-table-column label="仓库/库位" width="140">
        <template #default="{ row }">{{ row.warehouse_name }} / {{ row.location_name }}</template>
      </el-table-column>
      <el-table-column label="方向" width="70">
        <template #default="{ row }">
          <span :class="row.direction === 1 ? 'dir-in' : 'dir-out'">{{ row.direction === 1 ? '+' : '-' }}</span>
        </template>
      </el-table-column>
      <el-table-column label="数量" width="100" align="right">
        <template #default="{ row }"><span class="font-code qty-cell">{{ row.quantity }}</span></template>
      </el-table-column>
      <el-table-column label="变动后余额" width="110" align="right">
        <template #default="{ row }"><span class="font-code">{{ row.balance_after }}</span></template>
      </el-table-column>
      <el-table-column label="类型" width="100">
        <template #default="{ row }"><el-tag size="small">{{ row.source_type_label }}</el-tag></template>
      </el-table-column>
      <el-table-column prop="operator_name" label="操作人" width="100" />
    </el-table>
    <el-pagination
      v-model:current-page="query.page"
      :total="total"
      :page-size="10"
      layout="total, prev, pager, next"
      @current-change="load"
    />
  </div>
</template>

<script setup lang="ts">
// 库存流水页：筛选（商品/仓库/类型/方向/日期）；单号点击跳对应单据（盘点类跳详情，其余提示）
import { onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ElMessage } from 'element-plus'
import { inventoryApi, type MovementItem } from '../../api/inventory'
import { productApi } from '../../api/product'
import { warehouseApi } from '../../api/warehouse'

const route = useRoute()
const router = useRouter()
const rows = ref<MovementItem[]>([])
const total = ref(0)
const loading = ref(false)
const products = ref<{ id: number; code: string; name: string }[]>([])
const warehouses = ref<{ id: number; name: string }[]>([])
const dateRange = ref<[Date, Date] | null>(null)
const query = reactive<{
  page: number
  product_id?: number
  warehouse_id?: number
  source_type?: string
  direction?: number
}>({ page: 1 })

// 单据类型中文标签（与后端枚举一致）
const sourceTypeLabels: Record<string, string> = {
  purchase_inbound: '采购入库',
  sales_outbound: '销售出库',
  pick: '领料出库',
  return: '退料入库',
  finished_inbound: '成品入库',
  outsourcing_out: '委外发出',
  outsourcing_in: '委外回收',
  check_in: '盘盈',
  check_out: '盘亏',
}

// 日期快捷项：今天/近7天/近30天
const dateShortcuts = [
  { text: '今天', value: () => [new Date(), new Date()] },
  { text: '近 7 天', value: () => [new Date(Date.now() - 6 * 86400000), new Date()] },
  { text: '近 30 天', value: () => [new Date(Date.now() - 29 * 86400000), new Date()] },
]

async function load() {
  loading.value = true
  try {
    // 日期范围格式化（本地日期 YYYY-MM-DD）
    const [from, to] = dateRange.value ?? []
    const res = await inventoryApi.movements({
      page: query.page,
      product_id: query.product_id,
      warehouse_id: query.warehouse_id,
      source_type: query.source_type,
      direction: query.direction,
      date_from: from ? from.toISOString().slice(0, 10) : undefined,
      date_to: to ? to.toISOString().slice(0, 10) : undefined,
    })
    rows.value = res.items
    total.value = res.total
  } catch (e) {
    ElMessage.error((e as Error).message)
  } finally {
    loading.value = false
  }
}
function reload() {
  query.page = 1
  load()
}

// 单号链接：盘点类跳盘点详情；其他来源提示对应模块未实施
function gotoSource(row: MovementItem) {
  if (row.source_type === 'check_in' || row.source_type === 'check_out') {
    router.push(`/inventory/checks/${row.source_id}`)
  } else {
    ElMessage.info(`${row.source_type_label}单据页随对应模块实施后开放`)
  }
}

onMounted(async () => {
  // 从余额页行点击带入的预填筛选
  const q = route.query as Record<string, string>
  if (q.product_id) query.product_id = Number(q.product_id)
  if (q.warehouse_id) query.warehouse_id = Number(q.warehouse_id)
  load()
  // 商品/仓库下拉（全量）
  try {
    const p = await productApi.list({ per_page: 100 })
    products.value = p.items.map((i) => ({ id: i.id, code: i.code, name: i.name }))
  } catch {
    // 下拉失败不阻断列表
  }
  try {
    const w = await warehouseApi.list({ per_page: 100 })
    warehouses.value = w.items.map((i) => ({ id: i.id, name: i.name }))
  } catch {
    // 下拉失败不阻断列表
  }
})
</script>

<style scoped>
/* 方向色：+绿 / -红（设计系统 inventory.md §3） */
.dir-in {
  color: #059669;
  font-weight: 700;
  font-family: 'Fira Code', monospace;
}
.dir-out {
  color: #dc2626;
  font-weight: 700;
  font-family: 'Fira Code', monospace;
}
.qty-cell {
  font-weight: 600;
}
.source-no {
  color: #334155;
  font-family: 'Fira Code', monospace;
  cursor: pointer;
  text-decoration: underline;
  text-decoration-color: #cbd5e1;
}
.source-no:hover {
  color: #059669;
}
</style>
```

- [ ] **Step 3: 启动前端人工冒烟（可选，E2E 会覆盖）**

Run: `cd web && npm run dev`，访问 `http://localhost:5173/inventory/balances` 确认菜单与两页可渲染；无编译错误即通过（无需逐功能手测，Task 8 E2E 覆盖）。

- [ ] **Step 4: 提交**

```bash
cd /d/code/project/php-design && git add web/src/views/inventory/BalancesView.vue web/src/views/inventory/MovementsView.vue
git commit -m "feat: 库存余额/流水页面（筛选/预警标签/导出/单号跳转）"
```

---

## Task 7: 前端页面 B（库存盘点 + 库存预警）

**Files:**
- Create: `web/src/views/inventory/ChecksView.vue`
- Create: `web/src/views/inventory/AlertsView.vue`
- Modify: `web/src/router/index.ts`（盘点路由支持 `:id?` 可选参数，供流水页单号跳转）

**Interfaces:**
- Consumes: Task 5 `inventoryApi`（checks/checkDetail/createCheck/updateCheck/deleteCheck/approveCheck/autoBooks/alerts）与类型；Task 5 设计系统页 `pages/inventory.md`；基础资料 `productApi.byBarcode`（扫码）
- Produces: 盘点页（列表/新建弹窗含「加载账面数」与扫码/编辑/删除/审核确认与结果弹窗/详情只读含 diff 列）；预警页（汇总条 + 卡片网格）；E2E TC-INV-05~12 的 UI 载体

- [ ] **Step 1: 路由支持可选 id（详情直达）**

修改 `web/src/router/index.ts` 中盘点路由：

```ts
        {
          path: 'inventory/checks/:id?',
          name: 'inventory-checks',
          component: () => import('../views/inventory/ChecksView.vue'),
          meta: { permission: 'check.list' },
        },
```

- [ ] **Step 2: 实现 `web/src/views/inventory/ChecksView.vue`**

```vue
<!-- 库存盘点页：草稿 CRUD + 审核（确认/结果弹窗）+ 详情只读 -->
<template>
  <div class="page-card">
    <div class="toolbar">
      <span class="page-title">库存盘点</span>
      <el-input v-model="query.keyword" placeholder="单号" clearable style="width: 200px" @keyup.enter="reload" @clear="reload" />
      <el-select v-model="query.status" placeholder="状态" clearable style="width: 120px" @change="reload">
        <el-option label="草稿" :value="0" />
        <el-option label="已审核" :value="1" />
      </el-select>
      <el-button v-if="auth.has('check.create')" class="btn-primary" @click="openCreate">新 建</el-button>
      <el-button class="btn-secondary" @click="reload">查 询</el-button>
    </div>

    <el-table v-loading="loading" :data="rows">
      <el-table-column prop="no" label="单号" width="180" class-name="font-code" />
      <el-table-column prop="warehouse_name" label="仓库" width="110" />
      <el-table-column label="状态" width="100">
        <template #default="{ row }">
          <el-tag :type="row.status === 1 ? 'success' : 'info'">{{ row.status === 1 ? '已审核' : '草稿' }}</el-tag>
        </template>
      </el-table-column>
      <el-table-column prop="checker" label="审核人" width="100" />
      <el-table-column prop="check_time" label="审核时间" width="170" />
      <el-table-column prop="remark" label="备注" min-width="120" />
      <el-table-column label="操作" width="200" fixed="right">
        <template #default="{ row }">
          <template v-if="row.status === 0">
            <el-button v-if="auth.has('check.update')" link type="primary" @click="openEdit(row)">编 辑</el-button>
            <el-button v-if="auth.has('check.delete')" link type="danger" @click="remove(row)">删 除</el-button>
            <el-button v-if="auth.has('check.update')" link type="success" @click="approve(row)">审 核</el-button>
          </template>
          <el-button v-else link type="primary" @click="openDetail(row.id)">查 看</el-button>
        </template>
      </el-table-column>
    </el-table>
    <el-pagination
      v-model:current-page="query.page"
      :total="total"
      :page-size="10"
      layout="total, prev, pager, next"
      @current-change="load"
    />

    <!-- 新建/编辑弹窗：仓库 + 加载账面数 + 扫码 + 明细行实盘录入 -->
    <el-dialog v-model="dialogVisible" :title="form.id ? '编辑盘点单' : '新建盘点单'" width="900px" :close-on-click-modal="false">
      <div class="dialog-body">
        <div class="check-toolbar">
          <el-select v-model="form.warehouse_id" placeholder="盘点仓库" :disabled="!!form.id" style="width: 180px">
            <el-option v-for="w in warehouses" :key="w.id" :label="w.name" :value="w.id" />
          </el-select>
          <el-button class="btn-secondary" :disabled="!form.warehouse_id" :loading="loadingBooks" @click="loadBooks"
            >加 载账面数</el-button
          >
          <el-input
            ref="barcodeInput"
            v-model="barcode"
            placeholder="扫描条码回车添加商品"
            clearable
            style="width: 240px"
            @keyup.enter="scanAdd"
          />
        </div>
        <el-table :data="form.items" size="small" max-height="360">
          <el-table-column label="商品" min-width="200">
            <template #default="{ row }">{{ row.product_name }}（{{ row.product_code }}）</template>
          </el-table-column>
          <el-table-column prop="location_name" label="库位" width="90" />
          <el-table-column label="账面数" width="110" align="right">
            <template #default="{ row }"><span class="book-qty">{{ row.book_qty }}</span></template>
          </el-table-column>
          <el-table-column label="实盘数" width="160">
            <template #default="{ row }">
              <el-input-number v-model="row.actual_qty" :min="0" :precision="2" :controls="false" style="width: 110px" />
            </template>
          </el-table-column>
          <el-table-column label="操作" width="70">
            <template #default="{ $index }">
              <el-button link type="danger" @click="form.items.splice($index, 1)">删 除</el-button>
            </template>
          </el-table-column>
        </el-table>
        <div class="dialog-remark">
          <el-input v-model="form.remark" placeholder="备注（可选）" />
        </div>
      </div>
      <template #footer>
        <el-button @click="dialogVisible = false">取 消</el-button>
        <el-button type="primary" class="btn-primary" :loading="saving" @click="save">保 存</el-button>
      </template>
    </el-dialog>

    <!-- 详情只读弹窗：含差异列（红负/绿正） -->
    <el-dialog v-model="detailVisible" title="盘点单详情" width="800px">
      <div v-if="detail" class="detail-head">
        <span class="font-code">单号 {{ detail.no }}</span>
        <span>仓库 {{ detail.warehouse_name }}</span>
        <el-tag :type="detail.status === 1 ? 'success' : 'info'">{{ detail.status === 1 ? '已审核' : '草稿' }}</el-tag>
      </div>
      <el-table :data="detail?.items ?? []" size="small">
        <el-table-column label="商品" min-width="200">
          <template #default="{ row }">{{ row.product_name }}（{{ row.product_code }}）</template>
        </el-table-column>
        <el-table-column prop="location_name" label="库位" width="90" />
        <el-table-column prop="book_qty" label="账面数" width="110" align="right" />
        <el-table-column prop="actual_qty" label="实盘数" width="110" align="right" />
        <el-table-column label="差异" width="110" align="right">
          <template #default="{ row }">
            <span :class="Number(row.diff_qty) > 0 ? 'diff-in' : Number(row.diff_qty) < 0 ? 'diff-out' : ''">{{
              row.diff_qty
            }}</span>
          </template>
        </el-table-column>
      </el-table>
      <template #footer>
        <el-button @click="detailVisible = false">关 闭</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup lang="ts">
// 库存盘点页：草稿增删改 + 账面预填 + 扫码录入 + 审核（确认与结果弹窗）+ 详情查看
import { onMounted, reactive, ref, nextTick } from 'vue'
import { useRoute } from 'vue-router'
import { ElMessage, ElMessageBox } from 'element-plus'
import { inventoryApi, type CheckItem, type CheckDetailItem, type AutoBookItem } from '../../api/inventory'
import { productApi } from '../../api/product'
import { warehouseApi } from '../../api/warehouse'
import { useAuthStore } from '../../stores/auth'

const auth = useAuthStore()
const route = useRoute()
const rows = ref<CheckItem[]>([])
const total = ref(0)
const loading = ref(false)
const warehouses = ref<{ id: number; name: string }[]>([])
const query = reactive<{ page: number; keyword: string; status?: number }>({ page: 1, keyword: '' })

// 新建/编辑弹窗
const dialogVisible = ref(false)
const saving = ref(false)
const loadingBooks = ref(false)
const barcode = ref('')
const barcodeInput = ref<{ focus: () => void } | null>(null)
// 账面缓存：autoBooks 结果（扫码回填账面数用）
const books = ref<AutoBookItem[]>([])
interface CheckRow {
  product_id: number
  product_name: string
  product_code: string
  location_id: number
  location_name: string
  book_qty: number
  actual_qty: number
}
interface CheckForm {
  id: number | null
  warehouse_id?: number
  remark: string
  items: CheckRow[]
}
const form = reactive<CheckForm>({ id: null, warehouse_id: undefined, remark: '', items: [] })

// 详情弹窗
const detailVisible = ref(false)
const detail = ref<{
  no: string
  warehouse_name: string
  status: number
  items: CheckDetailItem[]
} | null>(null)

async function load() {
  loading.value = true
  try {
    const res = await inventoryApi.checks({ page: query.page, keyword: query.keyword || undefined, status: query.status })
    rows.value = res.items
    total.value = res.total
  } catch (e) {
    ElMessage.error((e as Error).message)
  } finally {
    loading.value = false
  }
}
function reload() {
  query.page = 1
  load()
}

// 新建：打开弹窗并聚焦扫码框
function openCreate() {
  Object.assign(form, { id: null, warehouse_id: undefined, remark: '', items: [] })
  books.value = []
  dialogVisible.value = true
  nextTick(() => barcodeInput.value?.focus())
}

// 编辑：回填明细（仓库锁定）
async function openEdit(row: CheckItem) {
  try {
    const d = await inventoryApi.checkDetail(row.id)
    form.id = d.id
    form.warehouse_id = d.warehouse_id
    form.remark = d.remark ?? ''
    form.items = d.items.map((i) => ({
      product_id: i.product_id,
      product_name: i.product_name,
      product_code: i.product_code,
      location_id: i.location_id,
      location_name: i.location_name,
      book_qty: i.book_qty,
      actual_qty: i.actual_qty,
    }))
    dialogVisible.value = true
  } catch (e) {
    ElMessage.error((e as Error).message)
  }
}

// 删除草稿
async function remove(row: CheckItem) {
  await ElMessageBox.confirm('确认删除该盘点单？', '提示', { type: 'warning' })
  try {
    await inventoryApi.deleteCheck(row.id)
    ElMessage.success('删除成功')
    load()
  } catch (e) {
    ElMessage.error((e as Error).message)
  }
}

// 加载账面数：明细重置为该仓库全部有余额行（实盘默认=账面）
async function loadBooks() {
  if (!form.warehouse_id) return
  loadingBooks.value = true
  try {
    const res = await inventoryApi.autoBooks(form.warehouse_id)
    books.value = res.items
    form.items = res.items.map((b) => ({
      product_id: b.product_id,
      product_name: b.product_name,
      product_code: b.product_code,
      location_id: b.location_id,
      location_name: b.location_name,
      book_qty: b.book_qty,
      actual_qty: b.book_qty,
    }))
  } catch (e) {
    ElMessage.error((e as Error).message)
  } finally {
    loadingBooks.value = false
  }
}

// 扫码：条码回车 → 商品匹配 → 有余额则追加行（账面数回填），无余额提示
async function scanAdd() {
  const code = barcode.value.trim()
  if (!code) return
  try {
    const p = await productApi.byBarcode(code)
    const book = books.value.find((b) => b.product_id === p.id)
    if (!book) {
      // 未加载账面或该商品无余额：尝试按仓库实时查询后补充
      if (form.warehouse_id && books.value.length === 0) {
        await loadBooks()
        const b2 = books.value.find((x) => x.product_id === p.id)
        if (b2) {
          appendRow(b2)
          barcode.value = ''
          return
        }
      }
      ElMessage.error('商品在该仓库无库存，无需盘点')
      return
    }
    if (form.items.some((i) => i.product_id === p.id)) {
      ElMessage.warning('该商品已在明细中')
      return
    }
    appendRow(book)
    barcode.value = ''
  } catch (e) {
    // 条码未匹配（后端 1117）：保留输入便于重扫
    ElMessage.error((e as Error).message)
  }
}

// 追加盘点行（实盘默认=账面）
function appendRow(book: AutoBookItem) {
  form.items.push({
    product_id: book.product_id,
    product_name: book.product_name,
    product_code: book.product_code,
    location_id: book.location_id,
    location_name: book.location_name,
    book_qty: book.book_qty,
    actual_qty: book.book_qty,
  })
}

// 保存草稿：新建/更新；负数由 el-input-number min=0 前端拦截
async function save() {
  if (!form.warehouse_id) return ElMessage.warning('请选择盘点仓库')
  if (form.items.length === 0) return ElMessage.warning('请先加载账面数或扫码添加明细')
  saving.value = true
  try {
    const payload = {
      warehouse_id: form.warehouse_id,
      remark: form.remark || undefined,
      items: form.items.map((i) => ({ product_id: i.product_id, location_id: i.location_id, actual_qty: i.actual_qty })),
    }
    if (form.id) await inventoryApi.updateCheck(form.id, payload)
    else await inventoryApi.createCheck(payload)
    ElMessage.success('保存成功')
    dialogVisible.value = false
    load()
  } catch (e) {
    ElMessage.error((e as Error).message)
  } finally {
    saving.value = false
  }
}

// 审核：二次确认 → approve → 结果弹窗
async function approve(row: CheckItem) {
  await ElMessageBox.confirm('确认审核？差异将生成盘盈/盘亏流水并更新库存', '审核确认', {
    confirmButtonText: '确 定',
    cancelButtonText: '取 消',
    type: 'warning',
  })
  try {
    const res = await inventoryApi.approveCheck(row.id)
    if (res.changed_items > 0) {
      await ElMessageBox.alert(
        `盘盈 ${res.increased_items} 项 +${res.increased}、盘亏 ${res.decreased_items} 项 -${res.decreased}`,
        '审核完成',
        { confirmButtonText: '确 定' },
      )
    } else {
      await ElMessageBox.alert('本次无差异，未生成流水', '审核完成', { confirmButtonText: '确 定' })
    }
    load()
  } catch (e) {
    ElMessage.error((e as Error).message)
  }
}

// 详情查看（含从流水页单号跳转直达）
async function openDetail(id: number) {
  try {
    const d = await inventoryApi.checkDetail(id)
    detail.value = {
      no: d.no,
      warehouse_name: d.warehouse_name,
      status: d.status,
      items: d.items,
    }
    detailVisible.value = true
  } catch (e) {
    ElMessage.error((e as Error).message)
  }
}

onMounted(async () => {
  load()
  // 流水页单号跳转直达详情
  const id = route.params.id
  if (id) openDetail(Number(id))
  try {
    const w = await warehouseApi.list({ per_page: 100 })
    warehouses.value = w.items.map((i) => ({ id: i.id, name: i.name }))
  } catch {
    // 下拉失败不阻断列表
  }
})
</script>

<style scoped>
/* 弹窗布局与差异色（设计系统 inventory.md §4） */
.check-toolbar {
  display: flex;
  gap: var(--space-md);
  margin-bottom: var(--space-lg);
}
.dialog-remark {
  margin-top: var(--space-lg);
}
.book-qty {
  color: #64748b;
}
.diff-in {
  color: #059669;
  font-weight: 700;
}
.diff-out {
  color: #dc2626;
  font-weight: 700;
}
.detail-head {
  display: flex;
  gap: var(--space-xl);
  align-items: center;
  margin-bottom: var(--space-lg);
}
</style>
```

- [ ] **Step 3: 实现 `web/src/views/inventory/AlertsView.vue`**

```vue
<!-- 库存预警页：顶部汇总 + KPI 卡片（level=1 红 / level=2 琥珀） -->
<template>
  <div class="page-card">
    <div class="toolbar">
      <span class="page-title">库存预警</span>
      <el-button class="btn-secondary" @click="load">刷 新</el-button>
    </div>
    <div class="summary-bar">
      低于下限 <span class="num-danger">{{ lowCount }}</span> 项 / 高于上限
      <span class="num-warn">{{ highCount }}</span> 项
    </div>
    <div v-loading="loading" class="alert-grid">
      <div v-for="a in items" :key="`${a.product_code}-${a.warehouse_name}`" class="alert-card" :class="a.level === 1 ? 'card-low' : 'card-high'">
        <div class="card-title">
          <span class="product-name">{{ a.product_name }}</span>
          <span class="font-code product-code">{{ a.product_code }}</span>
        </div>
        <div class="card-wh">{{ a.warehouse_name }}</div>
        <div class="card-qty">
          <span class="font-code">{{ a.quantity }}</span>
          <span class="qty-unit">当前量</span>
        </div>
        <div class="card-limits">
          <span v-if="a.safety_min > 0">下限 {{ a.safety_min }}</span>
          <span v-if="a.safety_max > 0">上限 {{ a.safety_max }}</span>
        </div>
        <div class="card-gap" :class="a.level === 1 ? 'gap-low' : 'gap-high'">
          {{ a.level === 1 ? `低于下限 ${formatGap(a.safety_min, a.quantity)}` : `高于上限 ${formatGap(a.quantity, a.safety_max)}` }}
        </div>
      </div>
      <el-empty v-if="!loading && items.length === 0" description="暂无预警" />
    </div>
  </div>
</template>

<script setup lang="ts">
// 库存预警页：查询时计算的预警列表（上下限修改后立即生效）
import { onMounted, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { inventoryApi, type AlertItem } from '../../api/inventory'

const items = ref<AlertItem[]>([])
const loading = ref(false)

// 汇总数：level=1 低于下限 / level=2 高于上限
const lowCount = ref(0)
const highCount = ref(0)

// 超额幅度（保留两位）
function formatGap(limit: number, qty: number): string {
  return Math.abs(Number(limit) - Number(qty)).toFixed(2)
}

async function load() {
  loading.value = true
  try {
    const res = await inventoryApi.alerts()
    items.value = res.items
    lowCount.value = res.items.filter((a) => a.level === 1).length
    highCount.value = res.items.filter((a) => a.level === 2).length
  } catch (e) {
    ElMessage.error((e as Error).message)
  } finally {
    loading.value = false
  }
}

onMounted(load)
</script>

<style scoped>
/* 汇总条 + 卡片网格（设计系统 inventory.md §5） */
.summary-bar {
  background: #f8fafc;
  border-radius: 8px;
  padding: var(--space-lg) var(--space-xl);
  margin-bottom: var(--space-xl);
  font-size: 14px;
}
.num-danger {
  color: #dc2626;
  font-weight: 700;
}
.num-warn {
  color: #d97706;
  font-weight: 700;
}
.alert-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
  gap: var(--space-lg);
  min-height: 120px;
}
.alert-card {
  background: #fff;
  border-radius: 8px;
  padding: var(--space-xl);
  box-shadow: 0 1px 3px rgba(15, 23, 42, 0.08);
  border-left: 4px solid #cbd5e1;
}
.card-low {
  border-left-color: #dc2626;
}
.card-high {
  border-left-color: #d97706;
}
.card-title {
  display: flex;
  justify-content: space-between;
  align-items: baseline;
}
.product-name {
  font-weight: 600;
}
.product-code {
  color: #64748b;
  font-size: 12px;
}
.card-wh {
  color: #64748b;
  font-size: 13px;
  margin: var(--space-sm) 0 var(--space-md);
}
.card-qty {
  font-size: 20px;
  font-weight: 700;
  display: flex;
  align-items: baseline;
  gap: var(--space-sm);
}
.qty-unit {
  font-size: 12px;
  color: #94a3b8;
  font-weight: 400;
}
.card-limits {
  display: flex;
  gap: var(--space-xl);
  color: #64748b;
  font-size: 13px;
  margin-top: var(--space-md);
}
.card-gap {
  margin-top: var(--space-sm);
  font-size: 13px;
  font-weight: 600;
}
.gap-low {
  color: #dc2626;
}
.gap-high {
  color: #d97706;
}
</style>
```

- [ ] **Step 4: 跑类型检查确认无编译错误**

Run: `cd web && npm run type-check`
Expected: 通过（无 TS 错误）。

- [ ] **Step 5: 提交**

```bash
cd /d/code/project/php-design && git add web/src/views/inventory/ChecksView.vue web/src/views/inventory/AlertsView.vue web/src/router/index.ts
git commit -m "feat: 库存盘点/预警页面（账面预填/扫码/审核确认/卡片预警）"
```

---

## Task 8: E2E 全量测试（Playwright TC-INV-01~12 + 1106 补测）

**Files:**
- Create: `web/e2e/inventory.spec.ts`
- Modify: `docs/test/2026-08-12-库存管理模块端到端测试.md`（§5 结果记录表填写执行结果）

**Interfaces:**
- Consumes: 全部后端 API 与前端页面；`web/e2e/helpers.ts` 的 `loginByAPI`；Playwright webServer（自动 `migrate:fresh --seed` 注入基线库存）
- Produces: 12 个库存 E2E 用例 + 1 个基础资料 1106 补测用例全绿；测试结果记录表回填

- [ ] **Step 1: 写 E2E 测试 `web/e2e/inventory.spec.ts`**

```ts
// 库存管理模块 E2E：TC-INV-01~12（串行，余额随盘点变化）+ 基础资料 1106 删除保护补测
// 基线库存（InventorySeeder）：MAT-001=100@A-01、SEMI-001=30@A-01、FIN-002=20@B-01（主仓）
import { readFile } from 'node:fs/promises'
import { expect, test, type Page } from '@playwright/test'
import { loginByAPI } from './helpers'

// 已登录页面的认证请求辅助：token 取自 localStorage
async function apiGet(page: Page, url: string, params: Record<string, string | number> = {}) {
  const token = await page.evaluate(() => localStorage.getItem('token'))
  const res = await page.request.get(url, { headers: { Authorization: `Bearer ${token}` }, params })
  expect(res.ok()).toBeTruthy()
  return (await res.json()).data
}
async function apiPost(page: Page, url: string, body?: unknown) {
  const token = await page.evaluate(() => localStorage.getItem('token'))
  const res = await page.request.post(url, { headers: { Authorization: `Bearer ${token}` }, data: body })
  return (await res.json()) as { code: number; message?: string; data?: unknown }
}

test.describe('库存管理模块', () => {
  // 用例间余额相互依赖（盘点变更库存），串行执行
  test.describe.configure({ mode: 'serial' })

  test('TC-INV-01 余额列表与筛选', async ({ page }) => {
    await loginByAPI(page, 'admin', 'admin123')
    await page.goto('/inventory/balances')
    await expect(page.locator('.el-table__row', { hasText: 'MAT-001' })).toContainText('100')
    await expect(page.locator('.el-table__row', { hasText: 'SEMI-001' })).toContainText('30')
    await expect(page.locator('.el-table__row', { hasText: 'FIN-002' })).toContainText('20')
    // 仓库筛选「主仓」+ 关键字 MAT
    await page.getByPlaceholder('仓库').click()
    await page.locator('.el-select-dropdown__item', { hasText: '主仓' }).click()
    await page.getByPlaceholder('商品编码/名称/条码').fill('MAT')
    await page.getByRole('button', { name: /查\s*询/ }).click()
    await expect(page.locator('.el-table__row')).toHaveCount(1)
    await expect(page.locator('.el-table__row')).toContainText('MAT-001')
    // 类型筛选取「原料」
    await page.getByPlaceholder('类型').click()
    await page.locator('.el-select-dropdown__item', { hasText: '原料' }).click()
    await page.getByRole('button', { name: /查\s*询/ }).click()
    await expect(page.locator('.el-table__row')).toHaveCount(1)
  })

  test('TC-INV-02 余额导出 CSV（BOM/表头/行数）', async ({ page }) => {
    await loginByAPI(page, 'admin', 'admin123')
    await page.goto('/inventory/balances')
    const downloadPromise = page.waitForEvent('download')
    await page.getByRole('button', { name: /导\s*出/ }).click()
    const download = await downloadPromise
    const path = await download.path()
    expect(path).toBeTruthy()
    const csv = await readFile(path!, 'utf-8')
    // UTF-8 BOM 开头（中文无乱码）
    expect(csv.charCodeAt(0)).toBe(0xfeff)
    const lines = csv.trim().split('\n')
    expect(lines[0]).toBe('商品编码,商品名称,仓库,库位,数量,下限,上限,状态')
    expect(lines).toHaveLength(4) // 表头 + 3 行基线库存
    expect(lines[1]).toContain('MAT-001')
  })

  test('TC-INV-03 流水列表与筛选', async ({ page }) => {
    await loginByAPI(page, 'admin', 'admin123')
    await page.goto('/inventory/movements')
    // 首行为最新流水（基线为采购入库 MAT-001 +100）
    const firstRow = page.locator('.el-table__row').first()
    await expect(firstRow).toContainText('采购入库')
    await expect(firstRow).toContainText('MAT-001')
    await expect(firstRow).toContainText('100')
    // 方向选「出库 -」→ 基线无出库流水 → 空态
    await page.getByPlaceholder('方向').click()
    await page.locator('.el-select-dropdown__item', { hasText: '出库 -' }).click()
    await page.getByRole('button', { name: /查\s*询/ }).click()
    await expect(page.locator('.el-empty')).toContainText('暂无数据')
    // 重新进入页面重置筛选后：日期快捷「近 30 天」再查询
    await page.goto('/inventory/movements')
    await page.locator('.el-range-editor').click()
    await page.locator('.el-picker-panel__shortcut', { hasText: '近 30 天' }).click()
    await page.getByRole('button', { name: /查\s*询/ }).click()
    await expect(page.locator('.el-table__row').first()).toContainText('采购入库')
    // 单据类型选「盘盈」→ 当前无盘盈流水 → 空态
    await page.getByPlaceholder('单据类型').click()
    await page.locator('.el-select-dropdown__item', { hasText: '盘盈' }).click()
    await page.getByRole('button', { name: /查\s*询/ }).click()
    await expect(page.locator('.el-empty')).toContainText('暂无数据')
    // 重新进入重置筛选后：单号点击 → 采购入库来源提示对应模块未实施
    await page.goto('/inventory/movements')
    await page.locator('.source-no').first().click()
    await expect(page.locator('.el-message')).toContainText('随对应模块实施后开放')
  })

  test('TC-INV-04 余额=流水恒等式核对', async ({ page }) => {
    await loginByAPI(page, 'admin', 'admin123')
    // 三个基线商品逐一核对：Σ(direction*quantity) == 当前余额
    for (const [keyword, expected] of [
      ['MAT-001', 100],
      ['SEMI-001', 30],
      ['FIN-002', 20],
    ] as const) {
      const balances = await apiGet(page, '/api/v1/inventory/balances', { keyword, per_page: 100 })
      expect(balances.items).toHaveLength(1)
      const balanceQty = Number(balances.items[0].quantity)
      const productId = balances.items[0].product_id
      const movements = await apiGet(page, '/api/v1/inventory/movements', { product_id: productId, per_page: 100 })
      const sum = movements.items.reduce((acc: number, m: { direction: number; quantity: number }) => acc + m.direction * Number(m.quantity), 0)
      expect(Number(sum.toFixed(2))).toBe(Number(Number(balanceQty).toFixed(2)))
      expect(Number(balanceQty)).toBe(expected)
    }
  })

  test('TC-INV-05 新建盘点单（加载账面数）', async ({ page }) => {
    await loginByAPI(page, 'admin', 'admin123')
    await page.goto('/inventory/checks')
    await page.getByRole('button', { name: /新\s*建/ }).click()
    const dialog = page.locator('.el-dialog')
    await dialog.getByPlaceholder('盘点仓库').click()
    await page.locator('.el-select-dropdown__item', { hasText: '主仓' }).click()
    await dialog.getByRole('button', { name: /加\s*载账面数/ }).click()
    // 3 行明细：账面 100/30/20，实盘默认=账面
    await expect(dialog.locator('.el-table__row')).toHaveCount(3)
    await expect(dialog.locator('.el-table__row', { hasText: 'MAT-001' })).toContainText('100')
    await expect(dialog.locator('.el-table__row', { hasText: 'SEMI-001' })).toContainText('30')
    await expect(dialog.locator('.el-table__row', { hasText: 'FIN-002' })).toContainText('20')
    await dialog.getByRole('button', { name: /保\s*存/ }).click()
    await expect(page.locator('.el-message--success')).toContainText('保存成功')
    // 列表出现草稿单号 CK{date}-001
    await expect(page.locator('.el-table__row', { hasText: '草稿' })).toContainText('CK')
  })

  test('TC-INV-06 盘点单编辑与删除（已审核不可改删）', async ({ page }) => {
    await loginByAPI(page, 'admin', 'admin123')
    await page.goto('/inventory/checks')
    // 步骤 1：编辑草稿，MAT-001 实盘改 105
    const draftRow = page.locator('.el-table__row', { hasText: '草稿' }).first()
    await draftRow.getByRole('button', { name: /编\s*辑/ }).click()
    const dialog = page.locator('.el-dialog')
    const matRow = dialog.locator('.el-table__row', { hasText: 'MAT-001' })
    await matRow.locator('.el-input-number input').fill('105')
    await dialog.getByRole('button', { name: /保\s*存/ }).click()
    await expect(page.locator('.el-message--success')).toContainText('保存成功')
    // 步骤 2：再建一张全量单并审核（diff=0）
    await page.getByRole('button', { name: /新\s*建/ }).click()
    const dialog2 = page.locator('.el-dialog').last()
    await dialog2.getByPlaceholder('盘点仓库').click()
    await page.locator('.el-select-dropdown__item', { hasText: '主仓' }).click()
    await dialog2.getByRole('button', { name: /加\s*载账面数/ }).click()
    await dialog2.getByRole('button', { name: /保\s*存/ }).click()
    await expect(page.locator('.el-message--success')).toContainText('保存成功')
    const approvedRow = page.locator('.el-table__row', { hasText: '草稿' }).first()
    await approvedRow.getByRole('button', { name: /审\s*核/ }).click()
    await page.locator('.el-message-box').getByRole('button', { name: /确\s*定/ }).click()
    await page.locator('.el-message-box').getByRole('button', { name: /确\s*定/ }).click() // 结果弹窗
    await expect(page.locator('.el-table__row', { hasText: '已审核' })).toHaveCount(1)
    // 步骤 3：已审核单无编辑/删除入口；API 直改 → 1202
    await expect(page.locator('.el-table__row', { hasText: '已审核' }).getByRole('button', { name: /编\s*辑/ })).toHaveCount(0)
    const approvedNo = await page.locator('.el-table__row', { hasText: '已审核' }).locator('td').first().textContent()
    const list = await apiGet(page, '/api/v1/checks', { keyword: approvedNo?.trim() })
    const approved = list.items[0] as { id: number }
    const put = await apiPost(page, `/api/v1/checks/${approved.id}`, { warehouse_id: 1, items: [{ product_id: 1, location_id: 1, actual_qty: 1 }] })
    expect(put.code).toBe(1202)
    // 步骤 4：删除第一张草稿（MAT-001 105 那张）
    const draft = page.locator('.el-table__row', { hasText: '草稿' }).first()
    await draft.getByRole('button', { name: /删\s*除/ }).click()
    await page.locator('.el-message-box').getByRole('button', { name: /确\s*定/ }).click()
    await expect(page.locator('.el-message--success')).toContainText('删除成功')
  })

  test('TC-INV-07 扫码盘点添加行', async ({ page }) => {
    await loginByAPI(page, 'admin', 'admin123')
    await page.goto('/inventory/checks')
    await page.getByRole('button', { name: /新\s*建/ }).click()
    const dialog = page.locator('.el-dialog')
    await dialog.getByPlaceholder('盘点仓库').click()
    await page.locator('.el-select-dropdown__item', { hasText: '主仓' }).click()
    // 不点「加载账面数」，直接扫码：FIN-002 条码 888888
    await dialog.getByPlaceholder('扫描条码回车添加商品').fill('888888')
    await dialog.getByPlaceholder('扫描条码回车添加商品').press('Enter')
    const finRow = dialog.locator('.el-table__row', { hasText: 'FIN-002' })
    await expect(finRow).toBeVisible()
    await expect(finRow).toContainText('20')
    // 未匹配条码 000000 → 红色错误提示，不添加行
    await dialog.getByPlaceholder('扫描条码回车添加商品').fill('000000')
    await dialog.getByPlaceholder('扫描条码回车添加商品').press('Enter')
    await expect(page.locator('.el-message--error')).toContainText('条码未匹配')
    await expect(dialog.locator('.el-table__row')).toHaveCount(1)
  })

  test('TC-INV-08 盘点审核-盘盈（+5）', async ({ page }) => {
    await loginByAPI(page, 'admin', 'admin123')
    await page.goto('/inventory/checks')
    await page.getByRole('button', { name: /新\s*建/ }).click()
    const dialog = page.locator('.el-dialog')
    await dialog.getByPlaceholder('盘点仓库').click()
    await page.locator('.el-select-dropdown__item', { hasText: '主仓' }).click()
    await dialog.getByRole('button', { name: /加\s*载账面数/ }).click()
    const matRow = dialog.locator('.el-table__row', { hasText: 'MAT-001' })
    await matRow.locator('.el-input-number input').fill('105')
    await dialog.getByRole('button', { name: /保\s*存/ }).click()
    await expect(page.locator('.el-message--success')).toContainText('保存成功')
    // 审核：确认框 → 结果弹窗「盘盈 1 项 +5、盘亏 0 项」
    const row = page.locator('.el-table__row', { hasText: '草稿' }).first()
    await row.getByRole('button', { name: /审\s*核/ }).click()
    await expect(page.locator('.el-message-box')).toContainText('确认审核？差异将生成盘盈/盘亏流水并更新库存')
    await page.locator('.el-message-box').getByRole('button', { name: /确\s*定/ }).click()
    await expect(page.locator('.el-message-box')).toContainText('盘盈 1 项 +5')
    await expect(page.locator('.el-message-box')).toContainText('盘亏 0 项')
    await page.locator('.el-message-box').getByRole('button', { name: /确\s*定/ }).click()
    // 余额页：MAT-001 = 105
    await page.goto('/inventory/balances')
    await expect(page.locator('.el-table__row', { hasText: 'MAT-001' })).toContainText('105')
    // 流水页筛「盘盈」：MAT-001 +5，来源单号 CK
    await page.goto('/inventory/movements')
    await page.getByPlaceholder('单据类型').click()
    await page.locator('.el-select-dropdown__item', { hasText: '盘盈' }).click()
    await page.getByRole('button', { name: /查\s*询/ }).click()
    const gainRow = page.locator('.el-table__row', { hasText: 'MAT-001' })
    await expect(gainRow).toContainText('+')
    await expect(gainRow).toContainText('5')
    await expect(gainRow).toContainText('105')
    await expect(gainRow).toContainText('CK')
    // 单号点击：盘盈来源 → 跳盘点详情
    await gainRow.locator('.source-no').click()
    await expect(page.locator('.el-dialog')).toContainText('盘点单详情')
    await page.locator('.el-dialog').getByRole('button', { name: /关\s*闭/ }).click()
  })

  test('TC-INV-09 盘点审核-盘亏（-2）', async ({ page }) => {
    await loginByAPI(page, 'admin', 'admin123')
    await page.goto('/inventory/checks')
    await page.getByRole('button', { name: /新\s*建/ }).click()
    const dialog = page.locator('.el-dialog')
    await dialog.getByPlaceholder('盘点仓库').click()
    await page.locator('.el-select-dropdown__item', { hasText: '主仓' }).click()
    await dialog.getByRole('button', { name: /加\s*载账面数/ }).click()
    const semiRow = dialog.locator('.el-table__row', { hasText: 'SEMI-001' })
    await semiRow.locator('.el-input-number input').fill('28')
    await dialog.getByRole('button', { name: /保\s*存/ }).click()
    await expect(page.locator('.el-message--success')).toContainText('保存成功')
    const row = page.locator('.el-table__row', { hasText: '草稿' }).first()
    await row.getByRole('button', { name: /审\s*核/ }).click()
    await page.locator('.el-message-box').getByRole('button', { name: /确\s*定/ }).click()
    await expect(page.locator('.el-message-box')).toContainText('盘亏 1 项 -2')
    await page.locator('.el-message-box').getByRole('button', { name: /确\s*定/ }).click()
    // 余额页：SEMI-001 = 28
    await page.goto('/inventory/balances')
    await expect(page.locator('.el-table__row', { hasText: 'SEMI-001' })).toContainText('28')
    // 流水页筛「盘亏」：SEMI-001 -2
    await page.goto('/inventory/movements')
    await page.getByPlaceholder('单据类型').click()
    await page.locator('.el-select-dropdown__item', { hasText: '盘亏' }).click()
    await page.getByRole('button', { name: /查\s*询/ }).click()
    const lossRow = page.locator('.el-table__row', { hasText: 'SEMI-001' })
    await expect(lossRow).toContainText('-')
    await expect(lossRow).toContainText('2')
    await expect(lossRow).toContainText('28')
    // 详情查看：diff 列 -2
    await page.goto('/inventory/checks')
    const approvedRow = page.locator('.el-table__row', { hasText: '已审核' }).first()
    await approvedRow.getByRole('button', { name: /查\s*看/ }).click()
    await expect(page.locator('.el-dialog').locator('.el-table__row', { hasText: 'SEMI-001' })).toContainText('-2')
    await page.locator('.el-dialog').getByRole('button', { name: /关\s*闭/ }).click()
  })

  test('TC-INV-10 审核幂等与并发防重', async ({ page }) => {
    await loginByAPI(page, 'admin', 'admin123')
    // 幂等：对已审核单再次 approve → 1204，余额不变
    const list = await apiGet(page, '/api/v1/checks', { status: 1, per_page: 100 })
    const approved = list.items[0] as { id: number }
    const again = await apiPost(page, `/api/v1/checks/${approved.id}/approve`)
    expect(again.code).toBe(1204)
    expect(again.message).toBe('该盘点单已审核')
    const semiBalance = await apiGet(page, '/api/v1/inventory/balances', { keyword: 'SEMI-001' })
    expect(Number(semiBalance.items[0].quantity)).toBe(28)
    // 并发：同一草稿单双 approve，仅一个成功
    const fresh = await apiPost(page, '/api/v1/checks', {
      warehouse_id: 1,
      items: [{ product_id: 2, location_id: 1, actual_qty: 30 }], // SEMI-001 账面 28 → 盘盈 2
    })
    expect(fresh.code).toBe(0)
    const freshList = await apiGet(page, '/api/v1/checks', { keyword: (fresh.data as { no: string }).no })
    const freshId = freshList.items[0].id as number
    const [r1, r2] = await Promise.all([
      apiPost(page, `/api/v1/checks/${freshId}/approve`),
      apiPost(page, `/api/v1/checks/${freshId}/approve`),
    ])
    const codes = [r1.code, r2.code].sort()
    expect(codes).toEqual([0, 1204])
    const after = await apiGet(page, '/api/v1/inventory/balances', { keyword: 'SEMI-001' })
    expect(Number(after.items[0].quantity)).toBe(30) // 仅变动一次
  })

  test('TC-INV-11 预警联动（低于下限出现与消除）', async ({ page }) => {
    await loginByAPI(page, 'admin', 'admin123')
    // 构造：MAT-001 盘点至 40（低于下限 50）并审核
    const mat = await apiGet(page, '/api/v1/inventory/balances', { keyword: 'MAT-001' })
    const matId = mat.items[0].product_id as number
    const locId = mat.items[0].location_id as number
    const low = await apiPost(page, '/api/v1/checks', {
      warehouse_id: 1,
      items: [{ product_id: matId, location_id: locId, actual_qty: 40 }],
    })
    expect(low.code).toBe(0)
    const lowList = await apiGet(page, '/api/v1/checks', { keyword: (low.data as { no: string }).no })
    const lowApprove = await apiPost(page, `/api/v1/checks/${lowList.items[0].id}/approve`)
    expect(lowApprove.code).toBe(0)
    // 余额页：低库存红标签
    await page.goto('/inventory/balances')
    const matRow = page.locator('.el-table__row', { hasText: 'MAT-001' })
    await expect(matRow).toContainText('40')
    await expect(matRow.locator('.el-tag', { hasText: '低库存' })).toHaveClass(/danger/)
    // 预警页：汇总「低于下限 1 项」+ 红色卡片
    await page.goto('/inventory/alerts')
    await expect(page.locator('.summary-bar')).toContainText('低于下限 1 项')
    const card = page.locator('.alert-card', { hasText: 'MAT-001' })
    await expect(card).toContainText('40')
    await expect(card).toContainText('50')
    await expect(card).toHaveClass(/card-low/)
    // 恢复：盘点 MAT-001 至 60 → 预警消除
    const restore = await apiPost(page, '/api/v1/checks', {
      warehouse_id: 1,
      items: [{ product_id: matId, location_id: locId, actual_qty: 60 }],
    })
    expect(restore.code).toBe(0)
    const restoreList = await apiGet(page, '/api/v1/checks', { keyword: (restore.data as { no: string }).no })
    const restoreApprove = await apiPost(page, `/api/v1/checks/${restoreList.items[0].id}/approve`)
    expect(restoreApprove.code).toBe(0)
    await page.goto('/inventory/balances')
    await expect(page.locator('.el-table__row', { hasText: 'MAT-001' })).toContainText('60')
    await page.goto('/inventory/alerts')
    await expect(page.locator('.summary-bar')).toContainText('低于下限 0 项')
    await expect(page.locator('.alert-card', { hasText: 'MAT-001' })).toHaveCount(0)
  })

  test('TC-INV-12 边界：负数与零差异', async ({ page }) => {
    await loginByAPI(page, 'admin', 'admin123')
    // 前端拦截：实盘输入负数被钳制（el-input-number min=0）
    await page.goto('/inventory/checks')
    await page.getByRole('button', { name: /新\s*建/ }).click()
    const dialog = page.locator('.el-dialog')
    await dialog.getByPlaceholder('盘点仓库').click()
    await page.locator('.el-select-dropdown__item', { hasText: '主仓' }).click()
    await dialog.getByRole('button', { name: /加\s*载账面数/ }).click()
    const input = dialog.locator('.el-table__row', { hasText: 'MAT-001' }).locator('.el-input-number input')
    await input.fill('-5')
    await input.blur()
    expect(Number(await input.inputValue())).toBeGreaterThanOrEqual(0)
    // 后端拦截：API 直发负数 → 1201
    const mat = await apiGet(page, '/api/v1/inventory/balances', { keyword: 'MAT-001' })
    const neg = await apiPost(page, '/api/v1/checks', {
      warehouse_id: 1,
      items: [{ product_id: mat.items[0].product_id, location_id: mat.items[0].location_id, actual_qty: -5 }],
    })
    expect(neg.code).toBe(1201)
    expect(neg.message).toBe('实盘数量不能为负数')
    // 零差异：实盘=账面审核 → 无流水
    const zero = await apiPost(page, '/api/v1/checks', {
      warehouse_id: 1,
      items: [{ product_id: mat.items[0].product_id, location_id: mat.items[0].location_id, actual_qty: 60 }],
    })
    expect(zero.code).toBe(0)
    const zeroList = await apiGet(page, '/api/v1/checks', { keyword: (zero.data as { no: string }).no })
    const zeroApprove = await apiPost(page, `/api/v1/checks/${zeroList.items[0].id}/approve`)
    expect(zeroApprove.code).toBe(0)
    expect((zeroApprove.data as { changed_items: number }).changed_items).toBe(0)
    // 无余额商品不可录盘 → 1205（RAW-001 铝材无库存）
    const raw = await apiGet(page, '/api/v1/products', { keyword: 'RAW-001', per_page: 100 })
    const rawId = raw.items[0].id as number
    const noBalance = await apiPost(page, '/api/v1/checks', {
      warehouse_id: 1,
      items: [{ product_id: rawId, location_id: 1, actual_qty: 1 }],
    })
    expect(noBalance.code).toBe(1205)
  })

  test('TC-MST-03 补测：仓库有库存不可删（1106）', async ({ page }) => {
    await loginByAPI(page, 'admin', 'admin123')
    // 基础资料仓库页：删除主仓（有基线库存）→ 1106 拒绝
    await page.goto('/master/warehouses')
    const whRow = page.locator('.el-table__row', { hasText: '主仓' }).first()
    await whRow.getByRole('button', { name: /删\s*除/ }).click()
    await page.locator('.el-message-box').getByRole('button', { name: /确\s*定/ }).click()
    await expect(page.locator('.el-message--error')).toContainText('仓库存在库存，不可删除')
    // 仓库仍在列表中
    await expect(page.locator('.el-table__row', { hasText: '主仓' }).first()).toBeVisible()
  })
})
```

- [ ] **Step 2: 跑 E2E 全量**

先确认 MySQL 容器运行中：`docker ps | grep php-design-mysql`（未运行则 `docker compose up -d`）。

Run: `cd web && npx playwright test e2e/inventory.spec.ts`
Expected: 13 个用例全部 PASS（webServer 自动 `migrate:fresh --seed` + serve :8000 + vite :5173）。

- [ ] **Step 3: 全量回归（CI 门禁本地模拟）**

```bash
# 后端：phpstan + phpcs + pint + phpunit
cd server && vendor/bin/phpstan analyse --no-progress --memory-limit=1G && vendor/bin/phpcs -q && vendor/bin/pint --test && php artisan test
# 前端：type-check + lint + lint:css + format:check + vitest
cd ../web && npm run type-check && npm run lint && npm run lint:css && npm run format:check && npm run test:unit
```
Expected: 全绿（PHPUnit 原 111 + 新增约 38 ≈ 149；Vitest 原 24 + 新增 6 = 30）。

- [ ] **Step 4: 全量 E2E 回归（既有 4 个 spec 不受影响）**

Run: `cd web && npx playwright test`
Expected: 全部 spec（auth/system/master/permission/inventory）通过。

- [ ] **Step 5: 回填 E2E 结果记录表**

修改 `docs/test/2026-08-12-库存管理模块端到测试.md` §5 表格：13 行用例结果全部填「通过」；TC-INV-03 步骤 3 注明「采购入库单号点击验证为『模块未实施』提示（采购模块实施后补验跳转）」。

- [ ] **Step 6: 提交**

```bash
cd /d/code/project/php-design && git add web/e2e/inventory.spec.ts docs/test/2026-08-12-库存管理模块端到端测试.md
git commit -m "test: 库存模块 E2E（TC-INV-01~12 + 1106 补测）全绿"
```

---

## 最终审查清单（全部 Task 完成后执行）

1. **全量门禁**：重复 Task 8 Step 3 的本地 CI 全绿；`git push origin main` 后云端 GitHub Actions 全绿（含 Playwright E2E）
2. **核心不变式抽查**：`php artisan test --filter="InventoryServiceTest|CheckTest"` 全过（100% 覆盖引擎与盘点审核）；确认恒等式（INV-04）在 E2E 中通过
3. **死代码检查**：`git status` 无多余文件；无未使用 import；无注释掉的代码块
4. **纪律检查**：未运行 pint/prettier 全仓格式化；提交粒度精确（无 `git add -A`）
5. **DeletionGuard 联动**：确认本模块 4 表落地后，基础资料 1106/1107/1116 删除保护在 E2E（TC-MST-03 补测）与 PHPUnit 中生效
6. **文档回填**：更新 `docs/progress/2026-08-12-基础资料模块实施.md`（或新进度文档）记录库存模块完成状态与提交号





