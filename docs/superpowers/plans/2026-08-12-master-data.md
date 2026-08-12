# 基础资料模块 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 实现基础资料模块（分类/单位/仓库库位/供应商/客户/工序/商品/BOM）的 Laravel 13 后端 API 与 Vue 3 前端页面，通过全部 PHPUnit/Vitest 测试与 E2E 测试文档 `docs/test/2026-08-12-基础资料模块端到端测试.md` 的 TC-MST-01~11。

**Architecture:** 前后端分离，复用系统管理模块全部基座（统一响应 `{code,message,data}`、`ApiResponse` trait、`permission:` 中间件、Sanctum 认证、前端 http/auth store/路由守卫/主布局）。后端 10 张新表 + 每资源一个控制器；删除保护统一走 `App\Support\DeletionGuard`（下游模块表未建时自动放行，建表后自动生效）。前端 8 个页面复用「工具栏 + el-table + el-pagination + el-dialog」模式，样式遵循 nexus-factory 设计系统（MASTER.md + 本计划 Task 7 落地的 `pages/master-data.md` 页覆盖）。

**Tech Stack:** PHP 8.5.9、Laravel 13.25.0、MySQL 8.4（Docker）、Vue 3 + TypeScript + Vite + Pinia + Vue Router + Element Plus、PHPUnit、Vitest、playwright-cli。

## Global Constraints

以下约束对每个 Task 隐式生效（来自主 spec 与基础资料细化 spec，逐条原文）：

- 统一响应：`{code, message, data}`；`code=0` 成功；错误码：1101 分类含子分类、1102 分类被商品使用、1103 单位编码重复、1104 单位被商品使用、1105 仓库编码重复、1106 仓库存在库存、1107 库位存在库存、1108 供应商编码重复、1109 供应商被采购单据使用、1110 客户编码重复、1111 客户被销售单据使用、1112 工序编码重复、1113 工序被工单使用、1114 商品编码重复、1115 条码重复、1116 商品被业务单据使用、1117 条码未匹配、1118 BOM 商品必须成品、1119 BOM 物料必须原料/半成品、1120 同成品启用版本唯一、1121 BOM 被工单使用、1122 安全库存下限>上限、1123 BOM 明细重复物料、1124 分类最多两级；422 仅用于格式校验（如库位编码重复），业务冲突一律走上述业务码
- API 前缀 `/api/v1`；权限中间件 `permission:{资源}.{动作}`；权限 code 命名 `{资源}.{动作}`（list/create/update/delete）；新权限追加到 `RbacSeeder` 权限数组（group=基础资料），admin 角色自动全量持有（`sync(Permission::pluck('id'))` 全量同步），operator 自动持有全部 `%.list`（like 查询）
- 分页统一 `{items,total,page,per_page}`；per_page 钳制 `max(1, min(100, (int) $request->input('per_page', 10)))`（与 UserController 一致）
- 金额/数量一律 decimal(12,2)；本模块无金额；safety_min/safety_max 默认 0（0=不预警该侧），min>max 拒绝（1122）
- 商品类型枚举 raw_material/semi_finished/finished；BOM 头关联成品（type=finished），明细物料仅原料/半成品；同一成品仅一个启用版本（启用时自动停用其他版本）
- 删除保护：商品被 BOM 明细/头、库存流水、采购/销售明细、生产工单引用不可删（1116）；仓库/库位被 `inventory_balances` 引用不可删（1106/1107）；供应商被 `purchase_orders` 引用（1109）；客户被 `sales_orders` 引用（1111）；工序被 `work_order_operations` 引用（1113）；BOM 被 `production_orders.bom_id` 引用（1121）——**除 BOM 明细/头（本模块表）外，其余均通过 `DeletionGuard` 检查，表未建时返回 false 自动放行，下游模块迁移落地后自动生效**
- BOM 单号规则：`BOM{yyyyMMdd}-{3位流水}`（如 BOM20260812-001），流水=当日已有单号数+1
- 扫码交互：商品条码输入框自动聚焦，扫枪回车即时校验 `GET /api/v1/products/barcode/{barcode}`（未匹配 1117，不清空输入便于重扫）
- 中文注释（类级/方法级/关键行）；UTF-8 无 BOM；LF 行尾（.gitattributes 已强制）；无死代码
- 核心路径（认证、权限校验、删除保护、启用版本唯一）单元测试 100% 覆盖；测试命名表达业务意图，覆盖正常/边界/异常
- 前端：侧边栏深色 `#0F172A`（220px），内容区 `#F8FAFC`；主色 `#334155`、强调绿 `#059669`、危险 `#DC2626`；Fira Code + Fira Sans；所有可点击元素 `cursor:pointer`；过渡 150-300ms；按钮文案「新 建/保 存/编 辑/删 除/明 细/库 位/启 用/停 用」（带全角空格，与系统管理模块一致，E2E 按文案定位）
- 类型标签语义色（spec §5.1）：原料蓝 `#3B82F6` / 半成品琥珀 `#D97706` / 成品绿 `#059669`；BOM 状态标签：启用绿/停用灰；状态标签：启用绿/停用灰（沿用系统管理模块）
- 端口：后端 `http://localhost:8000`、前端 `http://localhost:5173`、MySQL `3306`
- 本机命令路径：`php`=`D:\code\envs\php\8.5.9\php.exe`（已入 PATH）、`composer`=`D:\code\envs\composer\2.10.2\composer.phar`、Python=`D:\code\envs\python\3.14.6\python.exe`

---

## Task 1: 数据模型与种子（迁移 10 表 + 模型 + DeletionGuard + 种子）

**Files:**
- Create: `server/database/migrations/2026_08_12_080000_create_master_data_tables.php`
- Create: `server/app/Models/{Category,Unit,Warehouse,Location,Supplier,Customer,Process,Product,BomHeader,BomItem}.php`
- Create: `server/app/Support/DeletionGuard.php`
- Create: `server/database/seeders/MasterDataSeeder.php`
- Create: `server/tests/Feature/MasterDataStructureTest.php`
- Modify: `server/database/seeders/RbacSeeder.php`（权限数组追加 32 项基础资料权限）、`server/database/seeders/DatabaseSeeder.php`（注册 MasterDataSeeder）

**Interfaces:**
- Consumes: 系统管理模块的 users/roles/permissions 表与 RbacSeeder 结构
- Produces: 10 张表（字段见下）；10 个模型（fillable 与关系见下）；`DeletionGuard::referenced(string $table, string $column, int $id): bool`（Task 3-6 删除保护统一调用）；种子数据：分类「原材料/成品」、单位「个/件/千克」、仓库「主仓 WH01」、商品「RAW-001 铝材(原料)/FIN-001 成品A(成品)」、工序「下料 PROC-01」；权限 32 项（8 资源 × 4 动作，group=基础资料）

- [ ] **Step 1: 写失败测试 `server/tests/Feature/MasterDataStructureTest.php`**

```php
<?php
// 基础资料数据模型测试：表结构、种子完整性、引用保护守卫（核心数据结构，100% 覆盖）
namespace Tests\Feature;

use App\Models\Category;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\Unit;
use App\Support\DeletionGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MasterDataStructureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // 种子：RBAC（权限/角色/admin 用户）+ 基础资料主数据
        $this->seed();
    }

    public function test_all_master_data_tables_exist(): void
    {
        // 正常路径：10 张基础资料表全部建立
        foreach (['categories', 'units', 'warehouses', 'locations', 'suppliers', 'customers', 'processes', 'products', 'bom_headers', 'bom_items'] as $table) {
            $this->assertTrue(Schema::hasTable($table), "表 {$table} 不存在");
        }
    }

    public function test_master_data_seed_creates_base_data(): void
    {
        // 正常路径：种子含分类/单位/仓库/商品/工序
        $this->assertDatabaseHas('categories', ['name' => '原材料']);
        $this->assertDatabaseHas('units', ['code' => 'pc']);
        $this->assertDatabaseHas('warehouses', ['code' => 'WH01']);
        $this->assertDatabaseHas('products', ['code' => 'RAW-001', 'type' => 'raw_material']);
        $this->assertDatabaseHas('processes', ['code' => 'PROC-01']);
    }

    public function test_master_permissions_seeded_for_admin(): void
    {
        // 正常路径：基础资料 32 个权限已注册且 admin 角色全量持有
        $this->assertSame(32, Permission::where('group', '基础资料')->count());
        $admin = Role::where('code', 'admin')->first();
        $this->assertSame(2, $admin->permissions()->whereIn('code', ['product.list', 'bom.delete'])->count());
    }

    public function test_deletion_guard_returns_false_for_missing_table(): void
    {
        // 边界路径：下游模块表未建时守卫返回 false（不阻止删除）
        $this->assertFalse(DeletionGuard::referenced('inventory_balances', 'warehouse_id', 1));
    }

    public function test_deletion_guard_detects_reference_in_existing_table(): void
    {
        // 正常路径：已有表存在引用时守卫返回 true（临时表验证守卫逻辑本身）
        Schema::create('guard_test_tmp', function ($table) {
            $table->id();
            $table->unsignedBigInteger('ref_id');
        });
        DB::table('guard_test_tmp')->insert(['ref_id' => 7]);
        try {
            $this->assertTrue(DeletionGuard::referenced('guard_test_tmp', 'ref_id', 7));
            $this->assertFalse(DeletionGuard::referenced('guard_test_tmp', 'ref_id', 8));
        } finally {
            Schema::dropIfExists('guard_test_tmp');
        }
    }
}
```

- [ ] **Step 2: 跑测试确认失败**

Run: `cd server && php artisan test --filter=MasterDataStructureTest`
Expected: FAIL（表/模型/种子不存在）。

- [ ] **Step 3: 创建迁移**

创建 `server/database/migrations/2026_08_12_080000_create_master_data_tables.php`：

```php
<?php
// 基础资料核心表：分类/单位/仓库/库位/供应商/客户/工序/商品/BOM（头+明细）
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 50)->comment('分类名称');
            $table->unsignedBigInteger('parent_id')->default(0)->comment('上级分类ID，0=顶级');
            $table->integer('sort')->default(0)->comment('排序');
            $table->tinyInteger('status')->default(1)->comment('1启用 0停用');
            $table->timestamps();
        });

        Schema::create('units', function (Blueprint $table) {
            $table->id();
            $table->string('name', 50)->comment('单位名称，如 个');
            $table->string('code', 20)->unique()->comment('单位编码，如 pc');
            $table->tinyInteger('status')->default(1)->comment('1启用 0停用');
            $table->timestamps();
        });

        Schema::create('warehouses', function (Blueprint $table) {
            $table->id();
            $table->string('name', 50)->comment('仓库名称');
            $table->string('code', 20)->unique()->comment('仓库编码，如 WH01');
            $table->string('address')->nullable()->comment('仓库地址');
            $table->string('manager', 50)->nullable()->comment('负责人');
            $table->tinyInteger('status')->default(1)->comment('1启用 0停用');
            $table->timestamps();
        });

        Schema::create('locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete()->comment('所属仓库');
            $table->string('name', 50)->comment('库位名称，如 A-01');
            $table->string('code', 50)->unique()->comment('库位编码，全局唯一');
            $table->tinyInteger('status')->default(1)->comment('1启用 0停用');
            $table->timestamps();
        });

        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->comment('供应商名称');
            $table->string('code', 50)->unique()->comment('供应商编码');
            $table->string('contact', 50)->nullable()->comment('联系人');
            $table->string('phone', 30)->nullable()->comment('联系电话');
            $table->string('address')->nullable()->comment('地址');
            $table->string('remark')->nullable()->comment('备注');
            $table->tinyInteger('status')->default(1)->comment('1启用 0停用');
            $table->timestamps();
        });

        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->comment('客户名称');
            $table->string('code', 50)->unique()->comment('客户编码');
            $table->string('contact', 50)->nullable()->comment('联系人');
            $table->string('phone', 30)->nullable()->comment('联系电话');
            $table->string('address')->nullable()->comment('地址');
            $table->string('remark')->nullable()->comment('备注');
            $table->tinyInteger('status')->default(1)->comment('1启用 0停用');
            $table->timestamps();
        });

        Schema::create('processes', function (Blueprint $table) {
            $table->id();
            $table->string('name', 50)->comment('工序名称，如 车削');
            $table->string('code', 50)->unique()->comment('工序编码');
            $table->integer('sort')->default(0)->comment('排序，生产模块下拉按此升序');
            $table->string('description')->nullable()->comment('工序说明');
            $table->tinyInteger('status')->default(1)->comment('1启用 0停用');
            $table->timestamps();
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->comment('商品名称');
            $table->string('code', 50)->unique()->comment('商品编码，支持扫码');
            $table->enum('type', ['raw_material', 'semi_finished', 'finished'])->comment('类型：原料/半成品/成品');
            $table->foreignId('category_id')->constrained('categories')->restrictOnDelete()->comment('所属分类');
            $table->foreignId('unit_id')->constrained('units')->restrictOnDelete()->comment('计量单位');
            $table->string('spec', 100)->nullable()->comment('规格');
            $table->string('barcode', 50)->nullable()->unique()->comment('条码，可空，扫枪输入');
            $table->decimal('safety_min', 12, 2)->default(0)->comment('安全库存下限，0=不预警该侧');
            $table->decimal('safety_max', 12, 2)->default(0)->comment('安全库存上限，0=不预警该侧');
            $table->tinyInteger('status')->default(1)->comment('1启用 0停用');
            $table->string('remark')->nullable()->comment('备注');
            $table->timestamps();
        });

        Schema::create('bom_headers', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique()->comment('BOM 编码，如 BOM20260812-001');
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete()->comment('成品商品');
            $table->string('version', 20)->comment('版本号，如 v1');
            $table->decimal('quantity', 12, 2)->default(1)->comment('基准产出数量，默认1');
            $table->tinyInteger('status')->default(1)->comment('1启用 0停用；同成品启用版本唯一');
            $table->string('remark')->nullable()->comment('备注');
            $table->timestamps();
        });

        Schema::create('bom_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bom_header_id')->constrained()->cascadeOnDelete()->comment('所属 BOM 头');
            $table->foreignId('material_id')->constrained('products')->restrictOnDelete()->comment('物料商品（原料/半成品）');
            $table->decimal('quantity', 12, 2)->comment('单位产品用量，必须 >0');
            $table->foreignId('unit_id')->constrained('units')->restrictOnDelete()->comment('用量单位');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bom_items');
        Schema::dropIfExists('bom_headers');
        Schema::dropIfExists('products');
        Schema::dropIfExists('processes');
        Schema::dropIfExists('customers');
        Schema::dropIfExists('suppliers');
        Schema::dropIfExists('locations');
        Schema::dropIfExists('warehouses');
        Schema::dropIfExists('units');
        Schema::dropIfExists('categories');
    }
};
```

Run: `cd server && php artisan migrate`
Expected: 10 张表创建成功。

- [ ] **Step 4: 创建 10 个模型**

`server/app/Models/Category.php`（含自关联）:

```php
<?php
// 商品分类模型：两级树形（parent_id 自关联，0=顶级）
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    protected $fillable = ['name', 'parent_id', 'sort', 'status'];

    protected function casts(): array
    {
        return ['parent_id' => 'integer', 'sort' => 'integer', 'status' => 'integer'];
    }

    // 上级分类（顶级分类的 parent 为 null）
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    // 直接子分类
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }
}
```

`server/app/Models/Unit.php`:

```php
<?php
// 计量单位模型：商品/BOM 明细共用
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Unit extends Model
{
    protected $fillable = ['name', 'code', 'status'];

    protected function casts(): array
    {
        return ['status' => 'integer'];
    }
}
```

`server/app/Models/Warehouse.php`:

```php
<?php
// 仓库模型：库位挂载点，库存模块余额按仓库聚合
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Warehouse extends Model
{
    protected $fillable = ['name', 'code', 'address', 'manager', 'status'];

    protected function casts(): array
    {
        return ['status' => 'integer'];
    }

    // 仓库下库位
    public function locations(): HasMany
    {
        return $this->hasMany(Location::class);
    }
}
```

`server/app/Models/Location.php`:

```php
<?php
// 库位模型：挂载在仓库下，编码全局唯一
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Location extends Model
{
    protected $fillable = ['warehouse_id', 'name', 'code', 'status'];

    protected function casts(): array
    {
        return ['status' => 'integer'];
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }
}
```

`server/app/Models/Supplier.php` / `server/app/Models/Customer.php`（同构，fillable 为 `name/code/contact/phone/address/remark/status`，status 转 integer）:

```php
<?php
// 供应商模型：采购模块引用；被采购单据引用时不可删除
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Supplier extends Model
{
    protected $fillable = ['name', 'code', 'contact', 'phone', 'address', 'remark', 'status'];

    protected function casts(): array
    {
        return ['status' => 'integer'];
    }
}
```

`server/app/Models/Process.php`:

```php
<?php
// 生产工序模型：sort 决定生产模块工序序列
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Process extends Model
{
    protected $fillable = ['name', 'code', 'sort', 'description', 'status'];

    protected function casts(): array
    {
        return ['sort' => 'integer', 'status' => 'integer'];
    }
}
```

`server/app/Models/Product.php`:

```php
<?php
// 商品模型：原料/半成品/成品，安全库存上下限为库存预警数据源
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Product extends Model
{
    protected $fillable = ['name', 'code', 'type', 'category_id', 'unit_id', 'spec', 'barcode', 'safety_min', 'safety_max', 'status', 'remark'];

    protected function casts(): array
    {
        return [
            'safety_min' => 'decimal:2',
            'safety_max' => 'decimal:2',
            'status' => 'integer',
        ];
    }

    // 所属分类
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    // 计量单位
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }
}
```

`server/app/Models/BomHeader.php`:

```php
<?php
// BOM 头模型：关联成品，同成品启用版本唯一
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BomHeader extends Model
{
    protected $fillable = ['code', 'product_id', 'version', 'quantity', 'status', 'remark'];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'status' => 'integer',
        ];
    }

    // BOM 关联的成品商品
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    // 明细行（随头级联删除）
    public function items(): HasMany
    {
        return $this->hasMany(BomItem::class);
    }
}
```

`server/app/Models/BomItem.php`:

```php
<?php
// BOM 明细模型：物料（原料/半成品）+ 用量
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BomItem extends Model
{
    protected $fillable = ['bom_header_id', 'material_id', 'quantity', 'unit_id'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:2'];
    }

    // 物料商品（原料/半成品）
    public function material(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'material_id');
    }

    // 用量单位
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }
}
```

- [ ] **Step 5: 创建 DeletionGuard**

创建 `server/app/Support/DeletionGuard.php`：

```php
<?php
// 引用保护守卫：主数据删除前检查是否被业务单据引用
namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DeletionGuard
{
    /**
     * 检查指定业务表是否已引用目标主数据
     *
     * 表可能由后续模块（库存/采购/销售/生产）创建；表未创建时返回 false，
     * 下游模块迁移落地后本保护自动生效，无需回改本模块代码。
     */
    public static function referenced(string $table, string $column, int $id): bool
    {
        if (! Schema::hasTable($table)) {
            return false;
        }
        return DB::table($table)->where($column, $id)->exists();
    }
}
```

- [ ] **Step 6: 追加权限种子与主数据种子**

修改 `server/database/seeders/RbacSeeder.php`：在 `$permissions` 数组末尾追加（8 资源 × 4 动作，group=基础资料）：

```php
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
```

创建 `server/database/seeders/MasterDataSeeder.php`：

```php
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
```

修改 `server/database/seeders/DatabaseSeeder.php`：`$this->call([RbacSeeder::class, MasterDataSeeder::class]);`（顶部注释同步补充「基础资料主数据」）。

Run: `cd server && php artisan migrate:fresh --seed`
Expected: 种子成功（admin/admin123 可登录，基础资料 5 类数据就位）。

- [ ] **Step 7: 跑测试确认通过**

Run: `cd server && php artisan test --filter=MasterDataStructureTest`
Expected: 5 个测试全部 PASS。

- [ ] **Step 8: 提交**

```bash
cd /d/code/project/php-design && git add -A && git commit -m "feat: 基础资料数据模型/迁移/种子/引用保护守卫"
```

---
## Task 2: 简单资源 API（分类/单位/工序）

**Files:**
- Create: `server/app/Http/Controllers/Api/{CategoryController,UnitController,ProcessController}.php`
- Create: `server/tests/Feature/{CategoryTest,UnitTest,ProcessTest}.php`
- Modify: `server/routes/api.php`

**Interfaces:**
- Consumes: Task 1 模型与权限种子；`ApiResponse` trait；`DeletionGuard`
- Produces: `GET /api/v1/categories`（树形 `data:[{id,name,parent_id,sort,status,children:[...]}]`）；分类 POST/PUT/DELETE（1101/1102/1124）；`GET/POST/PUT/DELETE /api/v1/units`（1103/1104）；`GET/POST/PUT/DELETE /api/v1/processes`（列表 sort 升序全量返回 `data:{items:[...]}`；1112/1113）；全部挂在 `permission:category.*`/`permission:unit.*`/`permission:process.*`

- [ ] **Step 1: 写失败测试**

创建 `server/tests/Feature/CategoryTest.php`：

