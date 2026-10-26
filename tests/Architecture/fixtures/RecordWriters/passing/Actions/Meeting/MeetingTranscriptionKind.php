<?php

namespace App\Actions\Meeting;

use App\Actions\Ai\Api\RequestPrompt;

/**
 * 依頼の口の一覧の class(会議の文字起こしの種類の完了の受け手。2 段目の議事録の依頼)
 */
final class MeetingTranscriptionKind
{
    public function __construct(private RequestPrompt $requestPrompt) {}

    public function requestPrompt(): RequestPrompt
    {
        return $this->requestPrompt;
    }
}
