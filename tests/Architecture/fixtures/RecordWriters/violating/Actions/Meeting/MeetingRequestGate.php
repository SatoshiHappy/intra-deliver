<?php

namespace App\Actions\Meeting;

use App\Actions\Ai\Api\RequestTranscription;

/**
 * 入口の判定の部品も依頼の口の一覧の外(文だけを使うなら RunRequestMessages)
 */
final class MeetingRequestGate
{
    public function __construct(private RequestTranscription $requestTranscription) {}

    public function requestTranscription(): RequestTranscription
    {
        return $this->requestTranscription;
    }
}
