<?php

namespace App\Actions\Ai\Core;

/**
 * 1 回の送信が使う受け手(docs の 設計/AI/会話の中核の口と受け手.md 13.3 の受け手のうち、送信が呼ぶ物)。③ の繋ぎが実装する。文の口は、送る履歴の
 * 項目と中核の中の値の型を送るときに今の版で組むために使う(docs の 設計/AI/会話の中核.md 13.2)。
 */
final readonly class SendReceivers
{
    public function __construct(
        public CoreChecker $checker,
        public CoreNotifier $notifier,
        public CoreClock $clock,
        public LoopTexts $texts,
    ) {}
}
