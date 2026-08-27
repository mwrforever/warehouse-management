<?php

// 商品分类控制器：树形列表 读取 + CRUD 薄壳（写流程全部下沉 CategoryService）

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Master\SaveCategoryRequest;
use App\Models\Category;
use App\Services\CategoryService;
use App\Support\ApiResponse;

class CategoryController extends Controller
{
    use ApiResponse;

    public function __construct(private CategoryService $categoryService) {}

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

    /** 新建分类：最多两级（父级必须是顶级或空，否则 1124） */
    public function store(SaveCategoryRequest $request)
    {
        // 写流程下沉 CategoryService（两级限制/防环/删除保护业务码 1124/1101/1102 由其抛出）
        return $this->ok(['id' => $this->categoryService->create($request->validated())->id]);
    }

    /** 更新分类：同名两级限制 + 防移动到自己子级下（防环） */
    public function update(SaveCategoryRequest $request, Category $category)
    {
        $this->categoryService->update($category, $request->validated());

        return $this->ok();
    }

    /** 删除分类：含子分类 1101；被商品引用 1102 */
    public function destroy(Category $category)
    {
        $this->categoryService->delete($category);

        return $this->ok();
    }
}
