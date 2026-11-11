<?php

namespace Tests\Feature\Ai;

use App\Actions\Ai\Api\ConversationFailed;
use App\Actions\Ai\FailRun;
use App\Enums\Ai\AiRunErrorCode;
use App\Enums\Ai\AiRunStatus;
use App\Enums\Ai\AiRunStopReason;
use App\Enums\Ai\AiToolCallAnswer;
use App\Enums\Ai\AiToolCallStatus;
use App\Jobs\Ai\RunPromptJob;
use App\Models\Ai\AiRun;
use App\Models\Ai\AiRunStep;
use App\Models\Ai\AiSpendingCarryover;
use App\Models\Ai\AiToolCall;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\TransactionalTestCase;

/**
 * 失敗で終わる会話の型の実行の後始末を、後始末の表の各行(docs の 設計/AI/エージェントの実行/途中の表示と上限.md 9.8 の「失敗で終わるときの
 * 後始末」と 9.11 の「終わった実行に処理中のステップの行が残る」)と失敗の経路(設計/AI/実行と費用/README.md 4 節の「完了と失敗の書き方」の
 * F1・F3・F5)で通す。表のどの行にも当たる子の行を置くため、実行 R は確認待ちで終わった前の実行 P に答える処理中の実行にし、ほかの実行 Q の
 * 行も置く。答えの実行かどうかの場合分けと作りの誤りは {@see ConversationAnswerFailureCleanUpTest}。
 */
final class ConversationFailureCleanUpTest extends TransactionalTestCase
{
    use BuildsFailingConversations;
    use UsesTestConversationKinds;

    /** 後始末の後の状態(行の名前 → 状態。名前の始めは属する実行) */
    private const CLEANED_UP = [
        'R の処理中のステップ' => 'failed',
        'R の成功したステップ' => 'succeeded',
        'R の失敗したステップ' => 'failed',
        'R の処理中の道具' => 'unknown',
        'R の確認待ちの道具' => 'cancelled',
        'R の済んだ道具' => 'done',
        'R の断られた道具' => 'rejected',
        'P の R が書いている道具' => 'unknown',
        'P の R が書いた道具' => 'done',
        'P の確認待ちの道具' => 'cancelled',
        'Q の処理中のステップ' => 'running',
        'Q の処理中の道具' => 'running',
        'Q の確認待ちの道具' => 'awaiting_confirmation',
    ];

    /** 後始末が移す行(終わりの時刻を実行の終わりの時刻にする) */
    private const MOVED = [
        'R の処理中のステップ', 'R の処理中の道具', 'R の確認待ちの道具', 'P の R が書いている道具', 'P の確認待ちの道具',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->registerTestConversationKind();
    }

    /**
     * @return array<string, array{Closure(int): void, AiRunErrorCode}>
     */
    public static function failurePaths(): array
    {
        return [
            '失敗の経路(受け手が正常 = F1)' => [
                fn (int $runId) => app(FailRun::class)
                    ->execute($runId, AiRunErrorCode::Provider, new RuntimeException('誤り')),
                AiRunErrorCode::Provider,
            ],
            'ジョブの失敗の扱い(worker が落ちた)' => [
                fn (int $runId) => (new RunPromptJob($runId, 100))->failed(new RuntimeException('worker が落ちた')),
                AiRunErrorCode::WorkerLost,
            ],
        ];
    }

    /**
     * 後始末の表の各行(移した行の終わりの時刻は実行の終わりの時刻、変えない行の終わりの時刻はそのまま)と、表示用の和を失敗と一緒に書くこと。
     *
     * @param  Closure(int): void  $fail
     */
    #[DataProvider('failurePaths')]
    public function test_a_failure_moves_the_open_child_rows_as_the_table_says(
        Closure $fail,
        AiRunErrorCode $code,
    ): void {
        [$runId, $rows] = $this->rows();
        $before = $this->finishedAts($rows);

        $fail($runId);

        $run = AiRun::query()->findOrFail($runId);
        $this->assertSame([AiRunStatus::Failed, $code], [$run->status, $run->error_code]);
        $finishedAt = DB::table('ai_runs')->where('id', $runId)->value('finished_at');
        $moved = array_fill_keys(self::MOVED, $finishedAt);
        $this->assertSame([...$before, ...$moved], $this->finishedAts($rows));
        $this->assertSame(self::CLEANED_UP, $this->statuses($rows));
        $step = AiRunStep::query()->findOrFail($rows['R の処理中のステップ']->id);
        $this->assertSame([$code, null, '0.010000'], [$step->error_code, $step->cost_usd, $step->reserved_cost_usd]);
        $this->assertSame(['0.030000', '0.001000'], [$run->step_reserved_sum_usd, $run->step_cost_sum_usd]);
        $this->assertSame(10, $run->step_usage_sum['input_tokens'] ?? null);
    }

    /** 受け手が投げても(F3)、後始末は受け手の書き込みと一緒に巻き戻らず、処理中・確認待ちの行が残らない */
    public function test_the_clean_up_is_not_rolled_back_with_a_receiver_that_throws(): void
    {
        Exceptions::fake();
        [$runId, $rows] = $this->rows();
        $this->conversationKind->onFailed = function (ConversationFailed $run): void {
            $carried = ['budget' => 'default', 'spent_on' => '2026-10-18', 'cost_usd' => '1'];
            AiSpendingCarryover::query()->insert($carried);

            throw new RuntimeException("受け手の誤り {$run->runId}");
        };

        app(FailRun::class)->execute($runId, AiRunErrorCode::Timeout, new RuntimeException('時間切れ'));

        $this->assertSame(AiRunStatus::Failed, AiRun::query()->findOrFail($runId)->status);
        $this->assertSame(self::CLEANED_UP, $this->statuses($rows));
        $step = AiRunStep::query()->findOrFail($rows['R の処理中のステップ']->id);
        $this->assertSame(AiRunErrorCode::Timeout, $step->error_code);
        $this->assertSame(0, AiSpendingCarryover::query()->count());
        Exceptions::assertReported(
            fn (RuntimeException $exception): bool => str_starts_with($exception->getMessage(), '受け手の誤り'),
        );
    }

