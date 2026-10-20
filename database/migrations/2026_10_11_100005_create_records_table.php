<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('record_module_id')->constrained()->restrictOnDelete();
            $table->bigInteger('parent_record_module_id')->nullable();
            $table->bigInteger('parent_record_id')->nullable();
            // 明細の行か(親から決まる生成列。下の (record_module_id, is_detail) の外部キーで、明細のモジュールの行は親を持ち、
            // 親の無い業務モジュールの行は持たないことを DB が止める = docs の 設計/業務データの入れ物/データモデル/表.md 2.5)
            $table->boolean('is_detail')->storedAs('parent_record_id IS NOT NULL');
            $table->bigInteger('sort_key')->default(0);
            $table->jsonb('field_values')->default('{}');
            $table->foreignId('created_by_user_id')->nullable()->index()->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->index()->constrained('users')->restrictOnDelete();
            $table->timestampsTz();

            $table->unique(['record_module_id', 'id']);
            $table->foreign(['parent_record_module_id', 'parent_record_id'])->references(['record_module_id', 'id'])->on('records')->cascadeOnDelete();
            $table->foreign(['record_module_id', 'parent_record_module_id'])->references(['id', 'parent_record_module_id'])->on('record_modules')->restrictOnDelete();
            $table->foreign(['record_module_id', 'is_detail'])->references(['id', 'is_detail'])->on('record_modules')->restrictOnDelete();
            $table->index(['parent_record_module_id', 'parent_record_id']);
            $table->index(['record_module_id', 'parent_record_module_id']);
            $table->index(['record_module_id', 'is_detail']);
        });

        DB::statement('ALTER TABLE records ADD CONSTRAINT records_parent_record_module_id_parent_record_id_check CHECK ((parent_record_module_id IS NULL) = (parent_record_id IS NULL))');
        DB::statement("ALTER TABLE records ADD CONSTRAINT records_field_values_check CHECK (jsonb_typeof(field_values) = 'object' AND NOT jsonb_path_exists(field_values, '$.* ? (@ == null)'))");
        DB::statement('ALTER TABLE records ADD CONSTRAINT records_parent_record_id_sort_key_check CHECK (parent_record_id IS NOT NULL OR sort_key = 0)');
        DB::statement('ALTER TABLE records ADD CONSTRAINT records_sort_key_check CHECK (sort_key BETWEEN -999999999999999 AND 999999999999999)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('records');
    }
};
