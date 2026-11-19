<?php

namespace Tests\Feature\Ai\Conversation;

use App\Actions\Ai\Conversation\StepRecorder;
use App\Actions\Ai\Core\AfterSendNotice;
use App\Actions\Ai\Core\BeforeSendNotice;
use App\Actions\Ai\Core\ExchangeNotice;
use App\Actions\Ai\Core\ResponseFinish;
use App\Actions\Ai\Core\SendFailure;
use App\Actions\Ai\Core\SendKind;
use App\Actions\Ai\Core\StepFailedNotice;
use App\Actions\Ai\Core\StepStoppedNotice;
use App\Actions\Ai\Core\StopKind;
use App\Enums\Ai\AiRunErrorCode;
use App\Enums\Ai\AiRunStepStatus;
use App\Models\Ai\AiRunStep;
use App\Models\Ai\AiRunStepText;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TransactionalTestCase;

/**
 * ステップの行への知らせの書き込み(docs の 設計/AI/実行の記録.md 2.3)。単価 = config/ai_runs.php の会話のモデル(入力 2.00・出力 10.00・
 * キャッシュの読み 0.10・書き 2.50。100 万トークンあたりのドル)。
 */
final class StepRecorderTest extends TransactionalTestCase
{
    private const USAGE = [
        'input_tokens' => 1000,
        'output_tokens' => 500,
        'cache_read_input_tokens' => 200,
        'cache_write_input_tokens' => 100,
        'reasoning_tokens' => 50,
    ];

    private AiRunStep $step;

    private StepRecorder $recorder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->step = AiRunStep::factory()->create();
        $this->recorder = app(StepRecorder::class);
    }

    public function test_a_retry_records_its_number_and_origin(): void
    {
        $retry = AiRunStep::factory()->create(['ai_run_id' => $this->step->ai_run_id]);

        $this->recorder->beforeSend(new BeforeSendNotice($this->step->id, SendKind::Turn, 1, null, 10));
        $this->recorder->beforeSend(new BeforeSendNotice($retry->id, SendKind::Turn, 2, $this->step->id, 10));

        $first = self::reloaded($this->step);
        $second = self::reloaded($retry);
        $this->assertSame([null, null], [$first->retry_attempt, $first->retried_from_step_id]);
        $this->assertSame([1, $this->step->id], [$second->retry_attempt, $second->retried_from_step_id]);
    }

    public function test_the_exchange_is_kept_as_it_was(): void
    {
        $this->recorder->exchange(new ExchangeNotice($this->step->id, ['messages' => ['問い']], null));

        $text = AiRunStepText::query()->sole();
        $this->assertSame(['{"messages":["問い"]}', null], [$text->sent_text, $text->output_text]);
    }

    /** 使用量が届けば実費に替え、終わりまで届けば成功(キャッシュを除いた入力 700 × 2.00 + 読み 200 × 0.10 + 書き 100 × 2.50 + 出力 500 × 10.00) */
    public function test_the_usage_turns_into_the_cost(): void
    {
        $notice = new AfterSendNotice($this->step->id, self::USAGE, ResponseFinish::Stop, null, false);
        $this->recorder->afterSend($notice);

        $step = $this->step->fresh();
        $this->assertNotNull($step);
        $this->assertSame([1000, 500, 200, 100, 50], [
            $step->input_tokens,
            $step->output_tokens,
            $step->cache_read_input_tokens,
            $step->cache_write_input_tokens,
            $step->reasoning_tokens,
        ]);
        $this->assertSame(['0.006670', AiRunStepStatus::Succeeded], [$step->cost_usd, $step->status]);
        $this->assertEquals(2.0, $step->pricing['input'] ?? null);
        $this->assertNotNull($step->finished_at);
    }

    /** 流れの途中で切れた試しは、届いた使用量で失敗の行を実費に替える。使用量も無ければ見積もりのまま */
    public function test_a_failed_step_keeps_the_usage_that_arrived(): void
    {
        $this->recorder->afterSend(new AfterSendNotice($this->step->id, self::USAGE, null, 500, true));
        $this->assertSame(AiRunStepStatus::Running, self::reloaded($this->step)->status);
        $this->recorder->failed(new StepFailedNotice($this->step->id, SendFailure::Provider));
        $this->assertSame('0.006670', self::reloaded($this->step)->cost_usd);

        $other = AiRunStep::factory()->create(['ai_run_id' => $this->step->ai_run_id]);
        $this->recorder->afterSend(new AfterSendNotice($other->id, null, null, 500, false));
        $this->recorder->failed(new StepFailedNotice($other->id, SendFailure::Provider));
        $failed = self::reloaded($other);
        $this->assertSame([null, '0.010000'], [$failed->cost_usd, $failed->reserved_cost_usd]);
    }

    /**
     * @return array<string, array{SendFailure, AiRunErrorCode}>
     */
    public static function failures(): array
    {
        return [
            '接続先' => [SendFailure::Provider, AiRunErrorCode::Provider],
            '時間切れ' => [SendFailure::Timeout, AiRunErrorCode::Timeout],
            '長すぎる(接続先が断った)' => [SendFailure::TooLong, AiRunErrorCode::Provider],
        ];
    }

    #[DataProvider('failures')]
    public function test_failures_keep_the_kind(SendFailure $failure, AiRunErrorCode $expected): void
    {
        $this->recorder->failed(new StepFailedNotice($this->step->id, $failure));

        $step = $this->step->fresh();
        $this->assertSame([AiRunStepStatus::Failed, $expected], [$step?->status, $step?->error_code]);
        $this->assertNotNull($step?->finished_at);
    }

    public function test_a_stop_while_reading(): void
    {
        $this->recorder->stopped(new StepStoppedNotice($this->step->id, StopKind::User));

        $this->assertSame(AiRunStepStatus::Stopped, self::reloaded($this->step)->status);
    }

    private static function reloaded(AiRunStep $step): AiRunStep
    {
        return $step->fresh() ?? throw new \LogicException('ステップの行が無い');
    }
}
