<?php

// 单据号段持久序列：type+date 唯一，seq 单调自增（单据删除不回退号段，保证单号不重复、不撞号）
// 供盘点单（check）等单据号 CK{date}-{seq} 原子取号；后续采购/销售/生产模块可复用同一机制

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_sequences', function (Blueprint $table) {
            $table->id();
            $table->string('type', 20)->comment('单据类型：check=盘点单（后续模块扩展复用）');
            $table->string('date', 8)->comment('业务日期 Ymd');
            $table->unsignedInteger('seq')->default(0)->comment('当日已取用的最大序号（原子自增）');
            $table->timestamps();
            // 类型×日期唯一：并发建序列行由唯一索引拦截，撞号后换号重试
            $table->unique(['type', 'date'], 'document_sequences_type_date_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_sequences');
    }
};
