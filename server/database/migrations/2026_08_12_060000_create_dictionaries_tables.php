<?php
// 数据字典表：字典头 + 字典项（级联删除）
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dictionaries', function (Blueprint $table) {
            $table->id();
            $table->string('name', 50)->comment('字典名称');
            $table->string('code', 50)->unique()->comment('字典编码');
            $table->string('remark')->nullable();
            $table->timestamps();
        });

        Schema::create('dictionary_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dictionary_id')->constrained()->cascadeOnDelete();
            $table->string('label', 50)->comment('显示名');
            $table->string('value', 50)->comment('值');
            $table->integer('sort')->default(0);
            $table->tinyInteger('status')->default(1)->comment('1启用 0停用');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dictionary_items');
        Schema::dropIfExists('dictionaries');
    }
};
