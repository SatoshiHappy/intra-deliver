<?php

namespace Tests\Feature\Ai\Conversation;

use App\Actions\Ai\Conversation\SendCheck;
use App\Actions\Ai\Conversation\SendCheckInput;
use App\Actions\Ai\Conversation\SendCheckOutcome;
use App\Actions\Ai\Conversation\SendCheckResult;
use App\Actions\Ai\Core\ProviderTraits;
use App\Actions\Ai\Core\SendKind;
use App\Actions\Ai\Core\StopKind;
use App\Enums\Ai\AiConversationPurpose;
use App\Enums\Ai\AiRunStepSendKind;
use App\Enums\Ai\AiRunStepStatus;
use App\Models\Ai\AiRun;
use App\Models\Ai\AiRunStep;
use App\Queries\Ai\AiSettings;
use App\Queries\Ai\Api\RunBudgetQuery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Ai\RecordingConversationKind;
use Tests\Feature\Ai\UsesTestConversationKinds;
use Tests\Support\TransactionalTestCase;

/**
 * 送る前の確かめの関数(表 = docs の 設計/AI/エージェントの実行/README.md 9.2 の C1〜C6、費用の比べ方と見積もり = 設計/AI/実行と費用/接続先と費用.md
 * 5.3。費用は使った額だけで比べ、見積もりは比べに足さずにステップの行に書く)。
 * C7〜C10(ファイルを作る道具の中の呼び出し)は、中核の送信の種別に無いので当たらない。単価 = config/ai_runs.php の pricing(会話 = 入力 2.00・出力 10.00・
 * キャッシュの書き 2.50、題 = 入力 0.10・出力 0.50。100 万トークンあたりのドル)。
 */
final class SendCheckTest extends TransactionalTestCase
{
    use UsesTestConversationKinds;

    /** 1,000 字・係数 1・出力 1,000 の回の答えの見積もり = 1,000 × 2.00 + 1,000 × 10.00 */
    private const TURN_ESTIMATE = '0.012000';

    /** 前もって使った額(別の会話の成功したステップ 1 つ) */
    private const SPENT = '0.001000';

    protected function setUp(): void
    {
        parent::setUp();
        $this->registerTestConversationKind();
        $this->limits(null);
        AiRunStep::factory()->succeeded(self::SPENT)->create();
    }

    /**
     * @return array<string, array{SendKind, bool, bool, float|null, string}>
     */
    public static function rows(): array
    {
        return [
            'C1 止める印はほかに勝つ' => [SendKind::Turn, true, true, 0.0009, 'user'],
            'C2 時間は費用に勝つ' => [SendKind::Turn, false, true, 0.0009, 'time_limit'],
            'C3 費用の上限' => [SendKind::Turn, false, false, 0.0009, 'cost_limit'],
            'C4 送る' => [SendKind::Turn, false, false, null, 'send'],
            'C4 要約も同じ' => [SendKind::Compaction, false, false, null, 'send'],
            'C5 題は費用だけを見る' => [SendKind::Title, true, true, 0.0009, 'not_sent'],
            'C6 題は止める印と時間を見ない' => [SendKind::Title, true, true, null, 'send'],
        ];
    }

    #[DataProvider('rows')]
    public function test_the_table(SendKind $kind, bool $stopped, bool $timedOut, ?float $daily, string $expected): void
    {
        $run = $this->runningRun($stopped);
        $this->limits($daily);

        $outcome = $this->check($run, $kind, timedOut: $timedOut);

        $this->assertSame($expected, self::nameOf($outcome));
        $this->assertSame($expected === 'send' ? 2 : 1, AiRunStep::query()->count());
    }

    /**
     * 使った額の境目(刻み = 100 万分の 1 ドル)。見積もりは比べに足さない。上限以上で止める(5.2 の比べ方の表)。
     *
     * @return array<string, array{float, string}>
     */
    public static function boundaries(): array
    {
        return [
            '使った額が上限より 1 刻み少ない' => [0.001001, 'send'],
            '使った額が上限と同じ' => [0.001, 'cost_limit'],
            '使った額が上限より 1 刻み多い' => [0.000999, 'cost_limit'],
        ];
    }

    #[DataProvider('boundaries')]
    public function test_the_spent_amount_boundary(float $daily, string $expected): void
    {
        $this->limits($daily);

        $this->assertSame($expected, self::nameOf($this->check($this->runningRun(), SendKind::Turn)));
    }

    /** 処理中のステップは数えず、終わって費用の分からないステップ(止めた・使用量の無い失敗)は見積もりで数える */
    public function test_running_steps_are_not_counted_and_ended_unknown_steps_count_by_the_estimate(): void
    {
        AiRunStep::factory()->create(['reserved_cost_usd' => '5.000000']);
        $this->limits(0.003);
        $this->assertSame('send', self::nameOf($this->check($this->runningRun(), SendKind::Turn)));

        AiRunStep::query()->where('status', AiRunStepStatus::Running)->delete();
        AiRunStep::factory()->failed()->create(['reserved_cost_usd' => '0.001000']);
        AiRunStep::factory()->stopped()->create(['reserved_cost_usd' => '0.001001']);
        $this->assertSame('cost_limit', self::nameOf($this->check($this->runningRun(), SendKind::Turn)));
    }

    public function test_the_cost_stop_carries_the_exceeded_period(): void
    {
        $this->limits(0.0009);

        $outcome = $this->check($this->runningRun(), SendKind::Turn);

        $this->assertSame(RunBudgetQuery::DAY, $outcome->period);
    }

