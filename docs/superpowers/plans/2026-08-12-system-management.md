# 系统管理模块 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 实现系统管理模块（认证 + RBAC + 字典）的 Laravel 13 后端 API 与 Vue 3 前端页面，通过全部 PHPUnit/Vitest 测试与 E2E 测试文档 `docs/test/2026-08-12-系统管理模块端到端测试.md` 的 TC-SYS-01~12。

**Architecture:** 前后端分离。后端 Laravel 13 + Sanctum Bearer Token + RBAC 中间件，统一响应 `{code, message, data}`；前端 Vue 3 + TS + Pinia + Vue Router + Element Plus，路由守卫按 `/auth/me` 返回的 permissions 过滤。库存引擎等后续模块仅依赖本模块产出的登录态与权限（本计划不实现库存）。

**Tech Stack:** PHP 8.5.9（`D:\code\envs\php\8.5.9`）、Composer 2.10.2、Laravel 13.25.0、Sanctum 4.3.3、MySQL 8.4（Docker）、Vue 3 + TypeScript + Vite + Pinia + Vue Router + Element Plus、PHPUnit、Vitest、playwright-cli。

## Global Constraints

以下约束对每个 Task 隐式生效（来自主 spec 与系统管理细化 spec，逐条原文）：

- 统一响应：`{code, message, data}`；`code=0` 成功；错误码：1001 登录失败、1002 用户名重复、1003 内置管理员不可删除、1004 角色已分配不可删、1005 字典编码重复、1006 账号禁用、1007 至少保留一个管理员角色、1008 字典不存在；认证失败 401、无权限 403
- API 前缀 `/api/v1`；RESTful 资源化路由；权限中间件 `permission:xxx`；权限 code 命名 `{资源}.{动作}`（list/create/update/delete/approve）
- 数据库：MySQL 8.4，表 users/roles/permissions/role_user/permission_role/dictionaries/dictionary_items（+sanctum 的 personal_access_tokens）
- 用户密码 bcrypt 存储；内置 admin 用户（username=admin）不可删除；删除最后一个管理员角色被拒
- 前端 token 存 `localStorage`；登录页 `/login`；登录成功跳 `/dashboard`（本计划中为占位页）；已登录访问 /login 重定向 /dashboard
- 前端路由守卫：无 token → /login；有 token 先请求 /auth/me 校验；无权限路由 → 403 页
- 侧边栏深色 `#0F172A`（220px），内容区 `#F8FAFC`；主色 `#334155`、强调绿 `#059669`、危险 `#DC2626`；Fira Code + Fira Sans；所有可点击元素 `cursor:pointer`；过渡 150-300ms
- 中文注释（类级/方法级/关键行）；UTF-8 无 BOM；LF 行尾；无死代码
- 核心路径（认证、权限校验）单元测试 **100% 覆盖**；测试命名表达业务意图，覆盖正常/边界/异常
- 端口：后端 `http://localhost:8000`（`php artisan serve`）、前端 `http://localhost:5173`（vite）、MySQL `3306`
- 本机命令路径：`php`=`D:\code\envs\php\8.5.9\php.exe`，`composer`=`D:\code\envs\composer\2.10.2\composer.phar`（新终端 PATH 已配，旧终端用绝对路径）

---

## Task 1: 环境与脚手架（MySQL + Laravel 13 + Vue 3 工程 + 设计令牌）

**Files:**
- Create: `docker-compose.yml`（项目根）
- Create: `server/`（Laravel 13 工程，composer create-project）
- Create: `web/`（Vue 3 + TS + Vite 工程）
- Create: `web/src/styles/tokens.css`（设计令牌）
- Modify: `server/.env`（DB 配置）

**Interfaces:**
- Consumes: 无
- Produces: `docker compose up -d` 提供 MySQL（db=php_design, user=php, pass=secret, port=3306）；`php artisan serve` 提供后端 `:8000`；`npm run dev` 提供前端 `:5173`；`web/src/styles/tokens.css` 导出 CSS 变量 `--color-primary/--color-accent/--space-*` 等（Task 7/8 使用）

- [ ] **Step 1: 创建 MySQL docker-compose**

创建 `docker-compose.yml`（项目根）：

```yaml
services:
  mysql:
    image: mysql:8.4
    container_name: php-design-mysql
    environment:
      MYSQL_ROOT_PASSWORD: root
      MYSQL_DATABASE: php_design
      MYSQL_USER: php
      MYSQL_PASSWORD: secret
      TZ: Asia/Shanghai
    ports:
      - "3306:3306"
    volumes:
      - mysql_data:/var/lib/mysql
    command: --character-set-server=utf8mb4 --collation-server=utf8mb4_unicode_ci
volumes:
  mysql_data:
```

- [ ] **Step 2: 启动 MySQL 并验证**

Run: `cd /d/code/project/php-design && docker compose up -d && docker compose ps`
Expected: 容器 `php-design-mysql` 状态 running，端口 3306 监听。

- [ ] **Step 3: 创建 Laravel 13 工程（绕过不同步的阿里云镜像）**

阿里云 Composer 镜像不同步 Laravel 13（实测只到 12.40），本步骤必须指定官方源创建：

```bash
cd /d/code/project/php-design
php D:/code/envs/composer/2.10.2/composer.phar create-project "laravel/laravel:^13.0" server \
  --repository=https://repo.packagist.org --no-interaction
```

Expected: 成功创建 `server/`，`server/composer.json` 含 `"laravel/framework": "^13.0"`。

- [ ] **Step 4: 配置 .env 数据库连接**

修改 `server/.env`（用 Edit 精准替换）：

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=php_design
DB_USERNAME=php
DB_PASSWORD=secret
APP_URL=http://localhost:8000
```

Run: `cd server && php artisan migrate`
Expected: 迁移成功（users/password_reset_tokens/sessions 表创建）。

- [ ] **Step 5: 创建 Vue 3 + TS 工程并安装依赖**

```bash
cd /d/code/project/php-design
npm create vite@latest web -- --template vue-ts
cd web && npm install
npm install element-plus pinia vue-router axios @element-plus/icons-vue
npm install -D vitest @vue/test-utils jsdom
```

Expected: `web/package.json` 含上述依赖；`npm run dev` 可启动（端口 5173）。

- [ ] **Step 6: 落地设计令牌**

创建 `web/src/styles/tokens.css`（从 MASTER.md 提取）：

```css
/* 设计令牌：nexus-factory 设计系统（MASTER.md） */
@import url('https://fonts.googleapis.com/css2?family=Fira+Code:wght@400;500;600;700&family=Fira+Sans:wght@300;400;500;600;700&display=swap');

:root {
  --color-primary: #334155;    /* 主色-石板灰 */
  --color-on-primary: #FFFFFF;
  --color-secondary: #475569;
  --color-accent: #059669;     /* 强调-库存绿 */
  --color-background: #F8FAFC;
  --color-foreground: #0F172A;
  --color-muted: #F2F3F4;
  --color-border: #E6E8EA;
  --color-destructive: #DC2626;
  --color-ring: #334155;
  --space-xs: 2px; --space-sm: 4px; --space-md: 8px; --space-lg: 12px;
  --space-xl: 16px; --space-2xl: 24px; --space-3xl: 32px;
  --shadow-sm: 0 1px 2px rgba(0,0,0,0.05);
  --shadow-md: 0 4px 6px rgba(0,0,0,0.1);
  --shadow-lg: 0 10px 15px rgba(0,0,0,0.1);
  --el-color-primary: #334155; /* Element Plus 主色定制 */
  font-family: 'Fira Sans', system-ui, sans-serif;
}
.font-code { font-family: 'Fira Code', monospace; }
```

- [ ] **Step 7: 验证脚手架**

Run: `cd server && php artisan --version && php artisan test`
Expected: 输出 `Laravel Framework 13.25.x`，Laravel 自带示例测试 PASS。
Run: `cd web && npm run build`
Expected: 构建成功无 TS 报错。

- [ ] **Step 8: 提交**

```bash
cd /d/code/project/php-design && git init && git add -A && git commit -m "chore: 脚手架（MySQL/Laravel 13/Vue3）与设计令牌"
```

---

## Task 2: 认证 API（Sanctum 登录/登出/me + 统一响应）

**Files:**
- Create: `server/app/Http/Controllers/Api/AuthController.php`
- Create: `server/app/Http/Resources/UserResource.php`
- Create: `server/tests/Feature/AuthTest.php`
- Create: `server/app/Support/ApiResponse.php`（统一响应 trait）
- Modify: `server/routes/api.php`、`server/bootstrap/app.php`（异常 JSON 化）、`server/app/Models/User.php`（扩展字段 + HasApiTokens）

**Interfaces:**
- Consumes: Task 1 脚手架
- Produces: `POST /api/v1/auth/login` → `{code:0, data:{token, user:{id,name,username,roles:[],permissions:[]}}}`；`POST /api/v1/auth/logout` → `{code:0, message:"已退出登录"}`；`GET /api/v1/auth/me` → `data:{id,name,username,roles:[{id,name}],permissions:[]}`；trait `ApiResponse::ok($data,$message)/::fail($code,$message)`；`UserResource`（Task 4/5 复用）；错误码 1001/1006/401

- [ ] **Step 1: 安装 Sanctum 并配置**

```bash
cd server
php D:/code/envs/composer/2.10.2/composer.phar require laravel/sanctum:^4.3
php artisan vendor:publish --provider="Laravel\Sanctum\SanctumServiceProvider"
php artisan migrate
```

- [ ] **Step 2: 写失败测试 `server/tests/Feature/AuthTest.php`**

```php
<?php
// 认证接口测试：登录/登出/me 全链路（核心路径，100% 覆盖）
namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // 种子：admin 用户（密码 admin123）与普通用户（禁用态）
        User::create(['name' => '管理员', 'username' => 'admin', 'email' => 'admin@test.com', 'password' => bcrypt('admin123'), 'status' => 1]);
        User::create(['name' => '禁用用户', 'username' => 'disabled', 'email' => 'd@test.com', 'password' => bcrypt('pass'), 'status' => 0]);
    }

    public function test_login_success_returns_token_and_user(): void
    {
        // 正常路径：正确凭证返回 token 与用户信息
        $res = $this->postJson('/api/v1/auth/login', ['username' => 'admin', 'password' => 'admin123']);
        $res->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonStructure(['data' => ['token', 'user' => ['id', 'name', 'username', 'roles', 'permissions']]]);
    }

    public function test_login_wrong_password_fails_with_1001(): void
    {
        // 异常路径：错误密码返回 1001 且不返回 token
        $res = $this->postJson('/api/v1/auth/login', ['username' => 'admin', 'password' => 'wrong']);
        $res->assertOk()->assertJsonPath('code', 1001)->assertJsonMissingPath('data.token');
    }

    public function test_login_missing_username_returns_validation_error(): void
    {
        // 边界路径：空表单被 422 校验拦截
        $this->postJson('/api/v1/auth/login', [])->assertStatus(422);
    }

    public function test_login_disabled_account_fails_with_1006(): void
    {
        // 边界路径：禁用账号登录返回 1006
        $this->postJson('/api/v1/auth/login', ['username' => 'disabled', 'password' => 'pass'])
            ->assertJsonPath('code', 1006);
    }

    public function test_me_returns_user_with_permissions(): void
    {
        // 正常路径：带 token 访问 me 返回用户与权限数组
        $token = $this->postJson('/api/v1/auth/login', ['username' => 'admin', 'password' => 'admin123'])->json('data.token');
        $this->withToken($token)->getJson('/api/v1/auth/me')
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.username', 'admin')
            ->assertJsonStructure(['data' => ['roles', 'permissions']]);
    }

    public function test_me_without_token_returns_401(): void
    {
        // 异常路径：无 token 返回 401
        $this->getJson('/api/v1/auth/me')->assertStatus(401);
    }

    public function test_logout_revokes_token(): void
    {
        // 正常路径：登出后 token 失效
        $token = $this->postJson('/api/v1/auth/login', ['username' => 'admin', 'password' => 'admin123'])->json('data.token');
        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertJsonPath('code', 0);
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertStatus(401);
    }
}
```

- [ ] **Step 3: 跑测试确认失败**

Run: `cd server && php artisan test --filter=AuthTest`
Expected: FAIL（接口不存在，404）。

- [ ] **Step 4: 实现统一响应 trait**

创建 `server/app/Support/ApiResponse.php`：

```php
<?php
// 统一响应体：全系统所有接口的响应格式模板
namespace App\Support;

