<?php

namespace App\Actions\Meeting;

use App\Actions\Ai\Api\FailedRun;
use App\Enums\Ai\AiRunErrorCode;
use App\Enums\Meeting\MeetingNoticeReason;
use App\Enums\Meeting\MeetingRunStage;

/**
 * 会議の AI の実行の失敗を、通知の理由の文の分け方に写す 1 か所(docs の 設計/業務ツール/会議/通知と削除予定日.md の 6.4)。
 * 議事録と文字起こしの 2 つの種類が使い、種類ごとの違いだけを段で分ける。
 */
final class MeetingFailureReasons
{
    /**
     * 会議に書けない細かい理由(入れ物の検証が会議の更新を断った)。レコードが見つからない(404)は会議が無いので通知が出ない
     * (docs の 設計/業務ツール/会議/処理.md の 3.6)ため写さず、ほかの理由と同じに扱う。
     */
    private const RECORD_REASONS = [FailedRun::RECORD_REJECTED];

    /**
     * 段は、同じ失敗でも文字起こしと議事録で理由が分かれるため受け取る(分け方の正本 = 6.4)。
     */
    public static function of(FailedRun $run, MeetingRunStage $stage): MeetingNoticeReason
    {
        $truncated = $run->errorCode === AiRunErrorCode::Incomplete->value
            && $run->reason === FailedRun::OUTPUT_TRUNCATED;
        $transcription = $stage === MeetingRunStage::Transcription;
        $audioUnusable = $transcription && (
            $truncated
            || $run->errorCode === AiRunErrorCode::Conversion->value
            || ($run->errorCode === AiRunErrorCode::Completion->value
                && $run->reason === MeetingTranscriptionKind::TRANSCRIPT_EMPTY)
        );

        $minutesTooLong = $stage === MeetingRunStage::Minutes
            && $run->errorCode === AiRunErrorCode::Completion->value
            && $run->reason === MeetingMinutesKind::TOO_LONG;

        return match (true) {
            $audioUnusable => MeetingNoticeReason::AudioUnusable,
            $truncated, $minutesTooLong => MeetingNoticeReason::Truncated,
            in_array($run->reason, self::RECORD_REASONS, true) => MeetingNoticeReason::RecordUnavailable,
            default => MeetingNoticeReason::Other,
        };
    }
}
