<?php

// 商品分类服务：分类创建/更新/删除（两级层级限制 1124 + 删除引用保护 1101/1102）
// 写操作均为单表原子写，无跨表事务，不包 DB::transaction（原子性由单条 insert/update/delete 保证）

namespace App\Services;

use App\Exceptions\MasterDataException;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Support\Facades\Log;

class CategoryService
{
    /**
     * 新建分类（原控制器 store 下沉）：最多两级（父级必须是顶级分类，否则 1124）
     *
     * @param  array  $data  已过 SaveCategoryRequest 格式校验的载荷（name/parent_id/sort/status）
     * @return Category 新建分类（供控制器回显 id）
     *
     * @throws MasterDataException 父级不存在或非顶级 1124
     */
    public function create(array $data): Category
    {
        $parentId = (int) ($data['parent_id'] ?? 0);
        // 父级存在性 + 两级限制：父级必须是顶级分类（业务校验，非格式层）
        if ($parentId > 0) {
            $parent = Category::find($parentId);
            if (! $parent || $parent->parent_id !== 0) {
                throw new MasterDataException('分类最多支持两级', 1124);
            }
        }

        $category = Category::create([
            'name' => $data['name'], 'parent_id' => $parentId,
            'sort' => $data['sort'] ?? 0, 'status' => $data['status'] ?? Category::STATUS_ENABLED,
        ]);

        // 创建审计日志（D-14）：基础资料写路径，记录落库结果便于追溯
        Log::info('商品分类创建成功', ['category_id' => $category->id, 'name' => $category->name, 'operator' => auth()->id()]);

        return $category;
    }

    /**
     * 更新分类（原控制器 update 下沉）：同名两级限制 + 防移动到自己子级下（防环）1124
     *
     * @param  Category  $category  路由绑定的分类模型
     * @param  array  $data  已过 SaveCategoryRequest 格式校验的载荷
     *
     * @throws MasterDataException 含子分类移动/挂到自身或子分类下/父级非顶级 1124
     */
    public function update(Category $category, array $data): void
    {
        $parentId = (int) ($data['parent_id'] ?? 0);
        if ($parentId > 0) {
            // 含子分类的分类只能保持顶级：移动会使子分类成为第三级，违反「分类最多两级」1124
            if ($category->children()->exists()) {
                throw new MasterDataException('分类最多支持两级', 1124);
            }
            // 防环：不能挂到自身或自身子分类下
            $hasSelfDescendant = Category::where('parent_id', $category->id)->where('id', $parentId)->exists();
            if ($parentId === $category->id || $hasSelfDescendant) {
                throw new MasterDataException('不能将分类移动到自身或子分类下', 1124);
            }
            $parent = Category::find($parentId);
            if (! $parent || $parent->parent_id !== 0) {
                throw new MasterDataException('分类最多支持两级', 1124);
            }
        }

        $category->update([
            'name' => $data['name'], 'parent_id' => $parentId,
            'sort' => $data['sort'] ?? $category->sort, 'status' => $data['status'] ?? $category->status,
        ]);

        Log::info('商品分类更新成功', ['category_id' => $category->id, 'name' => $category->name, 'operator' => auth()->id()]);
    }

    /**
     * 删除分类（原控制器 destroy 下沉）：含子分类 1101；被商品引用 1102（读检查无需持锁）
     *
     * @param  Category  $category  路由绑定的分类模型
     *
     * @throws MasterDataException 存在子分类 1101 / 被商品使用 1102
     */
    public function delete(Category $category): void
    {
        if (Category::where('parent_id', $category->id)->exists()) {
            throw new MasterDataException('存在子分类，不可删除', 1101);
        }
        if (Product::where('category_id', $category->id)->exists()) {
            throw new MasterDataException('分类已被商品使用，不可删除', 1102);
        }
        $category->delete();

        Log::info('商品分类删除成功', ['category_id' => $category->id, 'name' => $category->name, 'operator' => auth()->id()]);
    }
}
