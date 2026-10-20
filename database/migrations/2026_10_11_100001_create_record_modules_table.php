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
        Schema::create('record_modules', function (Blueprint $table) {
            $table->id();
            $table->string('code', 100)->unique();
            $table->string('name', 1000);
            $table->string('description', 4000)->nullable();
            $table->foreignId('parent_record_module_id')->nullable()->index()->constrained('record_modules')->cascadeOnDelete();
            // 明細のモジュールか(親から決まる生成列。records の複合外部キーの参照先 = 明細のモジュールの行が親を持つことを DB が止める。
            // docs の 設計/業務データの入れ物/データモデル/表.md 2.1・2.5。アプリは書かず読まない)。PostgreSQL 18 は STORED を書かないと VIRTUAL になり、
            // VIRTUAL は一意・外部キーに使えないので storedAs にする
            $table->boolean('is_detail')->storedAs('parent_record_module_id IS NOT NULL');
            $table->bigInteger('sort_key')->default(0);
            $table->timestampsTz();

            $table->unique(['id', 'parent_record_module_id']);
            $table->unique(['id', 'is_detail']);
        });

        DB::statement("ALTER TABLE record_modules ADD CONSTRAINT record_modules_code_check CHECK (code ~ '^[a-z][a-z0-9_]{0,49}$')");
        DB::statement("ALTER TABLE record_modules ADD CONSTRAINT record_modules_name_check CHECK (name <> '')");
        DB::statement('ALTER TABLE record_modules ADD CONSTRAINT record_modules_parent_record_module_id_id_check CHECK (parent_record_module_id <> id)');
        DB::statement('ALTER TABLE record_modules ADD CONSTRAINT record_modules_sort_key_check CHECK (sort_key BETWEEN -999999999999999 AND 999999999999999)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('record_modules');
    }
};
