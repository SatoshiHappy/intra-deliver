<?php

namespace App\Actions\Ai\Core;

/**
 * ループが使う受け手(docs の 設計/AI/会話の中核の口と受け手.md 13.3 の「受け手」)。③ の繋ぎが実装し、中核のテストは偽の受け手で閉じる。
 */
final readonly class LoopReceivers
{
    public function __construct(
        public ToolRunner $tools,
        public CoreNotifier $notifier,
        public CoreChecker $checker,
        public CoreClock $clock,
        public LoopTexts $texts,
        public CreatedFilesReader $createdFiles,
    ) {}
}
