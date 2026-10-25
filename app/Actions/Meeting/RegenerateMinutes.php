<?php

namespace App\Actions\Meeting;

use App\Actions\Ai\Api\RequestPrompt;
use App\Actions\Ai\Api\RunRequestDeclined;
use App\Enums\Meeting\MeetingProcessingState;
use App\Enums\Meeting\MeetingRunOrigin;
use App\Enums\Meeting\MeetingRunStage;
use App\Models\Meeting\MeetingRun;
use App\Models\Meeting\TranscriptSegment;
use App\Queries\Authz\Api\Requester;
use App\Queries\Meeting\MeetingFieldsQuery;
use App\Queries\Meeting\MeetingLimits;
use App\Queries\Meeting\MeetingProcessingQuery;
use App\Queries\Meeting\MeetingRegenerableQuery;
use App\Queries\Meeting\MeetingTitleQuery;
use App\Support\AccessContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;
use Illuminate\Validation\ValidationException;

/**
 * 議事録を作り直す(docs の 設計/業務ツール/会議/処理.md の 3.7、材料 = 文字起こしと議事録.md の 5.3・5.4)。会議ごとの勧告ロックの中で
 * 押せる会議と押せる時を確かめてから依頼する(鍵の順 = 会議ごとの鍵 → AI の費用の鍵 = 設計/業務データの入れ物/レコードの共通機能/データモデル/README.md の 4 節)。
 * 書き起こしがあれば書き起こしから議事録を、無ければ会議の今の添付のうち受ける形式で実体のある最も新しい音声から文字起こしを依頼する
 * (完了の後に続けて議事録を作る)。再作成は件名を書かない。入口の判定は {@see MeetingRequestGate::ensureCanRegenerate()}。
 */
class RegenerateMinutes
{
    /** 追加の指示のキー */
    public const INSTRUCTIONS = 'instructions';

    /** 押せる時に合わないときのキー */
    public const STATE_KEY = 'meeting';

    public const INSTRUCTIONS_TOO_LONG = '追加の指示は '.MeetingLimits::INSTRUCTIONS_MAX_LENGTH.' 字までにしてください';

    public const INSTRUCTIONS_INVALID_CHARACTER = '追加の指示に使えない文字が含まれています';

    public const NOT_FROM_AUDIO = '音声から作成した会議でないため、再作成できません';

    public const PROCESSING = '処理中のため再作成できません。完了してからお試しください';

    public const INSTRUCTIONS_WITHOUT_TRANSCRIPT = '書き起こしが無いため、追加の指示は付けられません';

    /** 発言の行を読む単位 */
    private const CHUNK_SIZE = 1000;

    public function __construct(
        private MeetingLock $lock,
        private MeetingAudioRequest $audioRequest,
        private MeetingProcessingQuery $states,
        private MeetingRegenerableQuery $regenerables,
        private MeetingFieldsQuery $fields,
        private MeetingTitleQuery $titles,
        private MinutesPrompt $prompts,
        private RequestPrompt $requestPrompt,
    ) {}

    /**
     * @param  string|null  $instructions  追加の指示(任意)
     * @return int 依頼した実行の id
     *
     * @throws ValidationException 追加の指示の誤り・書き起こしが無く音声から作り直すのに追加の指示があるとき(キー instructions)・
     *                             押せる会議でない・処理中・書き起こしと音声が無いとき(キー meeting)
     * @throws RunRequestDeclined 依頼が上限を超える・長すぎる・音声を読み取れない・音声が削除されたとき(キー ai)
     * @throws ModelNotFoundException 依頼者が会議を閲覧できない・会議が無いとき
     */
    public function execute(AccessContext $context, int $recordId, ?string $instructions): int
    {
        $instructions = $instructions === '' ? null : $instructions;
        self::ensureInstructions($instructions);

        return DB::transaction(function () use ($context, $recordId, $instructions): int {
            $this->lock->lock($recordId);
            $transcriptId = $this->ensureRegenerable($recordId);

            $material = $this->regenerables->materialOf($recordId, $transcriptId);

            if ($transcriptId === null) {
                return $this->fromAudio($context, $recordId, $material['audioUploadId'], $instructions);
            }

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
     * 書き起こしの無い会議の、音声からの再作成(追加の指示は受けない = 処理.md の 3.7)。
     *
     * @throws ValidationException
     * @throws RunRequestDeclined
     */
    private function fromAudio(AccessContext $context, int $recordId, ?int $uploadId, ?string $instructions): int
    {
        $uploadId ??= throw self::notRegenerable(MeetingRegenerableQuery::NO_MATERIAL);

        if ($instructions !== null) {
            throw ValidationException::withMessages([self::INSTRUCTIONS => self::INSTRUCTIONS_WITHOUT_TRANSCRIPT]);
        }

        return $this->audioRequest->execute($context, $recordId, $uploadId, MeetingRunOrigin::Regenerate, false);
    }

    /**
     * 押せる会議と押せる時(処理中でない)を確かめ、書き起こしの id を返す(無ければ null)。会議は申請書にならないので申請中は起きない(処理.md の 3.7)。
     *
     * @throws ValidationException
     */
    private function ensureRegenerable(int $recordId): ?int
    {
        $found = $this->regenerables->of($recordId);

        if (! $found['regenerable']) {
            throw self::notRegenerable(self::NOT_FROM_AUDIO);
        }

        if (($this->states->statesOf([$recordId])[$recordId] ?? null) === MeetingProcessingState::Processing) {
            throw self::notRegenerable(self::PROCESSING);
        }

        return $found['transcriptId'];
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
