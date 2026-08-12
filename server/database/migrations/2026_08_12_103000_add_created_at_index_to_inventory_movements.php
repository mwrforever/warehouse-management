<?php

// 流水日期筛选索引：流水量大时 date_from/date_to 筛选避免全表扫
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->index('created_at', 'movement_created_at');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->dropIndex('movement_created_at');
        });
    }
};
