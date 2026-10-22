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
     * 件名を書く対応(AI が件名を書く)のある会議で、議事録の実行がどれも成功しておらず、今の件名が仮の値(作成で取り込んだ名前)と同じか。
     * 件名を書く対応の無い会議は偽。作成で取り込んだ音声の名前が分からない会議は、人が入れたと分からないので真
     * (docs の 設計/業務ツール/会議/文字起こしと議事録.md の 5.3)。
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

        $transcriptionRunId = $titleRuns->first(fn (MeetingRun $run) => $run->stage === MeetingRunStage::Transcription
            && $run->origin === MeetingRunOrigin::Create)?->ai_run_id;

        return $this->matchesProvisional($recordId, $transcriptionRunId, $currentTitle);
    }

    /**
     * 書き起こしのファイルの名前は、作成の文字起こしが作った書き起こしのときだけ仮の値に使う(再作成で別の音声から作った名前は取り込んだ名前でない)。
     *
     * @param  int|null  $transcriptionRunId  作成の文字起こしの実行(テキスト・貼り付けの作成は null)
     */
    private function matchesProvisional(int $recordId, ?int $transcriptionRunId, ?string $currentTitle): bool
    {
        $transcript = Transcript::query()->where('record_id', $recordId)->first(['file_name', 'ai_run_id']);

        if ($transcriptionRunId === null) {
            return $transcript !== null && $currentTitle === ($transcript->file_name ?? self::PASTED_TITLE);
        }

        $name = $transcript !== null && $transcript->ai_run_id === $transcriptionRunId
            ? $transcript->file_name
            : $this->audioNameOf($recordId, $transcriptionRunId);

        return $name === null || $name === MeetingAudioQuery::UNKNOWN_FILE_NAME || $currentTitle === $name;
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