```php
<?php
// 分类接口测试：树形/两级限制/删除保护（正常+边界+异常）
namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\Role;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CategoryTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        // admin 用户挂 admin 角色（权限中间件放行）
        $role = Role::create(['name' => '管理员', 'code' => 'admin']);
        $u = User::create(['name' => '管理员', 'username' => 'admin', 'password' => 'admin123', 'status' => 1]);
        $u->roles()->sync([$role->id]);
        $this->token = $u->createToken('api')->plainTextToken;
    }

    public function test_index_returns_tree_with_children(): void
    {
        // 正常路径：顶级分类含 children 子树
        $parent = Category::create(['name' => '原材料', 'parent_id' => 0, 'sort' => 1]);
        Category::create(['name' => '金属', 'parent_id' => $parent->id, 'sort' => 1]);
        $this->withToken($this->token)->getJson('/api/v1/categories')
            ->assertJsonPath('code', 0)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', '原材料')
            ->assertJsonPath('data.0.children.0.name', '金属');
    }

    public function test_store_creates_top_level_category(): void
    {
        // 正常路径：顶级分类创建
        $this->withToken($this->token)->postJson('/api/v1/categories', ['name' => '包装', 'parent_id' => 0, 'sort' => 3])
            ->assertJsonPath('code', 0);
        $this->assertDatabaseHas('categories', ['name' => '包装', 'parent_id' => 0]);
    }

    public function test_store_rejects_third_level_with_1124(): void
    {
        // 异常路径：父级本身是子分类 → 第三级被拒 1124
        $parent = Category::create(['name' => '原材料', 'parent_id' => 0, 'sort' => 1]);
        $child = Category::create(['name' => '金属', 'parent_id' => $parent->id, 'sort' => 1]);
        $this->withToken($this->token)->postJson('/api/v1/categories', ['name' => '不锈钢', 'parent_id' => $child->id])
            ->assertJsonPath('code', 1124);
    }

    public function test_update_moves_category_under_top_level(): void
    {
        // 正常路径：更新名称与上级
        $a = Category::create(['name' => 'A', 'parent_id' => 0]);
        $b = Category::create(['name' => 'B', 'parent_id' => 0]);
        $this->withToken($this->token)->putJson("/api/v1/categories/{$b->id}", ['name' => 'B2', 'parent_id' => $a->id, 'sort' => 2])
            ->assertJsonPath('code', 0);
        $this->assertDatabaseHas('categories', ['id' => $b->id, 'name' => 'B2', 'parent_id' => $a->id]);
    }

    public function test_destroy_with_children_fails_with_1101(): void
    {
        // 异常路径：含子分类不可删
        $parent = Category::create(['name' => '原材料', 'parent_id' => 0]);
        Category::create(['name' => '金属', 'parent_id' => $parent->id]);
        $this->withToken($this->token)->deleteJson("/api/v1/categories/{$parent->id}")
            ->assertJsonPath('code', 1101);
        $this->assertDatabaseHas('categories', ['id' => $parent->id]);
    }

    public function test_destroy_referenced_by_product_fails_with_1102(): void
    {
        // 异常路径：被商品引用不可删
        $cat = Category::create(['name' => '成品', 'parent_id' => 0]);
        $unit = Unit::create(['name' => '个', 'code' => 'pc']);
        Product::create(['name' => '成品A', 'code' => 'FIN-001', 'type' => 'finished', 'category_id' => $cat->id, 'unit_id' => $unit->id]);
        $this->withToken($this->token)->deleteJson("/api/v1/categories/{$cat->id}")
            ->assertJsonPath('code', 1102);
        $this->assertDatabaseHas('categories', ['id' => $cat->id]);
    }

    public function test_destroy_empty_category_succeeds(): void
    {
        // 正常路径：无子分类无引用的分类可删
        $cat = Category::create(['name' => '临时', 'parent_id' => 0]);
        $this->withToken($this->token)->deleteJson("/api/v1/categories/{$cat->id}")->assertJsonPath('code', 0);
        $this->assertDatabaseMissing('categories', ['id' => $cat->id]);
    }
}
```

创建 `server/tests/Feature/UnitTest.php`：

```php
<?php
// 单位接口测试：CRUD/编码唯一/被引用保护（正常+边界+异常）
namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\Role;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UnitTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $role = Role::create(['name' => '管理员', 'code' => 'admin']);
        $u = User::create(['name' => '管理员', 'username' => 'admin', 'password' => 'admin123', 'status' => 1]);
        $u->roles()->sync([$role->id]);
        $this->token = $u->createToken('api')->plainTextToken;
    }

    public function test_index_returns_paginated_units(): void
    {
        // 正常路径：分页结构完整
        $this->withToken($this->token)->getJson('/api/v1/units')
            ->assertJsonPath('code', 0)
            ->assertJsonStructure(['data' => ['items', 'total', 'page', 'per_page']]);
    }

    public function test_store_and_duplicate_code_fails_with_1103(): void
    {
        // 正常路径：创建成功
        $this->withToken($this->token)->postJson('/api/v1/units', ['name' => '个', 'code' => 'pc', 'status' => 1])
            ->assertJsonPath('code', 0);
        // 异常路径：重复编码 1103
        $this->withToken($this->token)->postJson('/api/v1/units', ['name' => '重复', 'code' => 'pc'])
            ->assertJsonPath('code', 1103);
    }

    public function test_update_renames_unit(): void
    {
        // 正常路径：更新名称
        $unit = Unit::create(['name' => '个', 'code' => 'pc']);
        $this->withToken($this->token)->putJson("/api/v1/units/{$unit->id}", ['name' => '箱', 'code' => 'pc', 'status' => 1])
            ->assertJsonPath('code', 0);
        $this->assertDatabaseHas('units', ['id' => $unit->id, 'name' => '箱']);
    }

    public function test_destroy_referenced_by_product_fails_with_1104(): void
    {
        // 异常路径：被商品引用不可删
        $unit = Unit::create(['name' => '个', 'code' => 'pc']);
        $cat = Category::create(['name' => '成品', 'parent_id' => 0]);
        Product::create(['name' => '成品A', 'code' => 'FIN-001', 'type' => 'finished', 'category_id' => $cat->id, 'unit_id' => $unit->id]);
        $this->withToken($this->token)->deleteJson("/api/v1/units/{$unit->id}")
            ->assertJsonPath('code', 1104);
    }

    public function test_destroy_unreferenced_unit_succeeds(): void
    {
        // 正常路径：未被引用的单位可删
        $unit = Unit::create(['name' => '临时', 'code' => 'tmp']);
        $this->withToken($this->token)->deleteJson("/api/v1/units/{$unit->id}")->assertJsonPath('code', 0);
        $this->assertDatabaseMissing('units', ['id' => $unit->id]);
    }
}
```

创建 `server/tests/Feature/ProcessTest.php`：

```php
<?php
// 工序接口测试：CRUD/编码唯一/排序（正常+边界+异常）
namespace Tests\Feature;

use App\Models\Process;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProcessTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $role = Role::create(['name' => '管理员', 'code' => 'admin']);
        $u = User::create(['name' => '管理员', 'username' => 'admin', 'password' => 'admin123', 'status' => 1]);
        $u->roles()->sync([$role->id]);
        $this->token = $u->createToken('api')->plainTextToken;
    }

    public function test_index_returns_sorted_by_sort_asc(): void
    {
        // 正常路径：列表按 sort 升序（生产模块下拉顺序）
        Process::create(['name' => '打磨', 'code' => 'P2', 'sort' => 2, 'status' => 1]);
        Process::create(['name' => '下料', 'code' => 'P1', 'sort' => 1, 'status' => 1]);
        $this->withToken($this->token)->getJson('/api/v1/processes')
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.items.0.name', '下料')
            ->assertJsonPath('data.items.1.name', '打磨');
    }

    public function test_store_and_duplicate_code_fails_with_1112(): void
    {
        // 正常路径：创建成功
        $this->withToken($this->token)->postJson('/api/v1/processes', ['name' => '车削', 'code' => 'PROC-02', 'sort' => 2, 'description' => ''])
            ->assertJsonPath('code', 0);
        // 异常路径：重复编码 1112
        $this->withToken($this->token)->postJson('/api/v1/processes', ['name' => '重复', 'code' => 'PROC-02'])
            ->assertJsonPath('code', 1112);
    }

    public function test_update_changes_sort(): void
    {
        // 正常路径：更新排序生效
        $p = Process::create(['name' => '测试工序', 'code' => 'PROC-99', 'sort' => 99, 'status' => 1]);
        $this->withToken($this->token)->putJson("/api/v1/processes/{$p->id}", ['name' => '测试工序', 'code' => 'PROC-99', 'sort' => 1, 'status' => 1])
            ->assertJsonPath('code', 0);
        $this->assertDatabaseHas('processes', ['id' => $p->id, 'sort' => 1]);
    }

    public function test_destroy_succeeds_when_work_orders_table_missing(): void
    {
        // 边界路径：生产模块表未建（守卫放行），工序可删
        $p = Process::create(['name' => '测试工序', 'code' => 'PROC-99', 'sort' => 99, 'status' => 1]);
        $this->withToken($this->token)->deleteJson("/api/v1/processes/{$p->id}")->assertJsonPath('code', 0);
        $this->assertDatabaseMissing('processes', ['id' => $p->id]);
    }
}
```

- [ ] **Step 2: 跑测试确认失败**

Run: `cd server && php artisan test --filter="CategoryTest|UnitTest|ProcessTest"`
Expected: FAIL（路由/控制器不存在）。

- [ ] **Step 3: 实现三个控制器**

创建 `server/app/Http/Controllers/Api/CategoryController.php`：

```php
<?php
// 商品分类控制器：树形列表 + CRUD + 删除保护（子分类/被商品引用）
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    use ApiResponse;

    /** 树形列表：顶级分类 + 各自 children（全部层级，管理页直接渲染） */
    public function index()
    {
        $all = Category::orderBy('sort')->orderBy('id')->get();
        // 递归组装子树（仅顶级作为根）
        $tree = $all->where('parent_id', 0)->values()->map(fn ($c) => $this->withChildren($c, $all))->values();
        return $this->ok($tree);
    }

    // 组装节点与子孙（children 为空时不输出该键，保持结构精简）
    private function withChildren(Category $category, $all): array
    {
        $node = ['id' => $category->id, 'name' => $category->name, 'parent_id' => $category->parent_id, 'sort' => $category->sort, 'status' => $category->status];
        $children = $all->where('parent_id', $category->id)->values()->map(fn ($c) => $this->withChildren($c, $all))->values();
        if ($children->isNotEmpty()) {
            $node['children'] = $children;
        }
        return $node;
    }

    /** 新建分类：最多两级（parent 必须是顶级或空，否则 1124） */
    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:50',
            'parent_id' => 'nullable|integer|min:0',
            'sort' => 'nullable|integer',
            'status' => 'nullable|in:0,1',
        ]);

        $parentId = (int) ($data['parent_id'] ?? 0);
        // 父级存在性 + 两级限制：父级必须是顶级分类
        if ($parentId > 0) {
            $parent = Category::find($parentId);
            if (! $parent || $parent->parent_id !== 0) {
                return $this->fail(1124, '分类最多支持两级');
            }
        }

        $category = Category::create([
            'name' => $data['name'], 'parent_id' => $parentId,
            'sort' => $data['sort'] ?? 0, 'status' => $data['status'] ?? 1,
        ]);
        return $this->ok(['id' => $category->id]);
    }

    /** 更新分类：同名两级限制 + 防移动到自己子级下（防环） */
    public function update(Request $request, Category $category)
    {
        $data = $request->validate([
            'name' => 'required|string|max:50',
            'parent_id' => 'nullable|integer|min:0',
            'sort' => 'nullable|integer',
            'status' => 'nullable|in:0,1',
        ]);

        $parentId = (int) ($data['parent_id'] ?? 0);
        if ($parentId > 0) {
            // 防环：不能挂到自身或自身子分类下
            $hasSelfDescendant = Category::where('parent_id', $category->id)->where('id', $parentId)->exists();
            if ($parentId === $category->id || $hasSelfDescendant) {
                return $this->fail(1124, '不能将分类移动到自身或子分类下');
            }
            $parent = Category::find($parentId);
            if (! $parent || $parent->parent_id !== 0) {
                return $this->fail(1124, '分类最多支持两级');
            }
        }

        $category->update([
            'name' => $data['name'], 'parent_id' => $parentId,
            'sort' => $data['sort'] ?? $category->sort, 'status' => $data['status'] ?? $category->status,
        ]);
        return $this->ok();
    }

    /** 删除分类：含子分类 1101；被商品引用 1102 */
    public function destroy(Category $category)
    {
        if (Category::where('parent_id', $category->id)->exists()) {
            return $this->fail(1101, '存在子分类，不可删除');
        }
        if (Product::where('category_id', $category->id)->exists()) {
            return $this->fail(1102, '分类已被商品使用，不可删除');
        }
        $category->delete();
        return $this->ok();
    }
}
```

创建 `server/app/Http/Controllers/Api/UnitController.php`：

```php
<?php
// 计量单位控制器：CRUD + 编码唯一 + 被商品引用保护
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Unit;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class UnitController extends Controller
{
    use ApiResponse;

    /** 分页列表（per_page 钳制 1-100） */
    public function index(Request $request)
    {
        $units = Unit::orderByDesc('id')->paginate(max(1, min(100, (int) $request->input('per_page', 10))));
        return $this->ok([
            'items' => $units->map(fn ($u) => ['id' => $u->id, 'name' => $u->name, 'code' => $u->code, 'status' => $u->status]),
            'total' => $units->total(), 'page' => $units->currentPage(), 'per_page' => $units->perPage(),
        ]);
    }

    /** 新建单位：编码重复 1103 */
    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:50',
            'code' => 'required|string|max:20',
            'status' => 'nullable|in:0,1',
        ]);
        if (Unit::where('code', $data['code'])->exists()) {
            return $this->fail(1103, '单位编码已存在');
        }
        $unit = Unit::create(['name' => $data['name'], 'code' => $data['code'], 'status' => $data['status'] ?? 1]);
        return $this->ok(['id' => $unit->id]);
    }

    /** 更新单位：编码唯一（排除自身） */
    public function update(Request $request, Unit $unit)
    {
        $data = $request->validate([
            'name' => 'required|string|max:50',
            'code' => 'required|string|max:20',
            'status' => 'nullable|in:0,1',
        ]);
        if (Unit::where('code', $data['code'])->where('id', '!=', $unit->id)->exists()) {
            return $this->fail(1103, '单位编码已存在');
        }
        $unit->update(['name' => $data['name'], 'code' => $data['code'], 'status' => $data['status'] ?? $unit->status]);
        return $this->ok();
    }

    /** 删除单位：被商品引用 1104 */
    public function destroy(Unit $unit)
    {
        if (Product::where('unit_id', $unit->id)->exists()) {
            return $this->fail(1104, '单位已被商品使用，不可删除');
        }
        $unit->delete();
        return $this->ok();
    }
}
```

创建 `server/app/Http/Controllers/Api/ProcessController.php`：

```php
<?php
// 工序控制器：列表（sort 升序）+ CRUD + 编码唯一 + 被工单引用保护
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Process;
use App\Support\ApiResponse;
use App\Support\DeletionGuard;
use Illuminate\Http\Request;

class ProcessController extends Controller
{
    use ApiResponse;

    /** 列表：sort 升序全量返回（生产模块工序下拉数据源） */
    public function index()
    {
        $items = Process::orderBy('sort')->orderBy('id')->get()
            ->map(fn ($p) => ['id' => $p->id, 'name' => $p->name, 'code' => $p->code, 'sort' => $p->sort, 'description' => $p->description, 'status' => $p->status]);
        return $this->ok(['items' => $items]);
    }

    /** 新建工序：编码重复 1112 */
    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:50',
            'code' => 'required|string|max:50',
            'sort' => 'nullable|integer',
            'description' => 'nullable|string',
            'status' => 'nullable|in:0,1',
        ]);
        if (Process::where('code', $data['code'])->exists()) {
            return $this->fail(1112, '工序编码已存在');
        }
        $process = Process::create([
            'name' => $data['name'], 'code' => $data['code'],
            'sort' => $data['sort'] ?? 0, 'description' => $data['description'] ?? null, 'status' => $data['status'] ?? 1,
        ]);
        return $this->ok(['id' => $process->id]);
    }

    /** 更新工序 */
    public function update(Request $request, Process $process)
    {
        $data = $request->validate([
            'name' => 'required|string|max:50',
            'code' => 'required|string|max:50',
            'sort' => 'nullable|integer',
            'description' => 'nullable|string',
            'status' => 'nullable|in:0,1',
        ]);
        if (Process::where('code', $data['code'])->where('id', '!=', $process->id)->exists()) {
            return $this->fail(1112, '工序编码已存在');
        }
        $process->update([
            'name' => $data['name'], 'code' => $data['code'],
            'sort' => $data['sort'] ?? $process->sort, 'description' => $data['description'] ?? $process->description,
            'status' => $data['status'] ?? $process->status,
        ]);
        return $this->ok();
    }

    /** 删除工序：被生产工单引用 1113（工单表由生产模块创建，未建时守卫自动放行） */
    public function destroy(Process $process)
    {
        if (DeletionGuard::referenced('work_order_operations', 'process_id', $process->id)) {
            return $this->fail(1113, '工序已被生产工单使用，不可删除');
        }
        $process->delete();
        return $this->ok();
    }
}
```

- [ ] **Step 4: 注册路由**

修改 `server/routes/api.php`（顶部 use 追加三个控制器；`auth:sanctum` 组内追加）：

```php
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\ProcessController;
use App\Http\Controllers\Api\UnitController;

    // 分类：树形列表 + CRUD（category.list/create/update/delete）
    Route::middleware('auth:sanctum')->group(function () {
        Route::middleware('permission:category.list')->get('/categories', [CategoryController::class, 'index']);
        Route::middleware('permission:category.create')->post('/categories', [CategoryController::class, 'store']);
        Route::middleware('permission:category.update')->put('/categories/{category}', [CategoryController::class, 'update']);
        Route::middleware('permission:category.delete')->delete('/categories/{category}', [CategoryController::class, 'destroy']);
    });

    // 单位：CRUD（unit.list/create/update/delete）
    Route::middleware('auth:sanctum')->group(function () {
        Route::middleware('permission:unit.list')->get('/units', [UnitController::class, 'index']);
        Route::middleware('permission:unit.create')->post('/units', [UnitController::class, 'store']);
        Route::middleware('permission:unit.update')->put('/units/{unit}', [UnitController::class, 'update']);
        Route::middleware('permission:unit.delete')->delete('/units/{unit}', [UnitController::class, 'destroy']);
    });

    // 工序：列表 + CRUD（process.list/create/update/delete）
    Route::middleware('auth:sanctum')->group(function () {
        Route::middleware('permission:process.list')->get('/processes', [ProcessController::class, 'index']);
        Route::middleware('permission:process.create')->post('/processes', [ProcessController::class, 'store']);
        Route::middleware('permission:process.update')->put('/processes/{process}', [ProcessController::class, 'update']);
        Route::middleware('permission:process.delete')->delete('/processes/{process}', [ProcessController::class, 'destroy']);
    });
```

- [ ] **Step 5: 跑测试确认通过**

Run: `cd server && php artisan test --filter="CategoryTest|UnitTest|ProcessTest"`
Expected: 全部 PASS（CategoryTest 7 + UnitTest 5 + ProcessTest 4 = 16）。

- [ ] **Step 6: 提交**

```bash
cd /d/code/project/php-design && git add -A && git commit -m "feat: 分类/单位/工序 API"
```

---

## Task 3: 仓库/库位 API

**Files:**
- Create: `server/app/Http/Controllers/Api/WarehouseController.php`
- Create: `server/tests/Feature/WarehouseTest.php`
- Modify: `server/routes/api.php`

**Interfaces:**
- Consumes: Task 1 的 Warehouse/Location 模型与 `DeletionGuard`
- Produces: `GET/POST/PUT/DELETE /api/v1/warehouses`（1105/1106）；`GET/POST /api/v1/warehouses/{warehouse}/locations`、`PUT/DELETE /api/v1/locations/{location}`（1107）；权限 `warehouse.*`

- [ ] **Step 1: 写失败测试 `server/tests/Feature/WarehouseTest.php`**

```php
<?php
// 仓库/库位接口测试：CRUD/编码唯一/子资源/删除保护（正常+边界+异常）
namespace Tests\Feature;

use App\Models\Location;
use App\Models\Role;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WarehouseTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $role = Role::create(['name' => '管理员', 'code' => 'admin']);
        $u = User::create(['name' => '管理员', 'username' => 'admin', 'password' => 'admin123', 'status' => 1]);
        $u->roles()->sync([$role->id]);
        $this->token = $u->createToken('api')->plainTextToken;
    }

    public function test_index_supports_keyword_search(): void
    {
        // 正常路径：关键字按名称/编码模糊过滤
        Warehouse::create(['name' => '主仓', 'code' => 'WH01', 'status' => 1]);
        $this->withToken($this->token)->getJson('/api/v1/warehouses?keyword=WH01')
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.items.0.code', 'WH01');
    }

    public function test_store_and_duplicate_code_fails_with_1105(): void
    {
        // 正常路径：创建成功
        $this->withToken($this->token)->postJson('/api/v1/warehouses', ['name' => '测试仓', 'code' => 'WH02', 'address' => '厂区B', 'manager' => '李四', 'status' => 1])
            ->assertJsonPath('code', 0);
        // 异常路径：重复编码 1105
        $this->withToken($this->token)->postJson('/api/v1/warehouses', ['name' => '重复', 'code' => 'WH02'])
            ->assertJsonPath('code', 1105);
    }

    public function test_update_warehouse(): void
    {
        // 正常路径：更新地址与负责人
        $w = Warehouse::create(['name' => '主仓', 'code' => 'WH01', 'status' => 1]);
        $this->withToken($this->token)->putJson("/api/v1/warehouses/{$w->id}", ['name' => '主仓2', 'code' => 'WH01', 'address' => '新地址', 'manager' => '王五', 'status' => 1])
            ->assertJsonPath('code', 0);
        $this->assertDatabaseHas('warehouses', ['id' => $w->id, 'name' => '主仓2', 'manager' => '王五']);
    }

    public function test_location_crud_under_warehouse(): void
    {
        // 正常路径：库位增查改删全链路
        $w = Warehouse::create(['name' => '测试仓', 'code' => 'WH02', 'status' => 1]);
        $this->withToken($this->token)->postJson("/api/v1/warehouses/{$w->id}/locations", ['name' => 'A-01', 'code' => 'A-01', 'status' => 1])
            ->assertJsonPath('code', 0);
        $this->withToken($this->token)->getJson("/api/v1/warehouses/{$w->id}/locations")
            ->assertJsonPath('data.items.0.name', 'A-01');
        $location = Location::first();
        $this->withToken($this->token)->putJson("/api/v1/locations/{$location->id}", ['name' => 'A-02', 'code' => 'A-02', 'status' => 1])
            ->assertJsonPath('code', 0);
        $this->withToken($this->token)->deleteJson("/api/v1/locations/{$location->id}")->assertJsonPath('code', 0);
        $this->assertDatabaseMissing('locations', ['id' => $location->id]);
    }

    public function test_location_duplicate_code_returns_422(): void
    {
        // 异常路径：库位编码全局唯一（格式层 422，非业务码）
        $w = Warehouse::create(['name' => '测试仓', 'code' => 'WH02', 'status' => 1]);
        Location::create(['warehouse_id' => $w->id, 'name' => 'A-01', 'code' => 'A-01', 'status' => 1]);
        $this->withToken($this->token)->postJson("/api/v1/warehouses/{$w->id}/locations", ['name' => 'A-01b', 'code' => 'A-01', 'status' => 1])
            ->assertStatus(422);
    }

    public function test_destroy_warehouse_cascades_locations(): void
    {
        // 正常路径：删除仓库级联删除其库位（余额表未建，守卫放行）
        $w = Warehouse::create(['name' => '测试仓', 'code' => 'WH02', 'status' => 1]);
        Location::create(['warehouse_id' => $w->id, 'name' => 'A-01', 'code' => 'A-01', 'status' => 1]);
        $this->withToken($this->token)->deleteJson("/api/v1/warehouses/{$w->id}")->assertJsonPath('code', 0);
        $this->assertDatabaseMissing('warehouses', ['id' => $w->id]);
        $this->assertDatabaseMissing('locations', ['warehouse_id' => $w->id]);
    }

    public function test_destroy_with_balances_table_reference_fails(): void
    {
        // 边界路径：inventory_balances 表存在且有引用时，仓库删除被拒 1106（临时表验证守卫联动）
        $w = Warehouse::create(['name' => '测试仓', 'code' => 'WH02', 'status' => 1]);
        \Illuminate\Support\Facades\Schema::create('inventory_balances', function ($table) {
            $table->id();
            $table->unsignedBigInteger('warehouse_id');
            $table->unsignedBigInteger('location_id')->nullable();
        });
        \Illuminate\Support\Facades\DB::table('inventory_balances')->insert(['warehouse_id' => $w->id, 'location_id' => null]);
        try {
            $this->withToken($this->token)->deleteJson("/api/v1/warehouses/{$w->id}")
                ->assertJsonPath('code', 1106);
        } finally {
            \Illuminate\Support\Facades\Schema::dropIfExists('inventory_balances');
        }
    }
}
```

