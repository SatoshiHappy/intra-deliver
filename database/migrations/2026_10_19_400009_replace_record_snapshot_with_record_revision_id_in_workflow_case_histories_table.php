<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 履歴の申請・再申請の行の値の写し record_snapshot を、更新履歴の版の参照 record_revision_id に付け替える(docs の
 * 設計/業務データの入れ物/レコードの共通機能/更新履歴.md の 4.2 節)。申請・再申請の行がある DB では検査制約の追加で失敗する
 * (写しの値は移さない = 設計/業務データの入れ物/レコードの共通機能/データモデル/README.md の 5 節。開発用の DB は作り直す)。
 */
return new class extends Migration
{
    /** 消す検査制約([名前, 式]。down で戻す) */
    private const SNAPSHOT_CHECKS = [
        ['workflow_case_histories_record_snapshot_check', "jsonb_typeof(record_snapshot) = 'object'"],
        [
            'workflow_case_histories_action_record_snapshot_check',
            "(action IN ('apply', 'reapply')) = (record_snapshot IS NOT NULL)",
        ],
    ];

    /** 申請・再申請の行はいつも版を指す */
    private const REVISION_CHECK = 'workflow_case_histories_action_record_revision_id_check';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 消す制約は在ることが前提なので IF EXISTS を付けない(名前の食い違いを黙って通さない)
        foreach (self::SNAPSHOT_CHECKS as [$name]) {
            DB::statement("ALTER TABLE workflow_case_histories DROP CONSTRAINT {$name}");
        }

        Schema::table('workflow_case_histories', function (Blueprint $table) {
            $table->dropColumn('record_snapshot');
        });

        Schema::table('workflow_case_histories', function (Blueprint $table) {
            $table->foreignId('record_revision_id')->nullable()->index()
                ->constrained('record_revisions')->restrictOnDelete();
        });

        DB::statement('ALTER TABLE workflow_case_histories ADD CONSTRAINT '.self::REVISION_CHECK
            ." CHECK (action NOT IN ('apply', 'reapply') OR record_revision_id IS NOT NULL)");
    }

    /**
     * Reverse the migrations(版を指す行があれば、前の形の写しを作れないので何も変えずに止める)。
     */
    public function down(): void
    {
        if (DB::table('workflow_case_histories')->whereNotNull('record_revision_id')->exists()) {
            throw new RuntimeException('workflow_case_histories に版を指す行があるので down を止めた');
        }

        DB::statement('ALTER TABLE workflow_case_histories DROP CONSTRAINT '.self::REVISION_CHECK);

        Schema::table('workflow_case_histories', function (Blueprint $table) {
            $table->dropForeign(['record_revision_id']);
        });

        Schema::table('workflow_case_histories', function (Blueprint $table) {
            $table->dropColumn('record_revision_id');
            $table->jsonb('record_snapshot')->nullable();
        });

        foreach (self::SNAPSHOT_CHECKS as [$name, $expression]) {
            DB::statement("ALTER TABLE workflow_case_histories ADD CONSTRAINT {$name} CHECK ({$expression})");
        }
    }
};
