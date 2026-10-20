<?php

namespace Tests\Feature\Ai;

use App\Actions\Ai\Api\RequestPrompt;
use App\Actions\Ai\RunBudget;
use Illuminate\Support\Facades\Route;
use Tests\Support\TransactionalTestCase;

/**
 * AI の依頼の入口の頻度の制限(docs の 設計/AI/実行と費用.md 5.2): 名前つきの RateLimiter ai-requests を掛けたテスト用のルートで、
 * 接続元の IP ごとに 1 日 10 回まで通り、11 回目は検証の失敗(422・キー ai)で止まる(上限を超えた依頼と同じ見え方)。
 * Inertia の画面(JSON でない要求)では、検証の失敗と同じく前の画面に戻り、誤りのキー ai がセッションに入る。
 */
final class RequestThrottleTest extends TransactionalTestCase
{
    private const URI = '/__test/ai-request';

    protected function setUp(): void
    {
        parent::setUp();
        Route::middleware(['web', 'throttle:ai-requests'])->post(self::URI, fn () => response('accepted'));
    }

    public function test_the_eleventh_request_in_a_day_is_rejected_like_a_budget_error(): void
    {
        $this->useUpTheDailyRequests();

        $this->postJson(self::URI)->assertUnprocessable()->assertExactJson([
            'message' => RequestPrompt::TOO_MANY_REQUESTS,
            'errors' => [RunBudget::ERROR_KEY => [RequestPrompt::TOO_MANY_REQUESTS]],
        ]);
        $this->from('/meetings')->post(self::URI)->assertRedirect('/meetings')->assertSessionHasErrors([RunBudget::ERROR_KEY => RequestPrompt::TOO_MANY_REQUESTS]);
    }

    public function test_another_address_has_its_own_count(): void
    {
        $this->useUpTheDailyRequests();

        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.10'])->postJson(self::URI)->assertOk();
    }

    public function test_the_count_starts_over_after_a_day(): void
    {
        $this->useUpTheDailyRequests();

        $this->travel(23)->hours();
        $this->postJson(self::URI)->assertUnprocessable();

        $this->travel(61)->minutes();
        $this->postJson(self::URI)->assertOk();
    }

    private function useUpTheDailyRequests(): void
    {
        for ($attempt = 1; $attempt <= RequestPrompt::REQUESTS_PER_DAY; $attempt++) {
            $this->postJson(self::URI)->assertOk();
        }
    }
}
