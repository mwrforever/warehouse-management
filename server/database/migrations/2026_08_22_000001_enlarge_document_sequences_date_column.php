<?php

// document_sequences.date 列扩宽：老键粒度 Ymd（8位），配置化后键粒度随 date_format 变化（YmdHi=12 位 / YmdHis=14 位）

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_sequences', function (Blueprint $table) {
            $table->string('date', 20)->change();
        });
    }

    public function down(): void
    {
        Schema::table('document_sequences', function (Blueprint $table) {
            $table->string('date', 8)->change();
        });
    }
};