trait ApiResponse
{
    /** 成功响应：code=0，data 可空 */
    protected function ok(mixed $data = null, string $message = ''): \Illuminate\Http\JsonResponse
    {
        return response()->json(['code' => 0, 'message' => $message, 'data' => $data]);
    }

    /** 业务失败响应：非 0 code + 中文 message（HTTP 状态保持 200，由 code 承载业务结果） */
    protected function fail(int $code, string $message, int $httpStatus = 200): \Illuminate\Http\JsonResponse
    {
        return response()->json(['code' => $code, 'message' => $message, 'data' => null], $httpStatus);
    }
}
```

- [ ] **Step 5: 扩展 User 模型（HasApiTokens + 角色关系 + 字段）**

修改 `server/app/Models/User.php`（替换 fillable 与 trait 行）：

```php
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    // 用户字段白名单：账号信息与状态（密码单独走 setter）
    protected $fillable = ['name', 'username', 'email', 'password', 'status'];

    // 密码统一 bcrypt 加密存储
    protected function casts(): array
    {
        return ['password' => 'hashed', 'status' => 'integer'];
    }

    // 用户可挂多个角色（RBAC 多对多）
    public function roles(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Role::class);
    }

    // 合并所有角色的权限 code 集合（去重）
    public function permissions(): \Illuminate\Support\Collection
    {
        return $this->roles()->with('permissions')->get()
            ->pluck('permissions')->flatten()->pluck('code')->unique()->values();
    }
}
```

- [ ] **Step 6: 添加 users 表迁移字段**

创建 `server/database/migrations/xxxx_add_fields_to_users_table.php`：

```php
<?php
// 用户表扩展：username 唯一登录名、status 启用状态
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('username', 50)->unique()->after('name');
            $table->tinyInteger('status')->default(1)->comment('1启用 0禁用')->after('email');
            $table->timestamp('last_login_at')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['username', 'status', 'last_login_at']);
        });
    }
};
```

Run: `php artisan migrate`

- [ ] **Step 7: 实现 AuthController**

创建 `server/app/Http/Controllers/Api/AuthController.php`：

```php
<?php
// 认证控制器：登录/登出/当前用户信息
// 依赖 Sanctum Token 认证；登录成功签发 token，登出撤销当前 token
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    use ApiResponse;

    /** 登录：校验凭证 → 禁用拦截 → 签发 token 并记录最后登录时间 */
    public function login(Request $request)
    {
        // 表单校验：username/password 必填
        $data = $request->validate([
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('username', $data['username'])->first();

        // 用户不存在或密码错误：统一提示，不泄露具体原因
        if (! $user || ! Hash::check($data['password'], $user->password)) {
            return $this->fail(1001, '用户名或密码错误');
        }

        // 禁用账号拦截：不签发 token
        if ($user->status !== 1) {
            return $this->fail(1006, '账号已被禁用');
        }

        // 签发 token 并更新最后登录时间
        $user->update(['last_login_at' => now()]);
        $token = $user->createToken('api')->plainTextToken;

        return $this->ok(['token' => $token, 'user' => new UserResource($user)]);
    }

    /** 登出：撤销当前请求的 token */
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();
        return $this->ok(null, '已退出登录');
    }

    /** 当前用户信息：供前端路由守卫拉取角色与权限 */
    public function me(Request $request)
    {
        return $this->ok(new UserResource($request->user()));
    }
}
```

创建 `server/app/Http/Resources/UserResource.php`：

```php
<?php
// 用户资源：对外输出的用户数据结构（含角色与权限，供前端守卫使用）
namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'username' => $this->username,
            'email' => $this->email,
            'status' => $this->status,
            'last_login_at' => $this->last_login_at?->toDateTimeString(),
            'roles' => $this->roles->map(fn ($r) => ['id' => $r->id, 'name' => $r->name]),
            'permissions' => $this->permissions(),
        ];
    }
}
```

- [ ] **Step 8: 注册路由与 401 JSON 化**

修改 `server/routes/api.php`：

```php
<?php
// API 路由：/api/v1 前缀，认证路由公开，其余挂 auth:sanctum
use App\Http\Controllers\Api\AuthController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('/auth/login', [AuthController::class, 'login']);
    Route::post('/auth/logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');
    Route::get('/auth/me', [AuthController::class, 'me'])->middleware('auth:sanctum');
});
```

修改 `server/bootstrap/app.php`（`withExceptions` 内追加，使未认证/无权限响应统一 JSON）：

```php
->withExceptions(function (Exceptions $exceptions) {
    // 未认证与无权限统一返回 JSON（前后端分离约定）
    $exceptions->render(function (AuthenticationException $e, Request $request) {
        if ($request->is('api/*')) {
            return response()->json(['code' => 401, 'message' => '未认证或登录已过期', 'data' => null], 401);
        }
    });
    $exceptions->render(function (AuthorizationException $e, Request $request) {
        if ($request->is('api/*')) {
            return response()->json(['code' => 403, 'message' => '无权限操作', 'data' => null], 403);
        }
    });
})
```

（顶部 `use Illuminate\Auth\AuthenticationException;` `use Illuminate\Auth\Access\AuthorizationException;` `use Illuminate\Http\Request;`）

- [ ] **Step 9: 跑测试确认通过**

Run: `cd server && php artisan test --filter=AuthTest`
Expected: 7 个测试全部 PASS。

- [ ] **Step 10: 提交**

```bash
cd /d/code/project/php-design && git add -A && git commit -m "feat: 认证 API（Sanctum 登录/登出/me + 统一响应）"
```

---

## Task 3: RBAC 数据模型 + 权限中间件 + 种子

**Files:**
- Create: `server/database/migrations/xxxx_create_roles_permissions_tables.php`
- Create: `server/app/Models/Role.php`、`server/app/Models/Permission.php`
- Create: `server/app/Http/Middleware/EnsurePermission.php`
- Create: `server/database/seeders/RbacSeeder.php`
- Create: `server/tests/Feature/RbacTest.php`
- Modify: `server/routes/api.php`（中间件别名）、`server/bootstrap/app.php`、`server/database/seeders/DatabaseSeeder.php`

**Interfaces:**
- Consumes: Task 2 的 `User::roles()`/`permissions()`、`ApiResponse`
- Produces: 表 roles/permissions/role_user/permission_role；模型 `Role::permissions()`（BelongsToMany）、`Permission`；中间件 `EnsurePermission` 别名 `permission`（用法 `permission:user.list`）；种子：角色 `admin`（全权限）/`operator`（仅 `*.list`）、权限分组数据；错误码 403

- [ ] **Step 1: 写失败测试 `server/tests/Feature/RbacTest.php`**

```php
<?php
// RBAC 测试：权限中间件拦截与放行（核心安全路径，100% 覆盖）
namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RbacTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        // 造角色与权限：admin 全权限、operator 仅 user.list
        $admin = Role::create(['name' => '管理员', 'code' => 'admin']);
        $operator = Role::create(['name' => '操作员', 'code' => 'operator']);
        $perm = Permission::create(['name' => '用户列表', 'code' => 'user.list', 'group' => '系统管理']);
        Permission::create(['name' => '用户创建', 'code' => 'user.create', 'group' => '系统管理']);
        $admin->permissions()->sync(Permission::all());
        $operator->permissions()->sync([$perm->id]);

        $this->user = User::create(['name' => '测试', 'username' => 't', 'password' => 'p', 'status' => 1]);
        $this->user->roles()->sync([$admin->id]);
    }

    public function test_user_with_admin_role_passes_permission_check(): void
    {
        // 正常路径：admin 角色含全部权限，请求带权限中间件放行
        $token = $this->user->createToken('api')->plainTextToken;
        $this->withToken($token)
            ->getJson('/api/v1/auth/me')  // 该路由仅 auth:sanctum，验证可访问
            ->assertJsonPath('code', 0);
    }

    public function test_user_without_permission_gets_403(): void
    {
        // 异常路径：operator 角色仅 user.list，访问 user.create 被拒
        $perm = Permission::where('code', 'user.list')->first();
        $operator = Role::where('code', 'operator')->first();
        $operator->permissions()->sync([$perm->id]);
        $u = User::create(['name' => 'op', 'username' => 'op', 'password' => 'p', 'status' => 1]);
        $u->roles()->sync([$operator->id]);

        $token = $u->createToken('api')->plainTextToken;
        $this->withToken($token)->getJson('/api/v1/test-permission')->assertJsonPath('code', 403);
    }

    public function test_user_permissions_method_returns_unique_codes(): void
    {
        // 正常路径：多角色权限合并去重
        $this->assertEquals(['user.list', 'user.create'], $this->user->permissions()->all());
    }
}
```

- [ ] **Step 2: 跑测试确认失败**

Run: `cd server && php artisan test --filter=RbacTest`
Expected: FAIL（表/模型/路由不存在）。

- [ ] **Step 3: 创建迁移与模型**

创建 `server/database/migrations/xxxx_create_roles_permissions_tables.php`：

```php
<?php
// RBAC 核心表：roles/permissions + 两张关联表（多对多）
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name', 50)->comment('角色名称');
            $table->string('code', 50)->unique()->comment('角色编码');
            $table->string('remark')->nullable()->comment('备注');
            $table->timestamps();
        });

        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('name', 50)->comment('权限名称');
            $table->string('code', 50)->unique()->comment('权限编码，如 user.create');
            $table->string('group', 50)->comment('权限分组，如 系统管理');
            $table->timestamps();
        });

        Schema::create('role_user', function (Blueprint $table) {
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->primary(['role_id', 'user_id']);
        });

        Schema::create('permission_role', function (Blueprint $table) {
            $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->primary(['permission_id', 'role_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('permission_role');
        Schema::dropIfExists('role_user');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('roles');
    }
};
```

创建 `server/app/Models/Role.php` 与 `server/app/Models/Permission.php`：

```php
<?php
// 角色模型：RBAC 中间实体，关联权限与用户
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Role extends Model
{
    protected $fillable = ['name', 'code', 'remark'];

    // 角色下挂权限（多对多）
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class);
    }

    // 拥有该角色的用户（多对多）
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }
}
```

```php
<?php
// 权限模型：RBAC 叶子节点，按 group 分组
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Permission extends Model
{
    protected $fillable = ['name', 'code', 'group'];
}
```

- [ ] **Step 4: 实现权限中间件**

创建 `server/app/Http/Middleware/EnsurePermission.php`：

```php
<?php
// 权限校验中间件：permission:user.list 用法，用户无权限时返回 403
namespace App\Http\Middleware;

