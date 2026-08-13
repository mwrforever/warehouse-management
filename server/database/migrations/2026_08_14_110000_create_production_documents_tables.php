<?php

// 生产单据表（单据域）：领料单+明细、退料单+明细、委外单+回收单、成品入库单+明细
// 各单据审核均经 InventoryService 写流水（pick/return/outsourcing_out/outsourcing_in/finished_inbound）

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pick_lists', function (Blueprint $table) {
            $table->id();
            $table->string('no', 30)->unique()->comment('领料单号，如 PL20260812-001');
            $table->foreignId('order_id')->constrained('production_orders')->restrictOnDelete()->comment('所属工单');
            $table->tinyInteger('status')->default(0)->comment('0草稿 1已审核');
            $table->tinyInteger('issue_status')->default(0)->comment('0未发料 1部分发料 2全部发料');
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete()->comment('领料仓库');
            $table->foreignId('location_id')->constrained('locations')->restrictOnDelete()->comment('领料库位');
            $table->timestamp('approved_at')->nullable()->comment('审核时间');
            $table->string('operator', 50)->nullable()->comment('审核人');
            $table->string('remark')->nullable()->comment('备注');
            $table->index('status', 'pick_lists_status');
            $table->index('order_id', 'pick_lists_order');
            $table->timestamps();
        });

        Schema::create('pick_list_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pick_id')->constrained('pick_lists')->cascadeOnDelete()->comment('所属领料单');
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete()->comment('物料商品');
            $table->decimal('required_qty', 12, 2)->comment('需求数量（生成时快照）');
            $table->decimal('pick_qty', 12, 2)->comment('本次领用数量');
            $table->decimal('issued_qty', 12, 2)->default(0)->comment('已发数量（发料动作回写）');
            $table->timestamps();
            $table->index('product_id', 'pick_list_items_product');
        });

        Schema::create('return_lists', function (Blueprint $table) {
            $table->id();
            $table->string('no', 30)->unique()->comment('退料单号，如 RL20260812-001');
            $table->foreignId('order_id')->constrained('production_orders')->restrictOnDelete()->comment('所属工单');
            $table->foreignId('pick_id')->nullable()->constrained('pick_lists')->nullOnDelete()->comment('冲销来源领料单（可空）');
            $table->tinyInteger('status')->default(0)->comment('0草稿 1已审核');
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete()->comment('退料仓库');
            $table->foreignId('location_id')->constrained('locations')->restrictOnDelete()->comment('退料库位');
            $table->timestamp('approved_at')->nullable()->comment('审核时间');
            $table->string('operator', 50)->nullable()->comment('审核人');
            $table->string('remark')->nullable()->comment('备注');
            $table->index('status', 'return_lists_status');
            $table->index('order_id', 'return_lists_order');
            $table->timestamps();
        });

        Schema::create('return_list_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('return_id')->constrained('return_lists')->cascadeOnDelete()->comment('所属退料单');
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete()->comment('物料商品');
            $table->decimal('quantity', 12, 2)->comment('退料数量');
            $table->timestamps();
            $table->index('product_id', 'return_list_items_product');
        });

        Schema::create('outsourcing_orders', function (Blueprint $table) {
            $table->id();
            $table->string('no', 30)->unique()->comment('委外加工单号，如 OS20260812-001');
            $table->foreignId('order_id')->constrained('production_orders')->restrictOnDelete()->comment('所属工单');
            $table->foreignId('operation_id')->constrained('work_order_operations')->restrictOnDelete()->comment('委外工序');
            $table->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete()->comment('委外供应商');
            $table->tinyInteger('status')->default(0)->comment('0草稿 1已审核(已发出) 2已回收');
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete()->comment('发出仓库');
            $table->foreignId('location_id')->constrained('locations')->restrictOnDelete()->comment('发出库位');
            $table->decimal('quantity', 12, 2)->comment('委外数量（发出=回收基准）');
            $table->timestamp('approved_at')->nullable()->comment('发出时间');
            $table->string('operator', 50)->nullable()->comment('操作人');
            $table->string('remark')->nullable()->comment('备注');
            $table->index('status', 'outsourcing_orders_status');
            $table->index('order_id', 'outsourcing_orders_order');
            $table->timestamps();
        });

        Schema::create('outsourcing_receipts', function (Blueprint $table) {
            $table->id();
            $table->string('no', 30)->unique()->comment('委外回收单号，如 OSR20260812-001');
            $table->foreignId('outsourcing_id')->constrained('outsourcing_orders')->cascadeOnDelete()->comment('所属委外单');
            $table->decimal('quantity', 12, 2)->comment('回收数量');
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete()->comment('入库仓库');
            $table->foreignId('location_id')->constrained('locations')->restrictOnDelete()->comment('入库库位');
            $table->tinyInteger('status')->default(1)->comment('回收单创建即审核，恒为 1 已审核');
            $table->timestamp('received_at')->comment('回收时间');
            $table->string('operator', 50)->nullable()->comment('操作人');
            $table->string('remark')->nullable()->comment('备注');
            $table->timestamps();
        });

        Schema::create('finished_inbounds', function (Blueprint $table) {
            $table->id();
            $table->string('no', 30)->unique()->comment('成品入库单号，如 FI20260812-001');
            $table->foreignId('order_id')->constrained('production_orders')->restrictOnDelete()->comment('所属工单');
            $table->tinyInteger('status')->default(0)->comment('0草稿 1已审核');
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete()->comment('入库仓库');
            $table->foreignId('location_id')->constrained('locations')->restrictOnDelete()->comment('入库库位');
            $table->timestamp('approved_at')->nullable()->comment('审核时间');
            $table->string('operator', 50)->nullable()->comment('审核人');
            $table->string('remark')->nullable()->comment('备注');
            $table->index('status', 'finished_inbounds_status');
            $table->index('order_id', 'finished_inbounds_order');
            $table->timestamps();
        });

        Schema::create('finished_inbound_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('finished_inbound_id')->constrained('finished_inbounds')->cascadeOnDelete()->comment('所属入库单');
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete()->comment('成品商品（必须与工单产品一致 1526）');
            $table->decimal('quantity', 12, 2)->comment('入库数量');
            $table->timestamps();
            $table->index('product_id', 'finished_inbound_items_product');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finished_inbound_items');
        Schema::dropIfExists('finished_inbounds');
        Schema::dropIfExists('outsourcing_receipts');
        Schema::dropIfExists('outsourcing_orders');
        Schema::dropIfExists('return_list_items');
        Schema::dropIfExists('return_lists');
        Schema::dropIfExists('pick_list_items');
        Schema::dropIfExists('pick_lists');
    }
};
