<?php

// 委外组件模型：委外单扩列（回收品/累计回收/已关闭态）+ 发料组件表 + 余料退回表（osrt 类型 ORT 前缀）

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 委外单：回收品=工序节点输出（半成品或成品）；status 3=已关闭（余料全部退回后自动）
        Schema::table('outsourcing_orders', function (Blueprint $table) {
            $table->foreignId('output_product_id')->nullable()->comment('回收品（工序节点输出）')->constrained('products')->restrictOnDelete();
            $table->decimal('received_qty', 12, 2)->default(0)->comment('累计回收量');
        });

        // 发料组件：应发=委外量×单位用量；实发=发出时全额扣减 issued_qty；同单同物料唯一
        Schema::create('outsourcing_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('outsourcing_id')->comment('所属委外单')->constrained('outsourcing_orders')->cascadeOnDelete();
            $table->foreignId('material_id')->comment('发料组件（原料/半成品）')->constrained('products')->restrictOnDelete();
            $table->decimal('required_qty', 12, 2)->comment('应发数量（委外量×单位用量折算）');
            $table->decimal('issued_qty', 12, 2)->default(0)->comment('已发数量');
            $table->decimal('returned_qty', 12, 2)->default(0)->comment('已退回数量');
            $table->foreignId('unit_id')->comment('计量单位')->constrained('units')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['outsourcing_id', 'material_id'], 'uniq_outsourcing_order_items');
        });

        // 余料退回：创建即审核（status 恒 1）；item_id 可空=按物料整体退回；单号 osrt 类型前缀 ORT
        Schema::create('outsourcing_returns', function (Blueprint $table) {
            $table->id();
            $table->string('no', 30)->unique()->comment('退回单号');
            $table->foreignId('outsourcing_id')->comment('所属委外单')->constrained('outsourcing_orders')->cascadeOnDelete();
            $table->foreignId('item_id')->nullable()->comment('退回组件行（可空=按物料退回）')->constrained('outsourcing_order_items')->restrictOnDelete();
            $table->foreignId('material_id')->comment('退回物料')->constrained('products')->restrictOnDelete();
            $table->decimal('quantity', 12, 2)->comment('退回数量');
            $table->foreignId('warehouse_id')->comment('入库仓库')->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('location_id')->comment('入库库位')->constrained('locations')->restrictOnDelete();
            $table->tinyInteger('status')->default(1)->comment('创建即审核恒为 1');
            $table->timestamp('returned_at')->comment('退回时间');
            $table->string('operator', 50)->nullable()->comment('操作人');
            $table->string('remark', 200)->nullable()->comment('备注');
            $table->timestamps();
            // 按单+物料查退回（IndexDefinition 不支持 comment 链，意图以注释承载）
            $table->index(['outsourcing_id', 'material_id'], 'idx_outsourcing_returns_pair');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outsourcing_returns');
        Schema::dropIfExists('outsourcing_order_items');
        Schema::table('outsourcing_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('output_product_id');
            $table->dropColumn('received_qty');
        });
    }
};