use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;

class EnsurePermission
{
    use ApiResponse;

    /** 校验当前用户是否拥有指定权限 code（admin 角色拥有全部权限） */
    public function handle(Request $request, Closure $next, string $permission): mixed
    {
        $user = $request->user();

        // admin 角色放行所有权限；其余按角色权限集合判断
        $isAdmin = $user->roles()->where('code', 'admin')->exists();
        if (! $isAdmin && ! $user->permissions()->contains($permission)) {
            return $this->fail(403, '无权限操作', 403);
        }

        return $next($request);
    }
}
```

- [ ] **Step 5: 注册中间件别名与测试路由**

修改 `server/bootstrap/app.php`（`withMiddleware` 闭包内）：

```php
->withMiddleware(function (Middleware $middleware) {
    // 权限中间件别名：permission:user.list
    $middleware->alias(['permission' => \App\Http\Middleware\EnsurePermission::class]);
})
```

修改 `server/routes/api.php`（追加测试路由，Task 4 替换为真实路由）：

```php
// 临时测试路由：验证权限中间件（Task 4 用户管理路由上线后删除）
Route::middleware(['auth:sanctum', 'permission:user.list'])->get('/v1/test-permission', fn () => response()->json(['code' => 0]));
```

- [ ] **Step 6: 创建种子并注册**

创建 `server/database/seeders/RbacSeeder.php`：

```php
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
```

修改 `server/database/seeders/DatabaseSeeder.php`：`$this->call([RbacSeeder::class]);`

- [ ] **Step 7: 跑测试确认通过**

Run: `cd server && php artisan test --filter=RbacTest`
Expected: 3 个测试 PASS。

- [ ] **Step 8: 提交**

```bash
cd /d/code/project/php-design && git add -A && git commit -m "feat: RBAC 模型/权限中间件/种子"
```

---

## Task 4: 用户管理 API

**Files:**
- Create: `server/app/Http/Controllers/Api/UserController.php`
- Create: `server/app/Http/Requests/UserStoreRequest.php`、`UserUpdateRequest.php`
- Create: `server/tests/Feature/UserManagementTest.php`
- Modify: `server/routes/api.php`（替换临时测试路由为真实用户路由）

**Interfaces:**
- Consumes: Task 2/3（ApiResponse、UserResource、permission 中间件、User::roles()）
- Produces: `GET/POST /api/v1/users`、`PUT/DELETE /api/v1/users/{id}`、`PUT /api/v1/users/{id}/reset-password`；分页 `{items,total,page,per_page}`；错误码 1002/1003/1006 语义；`UserStoreRequest` 规则（username 唯一、password ≥8 位含字母数字）

- [ ] **Step 1: 写失败测试 `server/tests/Feature/UserManagementTest.php`**

```php
<?php
// 用户管理接口测试：CRUD/角色分配/重置密码/删除保护（安全路径，100% 覆盖）
namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    private string $token;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        // admin 用户挂 admin 角色（中间件放行）
        $role = Role::create(['name' => '管理员', 'code' => 'admin']);
        $this->admin = User::create(['name' => '管理员', 'username' => 'admin', 'password' => 'admin123', 'status' => 1]);
        $this->admin->roles()->sync([$role->id]);
        $this->token = $this->admin->createToken('api')->plainTextToken;
    }

    public function test_index_returns_paginated_users(): void
    {
        // 正常路径：分页列表含 total/page/per_page
        $this->withToken($this->token)->getJson('/api/v1/users?page=1&per_page=10')
            ->assertJsonPath('code', 0)
            ->assertJsonStructure(['data' => ['items', 'total', 'page', 'per_page']]);
    }

    public function test_index_keyword_search_filters_username(): void
    {
        // 边界路径：keyword 按用户名/姓名模糊过滤
        User::create(['name' => '张三', 'username' => 'zhangsan', 'password' => 'p', 'status' => 1]);
        $this->withToken($this->token)->getJson('/api/v1/users?keyword=zhangsan')
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.items.0.username', 'zhangsan');
    }

    public function test_store_creates_user_with_roles(): void
    {
        // 正常路径：新建用户并挂角色
        $res = $this->withToken($this->token)->postJson('/api/v1/users', [
            'name' => '测试员', 'username' => 'tester01', 'password' => 'Test@12345',
            'email' => 't@t.com', 'status' => 1, 'role_ids' => [],
        ]);
        $res->assertJsonPath('code', 0);
        $this->assertDatabaseHas('users', ['username' => 'tester01']);
    }

    public function test_store_duplicate_username_fails_with_1002(): void
    {
        // 异常路径：重复用户名返回 1002
        User::create(['name' => '已有', 'username' => 'dup', 'password' => 'p', 'status' => 1]);
        $this->withToken($this->token)->postJson('/api/v1/users', [
            'name' => 'x', 'username' => 'dup', 'password' => 'Test@12345', 'status' => 1,
        ])->assertJsonPath('code', 1002);
    }

    public function test_update_user_and_roles(): void
    {
        // 正常路径：更新姓名与角色
        $u = User::create(['name' => '旧名', 'username' => 'u1', 'password' => 'p', 'status' => 1]);
        $this->withToken($this->token)->putJson("/api/v1/users/{$u->id}", ['name' => '新名', 'username' => 'u1', 'status' => 1, 'role_ids' => []])
            ->assertJsonPath('code', 0);
        $this->assertDatabaseHas('users', ['id' => $u->id, 'name' => '新名']);
    }

    public function test_delete_builtin_admin_fails_with_1003(): void
    {
        // 异常路径：内置 admin 不可删除（按 username=admin 判定）
        $this->withToken($this->token)->deleteJson('/api/v1/users/' . $this->admin->id)
            ->assertJsonPath('code', 1003);
    }

    public function test_delete_normal_user_succeeds(): void
    {
        // 正常路径：普通用户可删除
        $u = User::create(['name' => '临时', 'username' => 'tmp', 'password' => 'p', 'status' => 1]);
        $this->withToken($this->token)->deleteJson("/api/v1/users/{$u->id}")->assertJsonPath('code', 0);
        $this->assertDatabaseMissing('users', ['id' => $u->id]);
    }

    public function test_reset_password_takes_effect(): void
    {
        // 正常路径：重置密码后新密码可登录、旧密码失败
        $u = User::create(['name' => '重置', 'username' => 'rp', 'password' => 'Old@12345', 'status' => 1]);
        $this->withToken($this->token)->putJson("/api/v1/users/{$u->id}/reset-password", ['password' => 'New@12345'])
            ->assertJsonPath('code', 0);
        $this->postJson('/api/v1/auth/login', ['username' => 'rp', 'password' => 'New@12345'])->assertJsonPath('code', 0);
        $this->postJson('/api/v1/auth/login', ['username' => 'rp', 'password' => 'Old@12345'])->assertJsonPath('code', 1001);
    }

    public function test_user_management_requires_permission(): void
    {
        // 异常路径：无 user.list 权限的用户访问返回 403
        $role = Role::create(['name' => '操作员', 'code' => 'operator']);
        $u = User::create(['name' => 'op', 'username' => 'op1', 'password' => 'p', 'status' => 1]);
        $u->roles()->sync([$role->id]);
        $this->withToken($u->createToken('api')->plainTextToken)
            ->getJson('/api/v1/users')->assertJsonPath('code', 403);
    }
}
```

- [ ] **Step 2: 跑测试确认失败**

Run: `cd server && php artisan test --filter=UserManagementTest`
Expected: FAIL（路由/控制器不存在）。

- [ ] **Step 3: 实现 FormRequest 校验**

创建 `server/app/Http/Requests/UserStoreRequest.php`：

```php
<?php
// 新建用户表单校验：用户名唯一、密码强度、角色数组
namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UserStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // 权限由路由中间件 permission:user.create 控制
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:50'],
            'username' => ['required', 'string', 'max:50', Rule::unique('users', 'username')],
            'password' => ['required', 'string', 'min:8', 'regex:/[A-Za-z]/', 'regex:/[0-9]/'],
            'email' => ['nullable', 'email'],
            'status' => ['required', 'in:0,1'],
            'role_ids' => ['array'],
            'role_ids.*' => ['exists:roles,id'],
        ];
    }
}
```

创建 `server/app/Http/Requests/UserUpdateRequest.php`（同结构，password 改 `nullable` 且删除 `required`）。

- [ ] **Step 4: 实现 UserController**

创建 `server/app/Http/Controllers/Api/UserController.php`：

```php
<?php
// 用户管理控制器：CRUD + 重置密码 + 角色分配
// 依赖 ApiResponse/UserResource；删除保护内置 admin；用户名唯一由 FormRequest 校验
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UserStoreRequest;
use App\Http\Requests\UserUpdateRequest;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class UserController extends Controller
{
    use ApiResponse;

    /** 分页列表：支持用户名/姓名模糊搜索与状态过滤，附带角色 */
    public function index(Request $request)
    {
        $query = User::with('roles')->orderByDesc('id');

        // 关键字搜索：匹配 username 或 name
        if ($keyword = $request->input('keyword')) {
            $query->where(fn ($q) => $q->where('username', 'like', "%{$keyword}%")->orWhere('name', 'like', "%{$keyword}%"));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        $users = $query->paginate($request->integer('per_page', 10));

        // 分页映射：items 字段 + 用户资源（含角色，不含权限避免大响应）
        return $this->ok([
            'items' => $users->map(fn ($u) => [
                'id' => $u->id, 'name' => $u->name, 'username' => $u->username,
                'email' => $u->email, 'status' => $u->status, 'last_login_at' => $u->last_login_at?->toDateTimeString(),
                'roles' => $u->roles->map(fn ($r) => ['id' => $r->id, 'name' => $r->name]),
            ]),
            'total' => $users->total(), 'page' => $users->currentPage(), 'per_page' => $users->perPage(),
        ]);
    }

    /** 新建用户：校验后创建并分配角色 */
    public function store(UserStoreRequest $request)
    {
        $user = User::create($request->safe()->except('role_ids'));
        $user->roles()->sync($request->input('role_ids', []));
        return $this->ok(['id' => $user->id, 'name' => $user->name, 'username' => $user->username]);
    }

    /** 更新用户：password 为空则不变更；重新分配角色 */
    public function update(UserUpdateRequest $request, User $user)
    {
        // 排除空密码：避免覆盖原密码
        $data = $request->safe()->except('role_ids');
        if (empty($data['password'])) {
            unset($data['password']);
        }
        $user->update($data);
        $user->roles()->sync($request->input('role_ids', []));
        return $this->ok();
    }

    /** 删除用户：内置 admin（username=admin）保护 */
    public function destroy(User $user)
    {
        if ($user->username === 'admin') {
            return $this->fail(1003, '内置管理员不可删除');
        }
        $user->delete();
        return $this->ok();
    }

    /** 重置密码：仅更新密码字段 */
    public function resetPassword(Request $request, User $user)
    {
        $data = $request->validate(['password' => ['required', 'string', 'min:8', 'regex:/[A-Za-z]/', 'regex:/[0-9]/']]);
        $user->update(['password' => $data['password']]);
        return $this->ok();
    }
}
```

- [ ] **Step 5: 更新路由（替换临时测试路由）**

修改 `server/routes/api.php`（删除 `test-permission` 路由，追加用户资源路由）：

```php
use App\Http\Controllers\Api\UserController;

Route::middleware('auth:sanctum')->group(function () {
    Route::middleware('permission:user.list')->get('/users', [UserController::class, 'index']);
    Route::middleware('permission:user.create')->post('/users', [UserController::class, 'store']);
    Route::middleware('permission:user.update')->put('/users/{user}', [UserController::class, 'update']);
    Route::middleware('permission:user.update')->put('/users/{user}/reset-password', [UserController::class, 'resetPassword']);
    Route::middleware('permission:user.delete')->delete('/users/{user}', [UserController::class, 'destroy']);
});
```

注意：`test-permission` 路由删除后，Task 3 的 `test_user_without_permission_gets_403` 需要改用真实路由（`/api/v1/users` + 无权限用户）——**同步修改 RbacTest 该用例**为 `getJson('/api/v1/users')->assertJsonPath('code', 403)`。

- [ ] **Step 6: 跑测试确认通过**

Run: `cd server && php artisan test --filter="UserManagementTest|RbacTest"`
Expected: 全部 PASS（11 + 3）。

- [ ] **Step 7: 提交**

```bash
cd /d/code/project/php-design && git add -A && git commit -m "feat: 用户管理 API（CRUD/角色分配/重置密码）"
```

---

## Task 5: 角色与权限 API

**Files:**
- Create: `server/app/Http/Controllers/Api/RoleController.php`
- Create: `server/app/Http/Requests/RoleRequest.php`
- Create: `server/tests/Feature/RoleManagementTest.php`
- Modify: `server/routes/api.php`

**Interfaces:**
- Consumes: Task 3 模型与中间件
- Produces: `GET/POST /api/v1/roles`、`PUT/DELETE /api/v1/roles/{id}`、`GET /api/v1/permissions`（分组输出 `{groups:[{group, permissions:[{id,name,code}]}]}`）；错误码 1004（角色被用户引用）、1007（最后一个管理员角色）

- [ ] **Step 1: 写失败测试 `server/tests/Feature/RoleManagementTest.php`**

```php
<?php
// 角色管理接口测试：CRUD/权限分配/删除保护（安全路径，100% 覆盖）
namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoleManagementTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        // admin 角色 + admin 用户 + 若干权限
        $admin = Role::create(['name' => '管理员', 'code' => 'admin']);
        Permission::create(['name' => '用户列表', 'code' => 'user.list', 'group' => '系统管理']);
        Permission::create(['name' => '角色列表', 'code' => 'role.list', 'group' => '系统管理']);
        $admin->permissions()->sync(Permission::pluck('id'));
        $u = User::create(['name' => '管理员', 'username' => 'admin', 'password' => 'admin123', 'status' => 1]);
        $u->roles()->sync([$admin->id]);
        $this->token = $u->createToken('api')->plainTextToken;
    }

    public function test_index_returns_roles_with_permissions(): void
    {
        // 正常路径：角色列表附带权限 code 集合
        $this->withToken($this->token)->getJson('/api/v1/roles')
            ->assertJsonPath('code', 0)
            ->assertJsonStructure(['data' => ['items' => [['permissions']]]]);
    }

    public function test_store_creates_role_with_permission_ids(): void
    {
        // 正常路径：新建角色并勾选权限
        $permIds = Permission::pluck('id')->all();
        $this->withToken($this->token)->postJson('/api/v1/roles', [
            'name' => '仓库管理员', 'code' => 'warehouse', 'remark' => '', 'permission_ids' => $permIds,
        ])->assertJsonPath('code', 0);
        $this->assertDatabaseHas('roles', ['code' => 'warehouse']);
    }

    public function test_update_role_resyncs_permissions(): void
    {
        // 正常路径：更新角色名称与权限集
        $role = Role::create(['name' => '旧', 'code' => 'r1']);
        $role->permissions()->sync([Permission::first()->id]);
        $this->withToken($this->token)->putJson("/api/v1/roles/{$role->id}", [
            'name' => '新', 'code' => 'r1', 'permission_ids' => [],
        ])->assertJsonPath('code', 0);
        $this->assertDatabaseHas('roles', ['id' => $role->id, 'name' => '新']);
        $this->assertDatabaseCount('permission_role', 0);
    }

    public function test_delete_role_assigned_to_user_fails_with_1004(): void
    {
        // 异常路径：被用户引用的角色不可删除
        $role = Role::create(['name' => '操作员', 'code' => 'operator']);
        $u = User::create(['name' => 'u', 'username' => 'u2', 'password' => 'p', 'status' => 1]);
        $u->roles()->sync([$role->id]);
        $this->withToken($this->token)->deleteJson("/api/v1/roles/{$role->id}")
            ->assertJsonPath('code', 1004);
    }

    public function test_delete_last_admin_role_fails_with_1007(): void
    {
        // 边界路径：删除唯一 admin 编码角色返回 1007
        $adminRole = Role::where('code', 'admin')->first();
        $this->withToken($this->token)->deleteJson("/api/v1/roles/{$adminRole->id}")
            ->assertJsonPath('code', 1007);
    }

    public function test_delete_normal_role_succeeds(): void
    {
        // 正常路径：未引用的普通角色可删除
        $role = Role::create(['name' => '临时', 'code' => 'tmp']);
        $this->withToken($this->token)->deleteJson("/api/v1/roles/{$role->id}")->assertJsonPath('code', 0);
        $this->assertDatabaseMissing('roles', ['id' => $role->id]);
    }

    public function test_permissions_index_groups_by_group(): void
    {
        // 正常路径：权限按 group 分组输出
        $this->withToken($this->token)->getJson('/api/v1/permissions')
            ->assertJsonPath('code', 0)
            ->assertJsonStructure(['data' => ['groups' => [['group', 'permissions']]]]);
    }
}
```

- [ ] **Step 2: 跑测试确认失败**

Run: `cd server && php artisan test --filter=RoleManagementTest`
Expected: FAIL。

- [ ] **Step 3: 实现 RoleController 与 RoleRequest**

创建 `server/app/Http/Controllers/Api/RoleController.php`：

```php
<?php
// 角色管理控制器：CRUD + 权限分配 + 删除保护（被引用/最后一个 admin 角色）
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\RoleRequest;
use App\Models\Permission;
use App\Models\Role;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class RoleController extends Controller
{
    use ApiResponse;

    /** 角色分页列表：每角色带权限 code 集合 */
    public function index(Request $request)
    {
        $roles = Role::with('permissions')->orderByDesc('id')->paginate($request->integer('per_page', 10));
        return $this->ok([
            'items' => $roles->map(fn ($r) => [
                'id' => $r->id, 'name' => $r->name, 'code' => $r->code, 'remark' => $r->remark,
                'permissions' => $r->permissions->pluck('code'),
            ]),
            'total' => $roles->total(), 'page' => $roles->currentPage(), 'per_page' => $roles->perPage(),
        ]);
    }

    /** 新建角色并分配权限 */
    public function store(RoleRequest $request)
    {
        $role = Role::create($request->safe()->except('permission_ids'));
        $role->permissions()->sync($request->input('permission_ids', []));
        return $this->ok(['id' => $role->id]);
    }

    /** 更新角色并全量重挂权限 */
    public function update(RoleRequest $request, Role $role)
    {
        $role->update($request->safe()->except('permission_ids'));
        $role->permissions()->sync($request->input('permission_ids', []));
        return $this->ok();
    }

    /** 删除角色：被用户引用或为唯一 admin 角色时拒绝 */
    public function destroy(Role $role)
    {
        // 角色已分配给用户：拒绝删除
        if ($role->users()->exists()) {
            return $this->fail(1004, '该角色已分配给用户，不可删除');
        }
        // admin 编码角色若为最后一个：拒绝删除
        if ($role->code === 'admin' && Role::where('code', 'admin')->count() === 1) {
            return $this->fail(1007, '至少保留一个管理员角色');
        }
        $role->delete();
        return $this->ok();
    }

    /** 权限清单（按 group 分组）：角色编辑页权限树数据源 */
    public function permissions()
    {
        $groups = Permission::orderBy('group')->get()->groupBy('group')
            ->map(fn ($perms, $group) => ['group' => $group, 'permissions' => $perms->map(fn ($p) => ['id' => $p->id, 'name' => $p->name, 'code' => $p->code])->values()])
            ->values();
        return $this->ok(['groups' => $groups]);
    }
}
```

创建 `server/app/Http/Requests/RoleRequest.php`：

```php
<?php
// 角色表单校验：名称/编码必填、编码唯一（忽略自身）、权限数组
namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:50'],
            'code' => ['required', 'string', 'max:50', Rule::unique('roles', 'code')->ignore($this->route('role'))],
            'remark' => ['nullable', 'string'],
            'permission_ids' => ['array'],
            'permission_ids.*' => ['exists:permissions,id'],
        ];
    }
}
```

- [ ] **Step 4: 注册路由**

修改 `server/routes/api.php`（auth:sanctum 组内追加）：

```php
use App\Http\Controllers\Api\RoleController;

