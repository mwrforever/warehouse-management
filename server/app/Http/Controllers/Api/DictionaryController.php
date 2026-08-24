<?php

// 字典管理控制器：字典/字典项 CRUD + 按编码取值（供其他模块下拉）

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Dictionary;
use App\Models\DictionaryItem;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

/**
 * 字典管理控制器
 *
 * 负责数据字典与字典项的全量管理：字典 CRUD（编码唯一，重复返回 1005）、
 * 字典项 CRUD（items 列表返回全部项含停用，避免禁用项在管理 UI 消失后无法恢复）、
 * 按编码取启用项供其他模块下拉（登录即可访问）。
 * 依赖 Dictionary/DictionaryItem 模型、ApiResponse 统一响应与 permission 中间件；
 * 删除字典依赖外键级联删除字典项；编码唯一性由数据库 unique 约束兜底。
 */
class DictionaryController extends Controller
{
    use ApiResponse;

    /** 字典分页列表 */
    public function index(Request $request)
    {
        // per_page 钳制到 1-100：防 0 值除零 500 与超大分页拖垮性能
        $items = Dictionary::orderByDesc('id')->paginate(max(1, min(100, $request->integer('per_page', 10))));

        return $this->ok([
            'items' => $items->map(fn ($d) => [
                'id' => $d->id,
                'name' => $d->name,
                'code' => $d->code,
                'remark' => $d->remark,
            ]),
            'total' => $items->total(), 'page' => $items->currentPage(), 'per_page' => $items->perPage(),
        ]);
    }

    /** 新建字典：编码唯一 */
    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:50',
            'code' => 'required|string|max:50',
            'remark' => 'nullable|string',
        ]);
        // 编码重复属业务错误，返回 1005（不能用 unique 校验，否则被统一渲染为 422）
        if (Dictionary::where('code', $data['code'])->exists()) {
            return $this->fail(1005, '字典编码已存在');
        }

        return $this->ok(['id' => Dictionary::create($data)->id]);
    }

    /** 更新字典 */
    public function update(Request $request, Dictionary $dictionary)
    {
        $data = $request->validate([
            'name' => 'required|string|max:50',
            'code' => 'required|string|max:50',
            'remark' => 'nullable|string',
        ]);
        // 编码重复属业务错误，返回 1005（排除自身；数据库 unique 约束仍兜底并发场景）
        if (Dictionary::where('code', $data['code'])->where('id', '!=', $dictionary->id)->exists()) {
            return $this->fail(1005, '字典编码已存在');
        }
        $dictionary->update($data);

        return $this->ok();
    }

    /** 删除字典（级联删除字典项） */
    public function destroy(Dictionary $dictionary)
    {
        $dictionary->delete();

        return $this->ok();
    }

    /** 字典项列表（管理端）：返回全部项（含停用，防 UI 死端），按 sort 排序 */
    public function items(Dictionary $dictionary)
    {
        return $this->ok(['items' => $dictionary->items()->orderBy('sort')->get()]);
    }

    /** 新增字典项 */
    public function storeItem(Request $request, Dictionary $dictionary)
    {
        $data = $request->validate([
            'label' => 'required|string|max:50',
            'value' => 'required|string|max:50',
            'sort' => 'integer',
            'status' => 'in:0,1',
        ]);

        return $this->ok(['id' => $dictionary->items()->create($data)->id]);
    }

    /** 更新字典项 */
    public function updateItem(Request $request, DictionaryItem $item)
    {
        $data = $request->validate([
            'label' => 'required|string|max:50',
            'value' => 'required|string|max:50',
            'sort' => 'integer',
            'status' => 'in:0,1',
        ]);
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

        $items = $dictionary->items()->where('status', DictionaryItem::STATUS_ENABLED)->orderBy('sort')->get();

        return $this->ok(['items' => $items]);
    }
}
