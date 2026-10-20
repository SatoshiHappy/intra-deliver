<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 関連レコードの列(項目の部品。docs の 設計/業務データの入れ物/レコードの共通機能/データモデル/表.md の 2.7 節)。
 * 複合外部キーの索引は、列の順を外部キーと逆にする
 * (同じ順だと索引の名前が外部キーの名前と 63 バイトの切り詰めで同じになる)。
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('record_related_columns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('record_field_id')->constrained()->cascadeOnDelete();
            $table->bigInteger('record_module_id');
            $table->bigInteger('column_record_field_id');
            $table->bigInteger('sort_key')->default(0);
            $table->timestampsTz();

            // 一意制約が外部キー record_field_id の索引を兼ねる
            $table->unique(['record_field_id', 'column_record_field_id']);
            $table->foreign(['record_module_id', 'column_record_field_id'])
                ->references(['record_module_id', 'id'])->on('record_fields')->cascadeOnDelete();
            $table->index(['column_record_field_id', 'record_module_id']);
        });

        DB::statement('ALTER TABLE record_related_columns ADD CONSTRAINT record_related_columns_sort_key_check'
            .' CHECK (sort_key BETWEEN -999999999999999 AND 999999999999999)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('record_related_columns');
    }
};
