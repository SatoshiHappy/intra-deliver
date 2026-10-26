<?php

namespace Tests\Feature\Ai;

use App\Actions\Ai\Api\RequestPrompt;
use App\Actions\Ai\Api\RunRequestDeclined;
use App\Actions\Ai\Api\RunRequestMessages;
use App\Actions\Ai\PromptKinds;
use App\Models\Ai\AiRun;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Queue;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TransactionalTestCase;
use Throwable;

/**
 * 文章の依頼を断る理由が重なったときは、最初に当たった理由だけを返す(順 = docs の 設計/AI/公開の口.md 3 節の「断る理由が重なったとき」)。
 * 順の行ごとに、後ろの理由を立てられるだけ立てた 1 ケース。設定に無い種類は長さ・上限と組まない(起きない: 設定が無いと入力の上限と
 * 予算の区分を引けない)。失敗の受け手の中(failing)と完了中の実行との違い(mismatch)は印をテストで付けて作る(2 つの印は実際には同時に
 * 付かないが、順を確かめるために重ねる)。断ったときは実行の行もジョブも増えない。
 */
final class RequestPromptDeclinedOrderTest extends TransactionalTestCase
{
    use DeclinesUnderRunMarks;
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
     * 重なる理由(guest・unregistered・unconfigured・failing・mismatch・norecord・long・budget)、投げられる例外、その理由の文(の一部)。
     * 登録の無い種類は unregistered、設定に無い種類は unconfigured(両方 = どちらにも無いコード)。
     *
     * @return array<string, array{list<string>, class-string<Throwable>, string}>
     */
    public static function overlaps(): array
    {
        $guest = [AuthorizationException::class, RunRequestMessages::GUEST];
        $unregistered = [LogicException::class, PromptKinds::notRegistered(self::UNREGISTERED_KIND)];
        $noSetting = 'AI の実行の種類 '.RecordingPromptKind::CODE.' が設定(config/ai_runs.php の kinds)に無い';
        $unconfigured = [LogicException::class, $noSetting];
        $failing = [LogicException::class, '失敗の受け手の中'];
        $mismatch = [LogicException::class, '完了中の実行'];
        $noRecord = [ModelNotFoundException::class, 'AI の実行の対象のレコード '.self::MISSING_RECORD.' が無い'];
        $tooLong = [RunRequestDeclined::class, RunRequestDeclined::TOO_LONG];
        $all = ['failing', 'mismatch', 'norecord', 'long', 'budget'];

        return [
            'ゲスト' => [['guest', 'unregistered', ...$all], ...$guest],
            'ゲストと設定に無い未登録の種類' => [
                ['guest', 'unregistered', 'unconfigured', 'failing', 'mismatch', 'norecord'],
                ...$guest,
            ],
            '未登録の種類' => [['unregistered', ...$all], ...$unregistered],
            '設定に無い未登録の種類' => [['unregistered', 'unconfigured', 'failing', 'mismatch', 'norecord'], ...$unregistered],
            '設定に無い種類' => [['unconfigured', 'failing', 'mismatch', 'norecord'], ...$unconfigured],
            '失敗の受け手の中' => [$all, ...$failing],
            '完了中の実行との違い' => [['mismatch', 'norecord', 'long', 'budget'], ...$mismatch],
            '無いレコード' => [['norecord', 'long', 'budget'], ...$noRecord],
            '入力の長さ' => [['long', 'budget'], ...$tooLong],
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
        $receivingRunId = AiRun::factory()->running()->create()->id;
        $runs = AiRun::query()->count();

        $thrown = $this->thrownUnderMarks(
            in_array('failing', $overlapping, true),
            in_array('mismatch', $overlapping, true),
            $receivingRunId,
            fn () => app(RequestPrompt::class)
                ->execute($context, $this->promptRequest($kindCode, $recordId, userText: $userText)),
        );

        $this->assertDeclinedWith($thrown, $exception, $reason, $runs);
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
