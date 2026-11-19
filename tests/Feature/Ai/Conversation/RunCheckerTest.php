<?php

namespace Tests\Feature\Ai\Conversation;

use App\Actions\Ai\Conversation\RunChecker;
use App\Actions\Ai\Conversation\RunClock;
use App\Actions\Ai\Conversation\RunNoLongerRunning;
use App\Actions\Ai\Conversation\SendCheck;
use App\Actions\Ai\Conversation\StepTarget;
use App\Actions\Ai\Conversation\StepTargets;
use App\Actions\Ai\Core\CheckPoint;
use App\Actions\Ai\Core\CheckReply;
use App\Actions\Ai\Core\CheckRequest;
use App\Actions\Ai\Core\ProviderTraits;
use App\Actions\Ai\Core\SendKind;
use App\Actions\Ai\Core\StopKind;
use App\Enums\Ai\AiConversationPurpose;
use App\Models\Ai\AiRun;
use App\Models\Ai\AiRunStep;
use Carbon\CarbonImmutable;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Ai\RecordingConversationKind;
use Tests\Feature\Ai\UsesTestConversationKinds;
use Tests\Support\TransactionalTestCase;

/**
 * 繋ぎの確かめの口(位置ごとの確かめ = docs の 設計/AI/エージェントの実行/README.md 9.2 の表、返り値 = 設計/AI/会話の中核.md 13.5)と、
 * 実行の時間(設計/AI/エージェントの実行/途中の表示と上限.md 9.7)。実行の時間の上限 = 60 秒。
 */
final class RunCheckerTest extends TransactionalTestCase
{
    use UsesTestConversationKinds;

    private const STARTED = '2026-11-19 10:00:00';

    private AiRun $run;

    private RunClock $clock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registerTestConversationKind();
        config(['ai_runs.budget.default' => ['daily_usd' => null, 'monthly_usd' => null, 'total_usd' => null]]);
        Sleep::fake();
        $this->travelTo(CarbonImmutable::parse(self::STARTED));
        $this->run = AiRun::factory()->conversation()->running()->create([
            'kind' => RecordingConversationKind::CODE,
            'budget' => 'default',
            'started_at' => CarbonImmutable::parse(self::STARTED),
        ]);
        $this->clock = new RunClock(CarbonImmutable::parse(self::STARTED), 60);
    }

    /**
     * 時間の上限を超えた後(60 秒と 1 ミリ秒)の位置ごとの結果。道具を呼ぶ前と送り直しの待ちの前だけが時間を見る。
     *
     * @return array<string, array{CheckPoint, StopKind|null}>
     */
    public static function afterTheTimeLimit(): array
    {
        return [
            '道具を呼ぶ前' => [CheckPoint::BeforeTool, StopKind::TimeLimit],
            '送り直しの待ちの前' => [CheckPoint::BeforeRetryWait, StopKind::TimeLimit],
            '読む途中' => [CheckPoint::WhileReading, null],
            '読み終えた後' => [CheckPoint::AfterReading, null],
            '道具の後' => [CheckPoint::AfterTools, null],
            '送り直しの待ちの間' => [CheckPoint::DuringRetryWait, null],
        ];
    }

    #[DataProvider('afterTheTimeLimit')]
    public function test_only_some_points_look_at_the_time(CheckPoint $point, ?StopKind $expected): void
    {
        $this->travelTo(CarbonImmutable::parse(self::STARTED)->addMilliseconds(60_001));

        $this->assertSame($expected, $this->check($point)->stop);
    }

    /** 止める印はどの位置でも止めた(利用者)で、時間より先に見る */
    public function test_the_stop_request_wins_at_every_point(): void
    {
        $this->run->update(['stop_requested_at' => now()]);
        $this->travelTo(CarbonImmutable::parse(self::STARTED)->addMinutes(5));

        foreach (array_column(self::afterTheTimeLimit(), 0) as $point) {
            $this->assertSame(StopKind::User, $this->check($point)->stop, $point->value);
        }
    }

    /** 送り直しの待ちの前は、待ちの上限 = 時間の残りを返す(残り 0 は境で、まだ超えていない) */
    public function test_the_wait_limit_is_the_time_left(): void
    {
        $this->travelTo(CarbonImmutable::parse(self::STARTED)->addSeconds(45));
        $this->assertSame(15_000, $this->check(CheckPoint::BeforeRetryWait)->waitLimitMs);

        $this->travelTo(CarbonImmutable::parse(self::STARTED)->addSeconds(60));
        $reply = $this->check(CheckPoint::BeforeRetryWait);
        $this->assertSame([null, 0], [$reply->stop, $reply->waitLimitMs]);
    }

    /** 送り直しの待ちも実行の時間に数える(待ちを残りで頭打ちにするため) */
    public function test_waits_are_counted(): void
    {
        $this->clock->wait(30_000);
        $this->travelTo(CarbonImmutable::parse(self::STARTED)->addSeconds(61));

        $this->assertSame(-1_000, $this->clock->remainingMilliseconds());
        $this->assertSame(StopKind::TimeLimit, $this->check(CheckPoint::BeforeTool)->stop);
        Sleep::assertSleptTimes(1);
    }

    public function test_before_send_returns_the_new_step(): void
    {
        $reply = $this->checker()->check(CheckRequest::beforeSend(SendKind::Turn, 100, 1000, 10000));

        $this->assertSame(AiRunStep::query()->sole()->id, $reply->stepId);
    }

    /** 費用の上限は超えた期間を理由に添える。会話の題は送らない(費用の上限で止めるの返り値 = 中核が題なしにする) */
    public function test_cost_stops_carry_the_period(): void
    {
        config(['ai_runs.budget.default.daily_usd' => 0.000001]);

        $turn = $this->checker()->check(CheckRequest::beforeSend(SendKind::Turn, 100, 1000, 10000));
        $title = $this->checker()->check(CheckRequest::beforeSend(SendKind::Title, 100, 1000, 10000));

        $this->assertSame([StopKind::CostLimit, 'day'], [$turn->stop, $turn->reason]);
        $this->assertSame(StopKind::CostLimit, $title->stop);
    }

    /**
     * @return array<string, array{CheckPoint}>
     */
    public static function points(): array
    {
        return ['送る前' => [CheckPoint::BeforeSend], '道具を呼ぶ前' => [CheckPoint::BeforeTool]];
    }

    /** 実行がもう処理中でなければ、中核の外へ抜ける */
    #[DataProvider('points')]
    public function test_an_ended_run_leaves_the_core(CheckPoint $point): void
    {
        $this->run = AiRun::factory()->conversation()->failed()->create(['kind' => RecordingConversationKind::CODE]);

        $this->expectException(RunNoLongerRunning::class);

        $point === CheckPoint::BeforeSend
            ? $this->checker()->check(CheckRequest::beforeSend(SendKind::Turn, 100, 1000, 10000))
            : $this->check($point);
    }

    private function check(CheckPoint $point): CheckReply
    {
        return $this->checker()->check(CheckRequest::at($point, SendKind::Turn));
    }

    private function checker(): RunChecker
    {
        $target = static fn (AiConversationPurpose $purpose, string $model): StepTarget => new StepTarget(
            $purpose,
            'anthropic',
            $model,
            new ProviderTraits,
        );
        $targets = new StepTargets(
            $target(AiConversationPurpose::Conversation, 'claude-sonnet-5-5'),
            $target(AiConversationPurpose::Title, 'claude-haiku-5-5'),
        );

        return new RunChecker($this->run->id, $targets, $this->clock, app(SendCheck::class));
    }
}
