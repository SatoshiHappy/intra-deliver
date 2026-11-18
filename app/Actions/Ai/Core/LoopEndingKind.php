<?php

namespace App\Actions\Ai\Core;

/**
 * ループの終わり方の種類(docs の 設計/AI/会話の中核の口と受け手.md 13.3 の終わり方の表)。
 */
enum LoopEndingKind: string
{
    case Succeeded = 'succeeded';

    case AwaitingConfirmation = 'awaiting_confirmation';

    case Stopped = 'stopped';

    case Failed = 'failed';
}
