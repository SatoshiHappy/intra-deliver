<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 認可の対象者の条件に、外部キーで指さない種類(IPv4 アドレス・認証状態・期間)を足す(docs の 設計/共通マスタ/ロールと認可/対象者.md)。
 *
 * 「1 つの相手だけ」の検査制約は、置き換え前と後で名前を 63 バイトに切り詰めると同じになるので、古い制約を消してから作る。
 * down は元の列と検査制約に戻す。新しい種類の条件の行が残っていれば、古い検査制約を作り直すところで失敗する(行を黙って消さない)。
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('authz_subject_conditions', function (Blueprint $table) {
            $table->string('ipv4_pattern', 39)->nullable();
            $table->string('meta_subject', 20)->nullable();
            $table->date('term_start_date')->nullable();
            $table->date('term_end_date')->nullable();
        });

        DB::statement('ALTER TABLE authz_subject_conditions DROP CONSTRAINT authz_subject_conditions_user_id_department_id_company_post_id_role_id_check');
        DB::statement('ALTER TABLE authz_subject_conditions ADD CONSTRAINT authz_subject_conditions_user_id_department_id_company_post_id_role_id_ipv4_pattern_meta_subject_term_start_date_check CHECK (num_nonnulls(user_id, department_id, company_post_id, role_id, ipv4_pattern, meta_subject, term_start_date) = 1)');
        DB::statement(<<<'SQL'
            ALTER TABLE authz_subject_conditions ADD CONSTRAINT authz_subject_conditions_ipv4_pattern_check CHECK (ipv4_pattern ~ '^(25[0-5]|2[0-4][0-9]|1[0-9][0-9]|[1-9]?[0-9]|\*|\[(25[0-5]|2[0-4][0-9]|1[0-9][0-9]|[1-9]?[0-9])-(25[0-5]|2[0-4][0-9]|1[0-9][0-9]|[1-9]?[0-9])\])(\.(25[0-5]|2[0-4][0-9]|1[0-9][0-9]|[1-9]?[0-9]|\*|\[(25[0-5]|2[0-4][0-9]|1[0-9][0-9]|[1-9]?[0-9])-(25[0-5]|2[0-4][0-9]|1[0-9][0-9]|[1-9]?[0-9])\])){3}$')
            SQL);
        DB::statement("ALTER TABLE authz_subject_conditions ADD CONSTRAINT authz_subject_conditions_meta_subject_check CHECK (meta_subject IN ('anonymous', 'authenticated'))");
        DB::statement('ALTER TABLE authz_subject_conditions ADD CONSTRAINT authz_subject_conditions_term_start_date_term_end_date_check CHECK ((term_start_date IS NULL AND term_end_date IS NULL) OR (term_start_date IS NOT NULL AND term_end_date IS NOT NULL AND isfinite(term_start_date) AND isfinite(term_end_date) AND term_start_date < term_end_date))');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE authz_subject_conditions DROP CONSTRAINT authz_subject_conditions_user_id_department_id_company_post_id_role_id_ipv4_pattern_meta_subject_term_start_date_check');

        Schema::table('authz_subject_conditions', function (Blueprint $table) {
            $table->dropColumn(['ipv4_pattern', 'meta_subject', 'term_start_date', 'term_end_date']);
        });

        DB::statement('ALTER TABLE authz_subject_conditions ADD CONSTRAINT authz_subject_conditions_user_id_department_id_company_post_id_role_id_check CHECK (num_nonnulls(user_id, department_id, company_post_id, role_id) = 1)');
    }
};
