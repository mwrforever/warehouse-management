<?php

// 工序表加标签分类外键：category_id 可空指向字典项（工序标签分类），
// 删除字典项时置空（nullOnDelete），历史工序无分类不阻断

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 添加可空分类外键（字典项删除后工序保留、分类置空；老数据为 NULL 表示未分类）
     */
    public function up(): void
    {
        Schema::table('processes', function (Blueprint $table) {
            $table->foreignId('category_id')->nullable()
                ->constrained('dictionary_items')->nullOnDelete()
                ->comment('工序标签分类（字典项）');
        });
    }

    /** 对称回滚：删除外键与列（先约束后列由框架自动处理） */
    public function down(): void
    {
        Schema::table('processes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('category_id');
        });
    }
};
