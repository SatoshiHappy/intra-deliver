<?php

namespace Tests\Feature\Ai;

use App\Actions\Ai\Api\RequestTranscription;
use App\Actions\Ai\Api\RunRequestDeclined;
use App\Actions\Ai\Api\RunRequestMessages;
use App\Actions\Ai\Api\TranscriptionRequest;
use App\Actions\Ai\TranscriptionKinds;
use App\Models\Ai\AiRun;
use App\Models\Upload\Upload;
use App\Support\AccessContext;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Queue;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsRecordModules;
use Tests\Support\TransactionalTestCase;
use Throwable;

/**
 * 文字起こしの依頼を断る理由が重なったときは、最初に当たった理由だけを返す(順 = docs の 設計/AI/公開の口.md 3 節の「断る理由が重なったとき」)。
 * 順の行ごとに、後ろの理由を立てられるだけ立てた 1 ケース(文章の依頼の RequestPromptDeclinedOrderTest と同じ形)。音声の 422 は
 * 実体の無い音声で代表させる(音声の形式でない添付は、実体も無い添付にして重ねる)。断ったときは実行の行もジョブも増えない。
 */
final class RequestTranscriptionDeclinedOrderTest extends TransactionalTestCase
{
    use BuildsRecordModules;
    use DeclinesUnderRunMarks;
    use UsesTestTranscriptionKinds;

    /** 無いレコードの id */
    private const MISSING_RECORD = 999_999_999;

    /** 実装の登録が無い種類(設定にはある) */
    private const UNREGISTERED_KIND = 'test.unregistered-transcription';

    protected function setUp(): void
    {
        parent::setUp();
        $this->registerTestTranscriptionKind();
        $kinds = (array) config('ai_runs.kinds');
        config(['ai_runs.kinds' => [...$kinds, self::UNREGISTERED_KIND => $kinds[RecordingTranscriptionKind::CODE]]]);
        Queue::fake();
    }

    protected function tearDown(): void
    {
        $this->removeTranscriptionTemp();
        parent::tearDown();
    }

    /**
     * 重なる理由(guest・unregistered・notaudio・failing・mismatch・norecord・noaudio・budget)、投げられる例外、その理由の文(の一部)。
     *
     * @return array<string, array{list<string>, class-string<Throwable>, string}>
     */
    public static function overlaps(): array
    {
        $all = ['failing', 'mismatch', 'norecord', 'noaudio', 'budget'];

        return [
            'ゲスト' => [
                ['guest', 'unregistered', 'notaudio', ...$all],
                AuthorizationException::class,
                RunRequestMessages::GUEST,
            ],
            '未登録の種類' => [
                ['unregistered', 'notaudio', ...$all],
                LogicException::class,
                TranscriptionKinds::notRegistered(self::UNREGISTERED_KIND),
            ],
            '音声の形式でない添付' => [['notaudio', ...$all], LogicException::class, '音声の形式でない'],
            '失敗の受け手の中' => [$all, LogicException::class, '失敗の受け手の中'],
            '完了中の実行との違い' => [['mismatch', 'norecord', 'noaudio', 'budget'], LogicException::class, '完了中の実行'],
            '無いレコード' => [['norecord', 'noaudio', 'budget'], ModelNotFoundException::class, '対象のレコード'],
            '音声の実体が無い' => [['noaudio', 'budget'], RunRequestDeclined::class, RunRequestDeclined::MISSING],
        ];
    }

    /**
     * @param  list<string>  $overlapping
     * @param  class-string<Throwable>  $exception
     */
    #[DataProvider('overlaps')]
    public function test_only_the_first_reason_is_returned(array $overlapping, string $exception, string $reason): void
    {
        $has = fn (string $name): bool => in_array($name, $overlapping, true);
        $kindCode = $has('unregistered') ? self::UNREGISTERED_KIND : RecordingTranscriptionKind::CODE;
        $context = $has('guest') ? $this->guestContext() : $this->transcriberContext();
        $recordId = $has('norecord') ? self::MISSING_RECORD : $this->transcriptionRecord()->id;
        $uploadId = $this->uploadFor($has);

        if ($has('budget')) {
            config(['ai_runs.budget.transcription.daily_usd' => 0.0]);
        }

        $receivingRunId = AiRun::factory()->running()->create()->id;
        $runs = AiRun::query()->count();

        $thrown = $this->thrownUnderMarks(
            $has('failing'),
            $has('mismatch'),
            $receivingRunId,
            fn () => app(RequestTranscription::class)
                ->execute($context, new TranscriptionRequest($kindCode, $recordId, $uploadId)),
        );

        $this->assertDeclinedWith($thrown, $exception, $reason, $runs);
    }

    /**
     * 音声の形式でない(実体も無い)添付・実体の無い音声・読める音声のどれか。
     *
     * @param  Closure(string): bool  $has
     */
    private function uploadFor(Closure $has): int
    {
        return match (true) {
            $has('notaudio') => Upload::factory()->create()->id,
            $has('noaudio') => Upload::factory()->create(['mime' => 'audio/mpeg', 'path' => 'ai-test/none.mp3'])->id,
            default => $this->audioUpload('sin(2*PI*440*t)', 2),
        };
    }

    private function guestContext(): AccessContext
    {
        $context = $this->transcriberContext();

        return new AccessContext(null, null, null, $context->date, $context->localeCode);
    }
}
