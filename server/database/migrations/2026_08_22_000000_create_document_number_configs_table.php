<?php

// 编号规则配置表：type 唯一，prefix/date_format/seq_length 决定单号格式（Spec 2 配置驱动改造）

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_number_configs', function (Blueprint $table) {
            $table->id();
            $table->string('type', 20)->unique()->comment('单据类型键（DocumentSequence::TYPE_* / prd=商品编码）');
            $table->string('prefix', 10)->comment('单号前缀（大写字母，如 PO/MO/PRD）');
            $table->string('date_format', 10)->default('YmdHi')->comment('日期段格式（Ymd/YmdHi/YmdHis，空=无日期段）');
            $table->unsignedTinyInteger('seq_length')->default(3)->comment('序列号补零位数（1~10）');
            $table->boolean('enabled')->default(true)->comment('是否启用（停用回退默认规则）');
            $table->string('remark', 255)->nullable()->comment('备注');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_number_configs');
    }
};