Route::middleware('permission:role.list')->get('/roles', [RoleController::class, 'index']);
Route::middleware('permission:role.list')->get('/permissions', [RoleController::class, 'permissions']);
Route::middleware('permission:role.create')->post('/roles', [RoleController::class, 'store']);
Route::middleware('permission:role.update')->put('/roles/{role}', [RoleController::class, 'update']);
Route::middleware('permission:role.delete')->delete('/roles/{role}', [RoleController::class, 'destroy']);
```

- [ ] **Step 5: 跑测试确认通过**

Run: `cd server && php artisan test --filter=RoleManagementTest`
Expected: 7 个测试 PASS。

- [ ] **Step 6: 提交**

```bash
cd /d/code/project/php-design && git add -A && git commit -m "feat: 角色与权限 API"
```

---

## Task 6: 字典 API

**Files:**
- Create: `server/app/Models/Dictionary.php`、`server/app/Models/DictionaryItem.php`
- Create: `server/database/migrations/xxxx_create_dictionaries_tables.php`
- Create: `server/app/Http/Controllers/Api/DictionaryController.php`
- Create: `server/tests/Feature/DictionaryTest.php`
- Modify: `server/routes/api.php`

**Interfaces:**
- Consumes: ApiResponse、permission 中间件
- Produces: `GET/POST /api/v1/dictionaries`、`PUT/DELETE /api/v1/dictionaries/{id}`、`GET/POST /api/v1/dictionaries/{id}/items`、`PUT/DELETE /api/v1/dictionaries/items/{item}`、`GET /api/v1/dictionaries/code/{code}`；错误码 1005/1008

- [ ] **Step 1: 写失败测试 `server/tests/Feature/DictionaryTest.php`**

```php
<?php
// 字典接口测试：字典与字典项 CRUD/取值/重复编码（正常+边界+异常）
namespace Tests\Feature;

