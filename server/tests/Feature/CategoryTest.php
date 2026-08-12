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
