<?php

// 引用保护守卫：主数据删除前检查是否被业务单据引用

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DeletionGuard
{
    /**
     * 检查指定业务表是否已引用目标主数据
     *
     * 表可能由后续模块（库存/采购/销售/生产）创建；表未创建时返回 false，
     * 下游模块迁移落地后本保护自动生效，无需回改本模块代码。
     */
    public static function referenced(string $table, string $column, int $id): bool
    {
        if (! Schema::hasTable($table)) {
            return false;
        }

        return DB::table($table)->where($column, $id)->exists();
    }
}
