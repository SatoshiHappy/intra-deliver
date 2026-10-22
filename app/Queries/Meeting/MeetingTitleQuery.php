<?php

namespace App\Queries\Meeting;

use App\Enums\Ai\AiRunStatus;
use App\Enums\Meeting\MeetingRunOrigin;
use App\Enums\Meeting\MeetingRunStage;
use App\Models\Meeting\MeetingRun;
use App\Models\Meeting\Transcript;
use App\Queries\Ai\Api\RunQuery;

/**
 * 会議の件名が作成で入れた仮の値のままか(docs の 設計/業務ツール/会議/README.md の 1.2、文字起こしと議事録.md の 5.3)。
 * 主体の判定をしない(呼び手が会議の閲覧を判定した後に呼ぶ)。
 */
class MeetingTitleQuery
{
    /** 貼り付けで作った会議の仮の件名 */
    public const PASTED_TITLE = '貼り付けたテキスト';

    public function __construct(private RunQuery $runs, private MeetingAudioQuery $audio) {}

    /**
     * 件名を書く対応(AI が件名を書く)のある会議で、議事録の実行がどれも成功しておらず、今の件名が仮の値と同じか。件名を書く対応の無い会議は偽。
     * 仮の値 = 書き起こしのファイルの名前(貼り付けは {@see self::PASTED_TITLE})。書き起こしの無い音声の会議は、作成の文字起こしの実行の入力の音声の、
     * 会議の今の添付の名前(外されていれば仮でない)。
     */
    public function isProvisional(int $recordId, ?string $currentTitle): bool
    {
        $titleRuns = MeetingRun::query()->where(['record_id' => $recordId, 'writes_title' => true])
            ->get(['stage', 'origin', 'ai_run_id']);

        if ($titleRuns->isEmpty()) {
            return false;
        }

        $minutesRunIds = $titleRuns->where('stage', MeetingRunStage::Minutes)->pluck('ai_run_id')->all();
        $statuses = $this->runs->statusesOf(array_values(array_filter($minutesRunIds, 'is_int')));

        if (in_array(AiRunStatus::Succeeded->value, $statuses, true)) {
            return false;
        }

        $transcript = Transcript::query()->where('record_id', $recordId)->first(['file_name']);
        $transcriptionRunId = $titleRuns->first(fn (MeetingRun $run) => $run->stage === MeetingRunStage::Transcription
            && $run->origin === MeetingRunOrigin::Create)?->ai_run_id;
        $provisional = match (true) {
            $transcript !== null => $transcript->file_name ?? self::PASTED_TITLE,
            $transcriptionRunId !== null => $this->audioNameOf($recordId, $transcriptionRunId),
            default => null,
        };

        return $provisional !== null && $currentTitle === $provisional;
    }

    /**
     * 文字起こしの実行の入力の音声を付けた、会議の今の添付の名前(外されていれば null)。
     */
    private function audioNameOf(int $recordId, int $runId): ?string
    {
        $uploadId = $this->audio->inputUploadOf($runId);

        return $uploadId === null ? null : $this->audio->nameOf($recordId, $uploadId);
    }
}
