<?php

namespace App\Actions\Meeting;

use App\Actions\Ai\Api\RequestTranscription;

/**
 * 依頼の口の一覧の class(作成と再作成だけが使う音声の依頼の部品)
 */
final class MeetingAudioRequest
{
    public function __construct(private RequestTranscription $requestTranscription) {}

    public function requestTranscription(): RequestTranscription
    {
        return $this->requestTranscription;
    }
}
