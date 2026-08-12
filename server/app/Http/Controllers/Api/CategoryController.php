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
        $node = [
            'id' => $category->id,
            'name' => $category->name,
            'parent_id' => $category->parent_id,
            'sort' => $category->sort,
            'status' => $category->status,
        ];
        $children = $all->where('parent_id', $category->id)
            ->values()
            ->map(fn ($c) => $this->withChildren($c, $all))
            ->values();
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
            // 含子分类的分类只能保持顶级：移动会使子分类成为第三级，违反「分类最多两级」1124
            if ($category->children()->exists()) {
                return $this->fail(1124, '分类最多支持两级');
            }
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
