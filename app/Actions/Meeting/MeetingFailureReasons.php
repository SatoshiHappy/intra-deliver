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
    /** 書き込みの口が断った・依頼者が会議を読めない細かい理由 */
    private const RECORD_REASONS = [
        FailedRun::RECORD_FORBIDDEN,
        FailedRun::RECORD_NOT_FOUND,
        FailedRun::RECORD_REJECTED,
        FailedRun::REQUESTER_INACTIVE,
    ];

    /**
     * 出力が切れた = 議事録は「切れた」、文字起こしは音声から書き起こせない。文字起こしは、区間が空・音声の変換の失敗(実体が無いを含む)も
     * 音声から書き起こせない。会議に書けない・読めない理由は種類によらない。ほかは「ほか」。
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

        return match (true) {
            $audioUnusable => MeetingNoticeReason::AudioUnusable,
            $truncated => MeetingNoticeReason::Truncated,
            in_array($run->reason, self::RECORD_REASONS, true) => MeetingNoticeReason::RecordUnavailable,
            default => MeetingNoticeReason::Other,
        };
    }
}
