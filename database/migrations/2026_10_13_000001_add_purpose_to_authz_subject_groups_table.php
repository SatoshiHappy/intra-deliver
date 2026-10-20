<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 認可の対象者に用途 purpose を足す(docs の 設計/共通マスタ/ロールと認可/対象者.md の authz_subject_groups の purpose)。
 * policy = 認可のポリシーが指す対象者、selection = ほかのモジュールの指定が指す専用の対象者。既存の行は既定の policy になる。
 * UNIQUE (id, purpose) は、指す側の表の複合外部キー (authz_subject_group_id, purpose) の参照先。
 *
 * down は、この機能で入った用途 selection の対象者(条件・表示名は外部キーの cascade で一緒に消える)を消してから、検査制約・一意制約・列を消す
 * (列だけを消すと selection の行が policy の行と見分けられなくなり、ポリシーが指せるようになる)。selection を指すワークフローの表は、
 * 後のマイグレーションなので先に rollback されている。
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('authz_subject_groups', function (Blueprint $table) {
            $table->string('purpose', 20)->default('policy');
            $table->unique(['id', 'purpose'], 'authz_subject_groups_id_purpose_unique');
        });

        DB::statement("ALTER TABLE authz_subject_groups ADD CONSTRAINT authz_subject_groups_purpose_check CHECK (purpose IN ('policy', 'selection'))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('authz_subject_groups')->where('purpose', 'selection')->delete();

        DB::statement('ALTER TABLE authz_subject_groups DROP CONSTRAINT authz_subject_groups_purpose_check');

        Schema::table('authz_subject_groups', function (Blueprint $table) {
            $table->dropUnique('authz_subject_groups_id_purpose_unique');
            $table->dropColumn('purpose');
        });
    }
};