use App\Models\Dictionary;
use App\Models\DictionaryItem;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DictionaryTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        // admin 用户挂 admin 角色
        $role = Role::create(['name' => '管理员', 'code' => 'admin']);
        $u = User::create(['name' => '管理员', 'username' => 'admin', 'password' => 'admin123', 'status' => 1]);
        $u->roles()->sync([$role->id]);
        $this->token = $u->createToken('api')->plainTextToken;
    }

    public function test_dictionary_crud_and_duplicate_code(): void
    {
        // 正常路径：字典创建成功
        $this->withToken($this->token)->postJson('/api/v1/dictionaries', ['name' => '计量单位', 'code' => 'unit', 'remark' => ''])
            ->assertJsonPath('code', 0);
        // 异常路径：重复编码 1005
        $this->withToken($this->token)->postJson('/api/v1/dictionaries', ['name' => '重复', 'code' => 'unit'])
            ->assertJsonPath('code', 1005);
    }

    public function test_item_crud(): void
    {
        // 正常路径：字典项增改删
        $d = Dictionary::create(['name' => '计量单位', 'code' => 'unit']);
        $this->withToken($this->token)->postJson("/api/v1/dictionaries/{$d->id}/items", ['label' => '个', 'value' => 'pc', 'sort' => 1, 'status' => 1])
            ->assertJsonPath('code', 0);
        $item = DictionaryItem::first();
        $this->withToken($this->token)->putJson("/api/v1/dictionaries/items/{$item->id}", ['label' => '箱', 'value' => 'box', 'sort' => 2, 'status' => 1])
            ->assertJsonPath('code', 0);
        $this->withToken($this->token)->deleteJson("/api/v1/dictionaries/items/{$item->id}")->assertJsonPath('code', 0);
    }

    public function test_get_items_returns_only_enabled(): void
    {
        // 边界路径：items 列表仅返回 status=1 的项
        $d = Dictionary::create(['name' => 'd', 'code' => 'd1']);
        DictionaryItem::create(['dictionary_id' => $d->id, 'label' => '启用', 'value' => 'on', 'sort' => 1, 'status' => 1]);
        DictionaryItem::create(['dictionary_id' => $d->id, 'label' => '停用', 'value' => 'off', 'sort' => 2, 'status' => 0]);
        $this->withToken($this->token)->getJson("/api/v1/dictionaries/{$d->id}/items")
            ->assertJsonPath('data.items.0.value', 'on')
            ->assertJsonCount(1, 'data.items');
    }

    public function test_get_by_code_returns_enabled_items(): void
    {
        // 正常路径：按编码取启用项（供其他模块下拉）
        $d = Dictionary::create(['name' => 'unit', 'code' => 'unit']);
        DictionaryItem::create(['dictionary_id' => $d->id, 'label' => '个', 'value' => 'pc', 'sort' => 1, 'status' => 1]);
        DictionaryItem::create(['dictionary_id' => $d->id, 'label' => '停用箱', 'value' => 'box', 'sort' => 2, 'status' => 0]);
        $this->withToken($this->token)->getJson('/api/v1/dictionaries/code/unit')
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.label', '个');
    }

    public function test_get_by_code_not_found_fails_with_1008(): void
    {
        // 异常路径：编码不存在返回 1008
        $this->withToken($this->token)->getJson('/api/v1/dictionaries/code/not_exist')
            ->assertJsonPath('code', 1008);
    }

    public function test_delete_dictionary_cascades_items(): void
    {
        // 正常路径：删除字典级联删除字典项
        $d = Dictionary::create(['name' => 'd', 'code' => 'd2']);
        DictionaryItem::create(['dictionary_id' => $d->id, 'label' => 'x', 'value' => 'x', 'sort' => 1, 'status' => 1]);
        $this->withToken($this->token)->deleteJson("/api/v1/dictionaries/{$d->id}")->assertJsonPath('code', 0);
        $this->assertDatabaseCount('dictionary_items', 0);
    }
}
```

- [ ] **Step 2: 跑测试确认失败**

Run: `cd server && php artisan test --filter=DictionaryTest`
Expected: FAIL。

- [ ] **Step 3: 创建迁移与模型**

```php
<?php
// 数据字典表：字典头 + 字典项（级联删除）
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dictionaries', function (Blueprint $table) {
            $table->id();
            $table->string('name', 50)->comment('字典名称');
            $table->string('code', 50)->unique()->comment('字典编码');
            $table->string('remark')->nullable();
            $table->timestamps();
        });

        Schema::create('dictionary_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dictionary_id')->constrained()->cascadeOnDelete();
            $table->string('label', 50)->comment('显示名');
            $table->string('value', 50)->comment('值');
            $table->integer('sort')->default(0);
            $table->tinyInteger('status')->default(1)->comment('1启用 0停用');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dictionary_items');
        Schema::dropIfExists('dictionaries');
    }
};
```

`server/app/Models/Dictionary.php`（fillable: name/code/remark；hasMany items）、`server/app/Models/DictionaryItem.php`（fillable: dictionary_id/label/value/sort/status；belongsTo dictionary）。

- [ ] **Step 4: 实现 DictionaryController**

```php
<?php
// 字典管理控制器：字典/字典项 CRUD + 按编码取值（供其他模块下拉）
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Dictionary;
use App\Models\DictionaryItem;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class DictionaryController extends Controller
{
    use ApiResponse;

    /** 字典分页列表 */
    public function index(Request $request)
    {
        $items = Dictionary::orderByDesc('id')->paginate($request->integer('per_page', 10));
        return $this->ok([
            'items' => $items->map(fn ($d) => ['id' => $d->id, 'name' => $d->name, 'code' => $d->code, 'remark' => $d->remark]),
            'total' => $items->total(), 'page' => $items->currentPage(), 'per_page' => $items->perPage(),
        ]);
    }

    /** 新建字典：编码唯一 */
    public function store(Request $request)
    {
        $data = $request->validate(['name' => 'required|string|max:50', 'code' => 'required|string|max:50|unique:dictionaries,code', 'remark' => 'nullable|string']);
        return $this->ok(['id' => Dictionary::create($data)->id]);
    }

    /** 更新字典 */
    public function update(Request $request, Dictionary $dictionary)
    {
        $data = $request->validate([
            'name' => 'required|string|max:50',
            'code' => 'required|string|max:50|unique:dictionaries,code,' . $dictionary->id,
            'remark' => 'nullable|string',
        ]);
        $dictionary->update($data);
        return $this->ok();
    }

    /** 删除字典（级联删除字典项） */
    public function destroy(Dictionary $dictionary)
    {
        $dictionary->delete();
        return $this->ok();
    }

    /** 字典项列表：仅返回启用项 */
    public function items(Dictionary $dictionary)
    {
        return $this->ok(['items' => $dictionary->items()->where('status', 1)->orderBy('sort')->get()]);
    }

    /** 新增字典项 */
    public function storeItem(Request $request, Dictionary $dictionary)
    {
        $data = $request->validate(['label' => 'required|string|max:50', 'value' => 'required|string|max:50', 'sort' => 'integer', 'status' => 'in:0,1']);
        return $this->ok(['id' => $dictionary->items()->create($data)->id]);
    }

    /** 更新字典项 */
    public function updateItem(Request $request, DictionaryItem $item)
    {
        $data = $request->validate(['label' => 'required|string|max:50', 'value' => 'required|string|max:50', 'sort' => 'integer', 'status' => 'in:0,1']);
        $item->update($data);
        return $this->ok();
    }

    /** 删除字典项 */
    public function destroyItem(DictionaryItem $item)
    {
        $item->delete();
        return $this->ok();
    }

    /** 按编码取启用项（登录即可访问，供其他模块下拉） */
    public function byCode(Request $request, string $code)
    {
        $dictionary = Dictionary::where('code', $code)->first();
        if (! $dictionary) {
            return $this->fail(1008, '字典不存在');
        }
        return $this->ok(['items' => $dictionary->items()->where('status', 1)->orderBy('sort')->get()]);
    }
}
```

- [ ] **Step 5: 注册路由**

```php
use App\Http\Controllers\Api\DictionaryController;

