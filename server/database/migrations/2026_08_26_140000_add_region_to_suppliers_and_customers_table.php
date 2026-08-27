<?php

// 供应商/客户基础资料新增四级地址（省/市/区县/乡镇街道名称）。
// 原 address 列语义升级为「详细地址」：结构与数据保持不变，仅前端表单改为
// 四级级联 + 详细地址两段式录入，此处只补四列可空文本列（各级允许为空，不建索引）。

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 新增四级地址列：
     *
     * 各级为区划名称字符串（string(50) 足够省级最多 8 字、市级 20 字内），
     * nullable 表示允许跳过任一级（部分数据无镇级）；comment 落表说明口径，
     * 与前端 AreaCascader 契约（{province,city,district,town} 名称对象）一一对应。
     */
    public function up(): void
    {
        foreach (['suppliers', 'customers'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->string('province', 50)->nullable()->comment('省名称');
                $table->string('city', 50)->nullable()->comment('市名称');
                $table->string('district', 50)->nullable()->comment('区县名称');
                $table->string('town', 50)->nullable()->comment('乡镇街道名称');
            });
        }
    }

    /** 对称回滚：删除四列（base version 2026_08_12_080000 建表时无此四列） */
    public function down(): void
    {
        foreach (['suppliers', 'customers'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropColumn(['province', 'city', 'district', 'town']);
            });
        }
    }
};
