<?php

namespace App\Actions\Ai\Conversation;

use App\Actions\Ai\Core\ConversationCore;
use App\Actions\Ai\Core\Substitute\SubstituteConversationCore;
use Illuminate\Contracts\Container\Container;

/**
 * 会話の中核の版を選ぶ 1 か所(docs の 設計/AI/会話の中核.md 13.1、機械検査 = 設計/AI/検査で守る結果.md の (f))。既定は代わりの版で、手元だけの
 * リポジトリのプロバイダが後から移した版で結び直す(指示の組み立ての口と同じ形 = 設計/AI/設定とプロンプト/README.md 11.4)。
 */
final class CoreVersion
{
    public static function bindDefault(Container $container): void
    {
        $container->bind(ConversationCore::class, SubstituteConversationCore::class);
    }
}
