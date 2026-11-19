<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 道具の行に書く時の断りの理由を足す(docs の 設計/AI/実行の記録.md 2.4・設計/AI/道具と権限/まとめた実行と送るデータ.md 10.5 の表 2)。
 * 判定の断るの理由と別のデシジョン表の結果なので、別の列に持つ(持てる行の規則 = 2.4)。前の版の行は値が無く、実行の答えで断られた行が
 * まだ無い(書く時の部品より前)ので、そのまま通る(前方互換)。
 */
return new class extends Migration
{
    /** 断られたになる理由(P1〜P7) */
    private const REJECTED = "'prior_not_written', 'unavailable', 'no_permission', 'not_found', 'held', 'stale',"
        ." 'validation'";

    /** 道具の誤りになる理由(P9) */
    private const TOOL_ERRORS = "'invalid_shape', 'invalid_input'";

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('ai_tool_calls', function (Blueprint $table) {
            $table->string('write_denial_reason', 32)->nullable();
        });

        [$rejected, $toolErrors] = [self::REJECTED, self::TOOL_ERRORS];
        $written = "decision = 'ask' AND answer = 'approve'";
        $checks = [
            'ai_tool_calls_write_denial_reason_check' => "write_denial_reason IS NULL OR ({$written}"
                ." AND (status = 'rejected' AND write_denial_reason IN ({$rejected})"
                ." OR status = 'tool_error' AND write_denial_reason IN ({$toolErrors})))",
            'ai_tool_calls_write_denial_reason_required_check' => "NOT ({$written} AND status = 'rejected')"
                .' OR write_denial_reason IS NOT NULL',
        ];

        foreach ($checks as $name => $check) {
            DB::statement("ALTER TABLE ai_tool_calls ADD CONSTRAINT {$name} CHECK ({$check})");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ai_tool_calls', function (Blueprint $table) {
            $table->dropColumn('write_denial_reason');
        });
    }
};