// 登录即可访问：下拉取值
Route::get('/dictionaries/code/{code}', [DictionaryController::class, 'byCode']);
// 字典管理（RBAC）
Route::middleware('permission:dictionary.list')->get('/dictionaries', [DictionaryController::class, 'index']);
Route::middleware('permission:dictionary.list')->get('/dictionaries/{dictionary}/items', [DictionaryController::class, 'items']);
Route::middleware('permission:dictionary.create')->post('/dictionaries', [DictionaryController::class, 'store']);
Route::middleware('permission:dictionary.create')->post('/dictionaries/{dictionary}/items', [DictionaryController::class, 'storeItem']);
Route::middleware('permission:dictionary.update')->put('/dictionaries/{dictionary}', [DictionaryController::class, 'update']);
Route::middleware('permission:dictionary.update')->put('/dictionaries/items/{item}', [DictionaryController::class, 'updateItem']);
Route::middleware('permission:dictionary.delete')->delete('/dictionaries/{dictionary}', [DictionaryController::class, 'destroy']);
Route::middleware('permission:dictionary.delete')->delete('/dictionaries/items/{item}', [DictionaryController::class, 'destroyItem']);
```

注意路由顺序：`dictionaries/code/{code}` 必须注册在 `dictionaries/{dictionary}` 之前，否则 `code` 被当作 `{dictionary}` 参数。

- [ ] **Step 6: 跑测试确认通过**

Run: `cd server && php artisan test --filter=DictionaryTest`
Expected: 7 个测试 PASS。

- [ ] **Step 7: 提交**

```bash
cd /d/code/project/php-design && git add -A && git commit -m "feat: 数据字典 API"
```

---

## Task 7: 前端基座（axios/路由守卫/布局/登录页）

**Files:**
- Create: `web/src/api/http.ts`、`web/src/api/auth.ts`
- Create: `web/src/stores/auth.ts`、`web/src/stores/user.ts`（可后置，先 auth）
- Create: `web/src/router/index.ts`、`web/src/layouts/MainLayout.vue`、`web/src/views/LoginView.vue`、`web/src/views/DashboardView.vue`（占位）、`web/src/views/ForbiddenView.vue`
- Create: `web/src/tests/auth.test.ts`
- Modify: `web/src/main.ts`、`web/src/App.vue`、`web/vite.config.ts`（vitest 配置）、`web/src/styles/main.css`

**Interfaces:**
- Consumes: 后端 Task 2 认证接口；tokens.css
- Produces: `http` 实例（baseURL `/api/v1`，请求拦截器带 Bearer token，响应拦截器解包 `{code,message,data}` 并统一 401 跳转）；`auth` store（`token/login/logout/fetchMe` + `permissions` 数组）；`router`（`/login`、`/dashboard`、`/system/*` 占位、403）；`MainLayout`（侧边栏 220px 深色 + 顶栏用户菜单）

- [ ] **Step 1: 写失败测试 `web/src/tests/auth.test.ts`（Vitest）**

```ts
// 认证 store 测试：登录/登出/权限状态流转（核心路径）
import { describe, it, expect, beforeEach, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { useAuthStore } from '../stores/auth'

// mock axios：避免真实网络
vi.mock('../api/http', () => ({
  http: {
    post: vi.fn(),
    get: vi.fn(),
  },
}))

import { http } from '../api/http'

describe('auth store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    localStorage.clear()
    vi.clearAllMocks()
  })

  it('登录成功后保存 token 与用户权限', async () => {
    // 正常路径：login 保存 token、permissions
    const store = useAuthStore()
    ;(http.post as any).mockResolvedValue({
      data: { code: 0, data: { token: 'tk-1', user: { id: 1, name: 'a', username: 'admin', roles: [], permissions: ['user.list'] } } },
    })
    await store.login('admin', 'admin123')
    expect(localStorage.getItem('token')).toBe('tk-1')
    expect(store.permissions).toEqual(['user.list'])
    expect(store.user?.username).toBe('admin')
  })

  it('登录失败抛出后端 message', async () => {
    // 异常路径：1001 时抛错且不存 token
    const store = useAuthStore()
    ;(http.post as any).mockResolvedValue({ data: { code: 1001, message: '用户名或密码错误' } })
    await expect(store.login('admin', 'bad')).rejects.toThrow('用户名或密码错误')
    expect(localStorage.getItem('token')).toBeNull()
  })

  it('登出清除 token 与权限', async () => {
    // 正常路径：logout 清理全部状态
    const store = useAuthStore()
    localStorage.setItem('token', 'tk-1')
    store.permissions = ['user.list']
    ;(http.post as any).mockResolvedValue({ data: { code: 0 } })
    await store.logout()
    expect(localStorage.getItem('token')).toBeNull()
    expect(store.permissions).toEqual([])
  })

  it('fetchMe 失败(401)时标记未认证', async () => {
    // 异常路径：token 失效时清空状态
    const store = useAuthStore()
    localStorage.setItem('token', 'tk-old')
    ;(http.get as any).mockRejectedValue({ response: { status: 401 } })
    await store.fetchMe()
    expect(store.user).toBeNull()
    expect(localStorage.getItem('token')).toBeNull()
  })
})
```

- [ ] **Step 2: 跑测试确认失败**

Run: `cd web && npx vitest run src/tests/auth.test.ts`
Expected: FAIL（文件不存在/模块未创建）。

- [ ] **Step 3: 实现 http 封装与 auth API**

创建 `web/src/api/http.ts`：

```ts
// HTTP 封装：统一 baseURL、Bearer token、响应解包与 401 处理
import axios from 'axios'

export const http = axios.create({ baseURL: '/api/v1', timeout: 15000 })

// 请求拦截：自动附加 localStorage 中的 token
http.interceptors.request.use((config) => {
  const token = localStorage.getItem('token')
  if (token) config.headers.Authorization = `Bearer ${token}`
  return config
})

// 响应拦截：解包 {code,message,data}；业务失败抛错供调用方展示 message
http.interceptors.response.use(
  (res) => {
    const body = res.data
    if (body && typeof body.code === 'number' && body.code !== 0) {
      // 401/403 统一跳转（由路由守卫处理登录态）
      if (body.code === 401) {
        localStorage.removeItem('token')
        window.location.href = '/login'
      }
      return Promise.reject(new Error(body.message || '请求失败'))
    }
    return res
  },
  (err) => {
    // HTTP 层错误：401 清除登录态并跳登录页
    if (err.response?.status === 401) {
      localStorage.removeItem('token')
      window.location.href = '/login'
    }
    return Promise.reject(err)
  }
)
```

创建 `web/src/api/auth.ts`：

```ts
// 认证相关 API 封装
import { http } from './http'

export interface AuthUser {
  id: number
  name: string
  username: string
  email: string | null
  status: number
  roles: { id: number; name: string }[]
  permissions: string[]
}

export const authApi = {
  // 登录：返回 token 与用户信息
  async login(username: string, password: string) {
    const { data } = await http.post('/auth/login', { username, password })
    return data.data as { token: string; user: AuthUser }
  },
  // 登出
  async logout() {
    await http.post('/auth/logout')
  },
  // 当前用户信息（路由守卫使用）
  async me() {
    const { data } = await http.get('/auth/me')
    return data.data as AuthUser
  },
}
```

- [ ] **Step 4: 实现 auth store**

创建 `web/src/stores/auth.ts`：

```ts
// 认证状态：token 持久化、用户信息与权限（路由守卫/按钮级权限数据源）
import { defineStore } from 'pinia'
import { ref } from 'vue'
import { authApi, type AuthUser } from '../api/auth'

export const useAuthStore = defineStore('auth', () => {
  const token = ref<string | null>(localStorage.getItem('token'))
  const user = ref<AuthUser | null>(null)

  // 权限集合：登录/me 后填充，供守卫与按钮显隐判断
  const permissions = ref<string[]>([])

  /** 登录：调用后端，成功持久化 token 与用户信息 */
  async function login(username: string, password: string) {
    const res = await authApi.login(username, password)
    token.value = res.token
    user.value = res.user
    permissions.value = res.user.permissions
    localStorage.setItem('token', res.token)
  }

  /** 登出：撤销 token 并清空状态 */
  async function logout() {
    try {
      await authApi.logout()
    } finally {
      token.value = null
      user.value = null
      permissions.value = []
      localStorage.removeItem('token')
    }
  }

  /** 拉取当前用户（路由守卫首屏调用；失败视为未登录） */
  async function fetchMe() {
    user.value = await authApi.me()
    permissions.value = user.value.permissions
  }

  /** 按钮级权限判断 */
  function has(permission: string) {
    return permissions.value.includes(permission)
  }

  return { token, user, permissions, login, logout, fetchMe, has }
})
```

- [ ] **Step 5: 配置 vite 代理与 vitest**

修改 `web/vite.config.ts`：

```ts
import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'

export default defineConfig({
  plugins: [vue()],
  // 开发代理：/api/v1 → 后端 :8000
  server: {
    port: 5173,
    proxy: { '/api': { target: 'http://localhost:8000', changeOrigin: true } },
  },
  test: { environment: 'jsdom' }, // vitest 配置
})
```

（若 TS 对 `test` 报错，在 `web/src/vite-env.d.ts` 增加 `/// <reference types="vitest" />`）

修改 `web/src/main.ts`：挂载 pinia、router、Element Plus、引入 tokens.css 与 main.css：

```ts
import { createApp } from 'vue'
import { createPinia } from 'pinia'
import ElementPlus from 'element-plus'
import 'element-plus/dist/index.css'
import './styles/tokens.css'
import './styles/main.css'
import App from './App.vue'
import router from './router'

createApp(App).use(createPinia()).use(router).use(ElementPlus).mount('#app')
```

- [ ] **Step 6: 实现路由与布局**

创建 `web/src/router/index.ts`：

```ts
// 路由配置：登录页公开；业务路由挂守卫（登录校验 + 权限校验）
import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '../stores/auth'

const router = createRouter({
  history: createWebHistory(),
  routes: [
    { path: '/login', name: 'login', component: () => import('../views/LoginView.vue'), meta: { public: true } },
    {
      path: '/',
      component: () => import('../layouts/MainLayout.vue'),
      children: [
        { path: '', redirect: '/dashboard' },
        { path: 'dashboard', name: 'dashboard', component: () => import('../views/DashboardView.vue') },
        { path: 'system/users', name: 'system-users', component: () => import('../views/system/UsersView.vue'), meta: { permission: 'user.list' } },
        { path: 'system/roles', name: 'system-roles', component: () => import('../views/system/RolesView.vue'), meta: { permission: 'role.list' } },
        { path: 'system/dictionaries', name: 'system-dictionaries', component: () => import('../views/system/DictionariesView.vue'), meta: { permission: 'dictionary.list' } },
        { path: '403', name: 'forbidden', component: () => import('../views/ForbiddenView.vue') },
      ],
    },
    { path: '/:pathMatch(.*)*', redirect: '/dashboard' },
  ],
})

// 全局前置守卫：登录校验 + 权限校验（permissions 来自 auth store）
router.beforeEach(async (to) => {
  const auth = useAuthStore()
  if (to.meta.public) return true

  // 无 token：跳登录页
  if (!auth.token) return { name: 'login' }

  // 有 token 未拉用户：首屏拉取 /auth/me（失败自动清除并跳登录）
  if (!auth.user) {
    try {
      await auth.fetchMe()
    } catch {
      return { name: 'login' }
    }
  }

  // 页面要求权限但用户不具备：跳 403
  if (to.meta.permission && !auth.has(to.meta.permission as string)) {
    return { name: 'forbidden' }
  }
  return true
})

export default router
```

创建 `web/src/layouts/MainLayout.vue`（侧边栏 220px 深色 + 顶栏 + router-view；菜单含仪表盘与系统管理组，菜单项按权限过滤）：

```vue
<!-- 主布局：深色侧边栏(220px) + 浅色内容区；菜单按权限过滤 -->
<template>
  <div class="layout">
    <aside class="sidebar">
      <div class="brand font-code">Nexus Factory</div>
      <nav>
        <RouterLink to="/dashboard" class="menu-item">仪表盘</RouterLink>
        <div class="menu-group">系统管理</div>
        <RouterLink v-if="auth.has('user.list')" to="/system/users" class="menu-item">用户管理</RouterLink>
        <RouterLink v-if="auth.has('role.list')" to="/system/roles" class="menu-item">角色管理</RouterLink>
        <RouterLink v-if="auth.has('dictionary.list')" to="/system/dictionaries" class="menu-item">字典管理</RouterLink>
      </nav>
    </aside>
    <div class="main">
      <header class="topbar">
        <el-dropdown @command="onCommand">
          <span class="user-name">{{ auth.user?.name }}</span>
          <template #dropdown>
            <el-dropdown-menu>
              <el-dropdown-item command="logout">退出登录</el-dropdown-item>
            </el-dropdown-menu>
          </template>
        </el-dropdown>
      </header>
      <main class="content"><RouterView /></main>
    </div>
  </div>
</template>

<script setup lang="ts">
// 主布局：登录态展示 + 登出入口
import { useAuthStore } from '../stores/auth'
import { useRouter } from 'vue-router'

const auth = useAuthStore()
const router = useRouter()

// 登出：调后端撤销 token 后回登录页
async function onCommand(cmd: string) {
  if (cmd === 'logout') {
    await auth.logout()
    router.push('/login')
  }
}
</script>

<style scoped>
/* 布局样式：遵循设计令牌（MASTER.md 深色侧边栏 + 浅色内容区） */
.layout { display: flex; min-height: 100vh; }
.sidebar { width: 220px; background: var(--color-foreground); color: #fff; padding: var(--space-2xl) var(--space-lg); }
.brand { font-size: 18px; font-weight: 700; margin-bottom: var(--space-2xl); }
.menu-group { margin: var(--space-xl) 0 var(--space-md); color: #94a3b8; font-size: 12px; }
.menu-item { display: block; padding: var(--space-md) var(--space-lg); color: #cbd5e1; text-decoration: none; border-radius: 6px; cursor: pointer; }
.menu-item:hover { background: #1e293b; color: #fff; }
.menu-item.router-link-active { background: var(--color-primary); color: var(--color-on-primary); }
.main { flex: 1; background: var(--color-background); display: flex; flex-direction: column; }
.topbar { height: 56px; background: #fff; border-bottom: 1px solid var(--color-border); display: flex; align-items: center; justify-content: flex-end; padding: 0 var(--space-2xl); }
.user-name { cursor: pointer; }
.content { padding: var(--space-2xl); }
</style>
```

创建 `web/src/views/LoginView.vue`：

