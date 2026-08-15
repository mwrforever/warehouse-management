<?php

// 成本价扫描索引：purchase_inbound_items(product_id, created_at, id) 复合索引替代单列 product_id 索引——
// 成本价估算按「商品内 created_at DESC, id DESC 取首条」遍历，复合索引最左前缀 product_id 继续服务
// 既有单列查询，created_at+id 使遍历免 filesort、按索引序流式输出（cursor 首条即最新价）
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_inbound_items', function (Blueprint $table) {
            // 先建复合索引再删单列索引：最左前缀 product_id 覆盖原 purchase_inbound_items_product 全部用途，无保护空窗
            $table->index(['product_id', 'created_at', 'id'], 'purchase_inbound_items_product_created_id');
            $table->dropIndex('purchase_inbound_items_product');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_inbound_items', function (Blueprint $table) {
            $table->index('product_id', 'purchase_inbound_items_product');
            $table->dropIndex('purchase_inbound_items_product_created_id');
        });
    }
};
