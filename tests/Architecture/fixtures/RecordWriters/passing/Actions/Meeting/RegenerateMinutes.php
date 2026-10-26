<?php

namespace App\Actions\Meeting;

use App\Actions\Ai\Api\RequestPrompt;

/**
 * 依頼の口と音声の依頼の部品の一覧の class(議事録の再作成)
 */
final class RegenerateMinutes
{
    public function __construct(private RequestPrompt $requestPrompt, private MeetingAudioRequest $audioRequest) {}

    public function requestPrompt(): RequestPrompt
    {
        return $this->requestPrompt;
    }

    public function audioRequest(): MeetingAudioRequest
    {
        return $this->audioRequest;
    }
}
