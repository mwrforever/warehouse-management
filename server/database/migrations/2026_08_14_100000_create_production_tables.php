<?php

// 生产核心表（工单域）：生产工单（计划）+ 物料需求快照（BOM 展开结果）+ 工单工序（流转）+ 工序报工（记录）

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_orders', function (Blueprint $table) {
            $table->id();
            $table->string('no', 30)->unique()->comment('生产工单号，如 MO20260812-001');
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete()->comment('成品商品（被引用不可删，1116 数据源）');
            $table->decimal('quantity', 12, 2)->comment('计划数量');
            $table->date('plan_date')->comment('计划日期');
            $table->foreignId('bom_id')->constrained('bom_headers')->restrictOnDelete()->comment('BOM 版本（被引用不可删，1121 数据源）');
            $table->tinyInteger('status')->default(0)->comment('0草稿 1已下达 2生产中 3已完成 4关闭');
            $table->decimal('completed_qty', 12, 2)->default(0)->comment('累计完工数量（成品入库审核回写）');
            $table->unsignedBigInteger('created_by')->nullable()->comment('创建人ID');
            $table->timestamp('released_at')->nullable()->comment('下达时间');
            $table->timestamp('completed_at')->nullable()->comment('完工时间');
            $table->timestamp('closed_at')->nullable()->comment('关闭时间');
            $table->string('remark')->nullable()->comment('备注');
            $table->index('status', 'production_orders_status');
            $table->index('product_id', 'production_orders_product');
            $table->timestamps();
        });

        Schema::create('production_order_materials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('production_orders')->cascadeOnDelete()->comment('所属工单');
            $table->foreignId('material_id')->constrained('products')->restrictOnDelete()->comment('物料商品（原料/半成品，被引用不可删 1116）');
            $table->decimal('required_qty', 12, 2)->comment('需求数量（BOM 展开快照）');
            $table->decimal('issued_qty', 12, 2)->default(0)->comment('已领累计（领料审核+、退料审核-）');
            $table->timestamps();
            $table->unique(['order_id', 'material_id'], 'production_order_materials_unique');
        });

        Schema::create('work_order_operations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('production_orders')->cascadeOnDelete()->comment('所属工单');
            $table->foreignId('process_id')->constrained('processes')->restrictOnDelete()->comment('工序（被引用不可删，1113 数据源）');
            $table->integer('seq')->comment('工序顺序（启用工序按 sort 升序快照）');
            $table->tinyInteger('status')->default(0)->comment('0待开工 1进行中 2已完成');
            $table->decimal('qualified_qty', 12, 2)->default(0)->comment('合格累计（报工回写）');
            $table->decimal('defective_qty', 12, 2)->default(0)->comment('不良累计（仅记录与统计）');
            $table->decimal('hours', 12, 2)->default(0)->comment('工时累计');
            $table->timestamps();
            $table->unique(['order_id', 'seq'], 'work_order_operations_seq_unique');
            $table->index('process_id', 'work_order_operations_process');
        });

        Schema::create('operation_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('operation_id')->constrained('work_order_operations')->cascadeOnDelete()->comment('所属工序');
            $table->foreignId('order_id')->constrained('production_orders')->cascadeOnDelete()->comment('所属工单（冗余便于按工单查询）');
            $table->string('operator', 50)->nullable()->comment('操作人');
            $table->decimal('qualified_qty', 12, 2)->comment('本次合格数');
            $table->decimal('defective_qty', 12, 2)->default(0)->comment('本次不良数（V1 仅记录与统计，返修/报废后续版本）');
            $table->decimal('hours', 12, 2)->default(0)->comment('本次工时（小时，2 位小数）');
            $table->timestamp('report_time')->comment('报工时间');
            $table->string('remark')->nullable()->comment('备注');
            $table->timestamps();
            $table->index('order_id', 'operation_reports_order');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operation_reports');
        Schema::dropIfExists('work_order_operations');
        Schema::dropIfExists('production_order_materials');
        Schema::dropIfExists('production_orders');
    }
};
