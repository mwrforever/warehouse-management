<?php

// 计量单位服务：单位创建/更新/删除（编码唯一 1103 + 被商品引用保护 1104）
// 写操作均为单表原子写，无跨表事务，不包 DB::transaction（原子性由单条 insert/update/delete 保证）

namespace App\Services;

use App\Exceptions\MasterDataException;
use App\Models\Product;
use App\Models\Unit;
use Illuminate\Support\Facades\Log;

class UnitService
{
    /**
     * 新建单位（原控制器 store 下沉）：编码重复 1103
     *
     * @param  array  $data  已过 SaveUnitRequest 格式校验的载荷（name/code/status）
     * @return Unit 新建单位（供控制器回显 id）
     *
     * @throws MasterDataException 编码重复 1103
     */
    public function create(array $data): Unit
    {
        // 编码唯一 1103（读检查无需持锁；数据库 unique 约束兜底并发场景）
        if (Unit::where('code', $data['code'])->exists()) {
            throw new MasterDataException('单位编码已存在', 1103);
        }
        $unit = Unit::create([
            'name' => $data['name'], 'code' => $data['code'],
            'status' => $data['status'] ?? Unit::STATUS_ENABLED,
        ]);

        Log::info('计量单位创建成功', ['unit_id' => $unit->id, 'code' => $unit->code, 'operator' => auth()->id()]);

        return $unit;
    }

    /**
     * 更新单位（原控制器 update 下沉）：编码唯一（排除自身）1103
     *
     * @param  Unit  $unit  路由绑定的单位模型
     * @param  array  $data  已过 SaveUnitRequest 格式校验的载荷
     *
     * @throws MasterDataException 编码重复 1103
     */
    public function update(Unit $unit, array $data): void
    {
        if (Unit::where('code', $data['code'])->where('id', '!=', $unit->id)->exists()) {
            throw new MasterDataException('单位编码已存在', 1103);
        }
        $unit->update(['name' => $data['name'], 'code' => $data['code'], 'status' => $data['status'] ?? $unit->status]);

        Log::info('计量单位更新成功', ['unit_id' => $unit->id, 'code' => $unit->code, 'operator' => auth()->id()]);
    }

    /**
     * 删除单位（原控制器 destroy 下沉）：被商品引用 1104（读检查无需持锁）
     *
     * @param  Unit  $unit  路由绑定的单位模型
     *
     * @throws MasterDataException 被商品使用 1104
     */
    public function delete(Unit $unit): void
    {
        if (Product::where('unit_id', $unit->id)->exists()) {
            throw new MasterDataException('单位已被商品使用，不可删除', 1104);
        }
        $unit->delete();

        Log::info('计量单位删除成功', ['unit_id' => $unit->id, 'code' => $unit->code, 'operator' => auth()->id()]);
    }
}
