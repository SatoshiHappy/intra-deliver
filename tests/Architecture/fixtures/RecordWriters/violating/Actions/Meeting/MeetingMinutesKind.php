<?php

namespace App\Actions\Meeting;

use App\Actions\Ai\Api\RequestPrompt;

/**
 * 一覧に無い業務処理(議事録の種類 = 失敗の受け手を持つクラス)が依頼の口に依存するのは禁止
 */
final class MeetingMinutesKind
{
    public function __construct(private RequestPrompt $requestPrompt) {}

    public function requestPrompt(): RequestPrompt
    {
        return $this->requestPrompt;
    }
}
