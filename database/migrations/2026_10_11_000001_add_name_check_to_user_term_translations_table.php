<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ユーザーのロケール別の行の氏名 name に、空文字を拒む検査制約を足す
 * (docs の 設計/共通マスタ/組織とユーザー.md 7 節 user_term_translations)。
 *
 * 氏名が空文字の行が残っていれば、up は検査制約を作るところで失敗する。
 * down は検査制約を消すだけで、行には触らない。
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement("ALTER TABLE user_term_translations ADD CONSTRAINT user_term_translations_name_check CHECK (name <> '')");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE user_term_translations DROP CONSTRAINT user_term_translations_name_check');
    }
};