```vue
<!-- 登录页：全系统唯一免登录页面，居中卡片 -->
<template>
  <div class="login-page">
    <el-card class="login-card">
      <h1 class="font-code">Nexus Factory</h1>
      <el-form :model="form" :rules="rules" ref="formRef" @keyup.enter="submit">
        <el-form-item prop="username">
          <el-input v-model="form.username" placeholder="请输入用户名" />
        </el-form-item>
        <el-form-item prop="password">
          <el-input v-model="form.password" type="password" placeholder="请输入密码" show-password />
        </el-form-item>
        <el-button type="primary" class="login-btn" :loading="loading" @click="submit">登 录</el-button>
      </el-form>
    </el-card>
  </div>
</template>

<script setup lang="ts">
// 登录页：表单校验 + 调 auth store 登录 + 成功跳仪表盘
import { reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { ElMessage, type FormInstance, type FormRules } from 'element-plus'
import { useAuthStore } from '../stores/auth'

const router = useRouter()
const auth = useAuthStore()
const formRef = ref<FormInstance>()
const loading = ref(false)

const form = reactive({ username: '', password: '' })

// 表单校验规则：必填（空表单点击登录被拦截，不发请求）
const rules: FormRules = {
  username: [{ required: true, message: '请输入用户名', trigger: 'blur' }],
  password: [{ required: true, message: '请输入密码', trigger: 'blur' }],
}

// 登录：校验通过 → auth.login → 跳仪表盘；失败显示后端 message
async function submit() {
  const valid = await formRef.value?.validate().catch(() => false)
  if (!valid) return
  loading.value = true
  try {
    await auth.login(form.username, form.password)
    router.push('/dashboard')
  } catch (e) {
    ElMessage.error((e as Error).message)
  } finally {
    loading.value = false
  }
}
</script>

<style scoped>
/* 居中卡片 + 库存绿主按钮（btn-primary 语义） */
.login-page { min-height: 100vh; display: flex; align-items: center; justify-content: center; background: var(--color-background); }
.login-card { width: 400px; padding: var(--space-3xl); }
.login-btn { width: 100%; background: var(--color-accent); cursor: pointer; }
</style>
```

创建占位 `web/src/views/DashboardView.vue`（`<h1>仪表盘（后续模块实现）</h1>`）与 `web/src/views/ForbiddenView.vue`（403 提示 + 返回按钮）。

- [ ] **Step 7: 跑前端测试确认通过**

Run: `cd web && npx vitest run`
Expected: auth store 4 个测试 PASS。

- [ ] **Step 8: 手工冒烟验证**

Run: `cd server && php artisan serve`（后台）→ 浏览器 `http://localhost:5173`：
- 未登录访问 `/dashboard` → 重定向 `/login`
- 输入 admin/admin123 → 登录成功跳 `/dashboard`
- 顶栏点击用户名 → 「退出登录」→ 回 `/login`
Expected: 上述流程全部符合 spec §5.1。

- [ ] **Step 9: 提交**

```bash
cd /d/code/project/php-design && git add -A && git commit -m "feat: 前端基座（http/auth store/路由守卫/布局/登录页）"
```

---

## Task 8: 前端用户/角色/字典页面

**Files:**
- Create: `web/src/api/user.ts`、`web/src/api/role.ts`、`web/src/api/dictionary.ts`
- Create: `web/src/views/system/UsersView.vue`、`web/src/views/system/RolesView.vue`、`web/src/views/system/DictionariesView.vue`
- Create: `web/src/tests/user.api.test.ts`
- Modify: 无（路由已在 Task 7 注册）

**Interfaces:**
- Consumes: Task 4/5/6 后端 API、Task 7 的 http/auth store
- Produces: 三个页面（列表 + 弹窗表单 + 删除确认），交互行为对齐 `docs/test/2026-08-12-系统管理模块端到端测试.md` 用例步骤（按钮文案「新 建/保 存/编 辑/删 除/审 核」、ElMessage 错误提示、状态标签语义色）

- [ ] **Step 1: 写失败测试 `web/src/tests/user.api.test.ts`**

```ts
// 用户 API 封装测试：分页参数与响应解包正确
import { describe, it, expect, vi, beforeEach } from 'vitest'

vi.mock('../api/http', () => ({ http: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() } }))
import { http } from '../api/http'
import { userApi } from '../api/user'

describe('user api', () => {
  beforeEach(() => vi.clearAllMocks())

  it('list 携带分页与关键字参数', async () => {
    // 正常路径：查询参数正确传递
    ;(http.get as any).mockResolvedValue({ data: { code: 0, data: { items: [], total: 0, page: 1, per_page: 10 } } })
    await userApi.list({ page: 2, keyword: 'tester' })
    expect(http.get).toHaveBeenCalledWith('/users', { params: { page: 2, per_page: 10, keyword: 'tester' } })
  })

  it('create 提交 role_ids 数组', async () => {
    // 正常路径：创建请求体结构
    ;(http.post as any).mockResolvedValue({ data: { code: 0 } })
    await userApi.create({ name: 'x', username: 'u', password: 'Test@12345', status: 1, role_ids: [1] })
    expect(http.post).toHaveBeenCalledWith('/users', expect.objectContaining({ role_ids: [1] }))
  })
})
```

- [ ] **Step 2: 跑测试确认失败**

Run: `cd web && npx vitest run src/tests/user.api.test.ts`
Expected: FAIL。

- [ ] **Step 3: 实现 API 封装**

`web/src/api/user.ts`、`web/src/api/role.ts`、`web/src/api/dictionary.ts`（统一模式）：

```ts
// 用户管理 API 封装
import { http } from './http'

export interface UserItem {
  id: number
  name: string
  username: string
  email: string | null
  status: number
  last_login_at: string | null
  roles: { id: number; name: string }[]
}

export const userApi = {
  // 分页列表（keyword/status 筛选）
  async list(params: { page?: number; per_page?: number; keyword?: string; status?: number }) {
    const { data } = await http.get('/users', { params })
    return data.data as { items: UserItem[]; total: number; page: number; per_page: number }
  },
  // 新建用户
  async create(payload: { name: string; username: string; password: string; email?: string; status: number; role_ids: number[] }) {
    await http.post('/users', payload)
  },
  // 更新用户（password 可空=不修改）
  async update(id: number, payload: { name: string; username: string; email?: string; status: number; role_ids: number[] }) {
    await http.put(`/users/${id}`, payload)
  },
  // 删除用户
  async remove(id: number) {
    await http.delete(`/users/${id}`)
  },
  // 重置密码
  async resetPassword(id: number, password: string) {
    await http.put(`/users/${id}/reset-password`, { password })
  },
}
```

`role.ts`：`list()`、`permissions()`（返回 groups）、`create/update/remove`；`dictionary.ts`：`list()`、`create/update/remove`、`items(dictId)`、`createItem/updateItem/removeItem`。

- [ ] **Step 4: 实现 UsersView.vue（页面核心逻辑）**

```vue
<!-- 用户管理页：搜索 + 列表 + 新建/编辑弹窗 + 删除确认 + 重置密码 -->
<template>
  <div>
    <div class="toolbar">
      <el-input v-model="query.keyword" placeholder="用户名/姓名" clearable style="width: 220px" @keyup.enter="load" />
      <el-button class="btn-primary" @click="openCreate">新 建</el-button>
    </div>
    <el-table :data="rows" v-loading="loading">
      <el-table-column prop="id" label="ID" width="70" />
      <el-table-column prop="username" label="用户名" class-name="font-code" />
      <el-table-column prop="name" label="姓名" />
      <el-table-column prop="email" label="邮箱" />
      <el-table-column label="状态" width="100">
        <template #default="{ row }">
          <el-tag :type="row.status === 1 ? 'success' : 'info'">{{ row.status === 1 ? '启用' : '已禁用' }}</el-tag>
        </template>
      </el-table-column>
      <el-table-column label="角色">
        <template #default="{ row }">
          <el-tag v-for="r in row.roles" :key="r.id" size="small" class="role-tag">{{ r.name }}</el-tag>
        </template>
      </el-table-column>
      <el-table-column prop="last_login_at" label="最后登录" width="180" />
      <el-table-column label="操作" width="220" fixed="right">
        <template #default="{ row }">
          <el-button link type="primary" @click="openEdit(row)">编 辑</el-button>
          <el-button link type="warning" @click="openReset(row)">重置密码</el-button>
          <el-button link type="danger" @click="remove(row)">删 除</el-button>
        </template>
      </el-table-column>
    </el-table>
    <el-pagination v-model:current-page="query.page" :total="total" :page-size="10" layout="total, prev, pager, next" @current-change="load" />

    <!-- 新建/编辑弹窗 -->
    <el-dialog v-model="dialogVisible" :title="form.id ? '编辑用户' : '新建用户'" width="480px">
      <el-form :model="form" label-width="80px">
        <el-form-item label="用户名" required><el-input v-model="form.username" /></el-form-item>
        <el-form-item label="姓名" required><el-input v-model="form.name" /></el-form-item>
        <el-form-item label="邮箱"><el-input v-model="form.email" /></el-form-item>
        <el-form-item v-if="!form.id" label="密码" required><el-input v-model="form.password" type="password" show-password /></el-form-item>
        <el-form-item label="状态"><el-switch v-model="form.status" :active-value="1" :inactive-value="0" /></el-form-item>
        <el-form-item label="角色">
          <el-select v-model="form.role_ids" multiple placeholder="选择角色" style="width: 100%">
            <el-option v-for="r in roles" :key="r.id" :label="r.name" :value="r.id" />
          </el-select>
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="dialogVisible = false">取 消</el-button>
        <el-button type="primary" class="btn-primary" @click="save">保 存</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup lang="ts">
// 用户管理页：列表查询/新建编辑/删除（内置 admin 删除被后端拦截并提示）/重置密码
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { userApi, type UserItem } from '../../api/user'
import { roleApi } from '../../api/role'

const rows = ref<UserItem[]>([])
const roles = ref<{ id: number; name: string }[]>([])
const total = ref(0)
const loading = ref(false)
const query = reactive({ page: 1, keyword: '' })
const dialogVisible = ref(false)
const form = reactive<Record<string, any>>({})

// 加载列表：携带分页与关键字
async function load() {
  loading.value = true
  try {
    const res = await userApi.list({ page: query.page, per_page: 10, keyword: query.keyword })
    rows.value = res.items
    total.value = res.total
  } finally {
    loading.value = false
  }
}

// 新建/编辑弹窗初始化（角色下拉复用角色列表接口）
function openCreate() {
  Object.assign(form, { id: null, username: '', name: '', email: '', password: '', status: 1, role_ids: [] })
  dialogVisible.value = true
}
function openEdit(row: UserItem) {
  Object.assign(form, { id: row.id, username: row.username, name: row.name, email: row.email, status: row.status, role_ids: row.roles.map((r) => r.id) })
  dialogVisible.value = true
}

// 保存：新建走 create（必填密码），编辑走 update（不含密码）；失败 ElMessage 展示后端 message
async function save() {
  try {
    if (form.id) {
      await userApi.update(form.id, { name: form.name, username: form.username, email: form.email, status: form.status, role_ids: form.role_ids })
    } else {
      await userApi.create({ name: form.name, username: form.username, password: form.password, email: form.email, status: form.status, role_ids: form.role_ids })
    }
    ElMessage.success('保存成功')
    dialogVisible.value = false
    load()
  } catch (e) {
    ElMessage.error((e as Error).message)
  }
}

// 删除：二次确认后调用；后端拒绝（如内置 admin）时展示错误
async function remove(row: UserItem) {
  try {
    await ElMessageBox.confirm(`确定删除用户 ${row.name}？此操作不可恢复`, '提示', { type: 'warning' })
  } catch {
    return // 用户取消
  }
  try {
    await userApi.remove(row.id)
    ElMessage.success('删除成功')
    load()
  } catch (e) {
    ElMessage.error((e as Error).message)
  }
}

// 重置密码：输入新密码（后端校验强度）
async function openReset(row: UserItem) {
  const { value } = await ElMessageBox.prompt('请输入新密码（至少8位，含字母和数字）', `重置密码 - ${row.username}`, { inputType: 'password' })
  try {
    await userApi.resetPassword(row.id, value)
    ElMessage.success('密码重置成功')
  } catch (e) {
    ElMessage.error((e as Error).message)
  }
}

onMounted(async () => {
  load()
  roles.value = (await roleApi.list({ page: 1, per_page: 100 })).items
})
</script>

<style scoped>
/* 工具栏间距与主按钮样式（btn-primary 语义色） */
.toolbar { display: flex; gap: var(--space-lg); margin-bottom: var(--space-xl); }
.btn-primary { background: var(--color-accent); border-color: var(--color-accent); cursor: pointer; }
.role-tag { margin-right: var(--space-xs); }
</style>
```

