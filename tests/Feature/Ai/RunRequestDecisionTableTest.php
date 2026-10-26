<?php

namespace Tests\Feature\Ai;

use App\Actions\Ai\Api\RequestPrompt;
use App\Actions\Ai\Api\RequestTranscription;
use App\Actions\Ai\Api\RunRequestMessages;
use App\Actions\Ai\Api\TranscriptionRequest;
use App\Actions\Ai\CompletingRun;
use App\Actions\Ai\FailingRun;
use App\Models\Ai\AiRun;
use App\Models\Master\User;
use App\Models\Records\Record;
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
 * 2 つの依頼の口が断る・受ける組み合わせ(docs の 設計/AI/公開の口.md 3 節の「依頼の時点の確かめ」の表 B1〜B7)を、文章と文字起こしの両方で通す。
 * 呼ばれ方は、受け手を呼んでいる間の印({@see CompletingRun}・{@see FailingRun})をテストで付けて作る(印を付ける場所のテストは
 * RunReceiverMarksTest)。「どれでも」の欄は値を 1 つ選ぶ(B1 = 受け手の外・無い対象、B4 = ある対象、B7 = ある対象)。
 * 断ったときは実行の行もジョブも増えない。
 */
final class RunRequestDecisionTableTest extends TransactionalTestCase
{
    use BuildsRecordModules;
    use UsesTestPromptKinds;
    use UsesTestTranscriptionKinds;

    /** 無いレコードの id */
    private const MISSING_RECORD = 999_999_999;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registerTestPromptKinds();
        $this->registerTestTranscriptionKind();
        Queue::fake();
    }

    protected function tearDown(): void
    {
        $this->removeTranscriptionTemp();
        parent::tearDown();
    }

    /**
     * 行 → [依頼者, 呼ばれ方, 対象のレコード, 完了中の実行と違う所, 結果(例外の型か accepted), 文の一部]。
     *
     * @return array<string, array{string, string, string, string, string, string}>
     */
    public static function rows(): array
    {
        $guest = [AuthorizationException::class, RunRequestMessages::GUEST];
        $notFound = [ModelNotFoundException::class, '対象のレコード'];
        $rows = [
            'B1 ゲスト' => ['guest', 'outside', 'missing', 'none', ...$guest],
            'B2 受け手の外・無い対象' => ['user', 'outside', 'missing', 'none', ...$notFound],
            'B3 受け手の外・ある対象' => ['user', 'outside', 'present', 'none', 'accepted', ''],
            'B4 完了の受け手の中・依頼者が違う' => ['user', 'completing', 'present', 'requester', LogicException::class, '完了中の実行'],
            'B4 完了の受け手の中・対象が違う' => ['user', 'completing', 'present', 'record', LogicException::class, '完了中の実行'],
            'B5 完了の受け手の中・同じ・無い対象' => ['user', 'completing', 'missing', 'none', ...$notFound],
            'B6 完了の受け手の中・同じ・ある対象' => ['user', 'completing', 'present', 'none', 'accepted', ''],
            'B7 失敗の受け手の中' => ['user', 'failing', 'present', 'none', LogicException::class, '失敗の受け手の中'],
        ];
        $cases = [];

        foreach (['文章' => 'prompt', '文字起こし' => 'transcription'] as $label => $port) {
            foreach ($rows as $name => $row) {
                $cases["{$label}: {$name}"] = [$port, ...$row];
            }
        }

        return $cases;
    }

    /**
     * @param  'prompt'|'transcription'  $port
     * @param  'guest'|'user'  $requester
     * @param  'outside'|'completing'|'failing'  $calledFrom
     * @param  'missing'|'present'  $record
     * @param  'none'|'requester'|'record'  $differs
     */
    #[DataProvider('rows')]
    public function test_the_request_follows_the_row(
        string $port,
        string $requester,
        string $calledFrom,
        string $record,
        string $differs,
        string $expected,
        string $message,
    ): void {
        $userId = $this->requesterUserId();
        $target = $this->transcriptionRecord();
        $receivingRunId = $this->receivingRun($differs, $userId, $target->id);

        if ($record === 'missing') {
            Record::query()->whereKey($target->id)->delete();
        }

        $recordId = $record === 'missing' && $calledFrom === 'outside' ? self::MISSING_RECORD : $target->id;
        $context = $requester === 'guest' ? $this->guestContext() : $this->requesterContext($userId);
        $call = $this->requestOf($port, $context, $recordId);
        $runs = AiRun::query()->count();

        $result = $this->callFrom($calledFrom, $receivingRunId, $call);

        $this->assertOutcome($expected, $message, $result, $runs);
    }

    /**
     * 受け手を呼んでいる(とする)実行。$differs の値だけ、依頼と違う依頼者か対象のレコードを持つ。
     *
     * @param  'none'|'requester'|'record'  $differs
     */
    private function receivingRun(string $differs, int $userId, int $targetId): int
    {
        $runRecordId = $differs === 'record' ? $this->transcriptionRecord()->id : $targetId;

        return AiRun::factory()->running()->create([
            'requester_user_id' => $differs === 'requester' ? User::factory()->create()->id : $userId,
            'record_id' => $runRecordId,
            'original_record_id' => $runRecordId,
            'record_module_code' => 'ai_audio_notes',
        ])->id;
    }

    /**
     * 口ごとの依頼(文字起こしは読める音声を添える)。
     *
     * @param  'prompt'|'transcription'  $port
     * @return Closure(): int
     */
    private function requestOf(string $port, AccessContext $context, int $recordId): Closure
    {
        if ($port === 'prompt') {
            $prompt = $this->promptRequest(recordId: $recordId);

            return fn (): int => app(RequestPrompt::class)->execute($context, $prompt);
        }

        $uploadId = $this->audioUpload('sin(2*PI*440*t)', 2);
        $request = new TranscriptionRequest(RecordingTranscriptionKind::CODE, $recordId, $uploadId);

        return fn (): int => app(RequestTranscription::class)->execute($context, $request);
    }

    /**
     * 受けた = 実行の行とジョブが 1 つ増える。断った = 例外の型と文(の一部)で、実行の行もジョブも増えない。
     */
    private function assertOutcome(string $expected, string $message, int|Throwable $result, int $runs): void
    {
        if ($expected === 'accepted') {
            $this->assertIsInt($result);
            $this->assertSame($runs + 1, AiRun::query()->count());
            Queue::assertCount(1);

            return;
        }

        $this->assertInstanceOf(Throwable::class, $result);
        $this->assertSame($expected, $result::class);
        $this->assertStringContainsString($message, $result->getMessage());
        $this->assertSame($runs, AiRun::query()->count());
        Queue::assertNothingPushed();
    }

    /**
     * 呼ばれ方の印を付けて呼び、戻り値か投げられた例外を返す。
     *
     * @param  'outside'|'completing'|'failing'  $calledFrom
     * @param  Closure(): int  $call
     */
    private function callFrom(string $calledFrom, int $runId, Closure $call): int|Throwable
    {
        $result = null;
        $capture = function () use ($call, &$result): void {
            try {
                $result = $call();
            } catch (Throwable $exception) {
                $result = $exception;
            }
        };

        match ($calledFrom) {
            'outside' => $capture(),
            'completing' => app(CompletingRun::class)->during($runId, $capture),
            'failing' => app(FailingRun::class)->during($runId, $capture),
        };

        $this->assertNotNull($result);

        return $result;
    }
}
