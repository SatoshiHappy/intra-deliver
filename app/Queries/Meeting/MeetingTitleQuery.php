<?php

namespace App\Queries\Meeting;

use App\Enums\Ai\AiRunStatus;
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

    public function __construct(private RunQuery $runs) {}

    /**
     * 件名を書く対応(段 = 議事録・AI が件名を書く)の実行がどれも成功しておらず、今の件名が仮の値(書き起こしのファイル名、貼り付けは
     * {@see self::PASTED_TITLE})と同じか。件名を書く対応の無い会議は偽。
     */
    public function isProvisional(int $recordId, ?string $currentTitle): bool
    {
        $titleRuns = MeetingRun::query()
            ->where(['record_id' => $recordId, 'stage' => MeetingRunStage::Minutes, 'writes_title' => true])
            ->pluck('ai_run_id');

        if ($titleRuns->isEmpty()) {
            return false;
        }

        $statuses = $this->runs->statusesOf(array_values(array_filter($titleRuns->all(), 'is_int')));
        $succeeded = in_array(AiRunStatus::Succeeded->value, $statuses, true);
        $fileName = Transcript::query()->where('record_id', $recordId)->value('file_name');

        return ! $succeeded && $currentTitle === (is_string($fileName) ? $fileName : self::PASTED_TITLE);
    }
}