    public function test_the_step_row_holds_the_estimate_and_counts_up(): void
    {
        $run = $this->runningRun();

        $first = $this->check($run, SendKind::Turn);
        $second = $this->check($run, SendKind::Compaction);

        $steps = AiRunStep::query()->where('ai_run_id', $run->id)->orderBy('sequence')->get()->all();
        $this->assertSame([$first->stepId, $second->stepId], [$steps[0]->id, $steps[1]->id]);
        $this->assertSame([1, 2], [$steps[0]->sequence, $steps[1]->sequence]);
        $kinds = [$steps[0]->send_kind, $steps[1]->send_kind];
        $this->assertSame([AiRunStepSendKind::Turn, AiRunStepSendKind::Compaction], $kinds);
        $this->assertSame(AiConversationPurpose::Conversation, $steps[0]->purpose);
        $this->assertSame(AiRunStepStatus::Running, $steps[0]->status);
        $this->assertSame(self::TURN_ESTIMATE, $steps[0]->reserved_cost_usd);
        $this->assertSame(['anthropic', 'claude-sonnet-5-5'], [$steps[0]->provider, $steps[0]->model]);
    }

    /**
     * @return array<string, array{int, float, int, bool, string}>
     */
    public static function estimates(): array
    {
        return [
            '係数を掛けて切り上げる' => [1000, 1.05, 100000, false, '0.012100'],
            '送る量の上限で頭打ち' => [1000, 1.0, 500, false, '0.011000'],
            'キャッシュを使うと書きの単価' => [1000, 1.0, 100000, true, '0.012500'],
        ];
    }

    #[DataProvider('estimates')]
    public function test_the_estimate(int $chars, float $factor, int $sendLimit, bool $cached, string $expected): void
    {
        $traits = new ProviderTraits(cachesInstructions: $cached, estimateFactor: $factor);

        $this->check($this->runningRun(), SendKind::Turn, $traits, chars: $chars, sendLimit: $sendLimit);

        $this->assertSame($expected, $this->latestStep()->reserved_cost_usd);
    }

    /** 会話の題は構造化の出力で接続先が足す分を上乗せする */
    public function test_the_title_estimate_adds_the_structured_output_overhead(): void
    {
        $overhead = app(AiSettings::class)->overheadTokens();

        $this->check($this->runningRun(), SendKind::Title, chars: 100, sendLimit: 100);

        $expected = number_format(((100 + $overhead) * 0.10 + 1000 * 0.50) / 1_000_000, 6, '.', '');
        $this->assertSame($expected, $this->latestStep()->reserved_cost_usd);
        $this->assertSame(AiConversationPurpose::Title, $this->latestStep()->purpose);
    }

    /** 会話の題はキャッシュに書かないので、キャッシュを使う接続先でも入力の単価で数える */
    public function test_the_title_does_not_use_the_cache_price(): void
    {
        $traits = new ProviderTraits(cachesInstructions: true);

        $this->check($this->runningRun(), SendKind::Title, $traits, chars: 100, sendLimit: 100);
        $cachedTitle = $this->latestStep()->reserved_cost_usd;
        $this->check($this->runningRun(), SendKind::Title, chars: 100, sendLimit: 100);

        $this->assertSame($this->latestStep()->reserved_cost_usd, $cachedTitle);
    }

    /** 実行がもう処理中でなければ、ステップの行を足さない */
    public function test_a_run_no_longer_running_gets_no_step(): void
    {
        $run = AiRun::factory()->conversation()->failed()->create(['kind' => RecordingConversationKind::CODE]);

        $outcome = $this->check($run, SendKind::Turn);

        $this->assertSame(SendCheckResult::Ended, $outcome->result);
        $this->assertSame(1, AiRunStep::query()->count());
    }

    private function check(
        AiRun $run,
        SendKind $kind,
        ?ProviderTraits $traits = null,
        int $chars = 1000,
        int $sendLimit = 100000,
        bool $timedOut = false,
    ): SendCheckOutcome {
        $title = $kind === SendKind::Title;

        return app(SendCheck::class)->check(new SendCheckInput(
            $run->id,
            $title ? AiConversationPurpose::Title : AiConversationPurpose::Conversation,
            $kind,
            'anthropic',
            $title ? 'claude-haiku-5-5' : 'claude-sonnet-5-5',
            $traits ?? new ProviderTraits,
            $chars,
            1000,
            $sendLimit,
            $timedOut,
        ));
    }

    private static function nameOf(SendCheckOutcome $outcome): string
    {
        return match ($outcome->result) {
            SendCheckResult::Send => 'send',
            SendCheckResult::NotSent => 'not_sent',
            SendCheckResult::Ended => 'ended',
            SendCheckResult::Stop => match ($outcome->stop) {
                StopKind::User => 'user',
                StopKind::TimeLimit => 'time_limit',
                default => 'cost_limit',
            },
        };
    }

    private function runningRun(bool $stopRequested = false): AiRun
    {
        return AiRun::factory()->conversation()->running()->create([
            'kind' => RecordingConversationKind::CODE,
            'budget' => 'default',
            'stop_requested_at' => $stopRequested ? now() : null,
        ]);
    }

    private function latestStep(): AiRunStep
    {
        return AiRunStep::query()->orderByDesc('id')->firstOrFail();
    }

    private function limits(?float $daily): void
    {
        config(['ai_runs.budget.default' => ['daily_usd' => $daily, 'monthly_usd' => null, 'total_usd' => null]]);
    }
}
