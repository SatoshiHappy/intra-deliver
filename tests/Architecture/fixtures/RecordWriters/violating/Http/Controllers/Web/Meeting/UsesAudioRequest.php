<?php

namespace App\Http\Controllers\Web\Meeting;

use App\Actions\Meeting\MeetingAudioRequest;

/**
 * 会議の入口のコントローラが業務処理を通らずに音声の依頼の部品に依存するのは禁止
 */
final class UsesAudioRequest
{
    public function __construct(private MeetingAudioRequest $meetingAudioRequest) {}

    public function meetingAudioRequest(): MeetingAudioRequest
    {
        return $this->meetingAudioRequest;
    }
}
