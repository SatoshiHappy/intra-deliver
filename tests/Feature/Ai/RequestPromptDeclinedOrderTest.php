<?php

namespace Tests\Feature\Ai;

use App\Actions\Ai\Api\RequestPrompt;
use App\Actions\Ai\Api\RunRequestDeclined;
use App\Actions\Ai\PromptKinds;
use App\Models\Ai\AiRun;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Queue;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TransactionalTestCase;
use Throwable;

/**
 * 文章の依頼を断る理由が重なったときは、最初に当たった理由だけを返す(順 = docs の 設計/AI/公開の口.md 3 節の「依頼(文章の種類)」の行)。
 * 順の行ごとに、後ろの理由を立てられるだけ立てた 1 ケース。設定に無い種類は長さ・上限と組まない(起きない: 設定が無いと入力の上限と
 * 予算の区分を引けない)。実行の行もジョブも作らない。
 */
final class RequestPromptDeclinedOrderTest extends TransactionalTestCase
{
    use UsesTestPromptKinds;

    /** 無いレコードの id */
    private const MISSING_RECORD = 999_999_999;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registerTestPromptKinds();
        Queue::fake();
    }

    /**
     * 重なる理由(guest・unregistered・unconfigured・long・norecord・budget)、投げられる例外、その理由の文(の一部)。
     * 登録の無い種類は unregistered、設定に無い種類は unconfigured(両方 = どちらにも無いコード)。
     *
     * @return array<string, array{list<string>, class-string<Throwable>, string}>
     */
    public static function overlaps(): array
    {
        $guest = [AuthorizationException::class, RequestPrompt::GUEST];
        $unregistered = [LogicException::class, PromptKinds::notRegistered(self::UNREGISTERED_KIND)];
        $noSetting = 'AI の実行の種類 '.RecordingPromptKind::CODE.' が設定(config/ai_runs.php の kinds)に無い';
        $unconfigured = [LogicException::class, $noSetting];
        $tooLong = [RunRequestDeclined::class, RunRequestDeclined::TOO_LONG];
        $noRecord = [LogicException::class, 'AI の実行の対象のレコード '.self::MISSING_RECORD.' が無い'];

        return [
            'ゲスト' => [['guest', 'unregistered', 'long', 'norecord', 'budget'], ...$guest],
            'ゲストと設定に無い未登録の種類' => [['guest', 'unregistered', 'unconfigured', 'norecord'], ...$guest],
            '未登録の種類' => [['unregistered', 'long', 'norecord', 'budget'], ...$unregistered],
            '設定に無い未登録の種類' => [['unregistered', 'unconfigured', 'norecord'], ...$unregistered],
            '設定に無い種類' => [['unconfigured', 'norecord'], ...$unconfigured],
            '入力の長さ' => [['long', 'norecord', 'budget'], ...$tooLong],
            '無いレコード' => [['norecord', 'budget'], ...$noRecord],
        ];
    }

    /**
     * @param  list<string>  $overlapping
     * @param  class-string<Throwable>  $exception
     */
    #[DataProvider('overlaps')]
    public function test_only_the_first_reason_is_returned(array $overlapping, string $exception, string $reason): void
    {
        $kindCode = in_array('unregistered', $overlapping, true) ? self::UNREGISTERED_KIND : RecordingPromptKind::CODE;
        $context = in_array('guest', $overlapping, true) ? $this->guestContext() : $this->requesterContext();
        $userText = in_array('long', $overlapping, true) ? str_repeat('U', 50000) : null;
        $recordId = in_array('norecord', $overlapping, true) ? self::MISSING_RECORD : null;

        $this->applySettings($overlapping, $kindCode);

        $thrown = null;

        try {
            $request = $this->promptRequest($kindCode, $recordId, userText: $userText);
            app(RequestPrompt::class)->execute($context, $request);
        } catch (Throwable $caught) {
            $thrown = $caught;
        }

        $this->assertNotNull($thrown, '断られるはずの依頼が通った');
        $this->assertSame($exception, $thrown::class);
        $actual = $thrown instanceof RunRequestDeclined ? $thrown->reason : $thrown->getMessage();
        $this->assertStringContainsString($reason, $actual);
        $this->assertSame(0, AiRun::query()->count());
        Queue::assertNothingPushed();
    }

    /**
     * @param  list<string>  $overlapping
     */
    private function applySettings(array $overlapping, string $kindCode): void
    {
        if (in_array('unconfigured', $overlapping, true)) {
            $kinds = (array) config('ai_runs.kinds');
            unset($kinds[$kindCode]);
            config(['ai_runs.kinds' => $kinds]);
        }

        if (in_array('budget', $overlapping, true)) {
            config(['ai_runs.budget.default.daily_usd' => 0.0]);
        }
    }
}
