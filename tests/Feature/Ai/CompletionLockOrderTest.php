<?php

namespace Tests\Feature\Ai;

use App\Actions\Ai\Api\FailedRun;
use App\Actions\Ai\Api\WriteRecordFromRun;
use App\Actions\Ai\ExecutePrompt;
use App\Actions\Ai\ExecuteTranscription;
use App\Actions\Ai\FailRun;
use App\Actions\Ai\PromptAgent;
use App\Actions\Notice\Api\NoticeMessage;
use App\Actions\Notice\Api\SendNotices;
use App\Actions\Records\Api\RecordInput;
use App\Enums\Ai\AiRunErrorCode;
use App\Enums\Ai\AiRunStatus;
use App\Models\Ai\AiRun;
use App\Models\Records\Record;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Transcription;
use RuntimeException;
use Tests\Support\BuildsRecordModules;
use Tests\Support\TransactionalTestCase;

/**
 * 完了の鍵の順(文章と文字起こし。docs の 設計/AI/実行と費用.md 4 節、鍵の順の規則 = 設計/業務データの入れ物/レコードの共通機能/データモデル/README.md の 4 節):
 * 受け手が呼ぶ書き込みの口の鍵(レコードの行)の後に、実行の行の鍵を最後に取り、受け手を呼ぶ前は実行の行に鍵を取らず書き込みもしない。
 * 失敗の書き方も同じ順(受け手の通知の書き込み → 実行の行)。
 * レコードの削除は行の鍵の後に外部キーで実行の行を書くので、どちらも実行の行を最後に取り、待ちの輪を作らない。
 * テストはトランザクションの中で走り、2 つ目の接続からは実行の行が見えないので、2 つの接続で重ねる代わりに、完了が送る文の順で鍵の順を確かめる。
 */
final class CompletionLockOrderTest extends TransactionalTestCase
{
    use BuildsRecordModules;
    use UsesTestPromptKinds;
    use UsesTestTranscriptionKinds;

    private const RUN_ROW_LOCK = '/^(select .* from "ai_runs" .* for update|update "ai_runs")/';

    private const RECORD_ROW_LOCK = '/^select .* from "records" .* for no key update/';

    /** 失敗の受け手の書き込み(通知の行) */
    private const NOTICE_WRITE = '/^insert into "notices"/';

    /** @var list<string> LLM の呼び出しの後に送った文 */
    private array $statements = [];

    private bool $recording = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registerTestPromptKinds();
        $this->registerTestTranscriptionKind();
    }

    protected function tearDown(): void
    {
        $this->removeTranscriptionTemp();
        parent::tearDown();
    }

    public function test_the_completion_locks_the_run_row_only_after_the_lock_of_the_receiver(): void
    {
        $runId = $this->queuedRun(recordId: $this->noteRecord()->id);
        $receiverEnd = null;
        $this->recordingKind->writesTo = 'ai_notes';
        $this->recordingKind->onComplete = function ($run) use (&$receiverEnd): void {
            app(WriteRecordFromRun::class)->execute($run->runId, new RecordInput(['summary' => 'AI の要約']), 'AI(要約の作成)');
            $receiverEnd = count($this->statements);
        };
        DB::listen(function (QueryExecuted $query): void {
            if ($this->recording) {
                $this->statements[] = $query->sql;
            }
        });
        PromptAgent::fake(function (): array {
            $this->recording = true;

            return ['summary' => 'AI の要約'];
        });

        app(ExecutePrompt::class)->execute($runId);

        $this->assertSame(AiRunStatus::Succeeded, AiRun::query()->findOrFail($runId)->status);
        $this->assertLocksAfterTheReceiver((int) $receiverEnd);
    }

    public function test_the_completion_of_a_transcription_locks_the_run_row_only_after_the_lock_of_the_receiver(): void
    {
        $runId = $this->queuedTranscription($this->audioUpload('sin(2*PI*440*t)', 6), $this->noteRecord()->id);
        $receiverEnd = null;
        $this->transcriptionKind->writesTo = 'ai_notes';
        $this->transcriptionKind->onComplete = function ($run) use (&$receiverEnd): void {
            app(WriteRecordFromRun::class)->execute($run->runId, new RecordInput(['summary' => '書き起こし']), 'AI(書き起こし)');
            $receiverEnd = count($this->statements);
        };
        DB::listen(function (QueryExecuted $query): void {
            if ($this->recording) {
                $this->statements[] = $query->sql;
            }
        });
        Transcription::fake(function () {
            $this->recording = true;

            return $this->transcriptionResponse([['発言', 'A', 0, 5.9]]);
        });

        app(ExecuteTranscription::class)->execute($runId);

        $this->assertSame(AiRunStatus::Succeeded, AiRun::query()->findOrFail($runId)->status);
        $this->assertLocksAfterTheReceiver((int) $receiverEnd);
    }

    /**
     * 失敗の書き方も同じ順(実行と費用.md 4 節の「失敗の書き方」): 受け手の書き込み(通知)の後に、実行の行の鍵を最後に取る。
     */
    public function test_the_failure_locks_the_run_row_only_after_the_writes_of_the_receiver(): void
    {
        $runId = $this->queuedRun();
        $receiverEnd = null;
        $this->recordingKind->onFailed = function (FailedRun $run) use (&$receiverEnd): void {
            $message = new NoticeMessage('test.failed', null, null, null, []);
            app(SendNotices::class)->execute($message, [$run->requesterUserId]);
            $receiverEnd = count($this->statements);
        };
        DB::listen(function (QueryExecuted $query): void {
            if ($this->recording) {
                $this->statements[] = $query->sql;
            }
        });
        $this->recording = true;

        app(FailRun::class)->execute($runId, AiRunErrorCode::Provider, new RuntimeException('接続先の誤り'));

        $this->assertSame(AiRunStatus::Failed, AiRun::query()->findOrFail($runId)->status);
        $this->assertLocksAfterTheReceiver((int) $receiverEnd, self::NOTICE_WRITE);
    }

    /**
     * @param  string  $receiverPattern  受け手の鍵か書き込みの文(既定 = レコードの行の鍵)
     */
    private function assertLocksAfterTheReceiver(
        int $receiverEnd,
        string $receiverPattern = self::RECORD_ROW_LOCK,
    ): void {
        $receiverLocks = $this->indexesOf($receiverPattern);
        $runLocks = $this->indexesOf(self::RUN_ROW_LOCK);
        $this->assertNotSame([], $receiverLocks, '受け手の鍵を取った');
        $this->assertNotSame([], $runLocks, '実行の行の鍵を取った');
        $afterReceiver = fn (int $index): bool => $index >= $receiverEnd;
        $this->assertSame([], array_values(array_filter($receiverLocks, $afterReceiver)), '受け手の鍵は受け手の中');
        $this->assertSame($runLocks, array_values(array_filter($runLocks, $afterReceiver)), '実行の行の鍵と書き込みは受け手の後');
    }

    /**
     * @return list<int> 送った文のうち、正規表現に合うものの位置
     */
    private function indexesOf(string $pattern): array
    {
        $indexes = [];

        foreach ($this->statements as $index => $statement) {
            if (preg_match($pattern, $statement) === 1) {
                $indexes[] = $index;
            }
        }

        return $indexes;
    }

    private function noteRecord(): Record
    {
        $module = $this->recordModule('ai_notes');
        $this->field($module, 'summary');

        return Record::factory()->create(['record_module_id' => $module->id]);
    }
}
