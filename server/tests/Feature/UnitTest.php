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
