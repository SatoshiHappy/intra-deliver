<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 認可のリソースのリソースタイプを本家の上限(255 文字)に広げ、リソース URI がリソースタイプとコロンで始まる検査制約を足す
 * (docs の 設計/共通マスタ/ロールと認可/リソース.md の authz_resources)。
 *
 * URI がタイプで始まらない行が残っていれば、up は検査制約を作るところで失敗する。
 * down は検査制約を消して元の型(100 文字)に戻す。100 文字を超えるタイプの行が残っていれば、型を戻すところで失敗する(行を黙って消さない)。
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('authz_resources', function (Blueprint $table) {
            $table->string('resource_type', 255)->change();
        });

        DB::statement("ALTER TABLE authz_resources ADD CONSTRAINT authz_resources_uri_resource_type_check CHECK (starts_with(uri, resource_type || ':'))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE authz_resources DROP CONSTRAINT authz_resources_uri_resource_type_check');

        Schema::table('authz_resources', function (Blueprint $table) {
            $table->string('resource_type', 100)->change();
        });
    }
};
