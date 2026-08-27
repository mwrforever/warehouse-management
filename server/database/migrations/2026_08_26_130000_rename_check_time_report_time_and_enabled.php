<?php

// D-7 命名规约对齐（存量列更名）：时间字段统一 xxx_at（§3.3.1）、布尔字段统一 is_ 前缀
// check_time → checked_at（盘点审核时间）、report_time → reported_at（报工时间）、
// enabled → is_enabled（编号规则启用标记）；纯列更名，数据零丢失

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 逐列更名（RENAME COLUMN 原生支持，数据保留）：
     *
     * Laravel 原生生成 `ALTER TABLE ... RENAME COLUMN`，SQLite ≥ 3.25
     * （本项目测试环境 3.53）与 MySQL ≥ 8.0.3 均原生支持，无需
     * 「加新列 → UPDATE 复制 → 删旧列」三步法重建表。
     */
    public function up(): void
    {
        Schema::table('inventory_checks', function (Blueprint $table) {
            $table->renameColumn('check_time', 'checked_at');
        });

        Schema::table('operation_reports', function (Blueprint $table) {
            $table->renameColumn('report_time', 'reported_at');
        });

        Schema::table('document_number_configs', function (Blueprint $table) {
            $table->renameColumn('enabled', 'is_enabled');
        });
    }

    /** 对称回滚：新列名恢复旧列名（同样经 RENAME COLUMN，数据保留） */
    public function down(): void
    {
        Schema::table('inventory_checks', function (Blueprint $table) {
            $table->renameColumn('checked_at', 'check_time');
        });

        Schema::table('operation_reports', function (Blueprint $table) {
            $table->renameColumn('reported_at', 'report_time');
        });

        Schema::table('document_number_configs', function (Blueprint $table) {
            $table->renameColumn('is_enabled', 'enabled');
        });
    }
};
