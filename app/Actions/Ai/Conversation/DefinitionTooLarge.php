<?php

namespace App\Actions\Ai\Conversation;

use App\Enums\Ai\AiRunErrorCode;

/**
 * 外した後の定義の道具の定義の大きさが上限を超える(docs の 設計/AI/設定とプロンプト/README.md 11.3。外して直せないので失敗(設定))。
 */
final class DefinitionTooLarge extends ConversationStartFailure
{
    public function errorCode(): AiRunErrorCode
    {
        return AiRunErrorCode::Setting;
    }
}
