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
