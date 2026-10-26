<?php

namespace App\Actions\Meeting;

use App\Actions\Ai\Api\CompletedTranscription;
use App\Actions\Ai\Api\FailedRun;
use App\Actions\Ai\Api\ReadRecordForRun;
use App\Actions\Ai\Api\RequestPrompt;
use App\Actions\Ai\Api\RunCompletionFailed;
use App\Actions\Ai\Api\RunRequestDeclined;
use App\Actions\Ai\Api\TranscribedSegment;
use App\Actions\Ai\Api\TranscriptionKind;
use App\Enums\Meeting\MeetingAiKind;
use App\Enums\Meeting\MeetingNoticeReason;
use App\Enums\Meeting\MeetingNoticeType;
use App\Enums\Meeting\MeetingRunDecline;
use App\Enums\Meeting\MeetingRunStage;
use App\Enums\Meeting\TranscriptSource;
use App\Models\Meeting\MeetingRun;
use App\Queries\Meeting\MeetingAudioQuery;
use App\Queries\Meeting\MeetingFieldsQuery;
use App\Queries\Meeting\MeetingTitleQuery;
use App\Queries\Records\Api\RecordDisplayQuery;
use LogicException;

/**
 * 会議の文字起こしの種類(docs の 設計/業務ツール/会議/処理.md の 3.3・3.6、文字起こしと議事録.md の 4 節)。議事録の依頼が断られた 422 だけを
 * 受け止めるのは、費用をかけた書き起こしを巻き戻さないため。起動がタグ {@see TranscriptionKind::TAG} で登録する。
 */
class MeetingTranscriptionKind implements TranscriptionKind
{
    public const CODE = MeetingAiKind::Transcription->value;

    /** 区間が 0 か、すべての区間の発言が空 */
    public const TRANSCRIPT_EMPTY = 'transcript_empty';

    /** 話者のラベルの最大の文字数(transcript_segments.speaker_label の varchar(100)) */
    private const SPEAKER_MAX_LENGTH = 100;

    /** 区間の秒の最大(transcript_segments の started_at_sec・ended_at_sec の decimal(10,3)) */
    private const SECONDS_MAX = 9999999.999;

    public function __construct(
        private MeetingLock $lock,
        private RecordDisplayQuery $records,
        private MeetingAudioQuery $audio,
        private MeetingTranscriptWriter $transcripts,
        private ReadRecordForRun $readRecord,
        private MeetingTitleQuery $titles,
        private MinutesPrompt $prompts,
        private RequestPrompt $requestPrompt,
        private MeetingNotices $notices,
    ) {}

    public function code(): string
    {
        return self::CODE;
    }

    public function writesTo(): ?string
    {
        return null;
    }

    /**
     * 対応の行は会議ごとの鍵の後に引く。無ければ会議が消えたので何もしない(通知も送らない)。
     *
     * 材料の会議の欄は AI の読む口で読む(依頼者を判定しない = 処理.md の 3.11、文字起こしと議事録.md の 5.3)。
     *
     * @throws RunCompletionFailed 区間が空のとき
     * @throws LogicException 会議があるのに対応の行が無い・対応の行があるのに会議を読めないとき(整合の誤り)
     */
    public function complete(CompletedTranscription $run): void
    {
        if ($run->recordId !== null) {
            $this->lock->lock($run->recordId);
        }

        $meetingRun = MeetingRun::query()->where('ai_run_id', $run->runId)->first();

        if ($meetingRun === null) {
            $this->ensureMeetingGone($run->recordId, $run->runId);

            return;
        }

        $recordId = $meetingRun->record_id;
        $lines = self::lines($run->segments);
        $fileName = $this->fileNameOf($recordId, $run->runId);
        $this->transcripts->write($recordId, TranscriptSource::Audio, $fileName, $lines, $run->runId, $run->chunkCount);

        $context = $run->requester;
        $meeting = $this->readRecord->execute($run->runId)
            ?? throw new LogicException("文字起こしの実行 {$run->runId} の会議 {$recordId} を、対応の行があるのに読めない");
        $fields = MeetingFieldsQuery::fromValues($meeting['record']['values']);
        $provisional = $meetingRun->writes_title || $this->titles->isProvisional($recordId, $fields->title);
        $title = $provisional ? null : $fields->title;
        $request = $this->prompts->request($recordId, $fields, $title, $lines);
        $attributes = [
            'record_id' => $recordId,
            'stage' => MeetingRunStage::Minutes,
            'origin' => $meetingRun->origin,
            'writes_title' => $meetingRun->writes_title,
        ];

        try {
            $minutesRunId = $this->requestPrompt->execute($context, $request);
        } catch (RunRequestDeclined $declined) {
            $this->decline($attributes, $declined, $run->requesterUserId);

            return;
        }

        MeetingRun::query()->create([...$attributes, 'ai_run_id' => $minutesRunId]);
    }