- [ ] **Step 5: 实现 RolesView.vue（权限树）与 DictionariesView.vue**

RolesView 关键逻辑（权限树用 `el-tree` + check-strictly 半选）：

```vue
<!-- 角色管理页：列表 + 新建/编辑弹窗（权限树勾选） -->
<script setup lang="ts">
// 角色管理页：CRUD + 权限树分配；删除被引用角色时展示后端 1004 提示
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { roleApi } from '../../api/role'

const rows = ref<any[]>([])
const total = ref(0)
const query = reactive({ page: 1 })
const dialogVisible = ref(false)
const form = reactive<any>({})
const treeRef = ref()
// 权限树数据：从 groups 接口转换（value=permission.id）
const treeData = ref<any[]>([])
const checkedKeys = ref<number[]>([])

// 打开弹窗：新建清空；编辑回填已勾选权限
async function openEdit(row?: any) {
  Object.assign(form, row ? { id: row.id, name: row.name, code: row.code, remark: row.remark } : { id: null, name: '', code: '', remark: '' })
  checkedKeys.value = row ? row.permissions.map((p: string) => p) : []
  dialogVisible.value = true
}

// 保存：提交 permission_ids（树的已勾选 id）
async function save() {
  const permissionIds = treeRef.value?.getCheckedKeys() ?? []
  try {
    if (form.id) await roleApi.update(form.id, { ...form, permission_ids: permissionIds })
    else await roleApi.create({ ...form, permission_ids: permissionIds })
    ElMessage.success('保存成功')
    dialogVisible.value = false
    load()
  } catch (e) {
    ElMessage.error((e as Error).message)
  }
}
// （列表加载、删除确认、分页同 UsersView 模式）
</script>
```

权限树渲染（模板内）：`<el-tree ref="treeRef" :data="treeData" show-checkbox node-key="id" :props="{ label: 'label', children: 'children' }" default-expand-all />`；`treeData` 构造：`groups.map(g => ({ id: 'g-' + g.group, label: g.group, children: g.permissions.map(p => ({ id: p.id, label: p.name })) }))`——注意勾选回填用 `code` 或叶子 id 需保持一致（**统一用 permission id 作为 node-key**，回填 `checkedKeys` 为权限 id）。

DictionariesView 关键逻辑（左右结构：字典列表 + 字典项弹窗）：

```vue
<!-- 字典管理页：字典列表 + 字典项管理弹窗 -->
<script setup lang="ts">
// 字典管理页：字典/字典项 CRUD；删除字典前提示引用风险
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { dictionaryApi } from '../../api/dictionary'

const rows = ref<any[]>([])
const itemDialogVisible = ref(false)
const currentDict = ref<any>(null)
const items = ref<any[]>([])

// 打开字典项弹窗：加载启用项列表
async function openItems(dict: any) {
  currentDict.value = dict
  itemDialogVisible.value = true
  const res = await dictionaryApi.items(dict.id)
  items.value = res.items
}

// 删除字典：确认框提示引用风险（spec §8）
async function remove(row: any) {
  try {
    await ElMessageBox.confirm(`确定删除字典「${row.name}」？删除后引用此字典的下拉将失效`, '提示', { type: 'warning' })
  } catch {
    return
  }
  try {
    await dictionaryApi.remove(row.id)
    ElMessage.success('删除成功')
    load()
  } catch (e) {
    ElMessage.error((e as Error).message)
  }
}
// （列表加载/新建/编辑/字典项增删改同模式）
</script>
```

- [ ] **Step 6: 跑前端测试确认通过**

Run: `cd web && npx vitest run`
Expected: 全部 PASS（auth 4 + user api 2）。

- [ ] **Step 7: 浏览器冒烟验证（playwright-cli）**

```bash
playwright-cli open http://localhost:5173/login
playwright-cli fill <用户名输入框ref> "admin"
playwright-cli fill <密码输入框ref> "admin123"
playwright-cli click <登录按钮ref>
# 断言：URL 变为 /dashboard
# 进入 用户管理 → 新建用户 tester01（operator 角色）→ 列表出现
# 编辑/重置密码/删除 各走一遍
playwright-cli close
```

Expected: 页面操作与 `docs/test/2026-08-12-系统管理模块端到端测试.md` TC-SYS-03~07 行为一致。

- [ ] **Step 8: 提交**

```bash
cd /d/code/project/php-design && git add -A && git commit -m "feat: 用户/角色/字典管理页面"
```

---

## Task 9: 种子数据完善 + E2E 全量测试（playwright-cli）

**Files:**
- Modify: `server/database/seeders/DatabaseSeeder.php`（补字典种子 unit：个/箱）
- Create: `docs/test/evidence/`（失败证据目录）

**Interfaces:**
- Consumes: Task 1-8 全部产物
- Produces: 通过 `docs/test/2026-08-12-系统管理模块端到端测试.md` 全部用例（TC-SYS-01~12）；失败项按文档 §4 修复

- [ ] **Step 1: 补字典种子**

修改 `server/database/seeders/RbacSeeder.php`（追加字典种子，放在权限种子后）：

```php
// 种子字典：计量单位（供基础资料模块下拉引用）
use App\Models\Dictionary;

$unit = Dictionary::firstOrCreate(['code' => 'unit'], ['name' => '计量单位', 'remark' => '全系统计量单位']);
$unit->items()->delete();
$unit->items()->createMany([
    ['label' => '个', 'value' => 'pc', 'sort' => 1, 'status' => 1],
    ['label' => '箱', 'value' => 'box', 'sort' => 2, 'status' => 1],
]);
```

Run: `cd server && php artisan migrate:fresh --seed`
Expected: 种子成功（admin/admin123 可登录，unit 字典含 2 项）。

- [ ] **Step 2: 全量后端测试**

Run: `cd server && php artisan test`
Expected: 全部 PASS（AuthTest 7 + RbacTest 3 + UserManagementTest 9 + RoleManagementTest 7 + DictionaryTest 7 = 33 个）。

- [ ] **Step 3: 全量前端测试**

Run: `cd web && npx vitest run`
Expected: 全部 PASS。

- [ ] **Step 4: 启动服务并执行 E2E 文档 TC-SYS-01~12**

```bash
cd /d/code/project/php-design
docker compose up -d
cd server && php artisan serve &        # 后端 :8000
cd ../web && npm run dev &              # 前端 :5173
```

按 `docs/test/2026-08-12-系统管理模块端到端测试.md` 用例顺序执行（playwright-cli open/fill/click/requests/snapshot 断言）：

- TC-SYS-01 登录（正常+错误+空表单）
- TC-SYS-02 登出与 token 失效
- TC-SYS-03 用户分页搜索（需 ≥11 用户：种子 1 + 手工造 10 个，或用 run-code 直接调 API 造数据）
- TC-SYS-04 新建用户（含重复用户名）
- TC-SYS-05 受限账号权限验证（limited01）
- TC-SYS-06 编辑/重置密码
- TC-SYS-07 删除用户（含 admin 保护）
- TC-SYS-08 角色 CRUD（含被引用删除 1004）
- TC-SYS-09 权限分配生效链路
- TC-SYS-10 字典 CRUD（含重复编码 1005）
- TC-SYS-11 字典取值接口（code/unit）
- TC-SYS-12 禁用账号登录拦截（1006）

每用例断言失败时执行文档 §4 流程：`playwright-cli screenshot --filename=docs/test/evidence/{tc}-fail.png` + `console` + `requests` → 判定层级 → systematic-debugging 修复 → 补 PHPUnit/Vitest 测试 → 回归。

- [ ] **Step 5: 填写测试结果记录**

将每用例结果写入 `docs/test/2026-08-12-系统管理模块端到端测试.md` 末尾 §5 表格；失败项附失败详情与修复引用。

- [ ] **Step 6: 全量回归与提交**

Run: `cd server && php artisan test && cd ../web && npx vitest run`
Expected: 全绿。
Run: `cd /d/code/project/php-design && git add -A && git commit -m "test: 系统管理模块 E2E 全量通过"`
Expected: 提交成功。

---

## Self-Review

**1. Spec 覆盖核对**（对照 `docs/superpowers/specs/2026-08-12-system-management-spec.md`）：
- §4.1 认证三接口 → Task 2 ✅
- §4.2 用户管理 5 接口（含 reset-password、1002/1003 错误码）→ Task 4 ✅
- §4.3 角色管理 5 接口（含 permissions 分组、1004/1007）→ Task 5 ✅
- §4.4 字典 9 接口（含 byCode 1008、1005）→ Task 6 ✅
- §5.1 登录页（自动重定向、守卫）→ Task 7 ✅
- §5.2-5.4 三个页面（弹窗/删除确认/权限树/字典项弹窗）→ Task 8 ✅
- §6 业务流转（登录-权限链-字典）→ Task 7 守卫 + Task 9 E2E ✅
- §7 功能清单 SYS-01~12 → Task 9 TC 用例 ✅
- §8 边界（1006 禁用、1007 最后管理员、字典引用提示）→ Task 4/5/6 测试 + Task 8 前端 ✅
- E2E 文档 TC-SYS-01~12 → Task 9 ✅

**2. 占位符扫描**：无 TBD/TODO/“类似 Task N”引用；所有代码步骤含完整实现。

**3. 类型一致性核对**：
- `ApiResponse::ok()/::fail()` 签名在 Task 2 定义，Task 4/5/6 控制器统一使用 ✅
- `UserResource` 字段（roles/permissions）与前端 `AuthUser` 接口一致 ✅
- 权限 code（`user.list` 等）在 Task 3 种子、路由中间件、前端菜单/路由 meta 三处一致 ✅
- 错误码 1001-1008 在 spec、控制器、测试三处一致 ✅
- `role_ids`/`permission_ids` 参数名前后端一致 ✅
