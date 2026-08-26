<?php

// 编号规则配置接口测试：列表分页/编辑校验/预览示例/权限（非核心 ≥80%：正常+边界+异常三类）

namespace Tests\Feature;

use App\Models\DocumentNumberConfig;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\DocumentNumberConfigSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DocumentNumberConfigTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $admin = User::where('username', 'admin')->first();
        $this->token = $admin->createToken('api')->plainTextToken;
        $this->seed(DocumentNumberConfigSeeder::class);
    }

    public function test_index_lists_all_configs_paginated(): void
    {
        // 正常路径：15 类规则全部可查（14 类单据含工艺路线 rtg/委外退料 osrt + 商品编码 prd，per_page 覆盖）
        $this->withToken($this->token)->getJson('/api/v1/document-number-configs?per_page=50')
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.total', 15);
    }

    public function test_update_changes_rule_and_preview_reflects(): void
    {
        // 正常路径：改 po 的 seq_length=4 → 数据库更新；预览接口按新值出 4 位示例
        $cfg = DocumentNumberConfig::where('type', 'po')->firstOrFail();
        $this->withToken($this->token)->putJson("/api/v1/document-number-configs/{$cfg->id}", [
            'prefix' => 'PO', 'date_format' => 'YmdHi', 'seq_length' => 4, 'is_enabled' => true, 'remark' => '改宽',
        ])->assertJsonPath('code', 0);
        $this->assertSame(4, $cfg->refresh()->seq_length);
        $res = $this->withToken($this->token)->postJson('/api/v1/document-number-configs/preview', [
            'prefix' => 'PO', 'date_format' => 'YmdHi', 'seq_length' => 4,
        ]);
        $res->assertJsonPath('code', 0);
        $this->assertMatchesRegularExpression('/^PO\d{12}\d{4}$/', $res->json('data.no'));
    }

    public function test_update_accepts_empty_date_format(): void
    {
        // 正常路径（spec §3 边界）：date_format 空串=无日期段（商品编码场景），required 会误拒、present 放行
        $cfg = DocumentNumberConfig::where('type', 'prd')->firstOrFail();
        $this->withToken($this->token)->putJson("/api/v1/document-number-configs/{$cfg->id}", [
            'prefix' => 'PRD', 'date_format' => '', 'seq_length' => 6, 'is_enabled' => true, 'remark' => null,
        ])->assertJsonPath('code', 0);
        $this->assertSame('', $cfg->refresh()->date_format);
        $res = $this->withToken($this->token)->postJson('/api/v1/document-number-configs/preview', [
            'prefix' => 'PRD', 'date_format' => '', 'seq_length' => 6,
        ]);
        $res->assertJsonPath('code', 0);
        $this->assertMatchesRegularExpression('/^PRD\d{6}$/', $res->json('data.no'));
    }

    public function test_update_rejects_bad_rule(): void
    {
        // 异常路径：seq_length 越界/前缀含小写/date_format 非法 → 422
        $cfg = DocumentNumberConfig::where('type', 'po')->firstOrFail();
        $this->withToken($this->token)->putJson("/api/v1/document-number-configs/{$cfg->id}", [
            'prefix' => 'po', 'date_format' => 'YmdHi', 'seq_length' => 0, 'is_enabled' => true,
        ])->assertStatus(422);
    }

    public function test_update_requires_setting_permission(): void
    {
        // 异常路径：operator（仅 list）无 update 权限 → 403
        $role = Role::where('code', 'operator')->firstOrFail();
        $u = User::create(['name' => '只读', 'username' => 'ro', 'password' => 'admin123', 'status' => 1]);
        $u->roles()->sync([$role->id]);
        $cfg = DocumentNumberConfig::where('type', 'po')->firstOrFail();
        $this->withToken($u->createToken('api')->plainTextToken)
            ->putJson("/api/v1/document-number-configs/{$cfg->id}", ['prefix' => 'PO', 'date_format' => 'YmdHi', 'seq_length' => 3, 'is_enabled' => true])
            ->assertStatus(403);
    }
}
