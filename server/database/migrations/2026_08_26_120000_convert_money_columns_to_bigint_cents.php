<?php

// R2 裁决落地：全库金额列 decimal(14,2) → bigint 分单位整数（宪法 §4.1.1）。
//
// 存量数据口径说明（重要）：本库金额列自建库起即按「分」数值存储
// （specs/2026-08-12-purchase-spec.md 金额约定「价格与金额一律以分存储，前端展示除以 100」；
// 前端提交侧 price = Math.round(元×100)、展示侧 ÷100），decimal(14,2) 仅承载了
// 「数量(2位小数)×整数分单价」产生的小数分（如 190.65 分）。故本迁移不做 ×100 换算
// （若 ×100 会把全库金额放大 100 倍），仅将小数分四舍五入 ROUND 到整数分：
// MySQL ROUND 对 DECIMAL 精确值、SQLite round() 均为 half-away-from-zero，与
// app/Support/Cents.php 统一舍入口径一致；取整损失至多 0.5 分且方向确定可追溯。
//
// 数量类列（quantity/received_qty 等 decimal(12,2)）不在本迁移范围，保持不变。

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 12 个金额列（8 张表）逐表转换：先 ROUND 取整存量小数分，再改列类型 bigint。
     *
     * 先 UPDATE 后 ALTER：UPDATE 在 decimal 列上以 SQL 侧 ROUND 完成（测试库 sqlite 与
     * 生产 MySQL 语法一致）；随后 change() 改型时整数值原样保留。
     * 默认值与注释随 change() 重申（MySQL MODIFY 不携带则会丢失默认 0 与中文注释）。
     */
    public function up(): void
    {
        // 小数分 half-up 取整（无小数分存量时为幂等空转；sqlite/mysql 通用语法）
        DB::update('UPDATE purchase_orders SET total_amount = ROUND(total_amount)');
        DB::update('UPDATE purchase_order_items SET price = ROUND(price), amount = ROUND(amount)');
        DB::update('UPDATE purchase_inbounds SET total_amount = ROUND(total_amount)');
        DB::update('UPDATE purchase_inbound_items SET price = ROUND(price), amount = ROUND(amount)');
        DB::update('UPDATE sales_orders SET total_amount = ROUND(total_amount)');
        DB::update('UPDATE sales_order_items SET price = ROUND(price), amount = ROUND(amount)');
        DB::update('UPDATE sales_outbounds SET total_amount = ROUND(total_amount)');
        DB::update('UPDATE sales_outbound_items SET price = ROUND(price), amount = ROUND(amount)');

        // 列类型 decimal(14,2) → bigint（分单位整数；无符号语义沿用原列有符号定义，负价由业务校验拦截）
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->bigInteger('total_amount')->default(0)->comment('明细金额合计（分）')->change();
        });
        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->bigInteger('price')->comment('含税单价（分）')->change();
            $table->bigInteger('amount')->comment('行金额=数量×单价，half-up 取整数分')->change();
        });
        Schema::table('purchase_inbounds', function (Blueprint $table) {
            $table->bigInteger('total_amount')->default(0)->comment('明细金额合计（分）')->change();
        });
        Schema::table('purchase_inbound_items', function (Blueprint $table) {
            $table->bigInteger('price')->comment('含税单价（分）')->change();
            $table->bigInteger('amount')->comment('行金额=数量×单价，half-up 取整数分')->change();
        });
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->bigInteger('total_amount')->default(0)->comment('明细金额合计（分）')->change();
        });
        Schema::table('sales_order_items', function (Blueprint $table) {
            $table->bigInteger('price')->comment('单价（分）')->change();
            $table->bigInteger('amount')->comment('行金额=数量×单价，half-up 取整数分')->change();
        });
        Schema::table('sales_outbounds', function (Blueprint $table) {
            $table->bigInteger('total_amount')->default(0)->comment('明细金额合计（分）')->change();
        });
        Schema::table('sales_outbound_items', function (Blueprint $table) {
            $table->bigInteger('price')->comment('单价（分）')->change();
            $table->bigInteger('amount')->comment('行金额=数量×单价，half-up 取整数分')->change();
        });
    }

    /**
     * 回滚：金额列恢复 decimal(14,2)。
     * 注意：up 阶段对小数分的 ROUND 取整不可逆（历史小数分已并入整数分），回滚仅恢复列类型。
     */
    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->decimal('total_amount', 14, 2)->default(0)->comment('明细金额合计（分）')->change();
        });
        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->decimal('price', 14, 2)->comment('含税单价（分）')->change();
            $table->decimal('amount', 14, 2)->comment('行金额=数量×单价（分）')->change();
        });
        Schema::table('purchase_inbounds', function (Blueprint $table) {
            $table->decimal('total_amount', 14, 2)->default(0)->comment('明细金额合计（分）')->change();
        });
        Schema::table('purchase_inbound_items', function (Blueprint $table) {
            $table->decimal('price', 14, 2)->comment('含税单价（分）')->change();
            $table->decimal('amount', 14, 2)->comment('行金额=数量×单价（分）')->change();
        });
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->decimal('total_amount', 14, 2)->default(0)->comment('明细金额合计（分）')->change();
        });
        Schema::table('sales_order_items', function (Blueprint $table) {
            $table->decimal('price', 14, 2)->comment('单价（分）')->change();
            $table->decimal('amount', 14, 2)->comment('行金额=数量×单价（分）')->change();
        });
        Schema::table('sales_outbounds', function (Blueprint $table) {
            $table->decimal('total_amount', 14, 2)->default(0)->comment('明细金额合计（分）')->change();
        });
        Schema::table('sales_outbound_items', function (Blueprint $table) {
            $table->decimal('price', 14, 2)->comment('单价（分）')->change();
            $table->decimal('amount', 14, 2)->comment('行金额=数量×单价（分）')->change();
        });
    }
};