- [ ] **Step 2: 跑测试确认失败**

Run: `cd server && php artisan test --filter=WarehouseTest`
Expected: FAIL。

- [ ] **Step 3: 实现 WarehouseController**

创建 `server/app/Http/Controllers/Api/WarehouseController.php`：

```php
<?php
// 仓库/库位控制器：仓库 CRUD + 库位子资源 + 删除保护（有库存不可删）
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Location;
use App\Models\Warehouse;
use App\Support\ApiResponse;
use App\Support\DeletionGuard;
use Illuminate\Http\Request;

class WarehouseController extends Controller
{
    use ApiResponse;

    /** 仓库分页列表：名称/编码模糊搜索 + 状态过滤 */
    public function index(Request $request)
    {
        $query = Warehouse::orderByDesc('id');
        if ($keyword = $request->input('keyword')) {
            $query->where(fn ($q) => $q->where('name', 'like', "%{$keyword}%")->orWhere('code', 'like', "%{$keyword}%"));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        $rows = $query->paginate(max(1, min(100, (int) $request->input('per_page', 10))));
        return $this->ok([
            'items' => $rows->map(fn ($w) => ['id' => $w->id, 'name' => $w->name, 'code' => $w->code, 'address' => $w->address, 'manager' => $w->manager, 'status' => $w->status]),
            'total' => $rows->total(), 'page' => $rows->currentPage(), 'per_page' => $rows->perPage(),
        ]);
    }

    /** 新建仓库：编码重复 1105 */
    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:50',
            'code' => 'required|string|max:20',
            'address' => 'nullable|string',
            'manager' => 'nullable|string|max:50',
            'status' => 'nullable|in:0,1',
        ]);
        if (Warehouse::where('code', $data['code'])->exists()) {
            return $this->fail(1105, '仓库编码已存在');
        }
        $warehouse = Warehouse::create([
            'name' => $data['name'], 'code' => $data['code'],
            'address' => $data['address'] ?? null, 'manager' => $data['manager'] ?? null, 'status' => $data['status'] ?? 1,
        ]);
        return $this->ok(['id' => $warehouse->id]);
    }

    /** 更新仓库 */
    public function update(Request $request, Warehouse $warehouse)
    {
        $data = $request->validate([
            'name' => 'required|string|max:50',
            'code' => 'required|string|max:20',
            'address' => 'nullable|string',
            'manager' => 'nullable|string|max:50',
            'status' => 'nullable|in:0,1',
        ]);
        if (Warehouse::where('code', $data['code'])->where('id', '!=', $warehouse->id)->exists()) {
            return $this->fail(1105, '仓库编码已存在');
        }
        $warehouse->update([
            'name' => $data['name'], 'code' => $data['code'],
            'address' => $data['address'] ?? $warehouse->address, 'manager' => $data['manager'] ?? $warehouse->manager,
            'status' => $data['status'] ?? $warehouse->status,
        ]);
        return $this->ok();
    }

    /** 删除仓库：存在库存余额 1106（余额表由库存模块创建，未建时守卫自动放行） */
    public function destroy(Warehouse $warehouse)
    {
        if (DeletionGuard::referenced('inventory_balances', 'warehouse_id', $warehouse->id)) {
            return $this->fail(1106, '仓库存在库存，不可删除');
        }
        $warehouse->delete();
        return $this->ok();
    }

    /** 库位列表（按仓库过滤，全量返回供库位弹窗） */
    public function locations(Warehouse $warehouse)
    {
        $items = $warehouse->locations()->orderBy('id')->get()
            ->map(fn ($l) => ['id' => $l->id, 'name' => $l->name, 'code' => $l->code, 'status' => $l->status]);
        return $this->ok(['items' => $items]);
    }

    /** 新建库位：编码全局唯一（重复 422，格式层校验） */
    public function storeLocation(Request $request, Warehouse $warehouse)
    {
        $data = $request->validate([
            'name' => 'required|string|max:50',
            'code' => 'required|string|max:50|unique:locations,code',
            'status' => 'nullable|in:0,1',
        ]);
        $location = $warehouse->locations()->create(['name' => $data['name'], 'code' => $data['code'], 'status' => $data['status'] ?? 1]);
        return $this->ok(['id' => $location->id]);
    }

    /** 更新库位 */
    public function updateLocation(Request $request, Location $location)
    {
        $data = $request->validate([
            'name' => 'required|string|max:50',
            'code' => 'required|string|max:50|unique:locations,code,' . $location->id,
            'status' => 'nullable|in:0,1',
        ]);
        $location->update(['name' => $data['name'], 'code' => $data['code'], 'status' => $data['status'] ?? $location->status]);
        return $this->ok();
    }

    /** 删除库位：存在库存余额 1107 */
    public function destroyLocation(Location $location)
    {
        if (DeletionGuard::referenced('inventory_balances', 'location_id', $location->id)) {
            return $this->fail(1107, '库位存在库存，不可删除');
        }
        $location->delete();
        return $this->ok();
    }
}
```

- [ ] **Step 4: 注册路由**

修改 `server/routes/api.php`（use 追加 `WarehouseController`；`auth:sanctum` 组内追加；注意 `warehouses/{warehouse}/locations` 与 `locations/{location}` 独立）：

```php
    // 仓库/库位：CRUD + 库位子资源（warehouse.list/create/update/delete）
    Route::middleware('auth:sanctum')->group(function () {
        Route::middleware('permission:warehouse.list')->get('/warehouses', [WarehouseController::class, 'index']);
        Route::middleware('permission:warehouse.create')->post('/warehouses', [WarehouseController::class, 'store']);
        Route::middleware('permission:warehouse.update')->put('/warehouses/{warehouse}', [WarehouseController::class, 'update']);
        Route::middleware('permission:warehouse.delete')->delete('/warehouses/{warehouse}', [WarehouseController::class, 'destroy']);
        Route::middleware('permission:warehouse.list')->get('/warehouses/{warehouse}/locations', [WarehouseController::class, 'locations']);
        Route::middleware('permission:warehouse.create')->post('/warehouses/{warehouse}/locations', [WarehouseController::class, 'storeLocation']);
        Route::middleware('permission:warehouse.update')->put('/locations/{location}', [WarehouseController::class, 'updateLocation']);
        Route::middleware('permission:warehouse.delete')->delete('/locations/{location}', [WarehouseController::class, 'destroyLocation']);
    });
```

- [ ] **Step 5: 跑测试确认通过**

Run: `cd server && php artisan test --filter=WarehouseTest`
Expected: 7 个测试 PASS。

- [ ] **Step 6: 提交**

```bash
cd /d/code/project/php-design && git add -A && git commit -m "feat: 仓库/库位 API"
```

---
## Task 4: 供应商/客户 API

**Files:**
- Create: `server/app/Http/Controllers/Api/{SupplierController,CustomerController}.php`
- Create: `server/tests/Feature/{SupplierTest,CustomerTest}.php`
- Modify: `server/routes/api.php`

**Interfaces:**
- Consumes: Task 1 的 Supplier/Customer 模型与 `DeletionGuard`
- Produces: `GET/POST/PUT/DELETE /api/v1/suppliers`（keyword 模糊 name/code/contact + status 过滤；1108/1109）；`GET/POST/PUT/DELETE /api/v1/customers`（1110/1111）；权限 `supplier.*`/`customer.*`

- [ ] **Step 1: 写失败测试**

创建 `server/tests/Feature/SupplierTest.php`：

```php
<?php
// 供应商接口测试：CRUD/搜索/编码唯一/删除保护（正常+边界+异常）
namespace Tests\Feature;

use App\Models\Role;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupplierTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $role = Role::create(['name' => '管理员', 'code' => 'admin']);
        $u = User::create(['name' => '管理员', 'username' => 'admin', 'password' => 'admin123', 'status' => 1]);
        $u->roles()->sync([$role->id]);
        $this->token = $u->createToken('api')->plainTextToken;
    }

    public function test_index_keyword_search_filters_name_and_code(): void
    {
        // 正常路径：关键字按名称/编码/联系人模糊过滤
        Supplier::create(['name' => '测试供应商', 'code' => 'SUP-001', 'contact' => '张三', 'phone' => '13800000000', 'status' => 1]);
        Supplier::create(['name' => '其他供应商', 'code' => 'SUP-002', 'contact' => '李四', 'status' => 1]);
        $this->withToken($this->token)->getJson('/api/v1/suppliers?keyword=测试')
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.items.0.code', 'SUP-001');
    }

    public function test_store_and_duplicate_code_fails_with_1108(): void
    {
        // 正常路径：创建成功
        $this->withToken($this->token)->postJson('/api/v1/suppliers', ['name' => '测试供应商', 'code' => 'SUP-001', 'contact' => '张三', 'phone' => '13800000000', 'address' => '工业园1号', 'status' => 1])
            ->assertJsonPath('code', 0);
        // 异常路径：重复编码 1108
        $this->withToken($this->token)->postJson('/api/v1/suppliers', ['name' => '重复', 'code' => 'SUP-001'])
            ->assertJsonPath('code', 1108);
    }

    public function test_update_contact_and_phone(): void
    {
        // 正常路径：更新联系人电话
        $s = Supplier::create(['name' => '测试供应商', 'code' => 'SUP-001', 'contact' => '张三', 'status' => 1]);
        $this->withToken($this->token)->putJson("/api/v1/suppliers/{$s->id}", ['name' => '测试供应商', 'code' => 'SUP-001', 'contact' => '王五', 'phone' => '13900000000', 'status' => 1])
            ->assertJsonPath('code', 0);
        $this->assertDatabaseHas('suppliers', ['id' => $s->id, 'contact' => '王五', 'phone' => '13900000000']);
    }

    public function test_destroy_succeeds_when_purchase_tables_missing(): void
    {
        // 边界路径：采购模块表未建（守卫放行），供应商可删
        $s = Supplier::create(['name' => '测试供应商', 'code' => 'SUP-001', 'status' => 1]);
        $this->withToken($this->token)->deleteJson("/api/v1/suppliers/{$s->id}")->assertJsonPath('code', 0);
        $this->assertDatabaseMissing('suppliers', ['id' => $s->id]);
    }

    public function test_destroy_with_purchase_order_reference_fails(): void
    {
        // 边界路径：purchase_orders 表存在且有引用时删除被拒 1109（临时表验证守卫联动）
        $s = Supplier::create(['name' => '测试供应商', 'code' => 'SUP-001', 'status' => 1]);
        \Illuminate\Support\Facades\Schema::create('purchase_orders', function ($table) {
            $table->id();
            $table->unsignedBigInteger('supplier_id');
        });
        \Illuminate\Support\Facades\DB::table('purchase_orders')->insert(['supplier_id' => $s->id]);
        try {
            $this->withToken($this->token)->deleteJson("/api/v1/suppliers/{$s->id}")
                ->assertJsonPath('code', 1109);
        } finally {
            \Illuminate\Support\Facades\Schema::dropIfExists('purchase_orders');
        }
    }
}
```

创建 `server/tests/Feature/CustomerTest.php`（与 SupplierTest 同构，替换资源为 customers/客户，错误码 1110/1111，临时表 `sales_orders.customer_id`）。

- [ ] **Step 2: 跑测试确认失败**

Run: `cd server && php artisan test --filter="SupplierTest|CustomerTest"`
Expected: FAIL。

- [ ] **Step 3: 实现 SupplierController / CustomerController**

创建 `server/app/Http/Controllers/Api/SupplierController.php`：

```php
<?php
// 供应商控制器：CRUD + 搜索 + 编码唯一 + 被采购单据引用保护
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Supplier;
use App\Support\ApiResponse;
use App\Support\DeletionGuard;
use Illuminate\Http\Request;

class SupplierController extends Controller
{
    use ApiResponse;

    /** 分页列表：名称/编码/联系人模糊搜索 + 状态过滤 */
    public function index(Request $request)
    {
        $query = Supplier::orderByDesc('id');
        if ($keyword = $request->input('keyword')) {
            $query->where(fn ($q) => $q->where('name', 'like', "%{$keyword}%")
                ->orWhere('code', 'like', "%{$keyword}%")
                ->orWhere('contact', 'like', "%{$keyword}%"));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        $rows = $query->paginate(max(1, min(100, (int) $request->input('per_page', 10))));
        return $this->ok([
            'items' => $rows->map(fn ($s) => [
                'id' => $s->id, 'name' => $s->name, 'code' => $s->code, 'contact' => $s->contact,
                'phone' => $s->phone, 'address' => $s->address, 'remark' => $s->remark, 'status' => $s->status,
            ]),
            'total' => $rows->total(), 'page' => $rows->currentPage(), 'per_page' => $rows->perPage(),
        ]);
    }

    /** 新建供应商：编码重复 1108 */
    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'code' => 'required|string|max:50',
            'contact' => 'nullable|string|max:50',
            'phone' => 'nullable|string|max:30',
            'address' => 'nullable|string',
            'remark' => 'nullable|string',
            'status' => 'nullable|in:0,1',
        ]);
        if (Supplier::where('code', $data['code'])->exists()) {
            return $this->fail(1108, '供应商编码已存在');
        }
        $supplier = Supplier::create([
            'name' => $data['name'], 'code' => $data['code'], 'contact' => $data['contact'] ?? null,
            'phone' => $data['phone'] ?? null, 'address' => $data['address'] ?? null,
            'remark' => $data['remark'] ?? null, 'status' => $data['status'] ?? 1,
        ]);
        return $this->ok(['id' => $supplier->id]);
    }

    /** 更新供应商：编码唯一（排除自身） */
    public function update(Request $request, Supplier $supplier)
    {
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'code' => 'required|string|max:50',
            'contact' => 'nullable|string|max:50',
            'phone' => 'nullable|string|max:30',
            'address' => 'nullable|string',
            'remark' => 'nullable|string',
            'status' => 'nullable|in:0,1',
        ]);
        if (Supplier::where('code', $data['code'])->where('id', '!=', $supplier->id)->exists()) {
            return $this->fail(1108, '供应商编码已存在');
        }
        $supplier->update([
            'name' => $data['name'], 'code' => $data['code'], 'contact' => $data['contact'] ?? $supplier->contact,
            'phone' => $data['phone'] ?? $supplier->phone, 'address' => $data['address'] ?? $supplier->address,
            'remark' => $data['remark'] ?? $supplier->remark, 'status' => $data['status'] ?? $supplier->status,
        ]);
        return $this->ok();
    }

    /** 删除供应商：被采购单据引用 1109（采购表由采购模块创建，未建时守卫自动放行） */
    public function destroy(Supplier $supplier)
    {
        if (DeletionGuard::referenced('purchase_orders', 'supplier_id', $supplier->id)) {
            return $this->fail(1109, '供应商已被采购单据使用，不可删除');
        }
        $supplier->delete();
        return $this->ok();
    }
}
```

创建 `server/app/Http/Controllers/Api/CustomerController.php`（与 SupplierController 同构：模型 Customer、错误码 1110/1111、消息「客户编码已存在」/「客户已被销售单据使用，不可删除」、守卫表 `sales_orders.customer_id`）。

- [ ] **Step 4: 注册路由**

修改 `server/routes/api.php`（use 追加两个控制器；`auth:sanctum` 组内追加）：

```php
    // 供应商：CRUD（supplier.list/create/update/delete）
    Route::middleware('auth:sanctum')->group(function () {
        Route::middleware('permission:supplier.list')->get('/suppliers', [SupplierController::class, 'index']);
        Route::middleware('permission:supplier.create')->post('/suppliers', [SupplierController::class, 'store']);
        Route::middleware('permission:supplier.update')->put('/suppliers/{supplier}', [SupplierController::class, 'update']);
        Route::middleware('permission:supplier.delete')->delete('/suppliers/{supplier}', [SupplierController::class, 'destroy']);
    });

    // 客户：CRUD（customer.list/create/update/delete）
    Route::middleware('auth:sanctum')->group(function () {
        Route::middleware('permission:customer.list')->get('/customers', [CustomerController::class, 'index']);
        Route::middleware('permission:customer.create')->post('/customers', [CustomerController::class, 'store']);
        Route::middleware('permission:customer.update')->put('/customers/{customer}', [CustomerController::class, 'update']);
        Route::middleware('permission:customer.delete')->delete('/customers/{customer}', [CustomerController::class, 'destroy']);
    });
```

- [ ] **Step 5: 跑测试确认通过**

Run: `cd server && php artisan test --filter="SupplierTest|CustomerTest"`
Expected: 全部 PASS（各 5 个，共 10）。

- [ ] **Step 6: 提交**

```bash
cd /d/code/project/php-design && git add -A && git commit -m "feat: 供应商/客户 API"
```

---

## Task 5: 商品 API

**Files:**
- Create: `server/app/Http/Controllers/Api/ProductController.php`
- Create: `server/tests/Feature/ProductTest.php`
- Modify: `server/routes/api.php`

**Interfaces:**
- Consumes: Task 1 的 Product/BomHeader/BomItem 模型与 `DeletionGuard`
- Produces: `GET/POST/PUT/DELETE /api/v1/products`（Query：page/per_page/keyword/type/category_id/status；1114/1115/1116/1122）；`GET /api/v1/products/barcode/{barcode}`（1117）；items 含 `{id,name,code,type,type_label,category_id,category_name,unit_id,unit_name,spec,barcode,safety_min,safety_max,status}`；权限 `product.*`；**路由顺序：`products/barcode/{barcode}` 必须先于 `products/{product}`**

- [ ] **Step 1: 写失败测试 `server/tests/Feature/ProductTest.php`**

