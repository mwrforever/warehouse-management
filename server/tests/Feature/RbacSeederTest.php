<?php

// RBAC 种子测试：ADMIN_PASSWORD 首次建号 + 重跑种子密码轮换（升级路径安全修复生效验证，bug #8 回归）

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class RbacSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        // 还原进程级环境变量：避免污染同进程后续用例（env() 读取 getenv 链）
        putenv('ADMIN_PASSWORD');
        parent::tearDown();
    }

    public function test_first_run_creates_admin_with_env_password(): void
    {
        // 正常路径：首次 seed 以 ADMIN_PASSWORD 建号（hash 后可校验）
        putenv('ADMIN_PASSWORD=Strong@Pass2026');
        $this->seed();
        $admin = User::where('username', 'admin')->first();
        $this->assertNotNull($admin);
        $this->assertTrue(Hash::check('Strong@Pass2026', $admin->password));
    }

    public function test_rerun_rotates_existing_admin_password_by_env(): void
    {
        // 核心路径（bug #8 回归）：既有环境重跑种子时按 env 轮换密码——
        // 安全修复（ADMIN_PASSWORD 强口令）不因 admin 已存在而永久失效
        putenv('ADMIN_PASSWORD=Strong@Pass2026');
        $this->seed();
        $admin = User::where('username', 'admin')->first();
        $this->assertTrue(Hash::check('Strong@Pass2026', $admin->password));
        // 再换 env 重跑：新口令生效、旧口令失效（轮换而非追加）
        putenv('ADMIN_PASSWORD=New@Pass2027');
        $this->seed();
        $admin->refresh();
        $this->assertTrue(Hash::check('New@Pass2027', $admin->password));
        $this->assertFalse(Hash::check('Strong@Pass2026', $admin->password));
    }
}