    /**
     * 会議が無ければ(主体の判定をしない存在の確かめ)何もしない。
     */
    public function failed(FailedRun $run): void
    {
        if ($run->recordId === null || $this->records->topLevelModuleCodeOf($run->recordId) === null) {
            return;
        }

        $reason = MeetingFailureReasons::of($run, MeetingRunStage::Transcription);
        $this->notices->send(MeetingNoticeType::TranscriptionFailed, $run->recordId, $run->requesterUserId, $reason);
    }

    /**
     * @throws LogicException
     */
    private function ensureMeetingGone(?int $recordId, int $runId): void
    {
        if ($recordId !== null && $this->records->topLevelModuleCodeOf($recordId) !== null) {
            throw new LogicException("文字起こしの実行 {$runId} の会議 {$recordId} に、会議と実行の対応が無い");
        }
    }

    /**
     * 区間を発言の行にする(発言が空の区間は飛ばす。話者のラベルは空なら無し、長ければ列の長さで切る。秒は小数 3 桁)。
     * 接続先の秒は確かめられていないので、列の検査制約に当たらない形に整える(終わりが始まりより前なら終わりを始まりにそろえ、発言は残す)。
     *
     * @param  list<TranscribedSegment>  $segments
     * @return list<TranscriptLine>
     *
     * @throws RunCompletionFailed 行が 0 のとき
     */
    private static function lines(array $segments): array
    {
        $lines = [];

        foreach ($segments as $segment) {
            $text = TextLines::trim($segment->text);

            if ($text === '') {
                continue;
            }

            $label = mb_substr(TextLines::trim($segment->speakerLabel), 0, self::SPEAKER_MAX_LENGTH);
            $start = self::seconds($segment->startSeconds);
            $end = max($start, self::seconds($segment->endSeconds));
            $lines[] = new TranscriptLine(
                $text,
                $label === '' ? null : $label,
                number_format($start, 3, '.', ''),
                number_format($end, 3, '.', ''),
            );
        }

        return $lines === []
            ? throw new RunCompletionFailed(self::TRANSCRIPT_EMPTY, '文字起こしの区間が 0 か、すべての発言が空')
            : $lines;
    }

    /**
     * 秒を小数 3 桁に丸め、0 から列の最大までに収める(丸めた後に収めるので、丸めで列の桁を超えない)。
     */
    private static function seconds(float $seconds): float
    {
        return min(round(max(0.0, $seconds), 3), self::SECONDS_MAX);
    }

    /**
     * 書き起こしのファイルの名前 = 実行の入力の音声を付けた会議の今の添付の名前(外されていれば {@see MeetingAudioQuery::UNKNOWN_FILE_NAME})。
     */
    private function fileNameOf(int $recordId, int $runId): string
    {
        $uploadId = $this->audio->inputUploadOf($runId);
        $name = $uploadId === null ? null : $this->audio->nameOf($recordId, $uploadId);

        return $name ?? MeetingAudioQuery::UNKNOWN_FILE_NAME;
    }

    /**
     * 議事録の依頼が断られた対応(実行なし・理由・依頼した人)を書き、議事録の失敗の通知を送る(通知と削除予定日.md の 6.1)。
     *
     * @param  array<string, mixed>  $attributes
     */
    private function decline(array $attributes, RunRequestDeclined $declined, int $requesterUserId): void
    {
        $decline = MeetingRunDecline::from($declined->reason);
        MeetingRun::query()->create([
            ...$attributes,
            'ai_run_id' => null,
            'declined_code' => $decline,
            'requester_user_id' => $requesterUserId,
        ]);
        $reason = MeetingNoticeReason::from($decline->value);
        $recordId = (int) $attributes['record_id'];
        $this->notices->send(MeetingNoticeType::MinutesFailed, $recordId, $requesterUserId, $reason);
    }
}
