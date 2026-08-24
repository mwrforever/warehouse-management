<?php

// 生产表 DAG 扩列：工单锚定 routing_id 快照 / 物料归属节点 / 工序节点三列 / 工序边表

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 工单锚定工艺路线快照：null=旧逻辑展开（存量单不回写）
        Schema::table('production_orders', function (Blueprint $table) {
            $table->foreignId('routing_id')->nullable()->comment('工艺路线快照')->constrained('routing_headers')->restrictOnDelete();
        });

        // 物料归属工序节点：null=不归节点仅按总量领料（多节点共用物料时不归属）
        Schema::table('production_order_materials', function (Blueprint $table) {
            $table->string('node_no', 20)->nullable()->comment('归属工序节点号');
        });

        // 工序节点三列：node_no 快照 / 输出产品 / 委外标记
        Schema::table('work_order_operations', function (Blueprint $table) {
            $table->string('node_no', 20)->nullable()->comment('工艺路线节点号');
            $table->foreignId('output_product_id')->nullable()->comment('节点输出产品')->constrained('products')->restrictOnDelete();
            $table->tinyInteger('is_outsourced')->default(0)->comment('0自制 1委外');
        });
        // 节点号在工单内唯一（旧数据 node_no 为 null，唯一索引多 null 放行，MySQL/SQLite 均兼容）
        Schema::table('work_order_operations', function (Blueprint $table) {
            $table->unique(['order_id', 'node_no'], 'uniq_work_order_operations_node_no');
        });

        // 工单工序边表：DAG 快照（from/to 双 FK 级联，工序删除随删）；
        // 外键列 comment 置于 constrained() 之前才能写入 MySQL DDL（挂在 ForeignKeyDefinition 上会被静默丢弃）
        Schema::create('work_order_operation_edges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->comment('所属工单')->constrained('production_orders')->cascadeOnDelete();
            $table->foreignId('from_operation_id')->comment('直接前驱工序')
                ->constrained('work_order_operations')->cascadeOnDelete();
            $table->foreignId('to_operation_id')->comment('直接后继工序')
                ->constrained('work_order_operations')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['order_id', 'from_operation_id', 'to_operation_id'], 'uniq_work_order_operation_edges');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_order_operation_edges');
        Schema::table('work_order_operations', function (Blueprint $table) {
            $table->dropUnique('uniq_work_order_operations_node_no');
            $table->dropColumn(['node_no', 'output_product_id', 'is_outsourced']);
        });
        Schema::table('production_order_materials', function (Blueprint $table) {
            $table->dropColumn('node_no');
        });
        Schema::table('production_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('routing_id');
        });
    }
};
