<?php

namespace App\Actions\Ai\Core\Substitute;

use RuntimeException;

/**
 * 受け手が会話が無いを返した(ループの中だけで投げ、ループが止めた(利用者)で終える = docs の
 * 設計/AI/エージェントの実行/状態の移り方.md 9.11 の S4。それより後は書かない)。
 */
final class ConversationEnded extends RuntimeException {}