```php
<?php
// 商品接口测试：CRUD/筛选/编码条码唯一/上下限校验/扫码查询/删除保护（正常+边界+异常）
namespace Tests\Feature;

use App\Models\BomHeader;
use App\Models\BomItem;
use App\Models\Category;
use App\Models\Product;
use App\Models\Role;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductTest extends TestCase
{
    use RefreshDatabase;

    private string $token;
    private Category $rawCat;
    private Category $finCat;
    private Unit $unit;

    protected function setUp(): void
    {
        parent::setUp();
        $role = Role::create(['name' => '管理员', 'code' => 'admin']);
        $u = User::create(['name' => '管理员', 'username' => 'admin', 'password' => 'admin123', 'status' => 1]);
        $u->roles()->sync([$role->id]);
        $this->token = $u->createToken('api')->plainTextToken;
        $this->rawCat = Category::create(['name' => '原材料', 'parent_id' => 0]);
        $this->finCat = Category::create(['name' => '成品', 'parent_id' => 0]);
        $this->unit = Unit::create(['name' => '个', 'code' => 'pc']);
    }

    public function test_index_filters_by_keyword_type_and_category(): void
    {
        // 正常路径：关键字/类型/分类组合过滤
        Product::create(['name' => '铝材', 'code' => 'RAW-001', 'type' => 'raw_material', 'category_id' => $this->rawCat->id, 'unit_id' => $this->unit->id, 'status' => 1]);
        Product::create(['name' => '成品A', 'code' => 'FIN-001', 'type' => 'finished', 'category_id' => $this->finCat->id, 'unit_id' => $this->unit->id, 'status' => 1]);
        $this->withToken($this->token)->getJson('/api/v1/products?keyword=RAW-001&type=raw_material')
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.items.0.type_label', '原料');
    }

    public function test_store_creates_product_with_unit_name(): void
    {
        // 正常路径：创建成功且响应带 unit_name
        $this->withToken($this->token)->postJson('/api/v1/products', [
            'name' => '测试铝材', 'code' => 'MAT-001', 'type' => 'raw_material',
            'category_id' => $this->rawCat->id, 'unit_id' => $this->unit->id, 'spec' => '1mm',
            'barcode' => '999999', 'safety_min' => 10, 'safety_max' => 100, 'status' => 1,
        ])->assertJsonPath('code', 0);
        $this->assertDatabaseHas('products', ['code' => 'MAT-001']);
    }

    public function test_store_duplicate_code_fails_with_1114(): void
    {
        // 异常路径：编码重复 1114
        Product::create(['name' => '铝材', 'code' => 'RAW-001', 'type' => 'raw_material', 'category_id' => $this->rawCat->id, 'unit_id' => $this->unit->id, 'status' => 1]);
        $this->withToken($this->token)->postJson('/api/v1/products', [
            'name' => '重复', 'code' => 'RAW-001', 'type' => 'raw_material',
            'category_id' => $this->rawCat->id, 'unit_id' => $this->unit->id, 'status' => 1,
        ])->assertJsonPath('code', 1114);
    }

    public function test_store_duplicate_barcode_fails_with_1115(): void
    {
        // 异常路径：条码重复 1115
        Product::create(['name' => '铝材', 'code' => 'RAW-001', 'type' => 'raw_material', 'category_id' => $this->rawCat->id, 'unit_id' => $this->unit->id, 'barcode' => '888888', 'status' => 1]);
        $this->withToken($this->token)->postJson('/api/v1/products', [
            'name' => '重复', 'code' => 'MAT-002', 'type' => 'raw_material',
            'category_id' => $this->rawCat->id, 'unit_id' => $this->unit->id, 'barcode' => '888888', 'status' => 1,
        ])->assertJsonPath('code', 1115);
    }

    public function test_store_min_greater_than_max_fails_with_1122(): void
    {
        // 异常路径：安全库存下限大于上限 1122
        $this->withToken($this->token)->postJson('/api/v1/products', [
            'name' => '异常', 'code' => 'MAT-003', 'type' => 'raw_material',
            'category_id' => $this->rawCat->id, 'unit_id' => $this->unit->id,
            'safety_min' => 200, 'safety_max' => 100, 'status' => 1,
        ])->assertJsonPath('code', 1122);
    }

    public function test_update_product_keeps_unit_name(): void
    {
        // 正常路径：更新规格与上下限
        $p = Product::create(['name' => '铝材', 'code' => 'RAW-001', 'type' => 'raw_material', 'category_id' => $this->rawCat->id, 'unit_id' => $this->unit->id, 'status' => 1]);
        $this->withToken($this->token)->putJson("/api/v1/products/{$p->id}", [
            'name' => '铝材2', 'code' => 'RAW-001', 'type' => 'raw_material',
            'category_id' => $this->rawCat->id, 'unit_id' => $this->unit->id, 'spec' => '2mm',
            'safety_min' => 5, 'safety_max' => 50, 'status' => 1,
        ])->assertJsonPath('code', 0);
        $this->assertDatabaseHas('products', ['id' => $p->id, 'name' => '铝材2', 'spec' => '2mm']);
    }

    public function test_by_barcode_returns_product_info(): void
    {
        // 正常路径：扫码命中返回商品信息
        Product::create(['name' => '成品B', 'code' => 'FIN-002', 'type' => 'finished', 'category_id' => $this->finCat->id, 'unit_id' => $this->unit->id, 'barcode' => '888888', 'status' => 1]);
        $this->withToken($this->token)->getJson('/api/v1/products/barcode/888888')
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.name', '成品B')
            ->assertJsonPath('data.type', 'finished');
    }

    public function test_by_barcode_not_found_fails_with_1117(): void
    {
        // 异常路径：未知条码 1117
        $this->withToken($this->token)->getJson('/api/v1/products/barcode/000000')
            ->assertJsonPath('code', 1117);
    }

    public function test_destroy_referenced_by_bom_fails_with_1116(): void
    {
        // 异常路径：被 BOM 明细引用的商品不可删 1116
        $material = Product::create(['name' => '铝材', 'code' => 'RAW-001', 'type' => 'raw_material', 'category_id' => $this->rawCat->id, 'unit_id' => $this->unit->id, 'status' => 1]);
        $fin = Product::create(['name' => '成品A', 'code' => 'FIN-001', 'type' => 'finished', 'category_id' => $this->finCat->id, 'unit_id' => $this->unit->id, 'status' => 1]);
        $bom = BomHeader::create(['code' => 'BOM20260812-001', 'product_id' => $fin->id, 'version' => 'v1', 'quantity' => 1, 'status' => 1]);
        BomItem::create(['bom_header_id' => $bom->id, 'material_id' => $material->id, 'quantity' => 2, 'unit_id' => $this->unit->id]);
        $this->withToken($this->token)->deleteJson("/api/v1/products/{$material->id}")
            ->assertJsonPath('code', 1116);
    }

    public function test_destroy_unreferenced_product_succeeds(): void
    {
        // 正常路径：未被引用的商品可删
        $p = Product::create(['name' => '临时', 'code' => 'TMP-001', 'type' => 'raw_material', 'category_id' => $this->rawCat->id, 'unit_id' => $this->unit->id, 'status' => 1]);
        $this->withToken($this->token)->deleteJson("/api/v1/products/{$p->id}")->assertJsonPath('code', 0);
        $this->assertDatabaseMissing('products', ['id' => $p->id]);
    }
}
```

- [ ] **Step 2: 跑测试确认失败**

Run: `cd server && php artisan test --filter=ProductTest`
Expected: FAIL。

- [ ] **Step 3: 实现 ProductController**

创建 `server/app/Http/Controllers/Api/ProductController.php`：

```php
<?php
// 商品控制器：分页筛选 + CRUD + 扫码查询 + 删除保护（被 BOM/业务单据引用）
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BomHeader;
use App\Models\BomItem;
use App\Models\Product;
use App\Support\ApiResponse;
use App\Support\DeletionGuard;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProductController extends Controller
{
    use ApiResponse;

    // 类型 → 中文标签映射（前端表格类型标签）
    private const TYPE_LABELS = ['raw_material' => '原料', 'semi_finished' => '半成品', 'finished' => '成品'];

    /** 分页列表：编码/名称/条码模糊 + 类型/分类/状态过滤 */
    public function index(Request $request)
    {
        $query = Product::with(['category', 'unit'])->orderByDesc('id');
        if ($keyword = $request->input('keyword')) {
            $query->where(fn ($q) => $q->where('code', 'like', "%{$keyword}%")
                ->orWhere('name', 'like', "%{$keyword}%")
                ->orWhere('barcode', 'like', "%{$keyword}%"));
        }
        if ($request->filled('type')) {
            $query->where('type', $request->input('type'));
        }
        if ($request->filled('category_id')) {
            $query->where('category_id', $request->input('category_id'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        $rows = $query->paginate(max(1, min(100, (int) $request->input('per_page', 10))));
        return $this->ok([
            'items' => $rows->map(fn ($p) => $this->payload($p)),
            'total' => $rows->total(), 'page' => $rows->currentPage(), 'per_page' => $rows->perPage(),
        ]);
    }

    // 商品输出结构（列表与扫码复用：列表多 category_name/status）
    private function payload(Product $p): array
    {
        return [
            'id' => $p->id, 'name' => $p->name, 'code' => $p->code, 'type' => $p->type,
            'type_label' => self::TYPE_LABELS[$p->type] ?? $p->type,
            'category_id' => $p->category_id, 'category_name' => $p->category?->name,
            'unit_id' => $p->unit_id, 'unit_name' => $p->unit?->name,
            'spec' => $p->spec, 'barcode' => $p->barcode,
            'safety_min' => (float) $p->safety_min, 'safety_max' => (float) $p->safety_max,
            'status' => $p->status,
        ];
    }

    /** 新建商品：编码/条码唯一 + 安全库存上下限校验 */
    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'code' => 'required|string|max:50',
            'type' => ['required', Rule::in(['raw_material', 'semi_finished', 'finished'])],
            'category_id' => 'required|exists:categories,id',
            'unit_id' => 'required|exists:units,id',
            'spec' => 'nullable|string|max:100',
            'barcode' => 'nullable|string|max:50',
            'safety_min' => 'nullable|numeric|min:0',
            'safety_max' => 'nullable|numeric|min:0',
            'status' => 'nullable|in:0,1',
            'remark' => 'nullable|string',
        ]);

        // 编码唯一 1114；条码非空时唯一 1115
        if (Product::where('code', $data['code'])->exists()) {
            return $this->fail(1114, '商品编码已存在');
        }
        if (! empty($data['barcode']) && Product::where('barcode', $data['barcode'])->exists()) {
            return $this->fail(1115, '条码已存在');
        }
        // 安全库存下限不能大于上限 1122
        $min = (float) ($data['safety_min'] ?? 0);
        $max = (float) ($data['safety_max'] ?? 0);
        if ($max > 0 && $min > $max) {
            return $this->fail(1122, '安全库存下限不能大于上限');
        }

        $product = Product::create([
            'name' => $data['name'], 'code' => $data['code'], 'type' => $data['type'],
            'category_id' => $data['category_id'], 'unit_id' => $data['unit_id'],
            'spec' => $data['spec'] ?? null, 'barcode' => $data['barcode'] ?? null,
            'safety_min' => $min, 'safety_max' => $max,
            'status' => $data['status'] ?? 1, 'remark' => $data['remark'] ?? null,
        ]);
        return $this->ok(['id' => $product->id]);
    }

    /** 更新商品：编码/条码唯一（排除自身） */
    public function update(Request $request, Product $product)
    {
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'code' => 'required|string|max:50',
            'type' => ['required', Rule::in(['raw_material', 'semi_finished', 'finished'])],
            'category_id' => 'required|exists:categories,id',
            'unit_id' => 'required|exists:units,id',
            'spec' => 'nullable|string|max:100',
            'barcode' => 'nullable|string|max:50',
            'safety_min' => 'nullable|numeric|min:0',
            'safety_max' => 'nullable|numeric|min:0',
            'status' => 'nullable|in:0,1',
            'remark' => 'nullable|string',
        ]);

        if (Product::where('code', $data['code'])->where('id', '!=', $product->id)->exists()) {
            return $this->fail(1114, '商品编码已存在');
        }
        if (! empty($data['barcode']) && Product::where('barcode', $data['barcode'])->where('id', '!=', $product->id)->exists()) {
            return $this->fail(1115, '条码已存在');
        }
        $min = (float) ($data['safety_min'] ?? 0);
        $max = (float) ($data['safety_max'] ?? 0);
        if ($max > 0 && $min > $max) {
            return $this->fail(1122, '安全库存下限不能大于上限');
        }

        $product->update([
            'name' => $data['name'], 'code' => $data['code'], 'type' => $data['type'],
            'category_id' => $data['category_id'], 'unit_id' => $data['unit_id'],
            'spec' => $data['spec'] ?? null, 'barcode' => $data['barcode'] ?? null,
            'safety_min' => $min, 'safety_max' => $max,
            'status' => $data['status'] ?? $product->status, 'remark' => $data['remark'] ?? null,
        ]);
        return $this->ok();
    }

    /** 删除商品：被 BOM 头/明细、库存流水、采购/销售明细、生产工单引用不可删 1116 */
    public function destroy(Product $product)
    {
        // 本模块表（BOM）直接检查；下游模块表经守卫（未建自动放行，建后自动生效）
        $referencedByBom = BomItem::where('material_id', $product->id)->exists()
            || BomHeader::where('product_id', $product->id)->exists();
        $referencedByOther = DeletionGuard::referenced('inventory_movements', 'product_id', $product->id)
            || DeletionGuard::referenced('purchase_order_items', 'product_id', $product->id)
            || DeletionGuard::referenced('sales_order_items', 'product_id', $product->id)
            || DeletionGuard::referenced('production_orders', 'product_id', $product->id);
        if ($referencedByBom || $referencedByOther) {
            return $this->fail(1116, '商品已被业务单据使用，不可删除');
        }
        $product->delete();
        return $this->ok();
    }

    /** 扫码查询：扫枪场景按条码即时校验，未匹配 1117 */
    public function byBarcode(string $barcode)
    {
        $product = Product::with('unit')->where('barcode', $barcode)->first();
        if (! $product) {
            return $this->fail(1117, '条码未匹配到商品');
        }
        $p = $this->payload($product);
        return $this->ok(['id' => $p['id'], 'name' => $p['name'], 'code' => $p['code'], 'type' => $p['type'], 'spec' => $p['spec'], 'unit_name' => $p['unit_name']]);
    }
}
```

- [ ] **Step 4: 注册路由**

修改 `server/routes/api.php`（use 追加 `ProductController`；`auth:sanctum` 组内追加；**`barcode/{barcode}` 必须先于 `{product}` 注册**）：

```php
    // 商品：CRUD + 扫码查询（product.list/create/update/delete）
    Route::middleware('auth:sanctum')->group(function () {
        Route::middleware('permission:product.list')->get('/products', [ProductController::class, 'index']);
        Route::middleware('permission:product.list')->get('/products/barcode/{barcode}', [ProductController::class, 'byBarcode']);
        Route::middleware('permission:product.create')->post('/products', [ProductController::class, 'store']);
        Route::middleware('permission:product.update')->put('/products/{product}', [ProductController::class, 'update']);
        Route::middleware('permission:product.delete')->delete('/products/{product}', [ProductController::class, 'destroy']);
    });
```

- [ ] **Step 5: 跑测试确认通过**

Run: `cd server && php artisan test --filter=ProductTest`
Expected: 10 个测试 PASS。

- [ ] **Step 6: 提交**

```bash
cd /d/code/project/php-design && git add -A && git commit -m "feat: 商品 API（含扫码查询）"
```

---
## Task 6: BOM API（单头+明细）

**Files:**
- Create: `server/app/Http/Controllers/Api/BomController.php`
- Create: `server/tests/Feature/BomTest.php`
- Modify: `server/routes/api.php`

**Interfaces:**
- Consumes: Task 1 的 BomHeader/BomItem/Product 模型与 `DeletionGuard`
- Produces: `GET/POST /api/v1/boms`、`PUT/DELETE /api/v1/boms/{bom}`、`GET /api/v1/boms/{bom}/items`、`PUT /api/v1/boms/{bom}/toggle`；错误码 1118/1119/1120/1121/1123；POST 请求体 `{product_id, version, quantity, remark, status(0|1 可空，默认1), items:[{material_id, quantity, unit_id}]}`；items 输出 `[{id,material_id,material_name,quantity,unit_id,unit_name}]`；权限 `bom.*`；核心路径（启用版本唯一、类型校验、事务写入）100% 单测覆盖

- [ ] **Step 1: 写失败测试 `server/tests/Feature/BomTest.php`**

```php
<?php
// BOM 接口测试：单头+明细事务/类型校验/启用版本唯一/启用切换/删除（正常+边界+异常）
namespace Tests\Feature;

use App\Models\BomHeader;
use App\Models\Category;
use App\Models\Product;
use App\Models\Role;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BomTest extends TestCase
{
    use RefreshDatabase;

    private string $token;
    private Category $rawCat;
    private Category $finCat;
    private Unit $unit;
    private Product $material;
    private Product $finished;

    protected function setUp(): void
    {
        parent::setUp();
        $role = Role::create(['name' => '管理员', 'code' => 'admin']);
        $u = User::create(['name' => '管理员', 'username' => 'admin', 'password' => 'admin123', 'status' => 1]);
        $u->roles()->sync([$role->id]);
        $this->token = $u->createToken('api')->plainTextToken;
        $this->rawCat = Category::create(['name' => '原材料', 'parent_id' => 0]);
        $this->finCat = Category::create(['name' => '成品', 'parent_id' => 0]);
        $this->unit = Unit::create(['name' => '个', 'code' => 'pc']);
        $this->material = Product::create(['name' => '铝材', 'code' => 'MAT-001', 'type' => 'raw_material', 'category_id' => $this->rawCat->id, 'unit_id' => $this->unit->id, 'status' => 1]);
        $this->finished = Product::create(['name' => '成品B', 'code' => 'FIN-002', 'type' => 'finished', 'category_id' => $this->finCat->id, 'unit_id' => $this->unit->id, 'status' => 1]);
    }

    public function test_store_creates_header_and_items_in_one_submit(): void
    {
        // 正常路径：单头+明细一次提交成功，单号格式 BOM{date}-{seq}
        $res = $this->withToken($this->token)->postJson('/api/v1/boms', [
            'product_id' => $this->finished->id, 'version' => 'v1', 'quantity' => 1, 'remark' => '',
            'items' => [['material_id' => $this->material->id, 'quantity' => 2, 'unit_id' => $this->unit->id]],
        ]);
        $res->assertJsonPath('code', 0);
        $code = $res->json('data.code');
        $this->assertMatchesRegularExpression('/^BOM\d{8}-\d{3}$/', $code);
        $this->assertDatabaseCount('bom_items', 1);
        $this->assertDatabaseHas('bom_headers', ['code' => $code, 'status' => 1]);
    }

    public function test_store_product_not_finished_fails_with_1118(): void
    {
        // 异常路径：BOM 关联商品不是成品 1118
        $this->withToken($this->token)->postJson('/api/v1/boms', [
            'product_id' => $this->material->id, 'version' => 'v1', 'quantity' => 1,
            'items' => [['material_id' => $this->material->id, 'quantity' => 1, 'unit_id' => $this->unit->id]],
        ])->assertJsonPath('code', 1118);
    }

    public function test_store_material_is_finished_fails_with_1119(): void
    {
        // 异常路径：明细物料是成品（不允许成品嵌套）1119
        $this->withToken($this->token)->postJson('/api/v1/boms', [
            'product_id' => $this->finished->id, 'version' => 'v1', 'quantity' => 1,
            'items' => [['material_id' => $this->finished->id, 'quantity' => 1, 'unit_id' => $this->unit->id]],
        ])->assertJsonPath('code', 1119);
    }

    public function test_store_duplicate_enabled_version_fails_with_1120(): void
    {
        // 异常路径：同成品已有启用版本，再建启用版本 1120
        $this->withToken($this->token)->postJson('/api/v1/boms', [
            'product_id' => $this->finished->id, 'version' => 'v1', 'quantity' => 1,
            'items' => [['material_id' => $this->material->id, 'quantity' => 2, 'unit_id' => $this->unit->id]],
        ])->assertJsonPath('code', 0);
        $this->withToken($this->token)->postJson('/api/v1/boms', [
            'product_id' => $this->finished->id, 'version' => 'v2', 'quantity' => 1, 'status' => 1,
            'items' => [['material_id' => $this->material->id, 'quantity' => 3, 'unit_id' => $this->unit->id]],
        ])->assertJsonPath('code', 1120);
    }

    public function test_store_disabled_version_succeeds_even_when_enabled_exists(): void
    {
        // 边界路径：同成品已启用时，以停用状态建新版本允许
        $this->withToken($this->token)->postJson('/api/v1/boms', [
            'product_id' => $this->finished->id, 'version' => 'v1', 'quantity' => 1,
            'items' => [['material_id' => $this->material->id, 'quantity' => 2, 'unit_id' => $this->unit->id]],
        ])->assertJsonPath('code', 0);
        $this->withToken($this->token)->postJson('/api/v1/boms', [
            'product_id' => $this->finished->id, 'version' => 'v2', 'quantity' => 1, 'status' => 0,
            'items' => [['material_id' => $this->material->id, 'quantity' => 3, 'unit_id' => $this->unit->id]],
        ])->assertJsonPath('code', 0);
        $this->assertSame(2, BomHeader::count());
    }

    public function test_store_duplicate_material_rows_fails_with_1123(): void
    {
        // 异常路径：明细存在重复物料 1123
        $this->withToken($this->token)->postJson('/api/v1/boms', [
            'product_id' => $this->finished->id, 'version' => 'v1', 'quantity' => 1,
            'items' => [
                ['material_id' => $this->material->id, 'quantity' => 2, 'unit_id' => $this->unit->id],
                ['material_id' => $this->material->id, 'quantity' => 1, 'unit_id' => $this->unit->id],
            ],
        ])->assertJsonPath('code', 1123);
    }

    public function test_items_returns_material_and_unit_names(): void
    {
        // 正常路径：明细带物料名与单位名
        $bom = BomHeader::create(['code' => 'BOM20260812-001', 'product_id' => $this->finished->id, 'version' => 'v1', 'quantity' => 1, 'status' => 1]);
        $bom->items()->create(['material_id' => $this->material->id, 'quantity' => 2, 'unit_id' => $this->unit->id]);
        $this->withToken($this->token)->getJson("/api/v1/boms/{$bom->id}/items")
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.items.0.material_name', '铝材')
            ->assertJsonPath('data.items.0.unit_name', '个');
    }

    public function test_update_replaces_items_fully(): void
    {
        // 正常路径：更新后明细全量替换
        $bom = BomHeader::create(['code' => 'BOM20260812-001', 'product_id' => $this->finished->id, 'version' => 'v1', 'quantity' => 1, 'status' => 1]);
        $bom->items()->create(['material_id' => $this->material->id, 'quantity' => 2, 'unit_id' => $this->unit->id]);
        $other = Product::create(['name' => '螺丝', 'code' => 'MAT-002', 'type' => 'raw_material', 'category_id' => $this->rawCat->id, 'unit_id' => $this->unit->id, 'status' => 1]);
        $this->withToken($this->token)->putJson("/api/v1/boms/{$bom->id}", [
            'product_id' => $this->finished->id, 'version' => 'v1', 'quantity' => 1,
            'items' => [['material_id' => $other->id, 'quantity' => 5, 'unit_id' => $this->unit->id]],
        ])->assertJsonPath('code', 0);
        $this->assertSame(1, $bom->items()->count());
        $this->assertDatabaseHas('bom_items', ['bom_header_id' => $bom->id, 'material_id' => $other->id, 'quantity' => '5.00']);
    }

    public function test_toggle_enable_auto_disables_other_versions(): void
    {
        // 正常路径：启用 v2 自动停用 v1（同成品启用唯一动态生效）
        $v1 = BomHeader::create(['code' => 'BOM20260812-001', 'product_id' => $this->finished->id, 'version' => 'v1', 'quantity' => 1, 'status' => 1]);
        $v2 = BomHeader::create(['code' => 'BOM20260812-002', 'product_id' => $this->finished->id, 'version' => 'v2', 'quantity' => 1, 'status' => 0]);
        $this->withToken($this->token)->putJson("/api/v1/boms/{$v2->id}/toggle", ['status' => 1])->assertJsonPath('code', 0);
        $this->assertDatabaseHas('bom_headers', ['id' => $v2->id, 'status' => 1]);
        $this->assertDatabaseHas('bom_headers', ['id' => $v1->id, 'status' => 0]);
    }

    public function test_destroy_succeeds_when_production_tables_missing(): void
    {
        // 边界路径：生产模块表未建（守卫放行），BOM 可删
        $bom = BomHeader::create(['code' => 'BOM20260812-001', 'product_id' => $this->finished->id, 'version' => 'v1', 'quantity' => 1, 'status' => 1]);
        $bom->items()->create(['material_id' => $this->material->id, 'quantity' => 2, 'unit_id' => $this->unit->id]);
        $this->withToken($this->token)->deleteJson("/api/v1/boms/{$bom->id}")->assertJsonPath('code', 0);
        $this->assertDatabaseMissing('bom_headers', ['id' => $bom->id]);
        $this->assertDatabaseCount('bom_items', 0);
    }

    public function test_store_item_quantity_not_positive_returns_422(): void
    {
        // 边界路径：明细数量必须 > 0（格式层 422）
        $this->withToken($this->token)->postJson('/api/v1/boms', [
            'product_id' => $this->finished->id, 'version' => 'v1', 'quantity' => 1,
            'items' => [['material_id' => $this->material->id, 'quantity' => 0, 'unit_id' => $this->unit->id]],
        ])->assertStatus(422);
    }
}
```

