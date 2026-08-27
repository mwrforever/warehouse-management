<?php

// 仓库表区域列扩展：省/市/区县/乡镇街道四级地址名称（address 原列保留，语义变为「详细地址」）

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('warehouses', function (Blueprint $table) {
            $table->string('province', 50)->nullable()->comment('省/自治区名称')->after('address');
            $table->string('city', 50)->nullable()->comment('市/自治州名称')->after('province');
            $table->string('district', 50)->nullable()->comment('区县名称')->after('city');
            $table->string('town', 50)->nullable()->comment('乡镇/街道名称')->after('district');
        });
    }

    public function down(): void
    {
        Schema::table('warehouses', function (Blueprint $table) {
            $table->dropColumn(['province', 'city', 'district', 'town']);
        });
    }
};
