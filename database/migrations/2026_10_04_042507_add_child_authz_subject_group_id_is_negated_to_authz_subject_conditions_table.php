<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 認可の対象者の条件に、子の対象者(式の入れ子)と除外を足す(docs の 設計/共通マスタ/ロールと認可/対象者.md の authz_subject_conditions)。
 *
 * 「1 つの相手だけ」の検査制約は、置き換え前と後で名前を 63 バイトに切り詰めると同じになるので、古い制約を消してから作る。
 * down は元の列と検査制約に戻す。子の対象者だけを指す条件の行が残っていれば、古い検査制約を作り直すところで失敗する。
 * 除外の条件の行が残っていれば、列を消す前に失敗させる(消すと除外が外れて、条件が逆の意味になる)。どちらも行を黙って消さない・変えない。
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('authz_subject_conditions', function (Blueprint $table) {
            $table->foreignId('child_authz_subject_group_id')->nullable()->index()->constrained('authz_subject_groups')->restrictOnDelete();
            $table->boolean('is_negated')->default(false);
        });

        DB::statement('ALTER TABLE authz_subject_conditions DROP CONSTRAINT authz_subject_conditions_user_id_department_id_company_post_id_role_id_ipv4_pattern_meta_subject_term_start_date_check');
        DB::statement('ALTER TABLE authz_subject_conditions ADD CONSTRAINT authz_subject_conditions_user_id_department_id_company_post_id_role_id_ipv4_pattern_meta_subject_term_start_date_child_authz_subject_group_id_check CHECK (num_nonnulls(user_id, department_id, company_post_id, role_id, ipv4_pattern, meta_subject, term_start_date, child_authz_subject_group_id) = 1)');
        DB::statement('ALTER TABLE authz_subject_conditions ADD CONSTRAINT authz_subject_conditions_child_authz_subject_group_id_authz_subject_group_id_check CHECK (child_authz_subject_group_id <> authz_subject_group_id)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('authz_subject_conditions')->where('is_negated', true)->exists()) {
            throw new RuntimeException('除外の条件の行が残っているので戻せない(is_negated の列を消すと、除外が外れて条件が逆の意味になる)');
        }

        DB::statement('ALTER TABLE authz_subject_conditions DROP CONSTRAINT authz_subject_conditions_child_authz_subject_group_id_authz_subject_group_id_check');
        DB::statement('ALTER TABLE authz_subject_conditions DROP CONSTRAINT authz_subject_conditions_user_id_department_id_company_post_id_role_id_ipv4_pattern_meta_subject_term_start_date_child_authz_subject_group_id_check');

        Schema::table('authz_subject_conditions', function (Blueprint $table) {
            $table->dropForeign(['child_authz_subject_group_id']);
            $table->dropColumn(['child_authz_subject_group_id', 'is_negated']);
        });

        DB::statement('ALTER TABLE authz_subject_conditions ADD CONSTRAINT authz_subject_conditions_user_id_department_id_company_post_id_role_id_ipv4_pattern_meta_subject_term_start_date_check CHECK (num_nonnulls(user_id, department_id, company_post_id, role_id, ipv4_pattern, meta_subject, term_start_date) = 1)');
    }
};