- [ ] **Step 2: 跑测试确认失败**

Run: `cd server && php artisan test --filter=BomTest`
Expected: FAIL。

- [ ] **Step 3: 实现 BomController**

创建 `server/app/Http/Controllers/Api/BomController.php`：

```php
<?php
// BOM 控制器：单头+明细事务维护、启用版本唯一、启用切换、删除保护
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BomHeader;
use App\Models\Product;
use App\Support\ApiResponse;
use App\Support\DeletionGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class BomController extends Controller
{
    use ApiResponse;

    /** 分页列表：成品过滤 + 单号模糊，含成品名称 */
    public function index(Request $request)
    {
        $query = BomHeader::with('product')->orderByDesc('id');
        if ($request->filled('product_id')) {
            $query->where('product_id', $request->input('product_id'));
        }
        if ($keyword = $request->input('keyword')) {
            $query->where('code', 'like', "%{$keyword}%");
        }
        $rows = $query->paginate(max(1, min(100, (int) $request->input('per_page', 10))));
        return $this->ok([
            'items' => $rows->map(fn ($b) => [
                'id' => $b->id, 'code' => $b->code, 'product_id' => $b->product_id,
                'product_name' => $b->product?->name, 'version' => $b->version,
                'quantity' => (float) $b->quantity, 'status' => $b->status, 'remark' => $b->remark,
            ]),
            'total' => $rows->total(), 'page' => $rows->currentPage(), 'per_page' => $rows->perPage(),
        ]);
    }

    /** 新建 BOM：单头+明细一次提交（事务）；启用版本唯一、成品/物料类型校验 */
    public function store(Request $request)
    {
        $data = $this->validateBom($request, null);

        return DB::transaction(function () use ($data) {
            // 生成单号：BOM{yyyyMMdd}-{3位流水}
            $date = now()->format('Ymd');
            $seq = BomHeader::where('code', 'like', "BOM{$date}-%")->count() + 1;
            $code = "BOM{$date}-" . str_pad((string) $seq, 3, '0', STR_PAD_LEFT);

            $bom = BomHeader::create([
                'code' => $code, 'product_id' => $data['product_id'], 'version' => $data['version'],
                'quantity' => $data['quantity'], 'status' => $data['status'], 'remark' => $data['remark'] ?? null,
            ]);
            $bom->items()->createMany($data['items']);
            return $this->ok(['id' => $bom->id, 'code' => $code]);
        });
    }

    /** 更新 BOM：明细全量替换（事务）；启用版本唯一（排除自身） */
    public function update(Request $request, BomHeader $bom)
    {
        $data = $this->validateBom($request, $bom->id);

        return DB::transaction(function () use ($data, $bom) {
            $bom->update([
                'product_id' => $data['product_id'], 'version' => $data['version'],
                'quantity' => $data['quantity'], 'status' => $data['status'], 'remark' => $data['remark'] ?? null,
            ]);
            // 明细全量替换：先删后建（事务内，失败自动回滚）
            $bom->items()->delete();
            $bom->items()->createMany($data['items']);
            return $this->ok();
        });
    }

    /** 删除 BOM：被生产工单引用 1121（工单表由生产模块创建，未建时守卫自动放行） */
    public function destroy(BomHeader $bom)
    {
        if (DeletionGuard::referenced('production_orders', 'bom_id', $bom->id)) {
            return $this->fail(1121, 'BOM 已被生产工单使用，不可删除');
        }
        $bom->delete();
        return $this->ok();
    }

    /** 明细列表：物料名/单位名联查 */
    public function items(BomHeader $bom)
    {
        $items = $bom->items()->with(['material', 'unit'])->orderBy('id')->get()
            ->map(fn ($i) => [
                'id' => $i->id, 'material_id' => $i->material_id, 'material_name' => $i->material?->name,
                'quantity' => (float) $i->quantity, 'unit_id' => $i->unit_id, 'unit_name' => $i->unit?->name,
            ]);
        return $this->ok(['items' => $items]);
    }

    /** 启用/停用切换：启用时自动停用同成品其他版本（事务） */
    public function toggle(Request $request, BomHeader $bom)
    {
        $data = $request->validate(['status' => 'required|in:0,1']);
        $status = (int) $data['status'];

        DB::transaction(function () use ($bom, $status) {
            // 启用新版本：同成品其他启用版本全部停用，保证启用唯一
            if ($status === 1) {
                BomHeader::where('product_id', $bom->product_id)
                    ->where('status', 1)->where('id', '!=', $bom->id)
                    ->update(['status' => 0]);
            }
            $bom->update(['status' => $status]);
        });
        return $this->ok();
    }

    // BOM 表单校验：格式 422 + 业务码（1118 成品类型/1119 物料类型/1120 启用版本唯一/1123 重复物料）
    private function validateBom(Request $request, ?int $ignoreId): array
    {
        $data = $request->validate([
            'product_id' => 'required|exists:products,id',
            'version' => 'required|string|max:20',
            'quantity' => 'nullable|numeric|min:0.01',
            'remark' => 'nullable|string',
            'status' => 'nullable|in:0,1',
            'items' => 'required|array|min:1',
            'items.*.material_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|numeric|gt:0',
            'items.*.unit_id' => 'required|exists:units,id',
        ]);

        // 成品类型校验 1118
        $product = Product::find($data['product_id']);
        if ($product->type !== 'finished') {
            abort(response()->json(['code' => 1118, 'message' => 'BOM 关联商品必须是成品', 'data' => null], 200));
        }

        // 物料类型校验 1119：明细物料仅原料/半成品（不允许成品嵌套）
        $materialIds = array_column($data['items'], 'material_id');
        $materials = Product::whereIn('id', $materialIds)->get();
        if ($materials->contains(fn ($m) => $m->type === 'finished')) {
            abort(response()->json(['code' => 1119, 'message' => 'BOM 明细物料必须是原料或半成品', 'data' => null], 200));
        }

        // 重复物料 1123
        if (count($materialIds) !== count(array_unique($materialIds))) {
            abort(response()->json(['code' => 1123, 'message' => 'BOM 明细存在重复物料', 'data' => null], 200));
        }

        // 启用版本唯一 1120（status 为空默认 1=启用）
        $status = (int) ($data['status'] ?? 1);
        if ($status === 1) {
            $query = BomHeader::where('product_id', $data['product_id'])->where('status', 1);
            if ($ignoreId) {
                $query->where('id', '!=', $ignoreId);
            }
            if ($query->exists()) {
                abort(response()->json(['code' => 1120, 'message' => '该成品已有启用版本的 BOM', 'data' => null], 200));
            }
        }

        return [
            'product_id' => $data['product_id'], 'version' => $data['version'],
            'quantity' => $data['quantity'] ?? 1, 'status' => $status,
            'remark' => $data['remark'] ?? null,
            'items' => array_map(fn ($i) => [
                'material_id' => $i['material_id'], 'quantity' => $i['quantity'], 'unit_id' => $i['unit_id'],
            ], $data['items']),
        ];
    }
}
```

注意：`abort(response()->json(...))` 用于在 validateBom 内返回业务错误码（HTTP 200 + 业务 code），与 `$this->fail()` 等效但不依赖 trait 上下文；如 reviewer 认为可读性差，可改为在控制器方法内先调 `validateBom` 再单独判断——**二选一，保持最终实现与测试断言一致即可**（测试只断言 HTTP 200 + `code` 字段）。

- [ ] **Step 4: 注册路由**

修改 `server/routes/api.php`（use 追加 `BomController`；`auth:sanctum` 组内追加）：

```php
    // BOM：CRUD + 明细 + 启用切换（bom.list/create/update/delete）
    Route::middleware('auth:sanctum')->group(function () {
        Route::middleware('permission:bom.list')->get('/boms', [BomController::class, 'index']);
        Route::middleware('permission:bom.create')->post('/boms', [BomController::class, 'store']);
        Route::middleware('permission:bom.update')->put('/boms/{bom}', [BomController::class, 'update']);
        Route::middleware('permission:bom.delete')->delete('/boms/{bom}', [BomController::class, 'destroy']);
        Route::middleware('permission:bom.list')->get('/boms/{bom}/items', [BomController::class, 'items']);
        Route::middleware('permission:bom.update')->put('/boms/{bom}/toggle', [BomController::class, 'toggle']);
    });
```

- [ ] **Step 5: 跑测试确认通过**

Run: `cd server && php artisan test --filter=BomTest`
Expected: 11 个测试 PASS。

- [ ] **Step 6: 提交**

```bash
cd /d/code/project/php-design && git add -A && git commit -m "feat: BOM API（单头+明细/启用版本唯一）"
```

---
## Task 7: 前端基座（API 封装 + 路由 + 菜单 + 设计系统页覆盖）

**Files:**
- Create: `web/src/api/{category,unit,warehouse,supplier,customer,process,product,bom}.ts`
- Create: `web/src/tests/{product.api,bom.api}.test.ts`
- Create: `design-system/nexus-factory/pages/master-data.md`（ui-ux-pro-max 页覆盖：基础资料页面设计规范）
- Modify: `web/src/router/index.ts`、`web/src/layouts/MainLayout.vue`

**Interfaces:**
- Consumes: Task 2-6 后端 API；Task 7 系统管理模块的 `http` 封装与 `auth` store
- Produces: 8 个 API 模块（方法签名见下）；8 条路由 `/master/*`（meta.permission 对应 `{资源}.list`）；侧边栏「基础资料」菜单组（8 项按权限过滤）；`design-system/nexus-factory/pages/master-data.md`（页面卡片容器/类型标签语义色/扫枪聚焦约定，Task 8/9 页面样式依据）

- [ ] **Step 1: 写失败测试**

创建 `web/src/tests/product.api.test.ts`：

```ts
// 商品 API 封装测试：查询参数/创建载荷/扫码查询路径
import { describe, it, expect, vi, beforeEach } from 'vitest'

vi.mock('../api/http', () => ({ http: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() } }))
import { http } from '../api/http'
import { productApi } from '../api/product'

describe('product api', () => {
  beforeEach(() => vi.clearAllMocks())

  it('list 携带分页/关键字/类型/分类参数', async () => {
    // 正常路径：查询参数正确传递
    ;(http.get as any).mockResolvedValue({ data: { code: 0, data: { items: [], total: 0, page: 1, per_page: 10 } } })
    await productApi.list({ page: 2, keyword: 'MAT', type: 'raw_material', category_id: 3 })
    expect(http.get).toHaveBeenCalledWith('/products', { params: { page: 2, per_page: 10, keyword: 'MAT', type: 'raw_material', category_id: 3 } })
  })

  it('create 提交完整商品载荷', async () => {
    // 正常路径：创建请求体结构
    ;(http.post as any).mockResolvedValue({ data: { code: 0 } })
    await productApi.create({ name: '铝材', code: 'MAT-001', type: 'raw_material', category_id: 1, unit_id: 1, spec: '1mm', barcode: '888', safety_min: 10, safety_max: 100, status: 1 })
    expect(http.post).toHaveBeenCalledWith('/products', expect.objectContaining({ code: 'MAT-001', safety_max: 100 }))
  })

  it('byBarcode 命中条码查询路由', async () => {
    // 正常路径：扫码查询路径正确
    ;(http.get as any).mockResolvedValue({ data: { code: 0, data: { name: '成品B' } } })
    await productApi.byBarcode('888888')
    expect(http.get).toHaveBeenCalledWith('/products/barcode/888888')
  })
})
```

创建 `web/src/tests/bom.api.test.ts`：

```ts
// BOM API 封装测试：创建载荷（含明细数组）/明细查询/启用切换
import { describe, it, expect, vi, beforeEach } from 'vitest'

vi.mock('../api/http', () => ({ http: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() } }))
import { http } from '../api/http'
import { bomApi } from '../api/bom'

describe('bom api', () => {
  beforeEach(() => vi.clearAllMocks())

  it('create 提交单头+明细数组', async () => {
    // 正常路径：items 数组原样传递
    ;(http.post as any).mockResolvedValue({ data: { code: 0, data: { id: 1, code: 'BOM20260812-001' } } })
    await bomApi.create({ product_id: 9, version: 'v1', quantity: 1, items: [{ material_id: 5, quantity: 2, unit_id: 1 }] })
    expect(http.post).toHaveBeenCalledWith('/boms', expect.objectContaining({ items: [{ material_id: 5, quantity: 2, unit_id: 1 }] }))
  })

  it('toggle 提交启用状态', async () => {
    // 正常路径：启用切换请求体
    ;(http.put as any).mockResolvedValue({ data: { code: 0 } })
    await bomApi.toggle(3, 1)
    expect(http.put).toHaveBeenCalledWith('/boms/3/toggle', { status: 1 })
  })

  it('items 查询明细列表', async () => {
    // 正常路径：明细查询路径
    ;(http.get as any).mockResolvedValue({ data: { code: 0, data: { items: [] } } })
    await bomApi.items(3)
    expect(http.get).toHaveBeenCalledWith('/boms/3/items')
  })
})
```

- [ ] **Step 2: 跑测试确认失败**

Run: `cd web && npx vitest run src/tests/product.api.test.ts src/tests/bom.api.test.ts`
Expected: FAIL（文件不存在/模块未创建）。

- [ ] **Step 3: 实现 8 个 API 封装**

统一模式（列表返回 `{items,total,page,per_page}`；`list` 携带 `params`；错误由 `http` 响应拦截器抛 `Error(message)`）。以下为完整代码：

`web/src/api/category.ts`：

```ts
// 分类 API 封装：树形列表 + CRUD
import { http } from './http'

export interface CategoryItem {
  id: number
  name: string
  parent_id: number
  sort: number
  status: number
  children?: CategoryItem[]
}

export const categoryApi = {
  // 树形列表（含全部层级，供管理页 el-tree 与商品页 el-tree-select）
  async tree() {
    const { data } = await http.get('/categories')
    return data.data as CategoryItem[]
  },
  // 新建分类
  async create(payload: { name: string; parent_id: number; sort?: number; status?: number }) {
    const { data } = await http.post('/categories', payload)
    return data.data as { id: number }
  },
  // 更新分类
  async update(id: number, payload: { name: string; parent_id: number; sort?: number; status?: number }) {
    await http.put(`/categories/${id}`, payload)
  },
  // 删除分类
  async remove(id: number) {
    await http.delete(`/categories/${id}`)
  },
}
```

`web/src/api/unit.ts`：

```ts
// 单位 API 封装：分页 CRUD（商品弹窗单位下拉复用 list）
import { http } from './http'

export interface UnitItem {
  id: number
  name: string
  code: string
  status: number
}

export const unitApi = {
  // 分页列表（下拉取全量时传 per_page=100）
  async list(params: { page?: number; per_page?: number; keyword?: string }) {
    const { data } = await http.get('/units', { params })
    return data.data as { items: UnitItem[]; total: number; page: number; per_page: number }
  },
  // 新建单位
  async create(payload: { name: string; code: string; status?: number }) {
    await http.post('/units', payload)
  },
  // 更新单位
  async update(id: number, payload: { name: string; code: string; status?: number }) {
    await http.put(`/units/${id}`, payload)
  },
  // 删除单位
  async remove(id: number) {
    await http.delete(`/units/${id}`)
  },
}
```

`web/src/api/warehouse.ts`：

```ts
// 仓库/库位 API 封装：仓库 CRUD + 库位子资源
import { http } from './http'

export interface WarehouseItem {
  id: number
  name: string
  code: string
  address: string | null
  manager: string | null
  status: number
}

export interface LocationItem {
  id: number
  name: string
  code: string
  status: number
}

export const warehouseApi = {
  // 仓库分页列表
  async list(params: { page?: number; per_page?: number; keyword?: string; status?: number }) {
    const { data } = await http.get('/warehouses', { params })
    return data.data as { items: WarehouseItem[]; total: number; page: number; per_page: number }
  },
  // 新建仓库
  async create(payload: { name: string; code: string; address?: string; manager?: string; status?: number }) {
    await http.post('/warehouses', payload)
  },
  // 更新仓库
  async update(id: number, payload: { name: string; code: string; address?: string; manager?: string; status?: number }) {
    await http.put(`/warehouses/${id}`, payload)
  },
  // 删除仓库
  async remove(id: number) {
    await http.delete(`/warehouses/${id}`)
  },
  // 仓库下库位列表（全量）
  async locations(warehouseId: number) {
    const { data } = await http.get(`/warehouses/${warehouseId}/locations`)
    return data.data as { items: LocationItem[] }
  },
  // 新建库位
  async createLocation(warehouseId: number, payload: { name: string; code: string; status?: number }) {
    await http.post(`/warehouses/${warehouseId}/locations`, payload)
  },
  // 更新库位
  async updateLocation(id: number, payload: { name: string; code: string; status?: number }) {
    await http.put(`/locations/${id}`, payload)
  },
  // 删除库位
  async removeLocation(id: number) {
    await http.delete(`/locations/${id}`)
  },
}
```

`web/src/api/supplier.ts` / `web/src/api/customer.ts`（同构，字段 name/code/contact/phone/address/remark/status，路径 `/suppliers`/`/customers`，list 支持 `keyword/status`）：

```ts
// 供应商 API 封装：分页 CRUD + 关键字搜索
import { http } from './http'

export interface SupplierItem {
  id: number
  name: string
  code: string
  contact: string | null
  phone: string | null
  address: string | null
  remark: string | null
  status: number
}

export const supplierApi = {
  // 分页列表（名称/编码/联系人模糊搜索）
  async list(params: { page?: number; per_page?: number; keyword?: string; status?: number }) {
    const { data } = await http.get('/suppliers', { params })
    return data.data as { items: SupplierItem[]; total: number; page: number; per_page: number }
  },
  // 新建供应商
  async create(payload: { name: string; code: string; contact?: string; phone?: string; address?: string; remark?: string; status?: number }) {
    await http.post('/suppliers', payload)
  },
  // 更新供应商
  async update(id: number, payload: { name: string; code: string; contact?: string; phone?: string; address?: string; remark?: string; status?: number }) {
    await http.put(`/suppliers/${id}`, payload)
  },
  // 删除供应商
  async remove(id: number) {
    await http.delete(`/suppliers/${id}`)
  },
}
```

`web/src/api/process.ts`：

```ts
// 工序 API 封装：全量列表（sort 升序）+ CRUD
import { http } from './http'

export interface ProcessItem {
  id: number
  name: string
  code: string
  sort: number
  description: string | null
  status: number
}

export const processApi = {
  // 全量列表（sort 升序，供管理页与生产模块下拉）
  async list() {
    const { data } = await http.get('/processes')
    return data.data as { items: ProcessItem[] }
  },
  // 新建工序
  async create(payload: { name: string; code: string; sort?: number; description?: string; status?: number }) {
    await http.post('/processes', payload)
  },
  // 更新工序
  async update(id: number, payload: { name: string; code: string; sort?: number; description?: string; status?: number }) {
    await http.put(`/processes/${id}`, payload)
  },
  // 删除工序
  async remove(id: number) {
    await http.delete(`/processes/${id}`)
  },
}
```

`web/src/api/product.ts`：

```ts
// 商品 API 封装：分页筛选 + CRUD + 扫码查询
import { http } from './http'

export type ProductType = 'raw_material' | 'semi_finished' | 'finished'

export interface ProductItem {
  id: number
  name: string
  code: string
  type: ProductType
  type_label: string
  category_id: number
  category_name: string | null
  unit_id: number
  unit_name: string | null
  spec: string | null
  barcode: string | null
  safety_min: number
  safety_max: number
  status: number
}

export const productApi = {
  // 分页列表（编码/名称/条码模糊 + 类型/分类/状态过滤）
  async list(params: { page?: number; per_page?: number; keyword?: string; type?: ProductType; category_id?: number; status?: number }) {
    const { data } = await http.get('/products', { params })
    return data.data as { items: ProductItem[]; total: number; page: number; per_page: number }
  },
  // 新建商品
  async create(payload: { name: string; code: string; type: ProductType; category_id: number; unit_id: number; spec?: string; barcode?: string; safety_min?: number; safety_max?: number; status?: number; remark?: string }) {
    await http.post('/products', payload)
  },
  // 更新商品
  async update(id: number, payload: { name: string; code: string; type: ProductType; category_id: number; unit_id: number; spec?: string; barcode?: string; safety_min?: number; safety_max?: number; status?: number; remark?: string }) {
    await http.put(`/products/${id}`, payload)
  },
  // 删除商品
  async remove(id: number) {
    await http.delete(`/products/${id}`)
  },
  // 扫码查询（扫枪场景）
  async byBarcode(barcode: string) {
    const { data } = await http.get(`/products/barcode/${barcode}`)
    return data.data as { id: number; name: string; code: string; type: ProductType; spec: string | null; unit_name: string | null }
  },
}
```

