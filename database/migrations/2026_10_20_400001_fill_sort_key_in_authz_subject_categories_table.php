<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 既にある対象者のカテゴリの行の並び順を、カテゴリを作る業務処理と同じカテゴリの並びの表で埋める(docs の 設計/ワークフロー/申請の周辺/認可設定の編集.md 5.2。
 * 認証 → ロール → ユーザー → 組織 → 役職、そのほかの 1 種と 2 種以上はその後)。マイグレーションはアプリのコードに依存できないので、表の値をここにも持つ。
 * down は、この前の業務処理がすべての行を並び順 0 で作っていたので 0 に戻す。
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement(<<<'SQL'
            UPDATE authz_subject_categories SET sort_key = CASE code
                WHEN 'im_authz_meta_subject' THEN 1
                WHEN 'b_m_role' THEN 2
                WHEN 'imm_user' THEN 3
                WHEN 'imm_department' THEN 4
                WHEN 'imm_company_post' THEN 5
                ELSE 6
            END
            SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('authz_subject_categories')->update(['sort_key' => 0]);
    }
};
