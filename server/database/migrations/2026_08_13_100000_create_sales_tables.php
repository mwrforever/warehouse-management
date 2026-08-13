<?php

// 销售核心表：销售订单（计划）+ 销售出库单（执行）；出库单审核经 InventoryService 写流水扣库存（防超卖）

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_orders', function (Blueprint $table) {
            $table->id();
            $table->string('no', 30)->unique()->comment('销售订单号，如 SO20260812-001');
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete()->comment('客户（被引用不可删，1111 删除保护数据源）');
            $table->date('order_date')->comment('下单日期');
            $table->date('expected_date')->nullable()->comment('预计发货日期');
            $table->tinyInteger('status')->default(0)->comment('0草稿 1已审核 2部分出库 3已完成 4关闭');
            $table->decimal('total_amount', 14, 2)->default(0)->comment('明细金额合计（分）');
            $table->string('remark')->nullable()->comment('备注');
            $table->unsignedBigInteger('created_by')->nullable()->comment('创建人ID');
            $table->timestamp('approved_at')->nullable()->comment('审核时间');
            $table->timestamp('closed_at')->nullable()->comment('关闭时间');
            $table->index('status', 'sales_orders_status');
            $table->index('customer_id', 'sales_orders_customer');
            $table->timestamps();
        });

        Schema::create('sales_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('sales_orders')->cascadeOnDelete()->comment('所属订单');
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete()->comment('商品');
            $table->decimal('quantity', 12, 2)->comment('订购数量');
            $table->decimal('price', 14, 2)->comment('单价（分）');
            $table->decimal('shipped_qty', 12, 2)->default(0)->comment('已出库累计（审核回写）');
            $table->decimal('amount', 14, 2)->comment('行金额=数量×单价（分）');
            $table->timestamps();
            $table->index('product_id', 'sales_order_items_product');
        });

        Schema::create('sales_outbounds', function (Blueprint $table) {
            $table->id();
            $table->string('no', 30)->unique()->comment('销售出库单号，如 SOUT20260812-001');
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete()->comment('客户');
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete()->comment('出库仓库');
            $table->foreignId('location_id')->constrained('locations')->restrictOnDelete()->comment('出库库位');
            $table->foreignId('order_id')->nullable()->constrained('sales_orders')->nullOnDelete()->comment('来源销售订单（独立出库为空）');
            $table->tinyInteger('status')->default(0)->comment('0草稿 1已审核');
            $table->decimal('total_amount', 14, 2)->default(0)->comment('明细金额合计（分）');
            $table->timestamp('outbound_at')->nullable()->comment('审核出库时间');
            $table->string('operator', 50)->nullable()->comment('审核人');
            $table->string('remark')->nullable()->comment('备注');
            $table->index('status', 'sales_outbounds_status');
            $table->index('order_id', 'sales_outbounds_order');
            $table->timestamps();
        });

        Schema::create('sales_outbound_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('outbound_id')->constrained('sales_outbounds')->cascadeOnDelete()->comment('所属出库单');
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete()->comment('商品');
            $table->decimal('quantity', 12, 2)->comment('出库数量');
            $table->decimal('price', 14, 2)->comment('单价（分）');
            $table->decimal('amount', 14, 2)->comment('行金额=数量×单价（分）');
            $table->foreignId('order_item_id')->nullable()->constrained('sales_order_items')->nullOnDelete()->comment('关联订单行（独立出库为空）');
            $table->timestamps();
            $table->index('product_id', 'sales_outbound_items_product');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_outbound_items');
        Schema::dropIfExists('sales_outbounds');
        Schema::dropIfExists('sales_order_items');
        Schema::dropIfExists('sales_orders');
    }
};
