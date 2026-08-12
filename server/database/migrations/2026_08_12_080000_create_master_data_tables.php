<?php
// 基础资料核心表：分类/单位/仓库/库位/供应商/客户/工序/商品/BOM（头+明细）
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 50)->comment('分类名称');
            $table->unsignedBigInteger('parent_id')->default(0)->comment('上级分类ID，0=顶级');
            $table->integer('sort')->default(0)->comment('排序');
            $table->tinyInteger('status')->default(1)->comment('1启用 0停用');
            $table->timestamps();
        });

        Schema::create('units', function (Blueprint $table) {
            $table->id();
            $table->string('name', 50)->comment('单位名称，如 个');
            $table->string('code', 20)->unique()->comment('单位编码，如 pc');
            $table->tinyInteger('status')->default(1)->comment('1启用 0停用');
            $table->timestamps();
        });

        Schema::create('warehouses', function (Blueprint $table) {
            $table->id();
            $table->string('name', 50)->comment('仓库名称');
            $table->string('code', 20)->unique()->comment('仓库编码，如 WH01');
            $table->string('address')->nullable()->comment('仓库地址');
            $table->string('manager', 50)->nullable()->comment('负责人');
            $table->tinyInteger('status')->default(1)->comment('1启用 0停用');
            $table->timestamps();
        });

        Schema::create('locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete()->comment('所属仓库');
            $table->string('name', 50)->comment('库位名称，如 A-01');
            $table->string('code', 50)->unique()->comment('库位编码，全局唯一');
            $table->tinyInteger('status')->default(1)->comment('1启用 0停用');
            $table->timestamps();
        });

        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->comment('供应商名称');
            $table->string('code', 50)->unique()->comment('供应商编码');
            $table->string('contact', 50)->nullable()->comment('联系人');
            $table->string('phone', 30)->nullable()->comment('联系电话');
            $table->string('address')->nullable()->comment('地址');
            $table->string('remark')->nullable()->comment('备注');
            $table->tinyInteger('status')->default(1)->comment('1启用 0停用');
            $table->timestamps();
        });

        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->comment('客户名称');
            $table->string('code', 50)->unique()->comment('客户编码');
            $table->string('contact', 50)->nullable()->comment('联系人');
            $table->string('phone', 30)->nullable()->comment('联系电话');
            $table->string('address')->nullable()->comment('地址');
            $table->string('remark')->nullable()->comment('备注');
            $table->tinyInteger('status')->default(1)->comment('1启用 0停用');
            $table->timestamps();
        });

        Schema::create('processes', function (Blueprint $table) {
            $table->id();
            $table->string('name', 50)->comment('工序名称，如 车削');
            $table->string('code', 50)->unique()->comment('工序编码');
            $table->integer('sort')->default(0)->comment('排序，生产模块下拉按此升序');
            $table->string('description')->nullable()->comment('工序说明');
            $table->tinyInteger('status')->default(1)->comment('1启用 0停用');
            $table->timestamps();
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->comment('商品名称');
            $table->string('code', 50)->unique()->comment('商品编码，支持扫码');
            $table->enum('type', ['raw_material', 'semi_finished', 'finished'])->comment('类型：原料/半成品/成品');
            $table->foreignId('category_id')->constrained('categories')->restrictOnDelete()->comment('所属分类');
            $table->foreignId('unit_id')->constrained('units')->restrictOnDelete()->comment('计量单位');
            $table->string('spec', 100)->nullable()->comment('规格');
            $table->string('barcode', 50)->nullable()->unique()->comment('条码，可空，扫枪输入');
            $table->decimal('safety_min', 12, 2)->default(0)->comment('安全库存下限，0=不预警该侧');
            $table->decimal('safety_max', 12, 2)->default(0)->comment('安全库存上限，0=不预警该侧');
            $table->tinyInteger('status')->default(1)->comment('1启用 0停用');
            $table->string('remark')->nullable()->comment('备注');
            $table->timestamps();
        });

        Schema::create('bom_headers', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique()->comment('BOM 编码，如 BOM20260812-001');
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete()->comment('成品商品');
            $table->string('version', 20)->comment('版本号，如 v1');
            $table->decimal('quantity', 12, 2)->default(1)->comment('基准产出数量，默认1');
            $table->tinyInteger('status')->default(1)->comment('1启用 0停用；同成品启用版本唯一');
            $table->string('remark')->nullable()->comment('备注');
            $table->timestamps();
        });

        Schema::create('bom_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bom_header_id')->constrained()->cascadeOnDelete()->comment('所属 BOM 头');
            $table->foreignId('material_id')->constrained('products')->restrictOnDelete()->comment('物料商品（原料/半成品）');
            $table->decimal('quantity', 12, 2)->comment('单位产品用量，必须 >0');
            $table->foreignId('unit_id')->constrained('units')->restrictOnDelete()->comment('用量单位');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bom_items');
        Schema::dropIfExists('bom_headers');
        Schema::dropIfExists('products');
        Schema::dropIfExists('processes');
        Schema::dropIfExists('customers');
        Schema::dropIfExists('suppliers');
        Schema::dropIfExists('locations');
        Schema::dropIfExists('warehouses');
        Schema::dropIfExists('units');
        Schema::dropIfExists('categories');
    }
};
