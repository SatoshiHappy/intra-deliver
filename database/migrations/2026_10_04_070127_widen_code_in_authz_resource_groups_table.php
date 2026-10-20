<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 認可のリソースグループ ID を 255 文字に広げる(docs の 設計/共通マスタ/ロールと認可/リソース.md の authz_resource_groups: ほかの事柄の ID に接頭辞を付けて作るので、
 * 本家のリソースタイプ ID の上限と同じにする)。255 文字を超える ID は列の型が止める。
 *
 * 一意制約と空文字の検査制約は型を変えても残る。down は元の型(100 文字)に戻す。100 文字を超える ID の行が残っていれば、
 * 型を戻すところで失敗する(行を黙って消さない)。
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('authz_resource_groups', function (Blueprint $table) {
            $table->string('code', 255)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('authz_resource_groups', function (Blueprint $table) {
            $table->string('code', 100)->change();
        });
    }
};
