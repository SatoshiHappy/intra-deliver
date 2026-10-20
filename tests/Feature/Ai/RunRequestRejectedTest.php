<?php

namespace Tests\Feature\Ai;

use App\Actions\Ai\Api\RequestPrompt;
use App\Actions\Ai\Api\RunRequestRejected;
use App\Actions\Ai\RunBudget;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Tests\Support\TransactionalTestCase;

/**
 * 依頼の口の 422 の理由のコード(docs の 設計/AI/実行と費用.md 5.2): 上限を超える = budget、入力が長すぎる = too_long。
 * HTTP の応答(422・キー ai・文)は、理由を持たない ValidationException と同じ。
 */
final class RunRequestRejectedTest extends TransactionalTestCase
{
    use UsesTestPromptKinds;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registerTestPromptKinds();
        Queue::fake();
    }

    public function test_a_too_long_input_is_rejected_as_too_long(): void
    {
        $this->setKindSetting(RecordingPromptKind::CODE, 'max_input_chars', 99);

        $rejected = $this->rejectedRequest();

        $this->assertSame(RunRequestRejected::TOO_LONG, $rejected->reason);
        $this->assertSame([RunBudget::ERROR_KEY => ['AI に送る文が長すぎます(99 文字まで)']], $rejected->errors());
    }

    public function test_a_request_over_the_limit_is_rejected_as_budget(): void
    {
        config(['ai_runs.budget.default.daily_usd' => 0.0001]);

        $rejected = $this->rejectedRequest();

        $this->assertSame(RunRequestRejected::BUDGET, $rejected->reason);
        $this->assertSame([RunBudget::ERROR_KEY => [RunBudget::DAILY_EXCEEDED]], $rejected->errors());
    }

    public function test_the_http_response_is_the_one_of_a_validation_exception(): void
    {
        config(['ai_runs.budget.default.daily_usd' => 0.0001]);
        $message = [RunBudget::ERROR_KEY => RunBudget::DAILY_EXCEEDED];
        $request = fn () => app(RequestPrompt::class)->execute($this->requesterContext(), $this->promptRequest());
        Route::post('/test/ai/rejected', $request);
        Route::post('/test/ai/plain', fn () => throw ValidationException::withMessages($message));

        $rejected = $this->postJson('/test/ai/rejected');
        $plain = $this->postJson('/test/ai/plain');

        $rejected->assertUnprocessable()->assertJsonValidationErrors($message);
        $this->assertSame([$plain->getStatusCode(), $plain->json()], [$rejected->getStatusCode(), $rejected->json()]);
    }

    private function rejectedRequest(): RunRequestRejected
    {
        try {
            app(RequestPrompt::class)->execute($this->requesterContext(), $this->promptRequest());
        } catch (RunRequestRejected $rejected) {
            return $rejected;
        }

        $this->fail('依頼が断られなかった');
    }
}
