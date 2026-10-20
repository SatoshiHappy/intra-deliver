<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 項目の見出し(業務モジュールの部品。docs の 設計/業務データの入れ物/レコードの共通機能/データモデル/表.md の 2.6 節)。
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('record_field_headings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('record_module_id')->index()->constrained()->cascadeOnDelete();
            $table->string('name', 1000);
            $table->bigInteger('sort_key')->default(0);
            $table->timestampsTz();
        });

        DB::statement('ALTER TABLE record_field_headings ADD CONSTRAINT record_field_headings_name_check'
            ." CHECK (name <> '')");
        DB::statement('ALTER TABLE record_field_headings ADD CONSTRAINT record_field_headings_sort_key_check'
            .' CHECK (sort_key BETWEEN -999999999999999 AND 999999999999999)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('record_field_headings');
    }
};
