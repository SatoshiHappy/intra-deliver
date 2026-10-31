<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 行の権限を参照制限の形にする(docs の 設計/業務データの入れ物/データモデル/表.md 2.1 の「参照制限の列と今ある行」・2.5、ADR 0026)。
 * 今ある行の見え方を変えないように、related の業務モジュールとその親の無いレコードに参照制限を付けて移す。最新の版の snapshot にだけ
 * row_access を足す(版の写しの移行の例外 = 設計/業務データの入れ物/レコードの共通機能/データモデル/表.md の 2.1.1)。
 * 値は SQL と文字列で書く(列挙・モデルを読まない。マイグレーションは固定の履歴)。
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('record_modules', function (Blueprint $table) {
            $table->boolean('row_access_restricted_by_default')->default(false);
        });
        DB::statement(
            "UPDATE record_modules SET row_access_restricted_by_default = true WHERE row_access_scope = 'related'",
        );
        DB::statement(
            'ALTER TABLE record_modules ADD CONSTRAINT record_modules_row_access_restricted_by_default_check '
            .'CHECK (parent_record_module_id IS NULL OR NOT row_access_restricted_by_default)',
        );

        Schema::table('records', function (Blueprint $table) {
            $table->boolean('row_access_restricted')->default(false);
        });
        DB::statement(
            'UPDATE records SET row_access_restricted = true FROM record_modules '
            .'WHERE record_modules.id = records.record_module_id AND records.parent_record_id IS NULL '
            ."AND record_modules.row_access_scope = 'related'",
        );
        DB::statement(
            'ALTER TABLE records ADD CONSTRAINT records_parent_record_id_row_access_restricted_check '
            .'CHECK (parent_record_id IS NULL OR NOT row_access_restricted)',
        );

        DB::statement(
            "UPDATE record_revisions SET snapshot = record_revisions.snapshot || jsonb_build_object('row_access', "
            ."jsonb_build_object('restricted', records.row_access_restricted, 'viewers', '[]'::jsonb)) "
            .'FROM records WHERE records.id = record_revisions.record_id AND records.parent_record_id IS NULL '
            .'AND record_revisions.revision = (SELECT max(latest.revision) FROM record_revisions latest '
            .'WHERE latest.record_id = record_revisions.record_id)',
        );

        DB::statement(
            'ALTER TABLE record_modules DROP CONSTRAINT record_modules_parent_record_module_id_row_access_scope_check',
        );
        DB::statement('ALTER TABLE record_modules DROP CONSTRAINT record_modules_row_access_scope_check');
        Schema::table('record_modules', function (Blueprint $table) {
            $table->dropColumn('row_access_scope');
        });
    }

    /**
     * Reverse the migrations(足した順の逆に戻す)。レコードごとのチェックは row_access_scope の形に持つ所が無いので戻さない。
     * 版の snapshot の row_access は消さずに残す(前の形の読み手はキーを無視する。どれに書き足したかを区別できないため =
     * 設計/業務データの入れ物/レコードの共通機能/データモデル/表.md の 2.1.1)。
     */
    public function down(): void
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
        DB::statement("UPDATE record_modules SET row_access_scope = 'related' WHERE row_access_restricted_by_default");

        DB::statement('ALTER TABLE records DROP CONSTRAINT records_parent_record_id_row_access_restricted_check');
        Schema::table('records', function (Blueprint $table) {
            $table->dropColumn('row_access_restricted');
        });
        DB::statement(
            'ALTER TABLE record_modules DROP CONSTRAINT record_modules_row_access_restricted_by_default_check',
        );
        Schema::table('record_modules', function (Blueprint $table) {
            $table->dropColumn('row_access_restricted_by_default');
        });
    }
};
