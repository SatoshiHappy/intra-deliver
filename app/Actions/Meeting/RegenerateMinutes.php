<?php

namespace App\Actions\Meeting;

use App\Actions\Ai\Api\RequestPrompt;
use App\Actions\Ai\Api\RunRequestDeclined;
use App\Enums\Meeting\MeetingProcessingState;
use App\Enums\Meeting\MeetingRunOrigin;
use App\Enums\Meeting\MeetingRunStage;
use App\Models\Meeting\MeetingRun;
use App\Models\Meeting\Transcript;
use App\Models\Meeting\TranscriptSegment;
use App\Queries\Authz\Api\Requester;
use App\Queries\Meeting\MeetingFieldsQuery;
use App\Queries\Meeting\MeetingLimits;
use App\Queries\Meeting\MeetingProcessingQuery;
use App\Queries\Meeting\MeetingTitleQuery;
use App\Queries\Records\Api\RecordHoldQuery;
use App\Support\AccessContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;
use Illuminate\Validation\ValidationException;

/**
 * 書き起こしから議事録を作り直す(docs の 設計/業務ツール/会議/処理.md の 3.7、材料 = 文字起こしと議事録.md の 5.3・5.4)。会議ごとの勧告ロックの中で
 * 押せる時を確かめてから依頼する(鍵の順 = 会議ごとの鍵 → AI の費用の鍵 = 設計/業務データの入れ物/レコードの共通機能/データモデル/README.md の 4 節)。
 * 再作成は件名を書かない。入口の判定は {@see MeetingRequestGate::ensureCanRegenerate()}。
 */
class RegenerateMinutes
{
    /** 追加の指示のキー */
    public const INSTRUCTIONS = 'instructions';

    /** 押せる時に合わないときのキー */
    public const STATE_KEY = 'meeting';

    public const INSTRUCTIONS_TOO_LONG = '追加の指示は '.MeetingLimits::INSTRUCTIONS_MAX_LENGTH.' 字までにしてください';

    public const INSTRUCTIONS_INVALID_CHARACTER = '追加の指示に使えない文字が含まれています';

    public const HELD = '申請中のため再作成できません。';

    public const PROCESSING = '処理中のため再作成できません。完了してからお試しください';

    public const NO_TRANSCRIPT = '書き起こしと音声が無いため、再作成できません';

    /** 会議ごとの勧告ロックの鍵の起点(ほかの勧告ロックの鍵と重ならない空間に、会議の id を足す) */
    private const LOCK_KEY_BASE = 4_500_000_000_000_000;

    /** 発言の行を読む単位 */
    private const CHUNK_SIZE = 1000;

    public function __construct(
        private RecordHoldQuery $holds,
        private MeetingProcessingQuery $states,
        private MeetingFieldsQuery $fields,
        private MeetingTitleQuery $titles,
        private MinutesPrompt $prompts,
        private RequestPrompt $requestPrompt,
    ) {}

    /**
     * @param  string|null  $instructions  追加の指示(任意)
     * @return int 依頼した実行の id
     *
     * @throws ValidationException 追加の指示の誤り(キー instructions)・申請中・処理中・書き起こしが無いとき(キー meeting)
     * @throws RunRequestDeclined 依頼が上限を超える・長すぎるとき(キー ai)
     * @throws ModelNotFoundException 依頼者が会議を閲覧できないとき
     */
    public function execute(AccessContext $context, int $recordId, ?string $instructions): int
    {
        $instructions = $instructions === '' ? null : $instructions;
        self::ensureInstructions($instructions);

        return DB::transaction(function () use ($context, $recordId, $instructions): int {
            DB::select('SELECT pg_advisory_xact_lock(?)', [self::LOCK_KEY_BASE + $recordId]);
            $transcriptId = $this->regenerableTranscript($recordId);
            $requester = Requester::fromAccessContext($context);
            $fields = $this->fields->read($requester, $recordId, $context->date, $context->localeCode);
            $title = $this->titles->isProvisional($recordId, $fields->title) ? null : $fields->title;
            $request = $this->prompts->request($recordId, $fields, $title, self::lines($transcriptId), $instructions);
            $runId = $this->requestPrompt->execute($context, $request);
            MeetingRun::query()->create([
                'record_id' => $recordId,
                'ai_run_id' => $runId,
                'stage' => MeetingRunStage::Minutes,
                'origin' => MeetingRunOrigin::Regenerate,
                'writes_title' => false,
            ]);

            return $runId;
        });
    }

    /**
     * @throws ValidationException
     */
    private static function ensureInstructions(?string $instructions): void
    {
        if ($instructions === null) {
            return;
        }

        if (! mb_check_encoding($instructions, 'UTF-8') || str_contains($instructions, "\0")) {
            throw ValidationException::withMessages([self::INSTRUCTIONS => self::INSTRUCTIONS_INVALID_CHARACTER]);
        }

        if (mb_strlen($instructions) > MeetingLimits::INSTRUCTIONS_MAX_LENGTH) {
            throw ValidationException::withMessages([self::INSTRUCTIONS => self::INSTRUCTIONS_TOO_LONG]);
        }
    }

    /**
     * 押せる時(申請中でない・処理中でない・書き起こしがある)を確かめ、書き起こしの id を返す。
     *
     * @throws ValidationException
     */
    private function regenerableTranscript(int $recordId): int
    {
        if ($this->holds->isHeld($recordId)) {
            throw self::notRegenerable(self::HELD);
        }

        if (($this->states->statesOf([$recordId])[$recordId] ?? null) === MeetingProcessingState::Processing) {
            throw self::notRegenerable(self::PROCESSING);
        }

        $transcriptId = Transcript::query()->where('record_id', $recordId)->value('id');

        return is_int($transcriptId) ? $transcriptId : throw self::notRegenerable(self::NO_TRANSCRIPT);
    }

    private static function notRegenerable(string $message): ValidationException
    {
        return ValidationException::withMessages([self::STATE_KEY => $message]);
    }

    /**
     * @return LazyCollection<int, TranscriptLine>
     */
    private static function lines(int $transcriptId): LazyCollection
    {
        return TranscriptSegment::query()->where('transcript_id', $transcriptId)->orderBy('seq')->lazy(self::CHUNK_SIZE)
            ->map(fn (TranscriptSegment $segment) => new TranscriptLine(
                $segment->text,
                $segment->speaker_label,
                $segment->started_at_sec,
                $segment->ended_at_sec,
            ));
    }
}
