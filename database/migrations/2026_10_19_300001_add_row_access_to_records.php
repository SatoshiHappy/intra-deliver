<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 行の権限(docs の 設計/業務データの入れ物/データモデル/表.md 2.1・2.2・2.6 の最後、ADR 0018 の決定 2・3)。
 * 業務モジュールの見える範囲 row_access_scope と、項目の関係者の印 grants_row_access と、その検査制約を足す。
 * 今の業務モジュールはどれも全員(all)、今の項目はどれも旗なしになり、振る舞いは変わらない。索引は足さない(データモデル/表.md 2.6 の最後)。
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('record_modules', function (Blueprint $table) {
            $table->string('row_access_scope', 20)->default('all');
        });

        DB::statement(
            'ALTER TABLE record_modules ADD CONSTRAINT record_modules_row_access_scope_check '
            ."CHECK (row_access_scope IN ('all', 'related'))",
        );
        DB::statement(
            'ALTER TABLE record_modules ADD CONSTRAINT record_modules_parent_record_module_id_row_access_scope_check '
            ."CHECK (parent_record_module_id IS NULL OR row_access_scope = 'all')",
        );

        Schema::table('record_fields', function (Blueprint $table) {
            $table->boolean('grants_row_access')->default(false);
        });

        DB::statement(
            'ALTER TABLE record_fields ADD CONSTRAINT record_fields_type_grants_row_access_check '
            ."CHECK (type IN ('user', 'department') OR NOT grants_row_access)",
        );
    }

    /**
     * Reverse the migrations(足した順の逆に戻す)。
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE record_fields DROP CONSTRAINT record_fields_type_grants_row_access_check');

        Schema::table('record_fields', function (Blueprint $table) {
            $table->dropColumn('grants_row_access');
        });

        DB::statement(
            'ALTER TABLE record_modules DROP CONSTRAINT record_modules_parent_record_module_id_row_access_scope_check',
        );
        DB::statement('ALTER TABLE record_modules DROP CONSTRAINT record_modules_row_access_scope_check');

        Schema::table('record_modules', function (Blueprint $table) {
            $table->dropColumn('row_access_scope');
        });
    }
};