`web/src/api/bom.ts`：

```ts
// BOM API 封装：单头+明细 CRUD + 启用切换 + 明细查询
import { http } from './http'

export interface BomItem {
  id: number
  material_id: number
  material_name: string
  quantity: number
  unit_id: number
  unit_name: string
}

export interface BomRow {
  id: number
  code: string
  product_id: number
  product_name: string | null
  version: string
  quantity: number
  status: number
  remark: string | null
}

export const bomApi = {
  // 分页列表（成品/单号过滤）
  async list(params: { page?: number; per_page?: number; product_id?: number; keyword?: string }) {
    const { data } = await http.get('/boms', { params })
    return data.data as { items: BomRow[]; total: number; page: number; per_page: number }
  },
  // 新建（单头+明细一次提交；status 缺省=启用）
  async create(payload: { product_id: number; version: string; quantity?: number; remark?: string; status?: number; items: { material_id: number; quantity: number; unit_id: number }[] }) {
    const { data } = await http.post('/boms', payload)
    return data.data as { id: number; code: string }
  },
  // 更新（明细全量替换）
  async update(id: number, payload: { product_id: number; version: string; quantity?: number; remark?: string; status?: number; items: { material_id: number; quantity: number; unit_id: number }[] }) {
    await http.put(`/boms/${id}`, payload)
  },
  // 删除 BOM
  async remove(id: number) {
    await http.delete(`/boms/${id}`)
  },
  // 明细列表（物料名/单位名联查）
  async items(id: number) {
    const { data } = await http.get(`/boms/${id}/items`)
    return data.data as { items: BomItem[] }
  },
  // 启用/停用切换
  async toggle(id: number, status: number) {
    await http.put(`/boms/${id}/toggle`, { status })
  },
}
```

- [ ] **Step 4: 注册路由与菜单**

修改 `web/src/router/index.ts`（`children` 内、403 前追加）：

```ts
        { path: 'master/categories', name: 'master-categories', component: () => import('../views/master/CategoriesView.vue'), meta: { permission: 'category.list' } },
        { path: 'master/units', name: 'master-units', component: () => import('../views/master/UnitsView.vue'), meta: { permission: 'unit.list' } },
        { path: 'master/warehouses', name: 'master-warehouses', component: () => import('../views/master/WarehousesView.vue'), meta: { permission: 'warehouse.list' } },
        { path: 'master/suppliers', name: 'master-suppliers', component: () => import('../views/master/SuppliersView.vue'), meta: { permission: 'supplier.list' } },
        { path: 'master/customers', name: 'master-customers', component: () => import('../views/master/CustomersView.vue'), meta: { permission: 'customer.list' } },
        { path: 'master/processes', name: 'master-processes', component: () => import('../views/master/ProcessesView.vue'), meta: { permission: 'process.list' } },
        { path: 'master/products', name: 'master-products', component: () => import('../views/master/ProductsView.vue'), meta: { permission: 'product.list' } },
        { path: 'master/boms', name: 'master-boms', component: () => import('../views/master/BomsView.vue'), meta: { permission: 'bom.list' } },
```

修改 `web/src/layouts/MainLayout.vue`（「系统管理」组后追加「基础资料」组，8 项均按权限过滤）：

```html
        <div class="menu-group">基础资料</div>
        <RouterLink v-if="auth.has('product.list')" to="/master/products" class="menu-item">商品管理</RouterLink>
        <RouterLink v-if="auth.has('category.list')" to="/master/categories" class="menu-item">分类管理</RouterLink>
        <RouterLink v-if="auth.has('unit.list')" to="/master/units" class="menu-item">单位管理</RouterLink>
        <RouterLink v-if="auth.has('warehouse.list')" to="/master/warehouses" class="menu-item">仓库管理</RouterLink>
        <RouterLink v-if="auth.has('supplier.list')" to="/master/suppliers" class="menu-item">供应商管理</RouterLink>
        <RouterLink v-if="auth.has('customer.list')" to="/master/customers" class="menu-item">客户管理</RouterLink>
        <RouterLink v-if="auth.has('bom.list')" to="/master/boms" class="menu-item">BOM 管理</RouterLink>
        <RouterLink v-if="auth.has('process.list')" to="/master/processes" class="menu-item">工序管理</RouterLink>
```

- [ ] **Step 5: 落地设计系统页覆盖（ui-ux-pro-max）**

创建 `design-system/nexus-factory/pages/master-data.md`（内容为 Task 8/9 页面的设计依据，实现时严格遵循）：

```markdown
# Page Override: Master Data（基础资料模块页面）

> 依据 ui-ux-pro-max 检索（UX: table-handling / form-validation / submit-feedback / error-feedback / z-index 层级）与 MASTER.md 设计系统生成。

## 页面通用规范

- 页面结构：`页面标题（h2, Fira Sans 600）+ 白色卡片容器 .page-card（radius 8px、--shadow-sm、padding 20px）`；工具栏在卡片顶部，表格在卡片内
- 工具栏：搜索输入框（220px，clearable，Enter 触发）+「新 建」主按钮（.btn-primary 语义色 #059669）
- 表格：`el-table` + `el-pagination`（每页 10，layout: total, prev, pager, next）
- 弹窗：新建/编辑统一 `el-dialog`；表单项全部 `el-form-item label` 可见标签（禁止 placeholder-only）；提交按钮 `:loading` 反馈；保存成功 `ElMessage.success`、后端业务错误 `ElMessage.error` 就近提示
- 状态标签：启用 `el-tag type="success"`、停用 `el-tag type="info"`（与系统管理模块一致）
- 交互反馈：所有可点击元素 `cursor:pointer`；hover 过渡 150-300ms；焦点可见（不关闭 Element Plus 默认 focus 样式）；z-index 只用 Element Plus 默认层级，不写任意大值
- 删除：`ElMessageBox.confirm` 二次确认；取消/关闭静默返回（catch `'cancel'/'close'`），不产生未处理 rejection
- 图标：禁用 emoji，统一使用 `@element-plus/icons-vue` 或 Element Plus 内置图标

## 类型标签语义色（商品页）

| 类型 | 标签样式 |
|---|---|
| 原料 raw_material | 蓝 `#3B82F6`（`.tag-raw`） |
| 半成品 semi_finished | 琥珀 `#D97706`（`.tag-semi`） |
| 成品 finished | 绿 `#059669`（`.tag-fin`） |

```css
.tag-raw { background: rgba(59, 130, 246, 0.12); color: #2563EB; border: 1px solid rgba(59, 130, 246, 0.3); border-radius: 4px; padding: 2px 8px; font-size: 12px; }
.tag-semi { background: rgba(217, 119, 6, 0.12); color: #D97706; border: 1px solid rgba(217, 119, 6, 0.3); border-radius: 4px; padding: 2px 8px; font-size: 12px; }
.tag-fin { background: rgba(5, 150, 105, 0.12); color: #059669; border: 1px solid rgba(5, 150, 105, 0.3); border-radius: 4px; padding: 2px 8px; font-size: 12px; }
```

## 扫枪交互（商品页）

- 新建弹窗条码输入框 `autofocus`；聚焦条码框输入后按 Enter → 触发 `productApi.byBarcode` 即时校验：命中 → `ElMessage.success('条码匹配：{name}')` 并回填编码/名称；未命中 → `ElMessage.error('条码未匹配到商品')` 且**不清空输入**（便于修正重扫）
- 命中信息同时展示在弹窗内（如条码框下方提示行），满足 E2E TC-MST-08「页面显示匹配商品信息」

## 安全库存校验（商品页）

- 表单规则：`safety_max > 0 && safety_min > safety_max` → 前端拦截 `ElMessage.error('安全库存下限不能大于上限')` 且**不发请求**（与后端 1122 双保险）
```

- [ ] **Step 6: 跑前端测试确认通过**

Run: `cd web && npx vitest run src/tests/product.api.test.ts src/tests/bom.api.test.ts`
Expected: 6 个测试 PASS。

- [ ] **Step 7: 提交**

```bash
cd /d/code/project/php-design && git add -A && git commit -m "feat: 基础资料前端 API 封装/路由/菜单/设计系统页覆盖"
```

---

## Task 8: 前端页面 A（分类树/单位/供应商/客户/工序）

**Files:**
- Create: `web/src/views/master/{CategoriesView,UnitsView,SuppliersView,CustomersView,ProcessesView}.vue`
- Modify: 无（路由已在 Task 7 注册）

**Interfaces:**
- Consumes: Task 7 的 API 封装与设计系统页覆盖
- Produces: 5 个页面，交互行为对齐 E2E 文档（按钮文案「新 建/保 存/编 辑/删 除」、错误提示、状态标签、分类树）

- [ ] **Step 1: 实现 CategoriesView.vue（el-tree 树形 + 新建/编辑/删除）**

```vue
<!-- 分类管理页：树形展示 + 新建/编辑弹窗 + 删除保护提示 -->
<template>
  <div class="page-card">
    <div class="toolbar">
      <span class="page-title">分类管理</span>
      <el-button v-if="auth.has('category.create')" class="btn-primary" @click="openCreate()">新 建</el-button>
    </div>
    <el-tree :data="tree" :props="{ label: 'name', children: 'children' }" default-expand-all node-key="id">
      <template #default="{ data }">
        <div class="tree-node">
          <span>{{ data.name }}</span>
          <span class="tree-actions">
            <el-button v-if="auth.has('category.update')" link type="primary" @click.stop="openEdit(data)">编 辑</el-button>
            <el-button v-if="auth.has('category.delete')" link type="danger" :disabled="hasChildren(data)" @click.stop="remove(data)">删 除</el-button>
          </span>
        </div>
      </template>
    </el-tree>

    <!-- 新建/编辑弹窗：上级分类下拉（仅顶级 + 根） -->
    <el-dialog v-model="dialogVisible" :title="form.id ? '编辑分类' : '新建分类'" width="420px">
      <el-form :model="form" label-width="80px">
        <el-form-item label="上级分类">
          <el-select v-model="form.parent_id" style="width: 100%">
            <el-option :label="'无（顶级分类）'" :value="0" />
            <el-option v-for="c in topLevel" :key="c.id" :label="c.name" :value="c.id" />
          </el-select>
        </el-form-item>
        <el-form-item label="名称" required><el-input v-model="form.name" /></el-form-item>
        <el-form-item label="排序"><el-input-number v-model="form.sort" :min="0" /></el-form-item>
        <el-form-item label="状态"><el-switch v-model="form.status" :active-value="1" :inactive-value="0" /></el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="dialogVisible = false">取 消</el-button>
        <el-button type="primary" class="btn-primary" :loading="saving" @click="save">保 存</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup lang="ts">
