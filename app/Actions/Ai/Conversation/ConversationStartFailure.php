<?php

namespace App\Actions\Ai\Conversation;

use App\Enums\Ai\AiRunErrorCode;
use RuntimeException;

/**
 * 会話のジョブが実行の始めの確かめで失敗にする件(失敗の種類をほかの例外の完了の処理と分けるための型。docs の
 * 設計/AI/エージェントの実行/状態の移り方.md 9.11 の表)。
 */
abstract class ConversationStartFailure extends RuntimeException
{
    abstract public function errorCode(): AiRunErrorCode;
}
