<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 会話の型の道具の呼び出しの行と、期限で消す値の表(docs の 設計/AI/実行の記録.md 2.4、消す対象 = 設計/AI/データと失敗と検査.md 6 節)。
 * 複合の外部キーは、子どうしと答えを書いた実行を同じ実行の鎖に縛るため(ADR 0040 の決定 14)。ステップの中で呼んだ道具の行の外部キーは、表を作る順のためここで足す。
 */
return new class extends Migration
{
    /** 包みの結果(無い道具・形の誤り・道具の検証の誤り) */
    private const WRAPPER_DECISIONS = "'unknown_tool', 'invalid_input', 'validation_error'";

    /**
     * 判定と答えの組ごとに許す状態(判定・答えの null は無し。2.4 の T1〜T9。答えを書くのは問うの呼び出しだけ)。
     *
     * @var list<array{string|null, string|null, string}>
     */
    private const ALLOWED_STATES = [
        [null, null, "'cancelled'"],
        [self::WRAPPER_DECISIONS, null, "'tool_error'"],
        ["'deny'", null, "'rejected'"],
        ["'allow'", null, "'done', 'tool_error', 'running', 'unknown'"],
        ["'ask'", null, "'awaiting_confirmation', 'cancelled'"],
        ["'ask'", "'approve'", "'running', 'tool_error', 'rejected', 'done', 'cancelled', 'unknown'"],
        ["'ask'", "'reject', 'next_text'", "'rejected'"],
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        self::createToolCallsTable();
        self::createToolCallTextsTable();

        Schema::table('ai_run_steps', function (Blueprint $table) {
            // 同じ実行の中の参照は文の終わりで確かめる(実行の CASCADE が同じ文で両方を消す)
            $table->foreign(['ai_run_id', 'ai_tool_call_id'])->references(['ai_run_id', 'id'])->on('ai_tool_calls')
                ->noActionOnDelete();
        });
    }

    /** 道具の行の表と検査制約 */
    private static function createToolCallsTable(): void
    {
        Schema::create('ai_tool_calls', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('ai_run_id');
            $table->unsignedBigInteger('ai_run_step_id');
            $table->integer('position');
            $table->string('call_id', 255);
            $table->string('tool_name', 64);
            $table->string('decision', 32)->nullable();
            $table->string('decision_reason', 64)->nullable();
            $table->string('answer', 16)->nullable();
            $table->unsignedBigInteger('answer_run_id')->nullable();
            $table->string('status', 32);
            $table->jsonb('records');
            $table->integer('confirmation_version')->nullable();
            $table->timestampTz('created_at');
            $table->timestampTz('finished_at')->nullable();

            $table->unique(['ai_run_id', 'id']);
            $table->unique(['ai_run_id', 'call_id']);
            $table->unique(['ai_run_step_id', 'position']);
            $table->foreign(['ai_run_id', 'ai_run_step_id'])->references(['ai_run_id', 'id'])->on('ai_run_steps')
                ->cascadeOnDelete();
            $table->foreign(['ai_run_id', 'answer_run_id'])->references(['previous_run_id', 'id'])->on('ai_runs')
                ->restrictOnDelete();
        });

        foreach (self::toolCallChecks() as $name => $check) {
            DB::statement("ALTER TABLE ai_tool_calls ADD CONSTRAINT {$name} CHECK ({$check})");
        }
    }

    /** 道具の行の期限で消す値の表 */
    private static function createToolCallTextsTable(): void
    {
        Schema::create('ai_tool_call_texts', function (Blueprint $table) {
            $table->foreignId('ai_tool_call_id')->primary()->constrained()->cascadeOnDelete();
            $table->jsonb('arguments');
            $table->text('result_text')->nullable();
            $table->jsonb('confirmation')->nullable();
            $table->jsonb('record_names');
            $table->timestampTz('created_at');

            $table->index('created_at');
        });

        // 元の引数は形の確かめに落ちた object でない値も残すので、型の制約を置かない(docs の 設計/AI/エージェントの実行/道具の包みと止める.md 9.4)
        DB::statement('ALTER TABLE ai_tool_call_texts ADD CONSTRAINT ai_tool_call_texts_confirmation_check'
            ." CHECK (jsonb_typeof(confirmation) = 'object')");
        DB::statement('ALTER TABLE ai_tool_call_texts ADD CONSTRAINT ai_tool_call_texts_record_names_check'
            ." CHECK (jsonb_typeof(record_names) = 'array')");
    }

    /**
     * Reverse the migrations。道具の行があると戻せない(原文の行は道具の行に縛られる。表を消すと、会話の型の実行の記録を消すことになる)。
     */
    public function down(): void
    {
        if (DB::table('ai_tool_calls')->exists()) {
            throw new RuntimeException('道具の呼び出しの行が残っているので戻せない(表を消すと、会話の型の実行の記録を消すことになる)');
        }

        Schema::table('ai_run_steps', function (Blueprint $table) {
            $table->dropForeign(['ai_run_id', 'ai_tool_call_id']);
        });
        Schema::drop('ai_tool_call_texts');
        Schema::drop('ai_tool_calls');
    }

    /**
     * 道具の行の検査制約(名前 → 式)。判定と答えと状態の組の制約は、値の一覧の制約より名前の順で後に確かめられる名前にする。
     *
     * @return array<string, string>
     */
    private static function toolCallChecks(): array
    {
        $ask = "decision IS NOT DISTINCT FROM 'ask'";

        return [
            'ai_tool_calls_position_check' => 'position >= 1',
            'ai_tool_calls_call_id_check' => "call_id <> ''",
            'ai_tool_calls_tool_name_check' => "tool_name <> ''",
            'ai_tool_calls_decision_check' => 'decision IN ('.self::WRAPPER_DECISIONS.", 'allow', 'deny', 'ask')",
            'ai_tool_calls_decision_reason_check' => "(decision IS NOT DISTINCT FROM 'deny')"
                ." = (decision_reason IS NOT NULL) AND decision_reason <> ''",
            'ai_tool_calls_answer_check' => "answer IN ('approve', 'reject', 'next_text')",
            'ai_tool_calls_answer_run_id_check' => '(answer IS NULL) = (answer_run_id IS NULL)',
            'ai_tool_calls_status_check' => "status IN ('running', 'awaiting_confirmation', 'done', 'rejected',"
                ." 'tool_error', 'cancelled', 'unknown')",
            'ai_tool_calls_status_finished_at_check' => "(status IN ('running', 'awaiting_confirmation'))"
                .' = (finished_at IS NULL)',
            'ai_tool_calls_records_check' => "jsonb_typeof(records) = 'array'",
            'ai_tool_calls_confirmation_version_check' => "confirmation_version IS NULL OR {$ask}",
            'ai_tool_calls_status_of_decision_answer_check' => self::allowedStatesCheck(),
        ];
    }

    /** 判定と答えの組ごとに許す状態の式(どの組にも当たらない行を断る。null を空文字に寄せて、比べが null にならないようにする) */
    private static function allowedStatesCheck(): string
    {
        $in = static fn (string $column, ?string $values): string => $values === null
            ? "coalesce({$column}, '') = ''"
            : "coalesce({$column}, '') IN ({$values})";

        return implode(' OR ', array_map(
            static fn (array $row): string => '('.$in('decision', $row[0]).' AND '.$in('answer', $row[1])
                ." AND status IN ({$row[2]}))",
            self::ALLOWED_STATES,
        ));
    }
};
