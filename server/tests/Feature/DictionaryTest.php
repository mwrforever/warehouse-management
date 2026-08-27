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
        $this->withToken($this->token)
            ->postJson('/api/v1/dictionaries', ['name' => '计量单位', 'code' => 'unit', 'remark' => ''])
            ->assertJsonPath('code', 0);
        // 异常路径：重复编码 1005
        $this->withToken($this->token)->postJson('/api/v1/dictionaries', ['name' => '重复', 'code' => 'unit'])
            ->assertJsonPath('code', 1005);
    }

    public function test_item_crud(): void
    {
        // 正常路径：字典项增改删
        $d = Dictionary::create(['name' => '计量单位', 'code' => 'unit']);
        $this->withToken($this->token)->postJson("/api/v1/dictionaries/{$d->id}/items", [
            'label' => '个',
            'value' => 'pc',
            'sort' => 1,
            'status' => 1,
        ])
            ->assertJsonPath('code', 0);
        $item = DictionaryItem::first();
        $this->withToken($this->token)->putJson("/api/v1/dictionaries/items/{$item->id}", [
            'label' => '箱',
            'value' => 'box',
            'sort' => 2,
            'status' => 1,
        ])
            ->assertJsonPath('code', 0);
        $this->withToken($this->token)->deleteJson("/api/v1/dictionaries/items/{$item->id}")->assertJsonPath('code', 0);
    }

    public function test_get_items_returns_all_including_disabled(): void
    {
        // 正常路径：管理端 items 返回全部项（含停用，防禁用项在 UI 消失后无法恢复），按 sort 排序
        $d = Dictionary::create(['name' => 'd', 'code' => 'd1']);
        DictionaryItem::create([
            'dictionary_id' => $d->id,
            'label' => '启用',
            'value' => 'on',
            'sort' => 1,
            'status' => 1,
        ]);
        DictionaryItem::create([
            'dictionary_id' => $d->id,
            'label' => '停用',
            'value' => 'off',
            'sort' => 2,
            'status' => 0,
        ]);
        $this->withToken($this->token)->getJson("/api/v1/dictionaries/{$d->id}/items")
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.items.0.value', 'on')
            ->assertJsonPath('data.items.1.value', 'off');
    }

    public function test_index_clamps_per_page_to_valid_range(): void
    {
        // 边界路径：per_page 钳制到 1-100——0 值防除零 500、超上限防超大分页
        $this->withToken($this->token)->getJson('/api/v1/dictionaries?per_page=0')->assertJsonPath('data.per_page', 1);
        $this->withToken($this->token)
            ->getJson('/api/v1/dictionaries?per_page=1000')
            ->assertJsonPath('data.per_page', 100);
    }

    public function test_get_by_code_returns_enabled_items(): void
    {
        // 正常路径：按编码取启用项（供其他模块下拉）
        $d = Dictionary::create(['name' => 'unit', 'code' => 'unit']);
        DictionaryItem::create([
            'dictionary_id' => $d->id,
            'label' => '个',
            'value' => 'pc',
            'sort' => 1,
            'status' => 1,
        ]);
        DictionaryItem::create([
            'dictionary_id' => $d->id,
            'label' => '停用箱',
            'value' => 'box',
            'sort' => 2,
            'status' => 0,
        ]);
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

    public function test_get_by_code_rejects_user_without_dictionary_list_permission(): void
    {
        // 异常路径（D-11）：无 dictionary.list 权限的角色访问按编码取值 → 403（与 /dictionaries 列表同口径）
        $role = Role::create(['name' => '仅登录', 'code' => 'viewer']);
        $u = User::create(['name' => '访客', 'username' => 'viewer', 'password' => 'viewer123', 'status' => 1]);
        $u->roles()->sync([$role->id]);
        $token = $u->createToken('api')->plainTextToken;
        $this->withToken($token)->getJson('/api/v1/dictionaries/code/unit')
            ->assertStatus(403)
            ->assertJsonPath('code', 403)
            ->assertJsonPath('message', '无权限操作');
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
