<?php
// 用户表 email 改为可空：允许纯用户名登录用户不填邮箱（UserStoreRequest/UserUpdateRequest 中 email 为 nullable）
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // 放宽约束：邮箱可选，用户名是唯一登录凭证
            $table->string('email')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable(false)->change();
        });
    }
};
