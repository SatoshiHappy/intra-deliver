<?php

namespace Tests\Feature\Ai;

use App\Actions\Ai\Api\RequestPrompt;
use App\Actions\Ai\Api\RunRequestDeclined;
use App\Models\Ai\AiRun;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Queue;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TransactionalTestCase;
use Throwable;

/**
 * 文章の依頼を断る理由が重なったときは、最初に当たった理由だけを返す(順 = docs の 設計/AI/公開の口.md 3 節の「依頼(文章の種類)」の行):
 * ゲスト(403)→ 種類と設定(作りの誤り)→ 入力の長さ(422)→ 対象のレコードが無い(作りの誤り)→ 予算の区分の上限(422)。実行の行もジョブも作らない。
 * 重なる理由の組ごとに 1 ケース。設定に無い種類は長さ・上限と組まない(起きない: 設定が無いと入力の上限と予算の区分を引けない)。
 */
final class RequestPromptDeclinedOrderTest extends TransactionalTestCase
{
    use UsesTestPromptKinds;

    /** 実装の登録が無い種類(このテストだけが設定に足す) */
    private const UNREGISTERED = 'test.unregistered';

    /** 無いレコードの id */
    private const MISSING_RECORD = 2_000_000_000;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registerTestPromptKinds();
        $kinds = (array) config('ai_runs.kinds');
        config(['ai_runs.kinds' => [...$kinds, self::UNREGISTERED => $kinds[RecordingPromptKind::CODE]]]);
        Queue::fake();
    }

    /**
     * 重なる理由(guest・unregistered・unconfigured・long・norecord・budget)、投げられる例外、その理由のコードか文。
     *
     * @return array<string, array{list<string>, class-string<Throwable>, string}>
     */
    public static function overlaps(): array
    {
        return [
            'ゲストと未登録の種類' => [['guest', 'unregistered'], AuthorizationException::class, RequestPrompt::GUEST],
            'ゲストと設定に無い種類' => [['guest', 'unconfigured'], AuthorizationException::class, RequestPrompt::GUEST],
            'ゲストと入力の長さ' => [['guest', 'long'], AuthorizationException::class, RequestPrompt::GUEST],
            'ゲストと予算の上限' => [['guest', 'budget'], AuthorizationException::class, RequestPrompt::GUEST],
            '未登録の種類と入力の長さ' => [['unregistered', 'long'], LogicException::class, self::UNREGISTERED],
            '未登録の種類と予算の上限' => [['unregistered', 'budget'], LogicException::class, self::UNREGISTERED],
            '入力の長さと予算の上限' => [['long', 'budget'], RunRequestDeclined::class, RunRequestDeclined::TOO_LONG],
            '入力の長さと無いレコード' => [['long', 'norecord'], RunRequestDeclined::class, RunRequestDeclined::TOO_LONG],
            '無いレコードと予算の上限' => [['norecord', 'budget'], LogicException::class, '対象のレコード'],
        ];
    }

    /**
     * @param  list<string>  $overlapping
     * @param  class-string<Throwable>  $exception
     */
    #[DataProvider('overlaps')]
    public function test_only_the_first_reason_is_returned(array $overlapping, string $exception, string $reason): void
    {
        $kindCode = in_array('unregistered', $overlapping, true) ? self::UNREGISTERED : RecordingPromptKind::CODE;
        $context = in_array('guest', $overlapping, true) ? $this->guestContext() : $this->requesterContext();
        $userText = in_array('long', $overlapping, true) ? str_repeat('U', 50000) : null;
        $recordId = in_array('norecord', $overlapping, true) ? self::MISSING_RECORD : null;

        $this->applySettings($overlapping);

        try {
            $request = $this->promptRequest($kindCode, $recordId, userText: $userText);
            app(RequestPrompt::class)->execute($context, $request);
            $this->fail('断られるはずの依頼が通った');
        } catch (Throwable $thrown) {
            $this->assertSame($exception, $thrown::class);
            $actual = $thrown instanceof RunRequestDeclined ? $thrown->reason : $thrown->getMessage();
            $this->assertStringContainsString($reason, $actual);
        }

        $this->assertSame(0, AiRun::query()->count());
        Queue::assertNothingPushed();
    }

    /**
     * @param  list<string>  $overlapping
     */
    private function applySettings(array $overlapping): void
    {
        if (in_array('unconfigured', $overlapping, true)) {
            $kinds = (array) config('ai_runs.kinds');
            unset($kinds[RecordingPromptKind::CODE]);
            config(['ai_runs.kinds' => $kinds]);
        }

        if (in_array('budget', $overlapping, true)) {
            config(['ai_runs.budget.default.daily_usd' => 0.0]);
        }
    }
}
