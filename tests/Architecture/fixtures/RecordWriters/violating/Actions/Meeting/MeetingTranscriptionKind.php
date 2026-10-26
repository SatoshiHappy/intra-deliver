<?php

namespace App\Actions\Meeting;

use App\Actions\Meeting\MeetingAudioRequest;

/**
 * 依頼の口の一覧の場所(完了の受け手)も、音声の依頼の部品の一覧の外
 */
final class MeetingTranscriptionKind
{
    public function __construct(private MeetingAudioRequest $meetingAudioRequest) {}

    public function meetingAudioRequest(): MeetingAudioRequest
    {
        return $this->meetingAudioRequest;
    }
}
