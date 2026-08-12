<?php

// 库存核心表：余额/流水/盘点（头+明细），一切库存变动唯一事实来源
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete()->comment('商品');
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete()->comment('仓库');
            $table->foreignId('location_id')->constrained('locations')->restrictOnDelete()->comment('库位');
            $table->decimal('quantity', 12, 2)->default(0)->comment('当前余额，允许0不允许负');
            $table->decimal('safety_min', 12, 2)->default(0)->comment('安全库存下限冗余（自商品，查询用快照）');
            $table->decimal('safety_max', 12, 2)->default(0)->comment('安全库存上限冗余（自商品，查询用快照）');
            $table->timestamps();
            // 商品×仓库×库位唯一：并发首次入库靠此约束兜底（Service 捕获 1062 重查加锁）
            $table->unique(['product_id', 'warehouse_id', 'location_id'], 'balance_unique');
        });

        Schema::create('inventory_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete()->comment('商品');
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete()->comment('仓库');
            $table->foreignId('location_id')->constrained('locations')->restrictOnDelete()->comment('库位');
            $table->tinyInteger('direction')->comment('1=入库 -1=出库');
            $table->decimal('quantity', 12, 2)->comment('变动数量（恒正）');
            $table->decimal('balance_after', 12, 2)->comment('变动后余额快照');
            $table->string('source_type', 30)->comment('来源类型枚举：purchase_inbound/sales_outbound/pick/return/finished_inbound/outsourcing_out/outsourcing_in/check_in/check_out');
            $table->unsignedBigInteger('source_id')->comment('来源单据ID');
            $table->string('source_no', 30)->comment('来源单号，如 PO20260812-001');
            $table->string('remark')->nullable()->comment('备注');
            $table->unsignedBigInteger('operator_id')->nullable()->comment('操作人ID');
            $table->index(['product_id', 'warehouse_id'], 'movement_product_wh');
            $table->index('source_type', 'movement_source_type');
            $table->timestamps();
        });

        Schema::create('inventory_checks', function (Blueprint $table) {
            $table->id();
            $table->string('no', 30)->unique()->comment('盘点单号，如 CK20260812-001');
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete()->comment('盘点仓库');
            $table->tinyInteger('status')->default(0)->comment('0草稿 1已审核');
            $table->string('checker', 50)->nullable()->comment('审核人');
            $table->timestamp('check_time')->nullable()->comment('审核时间');
            $table->string('remark')->nullable()->comment('备注');
            $table->timestamps();
        });

        Schema::create('inventory_check_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('check_id')->constrained('inventory_checks')->cascadeOnDelete()->comment('所属盘点单');
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete()->comment('商品');
            $table->foreignId('location_id')->constrained('locations')->restrictOnDelete()->comment('库位');
            $table->decimal('book_qty', 12, 2)->comment('账面数（创建时余额快照）');
            $table->decimal('actual_qty', 12, 2)->comment('实盘数');
            $table->decimal('diff_qty', 12, 2)->default(0)->comment('差异=实盘-账面（审核时计算）');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_check_items');
        Schema::dropIfExists('inventory_checks');
        Schema::dropIfExists('inventory_movements');
        Schema::dropIfExists('inventory_balances');
    }
};