    /**
     * @return array<string, array{array<string, mixed>, Closure(int): void}>
     */
    public static function finishedRunsAndPaths(): array
    {
        $ends = [
            '確認待ち' => ['status' => AiRunStatus::AwaitingConfirmation],
            '止めた' => ['status' => AiRunStatus::Stopped, 'stop_reason' => AiRunStopReason::User],
            '成功' => ['status' => AiRunStatus::Succeeded],
            '失敗' => [
                'status' => AiRunStatus::Failed,
                'error_code' => AiRunErrorCode::Provider,
                'error_message' => '誤り',
            ],
        ];
        $cases = [];

        foreach ($ends as $end => $attributes) {
            foreach (self::failurePaths() as $path => [$fail]) {
                $cases["{$end}・{$path}"] = [$attributes, $fail];
            }
        }

        return $cases;
    }

    /**
     * 終わった実行に失敗の経路が来ても(F5)、子の行は変わらない(処理中・確認待ちの行を置いても)。
     *
     * @param  array<string, mixed>  $attributes  終わり方(開始と終わりの時刻はテストが足す)
     * @param  Closure(int): void  $fail
     */
    #[DataProvider('finishedRunsAndPaths')]
    public function test_a_failure_after_the_run_ended_changes_no_child_rows(array $attributes, Closure $fail): void
    {
        $run = $this->conversationRun([...$attributes, 'started_at' => now(), 'finished_at' => now()]);
        $step = AiRunStep::factory()->succeeded()->create(['ai_run_id' => $run->id]);
        $rows = [
            '処理中のステップ' => AiRunStep::factory()->create(['ai_run_id' => $run->id]),
            '処理中の道具' => AiToolCall::factory()->inStep($step)->allowed(AiToolCallStatus::Running)->create(),
            '確認待ちの道具' => AiToolCall::factory()->inStep($step)->asked()->create(),
        ];
        $before = AiRun::query()->findOrFail($run->id)->getAttributes();

        $fail($run->id);

        $this->assertSame($before, AiRun::query()->findOrFail($run->id)->getAttributes());
        $expected = ['処理中のステップ' => 'running', '処理中の道具' => 'running', '確認待ちの道具' => 'awaiting_confirmation'];
        $this->assertSame($expected, $this->statuses($rows));
    }

    /**
     * 後始末の表の行を全部置いた子の行(R = P に答える処理中の実行、P = 確認待ちで終わった前の実行、Q = ほかの処理中の実行)。
     * 終わっている子の行の終わりの時刻は 1 時間前にずらす(後始末が書く時刻と見分けるため)。
     *
     * @return array{int, array<string, AiRunStep|AiToolCall>} R の id と、名前ごとの子の行
     */
    private function rows(): array
    {
        [$previous, $run] = $this->answeredPair();
        $previousStep = AiRunStep::factory()->succeeded()->create(['ai_run_id' => $previous->id]);
        $step = AiRunStep::factory()->succeeded()->create(['ai_run_id' => $run->id]);
        $calls = AiToolCall::factory()->inStep($step);
        $previousCalls = AiToolCall::factory()->inStep($previousStep);
        $other = $this->conversationRun(['status' => AiRunStatus::Running, 'started_at' => now()]);
        $otherStep = AiRunStep::factory()->create(['ai_run_id' => $other->id]);
        $otherCallsStep = AiRunStep::factory()->succeeded()->create(['ai_run_id' => $other->id]);
        $otherCalls = AiToolCall::factory()->inStep($otherCallsStep);

        $rows = [
            'R の処理中のステップ' => AiRunStep::factory()->create(['ai_run_id' => $run->id]),
            'R の成功したステップ' => $step,
            'R の失敗したステップ' => AiRunStep::factory()->failed()->create(['ai_run_id' => $run->id]),
            'R の処理中の道具' => $calls->allowed(AiToolCallStatus::Running)->create(),
            'R の確認待ちの道具' => $calls->asked()->create(),
            'R の済んだ道具' => $calls->allowed()->create(),
            'R の断られた道具' => $calls->denied()->create(),
            'P の R が書いている道具' => $previousCalls->answered(AiToolCallAnswer::Approve, AiToolCallStatus::Running, $run)
                ->create(),
            'P の R が書いた道具' => $previousCalls->answered(AiToolCallAnswer::Approve, AiToolCallStatus::Done, $run)
                ->create(),
            'P の確認待ちの道具' => $previousCalls->asked()->create(),
            'Q の処理中のステップ' => $otherStep,
            'Q の処理中の道具' => $otherCalls->allowed(AiToolCallStatus::Running)->create(),
            'Q の確認待ちの道具' => $otherCalls->asked()->create(),
        ];

        foreach (['ai_run_steps', 'ai_tool_calls'] as $table) {
            DB::table($table)->whereNotNull('finished_at')->update(['finished_at' => now()->subHour()]);
        }

        return [$run->id, $rows];
    }

    /**
     * 子の行の今の終わりの時刻(DB の値のまま。終わっていなければ null)。
     *
     * @param  array<string, AiRunStep|AiToolCall>  $rows
     * @return array<string, mixed>
     */
    private function finishedAts(array $rows): array
    {
        return array_map(
            fn (AiRunStep|AiToolCall $row): mixed => DB::table($row->getTable())
                ->where('id', $row->getKey())->value('finished_at'),
            $rows,
        );
    }
}
