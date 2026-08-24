<?php

// 工艺路线四表：头（成品级可版本化）/节点（工序+输入材料+输出半成品+委外标记）/节点材料/边（DAG 依赖）

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 工艺路线头：挂成品下，同成品启用版本唯一（应用层保证，同 BOM 口径）
        Schema::create('routing_headers', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique()->comment('工艺路线编码（序列自动生成）');
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete()->comment('成品');
            $table->string('version', 20)->comment('版本号');
            $table->decimal('quantity', 12, 2)->default(1)->comment('基准产出数量');
            $table->tinyInteger('status')->default(0)->comment('0停用 1启用');
            $table->string('remark', 200)->nullable()->comment('备注');
            $table->timestamps();
            // 按成品查启用版本（brief 原稿 index(名字, 列) 参数倒置会致列不存在错误，按其预案降级为默认名索引）
            $table->index('product_id');
        });

        // 工序节点：node_no 同路线唯一（如 OP10），输出产品=半成品或成品
        Schema::create('routing_nodes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('routing_id')->constrained('routing_headers')->cascadeOnDelete();
            $table->foreignId('process_id')->constrained('processes')->restrictOnDelete()->comment('工序');
            $table->string('node_no', 20)->comment('节点号 OP10');
            $table->string('name', 50)->comment('工序名快照');
            $table->foreignId('output_product_id')->constrained('products')->restrictOnDelete()->comment('输出产品');
            $table->decimal('output_qty', 12, 2)->default(1)->comment('相对基准产出的产出数量');
            $table->tinyInteger('is_outsourced')->default(0)->comment('0自制 1委外');
            $table->string('remark', 200)->nullable();
            $table->timestamps();
            $table->unique(['routing_id', 'node_no'], 'uniq_routing_nodes_node_no');
        });

        // 节点输入材料：原料或前驱半成品；同节点同物料唯一
        Schema::create('routing_node_materials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('node_id')->constrained('routing_nodes')->cascadeOnDelete();
            $table->foreignId('material_id')->constrained('products')->restrictOnDelete();
            $table->decimal('qty_per_unit', 12, 2)->comment('单位产出的投入用量');
            $table->foreignId('unit_id')->constrained('units')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['node_id', 'material_id'], 'uniq_routing_node_materials');
        });

        // DAG 边：直接前驱→后继依赖
        Schema::create('routing_edges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('routing_id')->constrained('routing_headers')->cascadeOnDelete();
            $table->foreignId('from_node_id')->constrained('routing_nodes')->cascadeOnDelete();
            $table->foreignId('to_node_id')->constrained('routing_nodes')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['routing_id', 'from_node_id', 'to_node_id'], 'uniq_routing_edges_triple');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('routing_edges');
        Schema::dropIfExists('routing_node_materials');
        Schema::dropIfExists('routing_nodes');
        Schema::dropIfExists('routing_headers');
    }
};
