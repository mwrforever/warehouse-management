<?php

// 字典管理服务：字典 CRUD（编码唯一 1005，删除级联字典项）与字典项 CRUD
// 写操作均为单表原子写，无跨表事务，不包 DB::transaction（原子性由单条 insert/update/delete 保证；
// 字典删除的字典项级联由数据库 FK cascade 保证）

namespace App\Services;

use App\Exceptions\MasterDataException;
use App\Models\Dictionary;
use App\Models\DictionaryItem;
use Illuminate\Support\Facades\Log;

class DictionaryService
{
    /**
     * 新建字典（原控制器 store 下沉）：编码唯一（重复 1005，不能用 unique 校验否则被统一渲染为 422）
     *
     * @param  array  $data  已过 SaveDictionaryRequest 格式校验的载荷（name/code/remark）
     * @return Dictionary 新建字典（供控制器回显 id）
     *
     * @throws MasterDataException 编码重复 1005
     */
    public function create(array $data): Dictionary
    {
        // 编码重复属业务错误，返回 1005（不能用 unique 校验，否则被统一渲染为 422）
        if (Dictionary::where('code', $data['code'])->exists()) {
            throw new MasterDataException('字典编码已存在', 1005);
        }
        $dictionary = Dictionary::create($data);

        Log::info('字典创建成功', ['dictionary_id' => $dictionary->id, 'code' => $dictionary->code, 'operator' => auth()->id()]);

        return $dictionary;
    }

    /**
     * 更新字典（原控制器 update 下沉）：编码唯一（排除自身）1005
     *
     * @param  Dictionary  $dictionary  路由绑定的字典模型
     * @param  array  $data  已过 SaveDictionaryRequest 格式校验的载荷
     *
     * @throws MasterDataException 编码重复 1005
     */
    public function update(Dictionary $dictionary, array $data): void
    {
        // 编码重复属业务错误，返回 1005（排除自身；数据库 unique 约束仍兜底并发场景）
        if (Dictionary::where('code', $data['code'])->where('id', '!=', $dictionary->id)->exists()) {
            throw new MasterDataException('字典编码已存在', 1005);
        }
        $dictionary->update($data);

        Log::info('字典更新成功', ['dictionary_id' => $dictionary->id, 'code' => $dictionary->code, 'operator' => auth()->id()]);
    }

    /**
     * 删除字典（原控制器 destroy 下沉）：字典项由外键级联删除
     *
     * @param  Dictionary  $dictionary  路由绑定的字典模型
     */
    public function delete(Dictionary $dictionary): void
    {
        $dictionary->delete();

        Log::info('字典删除成功', ['dictionary_id' => $dictionary->id, 'code' => $dictionary->code, 'operator' => auth()->id()]);
    }

    /**
     * 新增字典项（原控制器 storeItem 下沉）
     *
     * @param  Dictionary  $dictionary  路由绑定的字典模型
     * @param  array  $data  已过 SaveDictionaryItemRequest 格式校验的载荷（label/value/sort/status）
     * @return DictionaryItem 新建字典项（供控制器回显 id）
     */
    public function createItem(Dictionary $dictionary, array $data): DictionaryItem
    {
        $item = $dictionary->items()->create($data);

        Log::info('字典项创建成功', ['item_id' => $item->id, 'label' => $item->label, 'dictionary_id' => $dictionary->id, 'operator' => auth()->id()]);

        return $item;
    }

    /**
     * 更新字典项（原控制器 updateItem 下沉）
     *
     * @param  DictionaryItem  $item  路由绑定的字典项模型
     * @param  array  $data  已过 SaveDictionaryItemRequest 格式校验的载荷
     */
    public function updateItem(DictionaryItem $item, array $data): void
    {
        $item->update($data);

        Log::info('字典项更新成功', ['item_id' => $item->id, 'label' => $item->label, 'operator' => auth()->id()]);
    }

    /**
     * 删除字典项（原控制器 destroyItem 下沉）
     *
     * @param  DictionaryItem  $item  路由绑定的字典项模型
     */
    public function deleteItem(DictionaryItem $item): void
    {
        $item->delete();

        Log::info('字典项删除成功', ['item_id' => $item->id, 'label' => $item->label, 'operator' => auth()->id()]);
    }
}
