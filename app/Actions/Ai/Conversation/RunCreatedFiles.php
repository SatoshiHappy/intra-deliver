<?php

namespace App\Actions\Ai\Conversation;

use App\Actions\Ai\Core\CreatedFilesReader;

/**
 * 作ったファイルの読み手(docs の 設計/AI/会話の中核の口と受け手.md 13.3)。ファイルを作る道具(設計/AI/ファイルの作業場.md 12.1)を渡すまでは、
 * 会話に作ったファイルが無いので空を返す。
 */
final class RunCreatedFiles implements CreatedFilesReader
{
    public function forCompaction(): array
    {
        return [];
    }
}
