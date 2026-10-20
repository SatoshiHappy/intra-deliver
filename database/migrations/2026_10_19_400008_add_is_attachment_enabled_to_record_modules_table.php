<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 業務モジュールに「添付ファイルを使用するか」is_attachment_enabled を足す(docs の
 * 設計/業務データの入れ物/レコードの共通機能/データモデル/表.md の 2.8 節)。既定は true(既存の業務モジュールも使用のまま増やす)。
 * 既存の明細のモジュールの行を false にしてから検査制約を足す(列の既定と検査制約を一度に満たすため)。
 */
return new class extends Migration
{
    private const CHECK = 'record_modules_parent_record_module_id_is_attachment_enabled_check';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('record_modules', function (Blueprint $table) {
            $table->boolean('is_attachment_enabled')->default(true);
        });

        DB::update('UPDATE record_modules SET is_attachment_enabled = false WHERE parent_record_module_id IS NOT NULL');
        DB::statement('ALTER TABLE record_modules ADD CONSTRAINT '.self::CHECK
            .' CHECK (parent_record_module_id IS NULL OR NOT is_attachment_enabled)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE record_modules DROP CONSTRAINT '.self::CHECK);

        Schema::table('record_modules', function (Blueprint $table) {
            $table->dropColumn('is_attachment_enabled');
        });
    }
};
