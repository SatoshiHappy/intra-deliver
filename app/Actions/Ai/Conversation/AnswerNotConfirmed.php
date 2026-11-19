<?php

namespace App\Actions\Ai\Conversation;

use RuntimeException;

/**
 * 答えの実行の始めの確かめが合わない(docs の 設計/AI/エージェントの実行/README.md 9.3 の「答えの実行の始めの確かめ」)。失敗の種類を
 * ほかの例外と分けるための型。
 */
final class AnswerNotConfirmed extends RuntimeException {}
