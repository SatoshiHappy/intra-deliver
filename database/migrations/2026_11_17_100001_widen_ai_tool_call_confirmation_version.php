<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 道具の行の確認の版を文字列に広げる(docs の 設計/AI/実行の記録.md 2.4: 版は入れ物の同時の編集の確かめの値をそのまま残す。長さの上限を置かず、
 * 空も残し、日時の型にしない = 入れ物の版の形に依らない)。問うの行だけが値を持てる制約は変えない。前の版の整数の値は 10 進の文字列になり、
 * 前の版の行はそのまま通る(前方互換)。
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE ai_tool_calls ALTER COLUMN confirmation_version TYPE text'
            .' USING confirmation_version::text');
    }

    /**
     * Reverse the migrations。整数(int の範囲)に読めない版(入れ物の版の文字列)の行があると戻せない。読めるかは、戻す時の
     * `::integer` と同じ読み込みで見る(PostgreSQL の pg_input_is_valid)。
     */
    public function down(): void
    {
        $unfit = DB::table('ai_tool_calls')->whereNotNull('confirmation_version')
            ->whereRaw("NOT pg_input_is_valid(confirmation_version, 'integer')")
            ->exists();

        if ($unfit) {
            throw new RuntimeException('整数に読めない確認の版の道具の行があるので戻せない');
        }

        DB::statement('ALTER TABLE ai_tool_calls ALTER COLUMN confirmation_version TYPE integer'
            .' USING confirmation_version::integer');
    }
};
