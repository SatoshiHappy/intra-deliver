<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * コンテンツに申請対象の項目 target_record_field_id と、その参照先の写し target_record_module_id を足し、業務モジュールを指すコンテンツを
 * 1 つまでにする(docs の 設計/ワークフロー/データモデル/表.md の 2.1 節)。今ある行は 2 列が NULL(申請対象なし)のまま。
 * 申請対象を持つ行が残っている間は戻さない(列を消すと黙って申請対象なしになる)。
 * 制約と索引の名前・索引の列の順は 設計/ワークフロー/データモデル/直列化と検査.md の 4 節。
 */
return new class extends Migration
{
    /** 申請対象の 2 列の組を止める外部キー(片方だけが NULL の行を MATCH FULL で拒む) */
    private const TARGET_FOREIGN = 'workflow_contents_target_record_field_id_target_record_module_id_foreign';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('workflow_contents', function (Blueprint $table) {
            $table->bigInteger('target_record_field_id')->nullable();
            $table->bigInteger('target_record_module_id')->nullable();
            $table->unique('record_module_id');
            $table->foreign(['record_module_id', 'target_record_field_id'])
                ->references(['record_module_id', 'id'])->on('record_fields')->restrictOnDelete();
            $table->index(['record_module_id', 'target_record_field_id']);
        });

        // スキーマビルダーは MATCH を書けない(書かなければ MATCH SIMPLE で片方だけが NULL の行を通す)ので SQL 文で作る
        DB::statement('ALTER TABLE workflow_contents ADD CONSTRAINT '.self::TARGET_FOREIGN
            .' FOREIGN KEY (target_record_field_id, target_record_module_id)'
            .' REFERENCES record_fields (id, referenced_record_module_id) MATCH FULL ON DELETE RESTRICT');

        Schema::table('workflow_contents', function (Blueprint $table) {
            // 外部キーと同じ列の順だと名前が切り詰めで重なる(直列化と検査.md の 4 節の手当て 4)
            $table->index(['target_record_module_id', 'target_record_field_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('workflow_contents')->whereNotNull('target_record_field_id')->exists()) {
            throw new RuntimeException(
                '申請対象を持つコンテンツの行が残っているので戻せない(申請対象の列を消すと、申請対象なしになる)',
            );
        }

        Schema::table('workflow_contents', function (Blueprint $table) {
            $table->dropIndex(['target_record_module_id', 'target_record_field_id']);
        });

        DB::statement('ALTER TABLE workflow_contents DROP CONSTRAINT '.self::TARGET_FOREIGN);

        Schema::table('workflow_contents', function (Blueprint $table) {
            $table->dropIndex(['record_module_id', 'target_record_field_id']);
            $table->dropForeign(['record_module_id', 'target_record_field_id']);
            $table->dropUnique(['record_module_id']);
            $table->dropColumn(['target_record_field_id', 'target_record_module_id']);
        });
    }
};
