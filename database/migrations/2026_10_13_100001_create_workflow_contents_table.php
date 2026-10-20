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
        Schema::create('workflow_contents', function (Blueprint $table) {
            $table->id();
            $table->string('code', 100)->unique();
            $table->string('name', 1000);
            $table->bigInteger('record_module_id');
            // 定数の生成列(下の (record_module_id, is_detail) の外部キーで、申請フォームの業務モジュールが親の無いもの = 明細のモジュールでないことを
            // DB が止める = docs の 設計/ワークフロー/データモデル/表.md 2.1。アプリは書かず読まない。records.is_detail と同じ形)
            $table->boolean('is_detail')->storedAs('false');
            $table->timestampsTz();

            $table->foreign(['record_module_id', 'is_detail'])->references(['id', 'is_detail'])->on('record_modules')->restrictOnDelete();
            $table->index(['record_module_id', 'is_detail']);
        });

        DB::statement("ALTER TABLE workflow_contents ADD CONSTRAINT workflow_contents_code_check CHECK (code ~ '^[a-z][a-z0-9_]{0,49}$')");
        DB::statement("ALTER TABLE workflow_contents ADD CONSTRAINT workflow_contents_name_check CHECK (name <> '')");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('workflow_contents');
    }
};
