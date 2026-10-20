<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 認可のリソースグループの表示名の列を、本家の列名(imaz_resource_group_i の display_name)に改名する
 * (docs の 設計/共通マスタ/ロールと認可/リソース.md の authz_resource_groups)。
 *
 * PostgreSQL は列を改名しても NOT NULL の制約の名前(<表>_name_not_null)を変えないので、共通の規則 9 節の形 <表>_<列>_<種類> に揃えて改名する。
 * NOT NULL が名前つきの制約として pg_constraint に載るのは PostgreSQL 18 からなので、制約があるときだけ改名する。
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('authz_resource_group_translations', function (Blueprint $table) {
            $table->renameColumn('name', 'display_name');
        });

        $this->renameNotNullConstraint('authz_resource_group_translations_name_not_null', 'authz_resource_group_translations_display_name_not_null');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $this->renameNotNullConstraint('authz_resource_group_translations_display_name_not_null', 'authz_resource_group_translations_name_not_null');

        Schema::table('authz_resource_group_translations', function (Blueprint $table) {
            $table->renameColumn('display_name', 'name');
        });
    }

    private function renameNotNullConstraint(string $from, string $to): void
    {
        $exists = DB::table('pg_constraint')
            ->whereRaw("conrelid = 'authz_resource_group_translations'::regclass")
            ->where('conname', $from)
            ->exists();

        if ($exists) {
            DB::statement("ALTER TABLE authz_resource_group_translations RENAME CONSTRAINT {$from} TO {$to}");
        }
    }
};