// 分类管理页：树形加载/新建/编辑/删除；含子分类禁用删除按钮，后端 1101/1102 双保险
import { computed, onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { categoryApi, type CategoryItem } from '../../api/category'
import { useAuthStore } from '../../stores/auth'

const auth = useAuthStore()
const tree = ref<CategoryItem[]>([])
const dialogVisible = ref(false)
const saving = ref(false)
const form = reactive<any>({})

// 顶级分类列表（上级下拉数据源）
const topLevel = computed(() => tree.value.map((n) => ({ id: n.id, name: n.name })))

// 含子分类时禁用删除（后端 1101 双保险）
function hasChildren(node: CategoryItem) {
  return !!node.children?.length
}

// 加载树
async function load() {
  tree.value = await categoryApi.tree()
}

// 新建（默认顶级）
function openCreate(parentId = 0) {
  Object.assign(form, { id: null, name: '', parent_id: parentId, sort: 0, status: 1 })
  dialogVisible.value = true
}

// 编辑回填
function openEdit(node: CategoryItem) {
  Object.assign(form, { id: node.id, name: node.name, parent_id: node.parent_id, sort: node.sort, status: node.status })
  dialogVisible.value = true
}

// 保存：失败展示后端业务错误（1101/1102/1124）
async function save() {
  if (!form.name) return ElMessage.warning('请输入分类名称')
  saving.value = true
  try {
    if (form.id) await categoryApi.update(form.id, { name: form.name, parent_id: form.parent_id, sort: form.sort, status: form.status })
    else await categoryApi.create({ name: form.name, parent_id: form.parent_id, sort: form.sort, status: form.status })
    ElMessage.success('保存成功')
    dialogVisible.value = false
    load()
  } catch (e) {
    ElMessage.error((e as Error).message)
  } finally {
    saving.value = false
  }
}

// 删除：二次确认；取消/关闭静默返回
async function remove(node: CategoryItem) {
  try {
    await ElMessageBox.confirm(`确定删除分类「${node.name}」？`, '提示', { type: 'warning' })
  } catch {
    return
  }
  try {
    await categoryApi.remove(node.id)
    ElMessage.success('删除成功')
    load()
  } catch (e) {
    ElMessage.error((e as Error).message)
  }
}

onMounted(load)
</script>

<style scoped>
/* 树节点行内操作：hover 显示，点击不冒泡到节点选中 */
.page-card { background: #fff; border-radius: 8px; box-shadow: var(--shadow-sm); padding: var(--space-2xl); }
.toolbar { display: flex; justify-content: space-between; align-items: center; margin-bottom: var(--space-xl); }
.page-title { font-size: 18px; font-weight: 600; color: var(--color-foreground); }
.btn-primary { background: var(--color-accent); border-color: var(--color-accent); cursor: pointer; }
.tree-node { display: flex; justify-content: space-between; align-items: center; flex: 1; padding-right: var(--space-lg); }
.tree-actions { visibility: hidden; }
.tree-node:hover .tree-actions { visibility: visible; }
</style>
```

- [ ] **Step 2: 实现 UnitsView.vue（简单 CRUD 列表）**

```vue
<!-- 单位管理页：列表 + 新建/编辑弹窗 + 删除确认 -->
<template>
  <div class="page-card">
    <div class="toolbar">
      <span class="page-title">单位管理</span>
      <el-button v-if="auth.has('unit.create')" class="btn-primary" @click="openCreate">新 建</el-button>
    </div>
    <el-table :data="rows" v-loading="loading">
      <el-table-column prop="id" label="ID" width="70" />
      <el-table-column prop="name" label="名称" />
      <el-table-column prop="code" label="编码" class-name="font-code" />
      <el-table-column label="状态" width="100">
        <template #default="{ row }">
          <el-tag :type="row.status === 1 ? 'success' : 'info'">{{ row.status === 1 ? '启用' : '停用' }}</el-tag>
        </template>
      </el-table-column>
      <el-table-column label="操作" width="160" fixed="right">
        <template #default="{ row }">
          <el-button v-if="auth.has('unit.update')" link type="primary" @click="openEdit(row)">编 辑</el-button>
          <el-button v-if="auth.has('unit.delete')" link type="danger" @click="remove(row)">删 除</el-button>
        </template>
      </el-table-column>
    </el-table>
    <el-pagination v-model:current-page="query.page" :total="total" :page-size="10" layout="total, prev, pager, next" @current-change="load" />

    <el-dialog v-model="dialogVisible" :title="form.id ? '编辑单位' : '新建单位'" width="420px">
      <el-form :model="form" label-width="80px">
        <el-form-item label="名称" required><el-input v-model="form.name" /></el-form-item>
        <el-form-item label="编码" required><el-input v-model="form.code" /></el-form-item>
        <el-form-item label="状态"><el-switch v-model="form.status" :active-value="1" :inactive-value="0" /></el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="dialogVisible = false">取 消</el-button>
        <el-button type="primary" class="btn-primary" :loading="saving" @click="save">保 存</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup lang="ts">
// 单位管理页：CRUD；删除被商品引用时展示后端 1104 提示
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { unitApi, type UnitItem } from '../../api/unit'
import { useAuthStore } from '../../stores/auth'

const auth = useAuthStore()
const rows = ref<UnitItem[]>([])
const total = ref(0)
const loading = ref(false)
const query = reactive({ page: 1 })
const dialogVisible = ref(false)
const saving = ref(false)
const form = reactive<any>({})

// 加载列表
async function load() {
  loading.value = true
  try {
    const res = await unitApi.list({ page: query.page, per_page: 10 })
    rows.value = res.items
    total.value = res.total
  } finally {
    loading.value = false
  }
}

function openCreate() {
  Object.assign(form, { id: null, name: '', code: '', status: 1 })
  dialogVisible.value = true
}
function openEdit(row: UnitItem) {
  Object.assign(form, { id: row.id, name: row.name, code: row.code, status: row.status })
  dialogVisible.value = true
}

// 保存：新建/编辑；后端 1103 重复编码提示展示
async function save() {
  if (!form.name || !form.code) return ElMessage.warning('请填写名称与编码')
  saving.value = true
  try {
    if (form.id) await unitApi.update(form.id, { name: form.name, code: form.code, status: form.status })
    else await unitApi.create({ name: form.name, code: form.code, status: form.status })
    ElMessage.success('保存成功')
    dialogVisible.value = false
    load()
  } catch (e) {
    ElMessage.error((e as Error).message)
  } finally {
    saving.value = false
  }
}

// 删除：二次确认；被商品引用时后端 1104 提示
async function remove(row: UnitItem) {
  try {
    await ElMessageBox.confirm(`确定删除单位「${row.name}」？`, '提示', { type: 'warning' })
  } catch {
    return
  }
  try {
    await unitApi.remove(row.id)
    ElMessage.success('删除成功')
    load()
  } catch (e) {
    ElMessage.error((e as Error).message)
  }
}

onMounted(load)
</script>

<style scoped>
/* 与 CategoriesView 相同页面骨架（工具栏/标题/主按钮），页面间保持一致 */
.page-card { background: #fff; border-radius: 8px; box-shadow: var(--shadow-sm); padding: var(--space-2xl); }
.toolbar { display: flex; justify-content: space-between; align-items: center; margin-bottom: var(--space-xl); }
.page-title { font-size: 18px; font-weight: 600; color: var(--color-foreground); }
.btn-primary { background: var(--color-accent); border-color: var(--color-accent); cursor: pointer; }
</style>
```

- [ ] **Step 3: 实现 SuppliersView.vue / CustomersView.vue（同构：搜索 + CRUD）**

```vue
<!-- 供应商管理页：关键字搜索 + 列表 + 新建/编辑弹窗 + 删除确认 -->
<template>
  <div class="page-card">
    <div class="toolbar">
      <span class="page-title">供应商管理</span>
      <div class="toolbar-right">
        <el-input v-model="query.keyword" placeholder="名称/编码/联系人" clearable style="width: 220px" @keyup.enter="load" />
        <el-button v-if="auth.has('supplier.create')" class="btn-primary" @click="openCreate">新 建</el-button>
      </div>
    </div>
    <el-table :data="rows" v-loading="loading">
      <el-table-column prop="code" label="编码" width="120" class-name="font-code" />
      <el-table-column prop="name" label="名称" />
      <el-table-column prop="contact" label="联系人" width="100" />
      <el-table-column prop="phone" label="电话" width="140" />
      <el-table-column prop="address" label="地址" show-overflow-tooltip />
      <el-table-column label="状态" width="90">
        <template #default="{ row }">
          <el-tag :type="row.status === 1 ? 'success' : 'info'">{{ row.status === 1 ? '启用' : '停用' }}</el-tag>
        </template>
      </el-table-column>
      <el-table-column label="操作" width="160" fixed="right">
        <template #default="{ row }">
          <el-button v-if="auth.has('supplier.update')" link type="primary" @click="openEdit(row)">编 辑</el-button>
          <el-button v-if="auth.has('supplier.delete')" link type="danger" @click="remove(row)">删 除</el-button>
        </template>
      </el-table-column>
    </el-table>
    <el-pagination v-model:current-page="query.page" :total="total" :page-size="10" layout="total, prev, pager, next" @current-change="load" />

    <el-dialog v-model="dialogVisible" :title="form.id ? '编辑供应商' : '新建供应商'" width="520px">
      <el-form :model="form" label-width="80px">
        <el-form-item label="名称" required><el-input v-model="form.name" /></el-form-item>
        <el-form-item label="编码" required><el-input v-model="form.code" /></el-form-item>
        <el-form-item label="联系人"><el-input v-model="form.contact" /></el-form-item>
        <el-form-item label="电话"><el-input v-model="form.phone" /></el-form-item>
        <el-form-item label="地址"><el-input v-model="form.address" /></el-form-item>
        <el-form-item label="备注"><el-input v-model="form.remark" type="textarea" :rows="2" /></el-form-item>
        <el-form-item label="状态"><el-switch v-model="form.status" :active-value="1" :inactive-value="0" /></el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="dialogVisible = false">取 消</el-button>
        <el-button type="primary" class="btn-primary" :loading="saving" @click="save">保 存</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup lang="ts">
// 供应商管理页：搜索 + CRUD；删除被采购单据引用时后端 1109 提示
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { supplierApi, type SupplierItem } from '../../api/supplier'
import { useAuthStore } from '../../stores/auth'

const auth = useAuthStore()
const rows = ref<SupplierItem[]>([])
const total = ref(0)
const loading = ref(false)
const query = reactive({ page: 1, keyword: '' })
const dialogVisible = ref(false)
const saving = ref(false)
const form = reactive<any>({})

// 加载列表：携带分页与关键字
async function load() {
  loading.value = true
  try {
    const res = await supplierApi.list({ page: query.page, per_page: 10, keyword: query.keyword })
    rows.value = res.items
    total.value = res.total
  } finally {
    loading.value = false
  }
}

function openCreate() {
  Object.assign(form, { id: null, name: '', code: '', contact: '', phone: '', address: '', remark: '', status: 1 })
  dialogVisible.value = true
}
function openEdit(row: SupplierItem) {
  Object.assign(form, { id: row.id, name: row.name, code: row.code, contact: row.contact, phone: row.phone, address: row.address, remark: row.remark, status: row.status })
  dialogVisible.value = true
}

// 保存：新建/编辑；后端 1108 重复编码提示展示
async function save() {
  if (!form.name || !form.code) return ElMessage.warning('请填写名称与编码')
  saving.value = true
  try {
    if (form.id) await supplierApi.update(form.id, { name: form.name, code: form.code, contact: form.contact, phone: form.phone, address: form.address, remark: form.remark, status: form.status })
    else await supplierApi.create({ name: form.name, code: form.code, contact: form.contact, phone: form.phone, address: form.address, remark: form.remark, status: form.status })
    ElMessage.success('保存成功')
    dialogVisible.value = false
    load()
  } catch (e) {
    ElMessage.error((e as Error).message)
  } finally {
    saving.value = false
  }
}

// 删除：二次确认；被引用时后端提示
async function remove(row: SupplierItem) {
  try {
    await ElMessageBox.confirm(`确定删除供应商「${row.name}」？`, '提示', { type: 'warning' })
  } catch {
    return
  }
  try {
    await supplierApi.remove(row.id)
    ElMessage.success('删除成功')
    load()
  } catch (e) {
    ElMessage.error((e as Error).message)
  }
}

onMounted(load)
</script>

<style scoped>
/* 页面骨架同上（page-card/toolbar/page-title/btn-primary） */
.page-card { background: #fff; border-radius: 8px; box-shadow: var(--shadow-sm); padding: var(--space-2xl); }
.toolbar { display: flex; justify-content: space-between; align-items: center; margin-bottom: var(--space-xl); }
.toolbar-right { display: flex; gap: var(--space-lg); align-items: center; }
.page-title { font-size: 18px; font-weight: 600; color: var(--color-foreground); }
.btn-primary { background: var(--color-accent); border-color: var(--color-accent); cursor: pointer; }
</style>
```

`CustomersView.vue` 同构：`customerApi`、`/customers`、字段一致、标题「客户管理」、权限码 `customer.*`。

- [ ] **Step 4: 实现 ProcessesView.vue（含排序）**

```vue
<!-- 工序管理页：全量列表（sort 升序）+ 新建/编辑弹窗 + 删除确认 -->
<template>
  <div class="page-card">
    <div class="toolbar">
      <span class="page-title">工序管理</span>
      <el-button v-if="auth.has('process.create')" class="btn-primary" @click="openCreate">新 建</el-button>
    </div>
    <el-table :data="rows" v-loading="loading">
      <el-table-column prop="sort" label="排序" width="80" />
      <el-table-column prop="name" label="名称" />
      <el-table-column prop="code" label="编码" class-name="font-code" />
      <el-table-column prop="description" label="说明" show-overflow-tooltip />
      <el-table-column label="状态" width="90">
        <template #default="{ row }">
          <el-tag :type="row.status === 1 ? 'success' : 'info'">{{ row.status === 1 ? '启用' : '停用' }}</el-tag>
        </template>
      </el-table-column>
      <el-table-column label="操作" width="160" fixed="right">
        <template #default="{ row }">
          <el-button v-if="auth.has('process.update')" link type="primary" @click="openEdit(row)">编 辑</el-button>
          <el-button v-if="auth.has('process.delete')" link type="danger" @click="remove(row)">删 除</el-button>
        </template>
      </el-table-column>
    </el-table>

    <el-dialog v-model="dialogVisible" :title="form.id ? '编辑工序' : '新建工序'" width="480px">
      <el-form :model="form" label-width="80px">
        <el-form-item label="名称" required><el-input v-model="form.name" /></el-form-item>
        <el-form-item label="编码" required><el-input v-model="form.code" /></el-form-item>
        <el-form-item label="排序"><el-input-number v-model="form.sort" :min="0" /></el-form-item>
        <el-form-item label="说明"><el-input v-model="form.description" type="textarea" :rows="2" /></el-form-item>
        <el-form-item label="状态"><el-switch v-model="form.status" :active-value="1" :inactive-value="0" /></el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="dialogVisible = false">取 消</el-button>
        <el-button type="primary" class="btn-primary" :loading="saving" @click="save">保 存</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup lang="ts">
// 工序管理页：全量 CRUD；排序字段决定生产模块工序下拉顺序
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { processApi, type ProcessItem } from '../../api/process'
import { useAuthStore } from '../../stores/auth'

const auth = useAuthStore()
const rows = ref<ProcessItem[]>([])
const loading = ref(false)
const dialogVisible = ref(false)
const saving = ref(false)
const form = reactive<any>({})

// 加载全量列表（后端已按 sort 升序）
async function load() {
  loading.value = true
  try {
    rows.value = (await processApi.list()).items
  } finally {
    loading.value = false
  }
}

function openCreate() {
  Object.assign(form, { id: null, name: '', code: '', sort: 0, description: '', status: 1 })
  dialogVisible.value = true
}
function openEdit(row: ProcessItem) {
  Object.assign(form, { id: row.id, name: row.name, code: row.code, sort: row.sort, description: row.description, status: row.status })
  dialogVisible.value = true
}

// 保存：新建/编辑；后端 1112 重复编码提示展示
async function save() {
  if (!form.name || !form.code) return ElMessage.warning('请填写名称与编码')
  saving.value = true
  try {
    if (form.id) await processApi.update(form.id, { name: form.name, code: form.code, sort: form.sort, description: form.description, status: form.status })
    else await processApi.create({ name: form.name, code: form.code, sort: form.sort, description: form.description, status: form.status })
    ElMessage.success('保存成功')
    dialogVisible.value = false
    load()
  } catch (e) {
    ElMessage.error((e as Error).message)
  } finally {
    saving.value = false
  }
}

// 删除：二次确认；被工单引用时后端 1113 提示
async function remove(row: ProcessItem) {
  try {
    await ElMessageBox.confirm(`确定删除工序「${row.name}」？`, '提示', { type: 'warning' })
  } catch {
    return
  }
  try {
    await processApi.remove(row.id)
    ElMessage.success('删除成功')
    load()
  } catch (e) {
    ElMessage.error((e as Error).message)
  }
}

onMounted(load)
</script>

<style scoped>
/* 页面骨架同上 */
.page-card { background: #fff; border-radius: 8px; box-shadow: var(--shadow-sm); padding: var(--space-2xl); }
.toolbar { display: flex; justify-content: space-between; align-items: center; margin-bottom: var(--space-xl); }
.page-title { font-size: 18px; font-weight: 600; color: var(--color-foreground); }
.btn-primary { background: var(--color-accent); border-color: var(--color-accent); cursor: pointer; }
</style>
```

- [ ] **Step 5: 跑前端全量测试 + 构建**

Run: `cd web && npx vitest run && npm run build`
Expected: 全部 PASS（auth 4 + user 2 + product 3 + bom 3 = 12）；构建无 TS 报错。

- [ ] **Step 6: 提交**

```bash
cd /d/code/project/php-design && git add -A && git commit -m "feat: 基础资料页面 A（分类树/单位/供应商/客户/工序）"
```

---
## Task 9: 前端页面 B（商品/仓库+库位弹窗/BOM）

**Files:**
- Create: `web/src/views/master/{ProductsView,WarehousesView,BomsView}.vue`
- Modify: 无（路由已在 Task 7 注册）

**Interfaces:**
- Consumes: Task 7 的 API 封装与设计系统页覆盖（类型标签语义色、扫枪交互、安全库存校验）
- Produces: 3 个页面；交互对齐 E2E 文档 TC-MST-03（库位弹窗）、TC-MST-07/08（商品+扫码）、TC-MST-09/10（BOM 弹窗与启用切换）

- [ ] **Step 1: 实现 ProductsView.vue（筛选 + 扫码 + 类型联动）**

```vue
<!-- 商品管理页：筛选列表 + 新建/编辑弹窗（条码扫枪自动聚焦）+ 类型标签语义色 -->
<template>
  <div class="page-card">
    <div class="toolbar">
      <span class="page-title">商品管理</span>
      <div class="toolbar-right">
        <el-input v-model="query.keyword" placeholder="编码/名称/条码" clearable style="width: 220px" @keyup.enter="load" />
        <el-select v-model="query.type" placeholder="类型" clearable style="width: 130px" @change="load">
          <el-option label="原料" value="raw_material" />
          <el-option label="半成品" value="semi_finished" />
          <el-option label="成品" value="finished" />
        </el-select>
        <el-button v-if="auth.has('product.create')" class="btn-primary" @click="openCreate">新 建</el-button>
      </div>
    </div>
    <el-table :data="rows" v-loading="loading">
      <el-table-column prop="code" label="编码" width="120" class-name="font-code" />
      <el-table-column prop="name" label="名称" min-width="140" />
      <el-table-column label="类型" width="90">
        <template #default="{ row }">
          <span :class="typeTagClass(row.type)">{{ row.type_label }}</span>
        </template>
      </el-table-column>
      <el-table-column prop="category_name" label="分类" width="110" />
      <el-table-column prop="spec" label="规格" width="100" />
      <el-table-column prop="unit_name" label="单位" width="70" />
      <el-table-column prop="barcode" label="条码" width="110" class-name="font-code" />
      <el-table-column label="安全库存" width="130">
        <template #default="{ row }">{{ row.safety_min }} ~ {{ row.safety_max }}</template>
      </el-table-column>
      <el-table-column label="状态" width="80">
        <template #default="{ row }">
          <el-tag :type="row.status === 1 ? 'success' : 'info'">{{ row.status === 1 ? '启用' : '停用' }}</el-tag>
        </template>
      </el-table-column>
      <el-table-column label="操作" width="160" fixed="right">
        <template #default="{ row }">
          <el-button v-if="auth.has('product.update')" link type="primary" @click="openEdit(row)">编 辑</el-button>
          <el-button v-if="auth.has('product.delete')" link type="danger" @click="remove(row)">删 除</el-button>
        </template>
      </el-table-column>
    </el-table>
    <el-pagination v-model:current-page="query.page" :total="total" :page-size="10" layout="total, prev, pager, next" @current-change="load" />

    <!-- 新建/编辑弹窗：条码框自动聚焦；扫枪回车即时校验 -->
    <el-dialog v-model="dialogVisible" :title="form.id ? '编辑商品' : '新建商品'" width="600px" @opened="focusBarcode">
      <el-form :model="form" label-width="100px">
        <el-form-item label="名称" required><el-input v-model="form.name" /></el-form-item>
        <el-form-item label="编码" required><el-input v-model="form.code" /></el-form-item>
        <el-form-item label="类型" required>
          <el-radio-group v-model="form.type">
            <el-radio value="raw_material">原料</el-radio>
            <el-radio value="semi_finished">半成品</el-radio>
            <el-radio value="finished">成品</el-radio>
          </el-radio-group>
          <div v-if="form.type === 'finished'" class="hint">成品可为其维护 BOM（基础资料 → BOM 管理）</div>
        </el-form-item>
        <el-form-item label="分类" required>
          <el-tree-select v-model="form.category_id" :data="categoryTree" :props="{ label: 'name', children: 'children' }" check-strictly style="width: 100%" placeholder="选择分类" />
        </el-form-item>
        <el-form-item label="单位" required>
          <el-select v-model="form.unit_id" style="width: 100%">
            <el-option v-for="u in units" :key="u.id" :label="u.name" :value="u.id" />
          </el-select>
        </el-form-item>
        <el-form-item label="规格"><el-input v-model="form.spec" /></el-form-item>
        <el-form-item label="条码">
          <el-input ref="barcodeRef" v-model="form.barcode" placeholder="扫码枪输入后回车" clearable @keyup.enter="scanBarcode" />
          <div v-if="scanHint" class="hint">{{ scanHint }}</div>
        </el-form-item>
        <el-form-item label="安全库存下限"><el-input-number v-model="form.safety_min" :min="0" /></el-form-item>
        <el-form-item label="安全库存上限"><el-input-number v-model="form.safety_max" :min="0" /></el-form-item>
        <el-form-item label="状态"><el-switch v-model="form.status" :active-value="1" :inactive-value="0" /></el-form-item>
        <el-form-item label="备注"><el-input v-model="form.remark" type="textarea" :rows="2" /></el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="dialogVisible = false">取 消</el-button>
        <el-button type="primary" class="btn-primary" :loading="saving" @click="save">保 存</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup lang="ts">
// 商品管理页：筛选/CRUD/扫码；类型联动提示；安全库存 min>max 前端拦截（后端 1122 双保险）
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { categoryApi, type CategoryItem } from '../../api/category'
import { productApi, type ProductItem, type ProductType } from '../../api/product'
import { unitApi, type UnitItem } from '../../api/unit'
import { useAuthStore } from '../../stores/auth'

const auth = useAuthStore()
const rows = ref<ProductItem[]>([])
const total = ref(0)
const loading = ref(false)
const query = reactive({ page: 1, keyword: '', type: '' as '' | ProductType })
const dialogVisible = ref(false)
const saving = ref(false)
const form = reactive<any>({})
const categoryTree = ref<CategoryItem[]>([])
const units = ref<UnitItem[]>([])
const barcodeRef = ref()
const scanHint = ref('')

// 类型标签语义色（设计系统页覆盖 master-data.md）
function typeTagClass(type: ProductType) {
  return { raw_material: 'tag-raw', semi_finished: 'tag-semi', finished: 'tag-fin' }[type] ?? 'tag-raw'
}

// 加载列表：分页 + 关键字 + 类型过滤
async function load() {
  loading.value = true
  try {
    const res = await productApi.list({ page: query.page, per_page: 10, keyword: query.keyword, type: query.type || undefined })
    rows.value = res.items
    total.value = res.total
  } finally {
    loading.value = false
  }
}

// 弹窗打开后聚焦条码框（扫枪输入就绪）
function focusBarcode() {
  barcodeRef.value?.focus()
}

// 扫码校验：回车即时查询；未匹配不清空输入（便于重扫）
async function scanBarcode() {
  if (!form.barcode) return
  try {
    const hit = await productApi.byBarcode(form.barcode)
    scanHint.value = `条码匹配：${hit.name}（${hit.code}）`
    ElMessage.success(`条码匹配：${hit.name}`)
  } catch (e) {
    scanHint.value = ''
    ElMessage.error((e as Error).message)
  }
}

function openCreate() {
  Object.assign(form, { id: null, name: '', code: '', type: 'raw_material', category_id: null, unit_id: null, spec: '', barcode: '', safety_min: 0, safety_max: 0, status: 1, remark: '' })
  scanHint.value = ''
  dialogVisible.value = true
}
function openEdit(row: ProductItem) {
  Object.assign(form, { id: row.id, name: row.name, code: row.code, type: row.type, category_id: row.category_id, unit_id: row.unit_id, spec: row.spec, barcode: row.barcode, safety_min: row.safety_min, safety_max: row.safety_max, status: row.status, remark: '' })
  scanHint.value = ''
  dialogVisible.value = true
}

// 保存：前端拦截 min>max；后端 1114/1115/1122 双保险
async function save() {
  if (!form.name || !form.code || !form.category_id || !form.unit_id) return ElMessage.warning('请填写必填项')
  if (form.safety_max > 0 && form.safety_min > form.safety_max) return ElMessage.error('安全库存下限不能大于上限')
  saving.value = true
  try {
    const payload = {
      name: form.name, code: form.code, type: form.type, category_id: form.category_id, unit_id: form.unit_id,
      spec: form.spec, barcode: form.barcode || null, safety_min: form.safety_min, safety_max: form.safety_max,
      status: form.status, remark: form.remark,
    }
    if (form.id) await productApi.update(form.id, payload)
    else await productApi.create(payload)
    ElMessage.success('保存成功')
    dialogVisible.value = false
    load()
  } catch (e) {
    ElMessage.error((e as Error).message)
  } finally {
    saving.value = false
  }
}

// 删除：二次确认；被业务单据引用时后端 1116 提示
async function remove(row: ProductItem) {
  try {
    await ElMessageBox.confirm(`确定删除商品「${row.name}」？`, '提示', { type: 'warning' })
  } catch {
    return
  }
  try {
    await productApi.remove(row.id)
    ElMessage.success('删除成功')
    load()
  } catch (e) {
    ElMessage.error((e as Error).message)
  }
}

onMounted(async () => {
  load()
  categoryTree.value = await categoryApi.tree()
  units.value = (await unitApi.list({ page: 1, per_page: 100 })).items
})
</script>

<style scoped>
/* 页面骨架 + 类型标签语义色（master-data.md 页覆盖） */
.page-card { background: #fff; border-radius: 8px; box-shadow: var(--shadow-sm); padding: var(--space-2xl); }
.toolbar { display: flex; justify-content: space-between; align-items: center; margin-bottom: var(--space-xl); }
.toolbar-right { display: flex; gap: var(--space-lg); align-items: center; }
.page-title { font-size: 18px; font-weight: 600; color: var(--color-foreground); }
.btn-primary { background: var(--color-accent); border-color: var(--color-accent); cursor: pointer; }
.hint { color: var(--color-secondary); font-size: 12px; margin-top: var(--space-sm); }
.tag-raw { background: rgba(59, 130, 246, 0.12); color: #2563EB; border: 1px solid rgba(59, 130, 246, 0.3); border-radius: 4px; padding: 2px 8px; font-size: 12px; }
.tag-semi { background: rgba(217, 119, 6, 0.12); color: #D97706; border: 1px solid rgba(217, 119, 6, 0.3); border-radius: 4px; padding: 2px 8px; font-size: 12px; }
.tag-fin { background: rgba(5, 150, 105, 0.12); color: #059669; border: 1px solid rgba(5, 150, 105, 0.3); border-radius: 4px; padding: 2px 8px; font-size: 12px; }
</style>
```

- [ ] **Step 2: 实现 WarehousesView.vue（含库位弹窗）**

```vue
<!-- 仓库管理页：列表 + 库位管理弹窗（库位 CRUD 在弹窗内完成） -->
<template>
  <div class="page-card">
    <div class="toolbar">
      <span class="page-title">仓库管理</span>
      <div class="toolbar-right">
        <el-input v-model="query.keyword" placeholder="名称/编码" clearable style="width: 200px" @keyup.enter="load" />
        <el-button v-if="auth.has('warehouse.create')" class="btn-primary" @click="openCreate">新 建</el-button>
      </div>
    </div>
    <el-table :data="rows" v-loading="loading">
      <el-table-column prop="code" label="编码" width="100" class-name="font-code" />
      <el-table-column prop="name" label="名称" min-width="120" />
      <el-table-column prop="address" label="地址" show-overflow-tooltip />
      <el-table-column prop="manager" label="负责人" width="100" />
      <el-table-column label="状态" width="80">
        <template #default="{ row }">
          <el-tag :type="row.status === 1 ? 'success' : 'info'">{{ row.status === 1 ? '启用' : '停用' }}</el-tag>
        </template>
      </el-table-column>
      <el-table-column label="操作" width="230" fixed="right">
        <template #default="{ row }">
          <el-button v-if="auth.has('warehouse.list')" link type="primary" @click="openLocations(row)">库 位</el-button>
          <el-button v-if="auth.has('warehouse.update')" link type="primary" @click="openEdit(row)">编 辑</el-button>
          <el-button v-if="auth.has('warehouse.delete')" link type="danger" @click="remove(row)">删 除</el-button>
        </template>
      </el-table-column>
    </el-table>
    <el-pagination v-model:current-page="query.page" :total="total" :page-size="10" layout="total, prev, pager, next" @current-change="load" />

    <!-- 仓库新建/编辑弹窗 -->
    <el-dialog v-model="dialogVisible" :title="form.id ? '编辑仓库' : '新建仓库'" width="480px">
      <el-form :model="form" label-width="80px">
        <el-form-item label="名称" required><el-input v-model="form.name" /></el-form-item>
        <el-form-item label="编码" required><el-input v-model="form.code" /></el-form-item>
        <el-form-item label="地址"><el-input v-model="form.address" /></el-form-item>
        <el-form-item label="负责人"><el-input v-model="form.manager" /></el-form-item>
        <el-form-item label="状态"><el-switch v-model="form.status" :active-value="1" :inactive-value="0" /></el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="dialogVisible = false">取 消</el-button>
        <el-button type="primary" class="btn-primary" :loading="saving" @click="save">保 存</el-button>
      </template>
    </el-dialog>

    <!-- 库位管理弹窗 -->
    <el-dialog v-model="locationVisible" :title="`库位管理 - ${currentWarehouse?.name}`" width="640px">
      <div class="loc-toolbar">
        <el-button v-if="auth.has('warehouse.create')" class="btn-primary" @click="openCreateLocation">新 增</el-button>
      </div>
      <el-table :data="locations" size="small">
        <el-table-column prop="name" label="库位名称" />
        <el-table-column prop="code" label="编码" class-name="font-code" />
        <el-table-column label="状态" width="80">
          <template #default="{ row }">
            <el-tag :type="row.status === 1 ? 'success' : 'info'">{{ row.status === 1 ? '启用' : '停用' }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="140">
          <template #default="{ row }">
            <el-button v-if="auth.has('warehouse.update')" link type="primary" @click="openEditLocation(row)">编 辑</el-button>
            <el-button v-if="auth.has('warehouse.delete')" link type="danger" @click="removeLocation(row)">删 除</el-button>
          </template>
        </el-table-column>
      </el-table>
      <!-- 库位新增/编辑小弹窗 -->
      <el-dialog v-model="locFormVisible" :title="locForm.id ? '编辑库位' : '新增库位'" width="380px" append-to-body>
        <el-form :model="locForm" label-width="80px">
          <el-form-item label="名称" required><el-input v-model="locForm.name" /></el-form-item>
          <el-form-item label="编码" required><el-input v-model="locForm.code" /></el-form-item>
          <el-form-item label="状态"><el-switch v-model="locForm.status" :active-value="1" :inactive-value="0" /></el-form-item>
        </el-form>
        <template #footer>
          <el-button @click="locFormVisible = false">取 消</el-button>
          <el-button type="primary" class="btn-primary" :loading="locSaving" @click="saveLocation">保 存</el-button>
        </template>
      </el-dialog>
    </el-dialog>
  </div>
</template>

<script setup lang="ts">
// 仓库管理页：CRUD + 库位弹窗子管理；删除有库存仓库时后端 1106 提示
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { warehouseApi, type LocationItem, type WarehouseItem } from '../../api/warehouse'
import { useAuthStore } from '../../stores/auth'

const auth = useAuthStore()
const rows = ref<WarehouseItem[]>([])
const total = ref(0)
const loading = ref(false)
const query = reactive({ page: 1, keyword: '' })
const dialogVisible = ref(false)
const saving = ref(false)
const form = reactive<any>({})

const locationVisible = ref(false)
const currentWarehouse = ref<WarehouseItem | null>(null)
const locations = ref<LocationItem[]>([])
const locFormVisible = ref(false)
const locSaving = ref(false)
const locForm = reactive<any>({})

// 加载仓库列表
async function load() {
  loading.value = true
  try {
    const res = await warehouseApi.list({ page: query.page, per_page: 10, keyword: query.keyword })
    rows.value = res.items
    total.value = res.total
  } finally {
    loading.value = false
  }
}

function openCreate() {
  Object.assign(form, { id: null, name: '', code: '', address: '', manager: '', status: 1 })
  dialogVisible.value = true
}
function openEdit(row: WarehouseItem) {
  Object.assign(form, { id: row.id, name: row.name, code: row.code, address: row.address, manager: row.manager, status: row.status })
  dialogVisible.value = true
}

// 保存仓库：后端 1105 重复编码提示展示
async function save() {
  if (!form.name || !form.code) return ElMessage.warning('请填写名称与编码')
  saving.value = true
  try {
    if (form.id) await warehouseApi.update(form.id, { name: form.name, code: form.code, address: form.address, manager: form.manager, status: form.status })
    else await warehouseApi.create({ name: form.name, code: form.code, address: form.address, manager: form.manager, status: form.status })
    ElMessage.success('保存成功')
    dialogVisible.value = false
    load()
  } catch (e) {
    ElMessage.error((e as Error).message)
  } finally {
    saving.value = false
  }
}

// 删除仓库：二次确认；有库存时后端 1106 提示
async function remove(row: WarehouseItem) {
  try {
    await ElMessageBox.confirm(`确定删除仓库「${row.name}」？`, '提示', { type: 'warning' })
  } catch {
    return
  }
  try {
    await warehouseApi.remove(row.id)
    ElMessage.success('删除成功')
    load()
  } catch (e) {
    ElMessage.error((e as Error).message)
  }
}

// 打开库位弹窗并加载该仓库库位
async function openLocations(row: WarehouseItem) {
  currentWarehouse.value = row
  locationVisible.value = true
  locations.value = (await warehouseApi.locations(row.id)).items
}

function openCreateLocation() {
  Object.assign(locForm, { id: null, name: '', code: '', status: 1 })
  locFormVisible.value = true
}
function openEditLocation(row: LocationItem) {
  Object.assign(locForm, { id: row.id, name: row.name, code: row.code, status: row.status })
  locFormVisible.value = true
}

// 保存库位：后端编码重复 422 提示展示
async function saveLocation() {
  if (!locForm.name || !locForm.code) return ElMessage.warning('请填写名称与编码')
  locSaving.value = true
  try {
    if (locForm.id) await warehouseApi.updateLocation(locForm.id, { name: locForm.name, code: locForm.code, status: locForm.status })
    else await warehouseApi.createLocation(currentWarehouse.value!.id, { name: locForm.name, code: locForm.code, status: locForm.status })
    ElMessage.success('保存成功')
    locFormVisible.value = false
    locations.value = (await warehouseApi.locations(currentWarehouse.value!.id)).items
  } catch (e) {
    ElMessage.error((e as Error).message)
  } finally {
    locSaving.value = false
  }
}

// 删除库位：二次确认；有库存时后端 1107 提示
async function removeLocation(row: LocationItem) {
  try {
    await ElMessageBox.confirm(`确定删除库位「${row.name}」？`, '提示', { type: 'warning' })
  } catch {
    return
  }
  try {
    await warehouseApi.removeLocation(row.id)
    ElMessage.success('删除成功')
    locations.value = (await warehouseApi.locations(currentWarehouse.value!.id)).items
  } catch (e) {
    ElMessage.error((e as Error).message)
  }
}

onMounted(load)
</script>

<style scoped>
/* 页面骨架 + 库位弹窗工具栏 */
.page-card { background: #fff; border-radius: 8px; box-shadow: var(--shadow-sm); padding: var(--space-2xl); }
.toolbar { display: flex; justify-content: space-between; align-items: center; margin-bottom: var(--space-xl); }
.toolbar-right { display: flex; gap: var(--space-lg); align-items: center; }
.page-title { font-size: 18px; font-weight: 600; color: var(--color-foreground); }
.btn-primary { background: var(--color-accent); border-color: var(--color-accent); cursor: pointer; }
.loc-toolbar { display: flex; justify-content: flex-end; margin-bottom: var(--space-lg); }
</style>
```

- [ ] **Step 3: 实现 BomsView.vue（单头+明细动态行 + 明细查看 + 启用切换）**

```vue
<!-- BOM 管理页：列表 + 新建/编辑大弹窗（明细动态行）+ 明细查看 + 启用/停用切换 -->
<template>
  <div class="page-card">
    <div class="toolbar">
      <span class="page-title">BOM 管理</span>
      <div class="toolbar-right">
        <el-input v-model="query.keyword" placeholder="BOM 编码" clearable style="width: 220px" @keyup.enter="load" />
        <el-button v-if="auth.has('bom.create')" class="btn-primary" @click="openCreate">新 建</el-button>
      </div>
    </div>
    <el-table :data="rows" v-loading="loading">
      <el-table-column prop="code" label="BOM 编码" width="170" class-name="font-code" />
      <el-table-column prop="product_name" label="成品名称" min-width="140" />
      <el-table-column prop="version" label="版本" width="80" class-name="font-code" />
      <el-table-column prop="quantity" label="基准数量" width="90" />
      <el-table-column label="状态" width="90">
        <template #default="{ row }">
          <el-tag :type="row.status === 1 ? 'success' : 'info'">{{ row.status === 1 ? '启用' : '停用' }}</el-tag>
        </template>
      </el-table-column>
      <el-table-column label="操作" width="270" fixed="right">
        <template #default="{ row }">
          <el-button v-if="auth.has('bom.list')" link type="primary" @click="openItems(row)">明 细</el-button>
          <el-button v-if="auth.has('bom.update')" link type="primary" @click="openEdit(row)">编 辑</el-button>
          <el-button v-if="auth.has('bom.update')" link :type="row.status === 1 ? 'warning' : 'success'" @click="toggle(row)">{{ row.status === 1 ? '停 用' : '启 用' }}</el-button>
          <el-button v-if="auth.has('bom.delete')" link type="danger" @click="remove(row)">删 除</el-button>
        </template>
      </el-table-column>
    </el-table>
    <el-pagination v-model:current-page="query.page" :total="total" :page-size="10" layout="total, prev, pager, next" @current-change="load" />

    <!-- 新建/编辑弹窗：单头 + 明细动态行（宽 800px） -->
    <el-dialog v-model="dialogVisible" :title="form.id ? '编辑 BOM' : '新建 BOM'" width="800px">
      <el-form :model="form" label-width="90px">
        <el-form-item label="成品" required>
          <el-select v-model="form.product_id" filterable style="width: 100%" placeholder="仅成品类型商品">
            <el-option v-for="p in finishedProducts" :key="p.id" :label="`${p.code} ${p.name}`" :value="p.id" />
          </el-select>
        </el-form-item>
        <el-form-item label="版本" required><el-input v-model="form.version" style="width: 160px" /></el-form-item>
        <el-form-item label="基准数量"><el-input-number v-model="form.quantity" :min="0.01" :precision="2" /></el-form-item>
        <el-form-item label="备注"><el-input v-model="form.remark" type="textarea" :rows="2" /></el-form-item>
        <el-form-item label="保存即启用"><el-switch v-model="form.status" :active-value="1" :inactive-value="0" /></el-form-item>
        <el-form-item label="物料明细">
          <div class="items-wrap">
            <div v-for="(row, idx) in form.items" :key="idx" class="item-row">
              <el-select v-model="row.material_id" filterable placeholder="物料（原料/半成品）" style="width: 260px">
                <el-option v-for="m in materialProducts" :key="m.id" :label="`${m.code} ${m.name}`" :value="m.id" />
              </el-select>
              <el-input-number v-model="row.quantity" :min="0.01" :precision="2" placeholder="用量" style="width: 120px" />
              <span class="unit-name">{{ unitName(row.unit_id) }}</span>
              <el-button link type="danger" @click="removeItem(idx)">删 除</el-button>
            </div>
            <el-button class="add-item" @click="addItem">+ 添加物料行</el-button>
          </div>
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="dialogVisible = false">取 消</el-button>
        <el-button type="primary" class="btn-primary" :loading="saving" @click="save">保 存</el-button>
      </template>
    </el-dialog>

    <!-- 明细查看弹窗：只读表格 -->
    <el-dialog v-model="itemsVisible" :title="`BOM 明细 - ${currentBom?.code}`" width="560px">
      <el-table :data="itemsRows" size="small">
        <el-table-column prop="material_name" label="物料" />
        <el-table-column prop="quantity" label="用量" width="100" />
        <el-table-column prop="unit_name" label="单位" width="80" />
      </el-table>
    </el-dialog>
  </div>
</template>

<script setup lang="ts">
// BOM 管理页：单头+明细一次保存/全量替换；启用切换自动停用同成品其他版本（后端 1120 兜底）
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { bomApi, type BomItem, type BomRow } from '../../api/bom'
import { productApi, type ProductItem } from '../../api/product'
import { unitApi, type UnitItem } from '../../api/unit'
import { useAuthStore } from '../../stores/auth'

const auth = useAuthStore()
const rows = ref<BomRow[]>([])
const total = ref(0)
const loading = ref(false)
const query = reactive({ page: 1, keyword: '' })
const dialogVisible = ref(false)
const saving = ref(false)
const form = reactive<any>({})
const finishedProducts = ref<ProductItem[]>([])
const materialProducts = ref<ProductItem[]>([])
const units = ref<UnitItem[]>([])
const itemsVisible = ref(false)
const currentBom = ref<BomRow | null>(null)
const itemsRows = ref<BomItem[]>([])

// 单位名映射（明细行显示）
function unitName(id: number) {
  return units.value.find((u) => u.id === id)?.name ?? ''
}

// 加载列表
async function load() {
  loading.value = true
  try {
    const res = await bomApi.list({ page: query.page, per_page: 10, keyword: query.keyword })
    rows.value = res.items
    total.value = res.total
  } finally {
    loading.value = false
  }
}

// 弹窗数据源：成品仅 finished；物料仅 raw_material/semi_finished
function openCreate() {
  Object.assign(form, { id: null, product_id: null, version: 'v1', quantity: 1, remark: '', status: 1, items: [newRow()] })
  dialogVisible.value = true
}
function openEdit(row: BomRow) {
  Object.assign(form, { id: row.id, product_id: row.product_id, version: row.version, quantity: row.quantity, remark: row.remark, status: row.status, items: [newRow()] })
  dialogVisible.value = true
  // 编辑回填明细（异步加载后重建行）
  bomApi.items(row.id).then((res) => {
    form.items = res.items.map((i) => ({ material_id: i.material_id, quantity: i.quantity, unit_id: i.unit_id }))
  })
}

// 动态行：默认单位取第一个单位
function newRow() {
  return { material_id: null, quantity: 1, unit_id: units.value[0]?.id ?? null }
}
function addItem() {
  form.items.push(newRow())
}
function removeItem(idx: number) {
  form.items.splice(idx, 1)
}

// 保存：单头+明细一次提交；后端 1118/1119/1120/1123 错误提示展示
async function save() {
  if (!form.product_id) return ElMessage.warning('请选择成品')
  if (!form.items.length) return ElMessage.warning('请至少添加一条物料明细')
  if (form.items.some((i: any) => !i.material_id || !i.quantity || !i.unit_id)) return ElMessage.warning('请补全物料行信息')
  saving.value = true
  try {
    const payload = {
      product_id: form.product_id, version: form.version, quantity: form.quantity,
      remark: form.remark, status: form.status,
      items: form.items.map((i: any) => ({ material_id: i.material_id, quantity: i.quantity, unit_id: i.unit_id })),
    }
    if (form.id) await bomApi.update(form.id, payload)
    else await bomApi.create(payload)
    ElMessage.success('保存成功')
    dialogVisible.value = false
    load()
  } catch (e) {
    ElMessage.error((e as Error).message)
  } finally {
    saving.value = false
  }
}

// 明细查看
async function openItems(row: BomRow) {
  currentBom.value = row
  itemsVisible.value = true
  itemsRows.value = (await bomApi.items(row.id)).items
}

// 启用/停用切换：后端保证同成品启用唯一
async function toggle(row: BomRow) {
  try {
    await bomApi.toggle(row.id, row.status === 1 ? 0 : 1)
    ElMessage.success(row.status === 1 ? '已停用' : '已启用')
    load()
  } catch (e) {
    ElMessage.error((e as Error).message)
  }
}

// 删除：二次确认；被工单引用时后端 1121 提示
async function remove(row: BomRow) {
  try {
    await ElMessageBox.confirm(`确定删除 BOM「${row.code}」？`, '提示', { type: 'warning' })
  } catch {
    return
  }
  try {
    await bomApi.remove(row.id)
    ElMessage.success('删除成功')
    load()
  } catch (e) {
    ElMessage.error((e as Error).message)
  }
}

onMounted(async () => {
  load()
  const [fin, mat, unit] = await Promise.all([
    productApi.list({ page: 1, per_page: 100, type: 'finished' }),
    productApi.list({ page: 1, per_page: 100 }),
    unitApi.list({ page: 1, per_page: 100 }),
  ])
  finishedProducts.value = fin.items
  // 物料下拉：仅原料/半成品（成品嵌套由后端 1119 兜底，前端直接过滤）
  materialProducts.value = mat.items.filter((p) => p.type !== 'finished')
  units.value = unit.items
})
</script>

<style scoped>
/* 页面骨架 + 明细动态行布局 */
.page-card { background: #fff; border-radius: 8px; box-shadow: var(--shadow-sm); padding: var(--space-2xl); }
.toolbar { display: flex; justify-content: space-between; align-items: center; margin-bottom: var(--space-xl); }
.toolbar-right { display: flex; gap: var(--space-lg); align-items: center; }
.page-title { font-size: 18px; font-weight: 600; color: var(--color-foreground); }
.btn-primary { background: var(--color-accent); border-color: var(--color-accent); cursor: pointer; }
.items-wrap { width: 100%; }
.item-row { display: flex; gap: var(--space-md); align-items: center; margin-bottom: var(--space-md); }
.unit-name { width: 50px; color: var(--color-secondary); }
.add-item { width: 100%; border-style: dashed; cursor: pointer; }
</style>
```

- [ ] **Step 4: 跑前端全量测试 + 构建**

Run: `cd web && npx vitest run && npm run build`
Expected: 全部 PASS；构建无 TS 报错（注意 `el-radio value` 是 Element Plus 2.6+ 新 API，若版本不支持改为 `label` 属性）。

- [ ] **Step 5: 浏览器冒烟验证（playwright-cli）**

```bash
# 启动服务（若未启动）
cd /d/code/project/php-design && docker compose up -d
cd server && php artisan migrate:fresh --seed && php artisan serve &
cd ../web && npm run dev &
```

用 playwright-cli 打开 `http://localhost:5173/login` 登录 admin/admin123，逐页冒烟：
- 侧边栏出现「基础资料」组 8 个菜单
- 商品页：新建「MAT-001 测试铝材」→ 列表出现（类型标签蓝色）
- 商品弹窗条码框自动聚焦（`eval "document.activeElement.placeholder"` 含「条码」）
- 分类页：树形显示「原材料」「成品」；新建子分类成功
- BOM 页：新建 FIN-001 的 BOM v1 + 明细 → 列表出现「启用」
- 仓库页：库位弹窗新增 A-01 成功

Expected: 全部符合 E2E 文档对应用例的 UI 行为。

- [ ] **Step 6: 提交**

```bash
cd /d/code/project/php-design && git add -A && git commit -m "feat: 基础资料页面 B（商品/仓库库位/BOM）"
```

---

## Task 10: E2E 全量测试（playwright-cli）

**Files:**
- Modify: `docs/test/2026-08-12-基础资料模块端到端测试.md`（§5 结果记录表）
- Create: `docs/test/evidence/`（失败证据目录，仅失败时产生）

**Interfaces:**
- Consumes: Task 1-9 全部产物
- Produces: E2E 文档 TC-MST-01~11 全部通过；失败项按文档 §4 流程修复后回归

- [ ] **Step 1: 全量后端测试**

Run: `cd server && php artisan test`
Expected: 全部 PASS（原 48 + MasterDataStructure 5 + Category 7 + Unit 5 + Process 4 + Warehouse 7 + Supplier 5 + Customer 5 + Product 10 + Bom 11 ≈ 107）。

- [ ] **Step 2: 全量前端测试**

Run: `cd web && npx vitest run`
Expected: 全部 PASS（原 14 + product 3 + bom 3 = 20）。

- [ ] **Step 3: 重建数据并启动服务**

```bash
cd /d/code/project/php-design
docker compose up -d
cd server && php artisan migrate:fresh --seed
cd server && php artisan serve &   # 后端 :8000
cd ../web && npm run dev &         # 前端 :5173
```

- [ ] **Step 4: 执行 E2E 文档 TC-MST-01~11**

按 `docs/test/2026-08-12-基础资料模块端到端测试.md` 用例顺序执行（playwright-cli state-load auth.json 恢复 admin 登录态）：

- TC-MST-01 分类树与删除保护（1101/1102）
- TC-MST-02 单位 CRUD 与重复编码拦截（1103/1104）
- TC-MST-03 仓库与库位 CRUD（1105；1106 视库存模块进度跳过并注明）
- TC-MST-04 供应商 CRUD（1108）
- TC-MST-05 客户 CRUD（1110）
- TC-MST-06 工序 CRUD（1112；排序生效）
- TC-MST-07 商品创建（1114/1115/1122 前端拦截；类型标签语义色）
- TC-MST-08 商品扫码查询（1117；输入不清空）
- TC-MST-09 BOM 创建（1118/1119/1120/1123）
- TC-MST-10 BOM 启用切换（自动停用旧版本）
- TC-MST-11 删除保护全链路（1116；按依赖逆序清理）

每用例断言失败时执行文档 §4 流程：`playwright-cli screenshot --filename=docs/test/evidence/{tc}-fail.png` + `console` + `requests` → 判定层级（接口层/UI 层/环境层）→ systematic-debugging 修复 → 补 PHPUnit/Vitest 测试 → 回归本文档全部用例及系统管理模块用例。

- [ ] **Step 5: 填写测试结果记录**

将每用例结果写入 `docs/test/2026-08-12-基础资料模块端到端测试.md` §5 表格；失败项附失败详情与修复引用；1106（仓库有库存不可删）在本模块阶段跳过需注明「库存模块实施后补测」。

- [ ] **Step 6: 全量回归与提交**

Run: `cd server && php artisan test && cd ../web && npx vitest run`
Expected: 全绿。
Run: `cd /d/code/project/php-design && git add -A && git commit -m "test: 基础资料模块 E2E 全量通过"`
Expected: 提交成功。

---

## Self-Review

**1. Spec 覆盖核对**（对照 `docs/superpowers/specs/2026-08-12-master-data-spec.md`）：
- §3 数据模型 10 张表 + 约束（成品/物料类型、启用版本唯一、引用保护）→ Task 1 迁移 + Task 6 校验 ✅
- §4.1 分类/单位（树形、1101-1104）→ Task 2 ✅
- §4.2 仓库/库位（1105-1107、子资源）→ Task 3 ✅
- §4.3 供应商/客户（1108-1111、keyword 搜索）→ Task 4 ✅
- §4.4 工序（1112-1113、sort 升序）→ Task 2 ✅
- §4.5 商品（1114-1117、扫码、type_label/unit_name）→ Task 5 ✅
- §4.6 BOM（1118-1121、items/toggle、1123）→ Task 6 ✅
- §5 页面与交互（8 页面、BOM 弹窗 800px、条码聚焦、类型标签语义色）→ Task 8/9 ✅
- §6 业务流转（引用保护链）→ Task 2-6 删除保护 + DeletionGuard ✅
- §7 功能清单 MST-01~10 → Task 10 TC 用例 ✅
- §8 边界（1122 min>max、1123 重复物料、1124 两级、停用商品不进下拉）→ Task 5/6 校验 + Task 9 前端（min>max 前端拦截）✅
- E2E 文档 TC-MST-01~11 → Task 10 ✅

**2. 占位符扫描**：无 TBD/TODO/"类似 Task N"引用；CustomerTest 与 CustomerController 在 Task 4 中给出同构说明并明确替换点（资源名/错误码/守卫表），实现时照抄 Supplier 版本替换即可；Task 6 中 `abort()` 与 `fail()` 二选一已注明等价性，不阻塞实现。

**3. 类型一致性核对**：
- 错误码 1101-1124 在 spec、控制器、测试三处一致 ✅
- 权限 code（`product.list` 等 32 项）在 Task 1 种子、Task 2-6 路由中间件、Task 7 前端路由 meta/菜单三处一致 ✅
- `DeletionGuard::referenced(table, column, id)` 签名在 Task 1 定义，Task 3/4/5/6 统一使用；守卫表名与下游 spec（purchase_orders.supplier_id / sales_orders.customer_id / inventory_balances.warehouse_id|location_id / work_order_operations.process_id / production_orders.bom_id|product_id / inventory_movements.product_id / purchase_order_items.product_id / sales_order_items.product_id）一致 ✅
- 前端类型：`ProductItem.type_label/unit_name/category_name`、`BomRow.product_name`、`BomItem.material_name/unit_name` 与后端响应字段一致 ✅
- 分页 `{items,total,page,per_page}` 与 per_page 钳制模式与 UserController 一致 ✅
- 按钮文案（「新 建/保 存/编 辑/删 除/明 细/库 位/启 用/停 用」）与 E2E 文档操作列一致 ✅
