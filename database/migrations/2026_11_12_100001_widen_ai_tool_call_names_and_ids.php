<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 道具の行の道具の名前と呼び出しの id の長さの上限を外し、包みの中の例外の判定を足す(docs の 設計/AI/実行の記録.md 2.4: 道具の名前はモデルが返したまま
 * 長さの上限を置かず空も残す(空の名前は無い道具)。呼び出しの id は長さの上限を置かず、一意は長さによらず効く形にする。判定に包みの中の例外)。
 * 一意は id の要約の値で張る(索引の 1 行の大きさの上限を超える長さの id も書けるように。索引の式に使えるのは不変の関数だけで、文字列を
 * バイト列にする convert_to は不変でないので、文字列を受ける md5 を使う)。前の制約の名前は保つ。前の版の行はそのまま通る(前方互換)。
 */
return new class extends Migration
{
    /** 一意の制約(索引)の名前(前の版の名前を保つ) */
    private const CALL_ID_UNIQUE = 'ai_tool_calls_ai_run_id_call_id_unique';

    /** 包みの結果(無い道具・形の誤り・道具の検証の誤り・包みの中の例外) */
    private const WRAPPER_DECISIONS = "'unknown_tool', 'invalid_input', 'validation_error', 'wrapper_exception'";

    /** 前の版の包みの結果 */
    private const WRAPPER_DECISIONS_BEFORE = "'unknown_tool', 'invalid_input', 'validation_error'";

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE ai_tool_calls DROP CONSTRAINT ai_tool_calls_tool_name_check');
        DB::statement('ALTER TABLE ai_tool_calls DROP CONSTRAINT '.self::CALL_ID_UNIQUE);
        DB::statement('ALTER TABLE ai_tool_calls ALTER COLUMN tool_name TYPE text, ALTER COLUMN call_id TYPE text');
        DB::statement('CREATE UNIQUE INDEX '.self::CALL_ID_UNIQUE
            .' ON ai_tool_calls (ai_run_id, md5(call_id))');
        self::replaceDecisionChecks(self::WRAPPER_DECISIONS);
    }

    /**
     * Reverse the migrations。前の版の形に入らない行(包みの中の例外・空か 64 字を超える名前・255 字を超える id)があると戻せない。
     */
    public function down(): void
    {
        $unfit = DB::table('ai_tool_calls')->where('decision', 'wrapper_exception')
            ->orWhereRaw("tool_name = '' OR length(tool_name) > 64 OR length(call_id) > 255")->exists();

        if ($unfit) {
            throw new RuntimeException('前の版の形に入らない道具の行があるので戻せない');
        }

        self::replaceDecisionChecks(self::WRAPPER_DECISIONS_BEFORE);
        DB::statement('DROP INDEX '.self::CALL_ID_UNIQUE);
        DB::statement('ALTER TABLE ai_tool_calls ALTER COLUMN tool_name TYPE varchar(64),'
            .' ALTER COLUMN call_id TYPE varchar(255)');
        DB::statement('ALTER TABLE ai_tool_calls ADD CONSTRAINT '.self::CALL_ID_UNIQUE.' UNIQUE (ai_run_id, call_id)');
        DB::statement("ALTER TABLE ai_tool_calls ADD CONSTRAINT ai_tool_calls_tool_name_check CHECK (tool_name <> '')");
    }

    /** 判定の値の一覧の制約と、判定と答えの組ごとに許す状態の制約を、包みの結果の一覧で張り直す(名前を保つ) */
    private static function replaceDecisionChecks(string $wrapperDecisions): void
    {
        $checks = [
            'ai_tool_calls_decision_check' => "decision IN ({$wrapperDecisions}, 'allow', 'deny', 'ask')",
            'ai_tool_calls_status_of_decision_answer_check' => self::allowedStatesCheck($wrapperDecisions),
        ];

        foreach ($checks as $name => $check) {
            DB::statement("ALTER TABLE ai_tool_calls DROP CONSTRAINT {$name}");
            DB::statement("ALTER TABLE ai_tool_calls ADD CONSTRAINT {$name} CHECK ({$check})");
        }
    }

    /** 判定と答えの組ごとに許す状態の式(2.4 の T1〜T9。前の版と同じ組み立て) */
    private static function allowedStatesCheck(string $wrapperDecisions): string
    {
        $rows = [
            [null, null, "'cancelled'"],
            [$wrapperDecisions, null, "'tool_error'"],
            ["'deny'", null, "'rejected'"],
            ["'allow'", null, "'done', 'tool_error', 'running', 'unknown'"],
            ["'ask'", null, "'awaiting_confirmation', 'cancelled'"],
            ["'ask'", "'approve'", "'running', 'tool_error', 'rejected', 'done', 'cancelled', 'unknown'"],
            ["'ask'", "'reject', 'next_text'", "'rejected'"],
        ];
        $in = static fn (string $column, ?string $values): string => $values === null
            ? "coalesce({$column}, '') = ''"
            : "coalesce({$column}, '') IN ({$values})";

        return implode(' OR ', array_map(
            static fn (array $row): string => '('.$in('decision', $row[0]).' AND '.$in('answer', $row[1])
                ." AND status IN ({$row[2]}))",
            $rows,
        ));
    }
};
