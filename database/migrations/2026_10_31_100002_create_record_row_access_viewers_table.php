<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 参照者の表(docs の 設計/業務データの入れ物/データモデル/表.md 2.8、ADR 0026)。1 行 = 参照を制限したレコードの参照者 1 つ(ユーザーか組織)。
 * レコードを消すと行も消え(CASCADE)、ユーザー・組織は RESTRICT。参照制限の無いレコードに行を置かないことは DB で止めない(業務処理が止める)。
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('record_row_access_viewers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('record_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->index()->constrained()->restrictOnDelete();
            $table->foreignId('department_id')->nullable()->index()->constrained()->restrictOnDelete();
            $table->bigInteger('sort_key')->default(0);
            $table->timestampsTz();

            $table->unique(['record_id', 'sort_key']);
            $table->unique(['record_id', 'user_id']);
            $table->unique(['record_id', 'department_id']);
        });

        DB::statement(
            'ALTER TABLE record_row_access_viewers '
            .'ADD CONSTRAINT record_row_access_viewers_user_id_department_id_check '
            .'CHECK (num_nonnulls(user_id, department_id) = 1)',
        );
        DB::statement(
            'ALTER TABLE record_row_access_viewers ADD CONSTRAINT record_row_access_viewers_sort_key_check '
            .'CHECK (sort_key BETWEEN -999999999999999 AND 999999999999999)',
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('record_row_access_viewers');
    }
};
