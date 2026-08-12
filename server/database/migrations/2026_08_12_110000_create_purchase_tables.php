<?php

// 采购核心表：采购订单（计划）+ 采购入库单（执行）；入库单审核经 InventoryService 写流水加库存
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->string('no', 30)->unique()->comment('采购订单号，如 PO20260812-001');
            $table->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete()->comment('供应商（被引用不可删，1109 删除保护数据源）');
            $table->date('order_date')->comment('下单日期');
            $table->date('expected_date')->nullable()->comment('预计到货日期');
            $table->tinyInteger('status')->default(0)->comment('0草稿 1已审核 2部分入库 3已完成 4关闭');
            $table->decimal('total_amount', 14, 2)->default(0)->comment('明细金额合计（分）');
            $table->string('remark')->nullable()->comment('备注');
            $table->unsignedBigInteger('created_by')->nullable()->comment('创建人ID');
            $table->timestamp('approved_at')->nullable()->comment('审核时间');
            $table->timestamp('closed_at')->nullable()->comment('关闭时间');
            $table->index('status', 'purchase_orders_status');
            $table->index('supplier_id', 'purchase_orders_supplier');
            $table->timestamps();
        });

        Schema::create('purchase_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('purchase_orders')->cascadeOnDelete()->comment('所属订单');
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete()->comment('商品');
            $table->decimal('quantity', 12, 2)->comment('订购数量');
            $table->decimal('price', 14, 2)->comment('含税单价（分）');
            $table->decimal('received_qty', 12, 2)->default(0)->comment('已入库累计（审核回写）');
            $table->decimal('amount', 14, 2)->comment('行金额=数量×单价（分）');
            $table->timestamps();
            $table->index('product_id', 'purchase_order_items_product');
        });

        Schema::create('purchase_inbounds', function (Blueprint $table) {
            $table->id();
            $table->string('no', 30)->unique()->comment('采购入库单号，如 PI20260812-001');
            $table->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete()->comment('供应商');
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete()->comment('入库仓库');
            $table->foreignId('location_id')->constrained('locations')->restrictOnDelete()->comment('入库库位');
            $table->foreignId('order_id')->nullable()->constrained('purchase_orders')->nullOnDelete()->comment('来源采购订单（独立入库为空）');
            $table->tinyInteger('status')->default(0)->comment('0草稿 1已审核');
            $table->decimal('total_amount', 14, 2)->default(0)->comment('明细金额合计（分）');
            $table->timestamp('inbound_at')->nullable()->comment('审核入库时间');
            $table->string('operator', 50)->nullable()->comment('审核人');
            $table->string('remark')->nullable()->comment('备注');
            $table->index('status', 'purchase_inbounds_status');
            $table->index('order_id', 'purchase_inbounds_order');
            $table->timestamps();
        });

        Schema::create('purchase_inbound_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inbound_id')->constrained('purchase_inbounds')->cascadeOnDelete()->comment('所属入库单');
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete()->comment('商品');
            $table->decimal('quantity', 12, 2)->comment('入库数量');
            $table->decimal('price', 14, 2)->comment('含税单价（分）');
            $table->decimal('amount', 14, 2)->comment('行金额=数量×单价（分）');
            $table->foreignId('order_item_id')->nullable()->constrained('purchase_order_items')->nullOnDelete()->comment('关联订单行（独立入库为空）');
            $table->timestamps();
            $table->index('product_id', 'purchase_inbound_items_product');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_inbound_items');
        Schema::dropIfExists('purchase_inbounds');
        Schema::dropIfExists('purchase_order_items');
        Schema::dropIfExists('purchase_orders');
    }
};
