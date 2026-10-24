<?php

namespace App\Enums\Reset;

/**
 * 初期化のコマンドの 1 回の結果(docs の 設計/公開の配備/毎日の初期化.md 2 節の「始めるか」の表の結果)。Disabled〜AlreadyTried が始めなかった回。
 */
enum ResetOutcome: string
{
    case Disabled = 'disabled';

    case Locked = 'locked';

    case Maintenance = 'maintenance';

    case AlreadyTried = 'already-tried';

    case Succeeded = 'succeeded';

    case Failed = 'failed';

    /** 終了コード(「始めるか」の表の終了コードの列) */
    public function exitCode(ResetTrigger $trigger): int
    {
        return match (true) {
            $this === self::Succeeded => 0,
            $this === self::Disabled, $this === self::Failed => 1,
            default => $trigger === ResetTrigger::Schedule ? 0 : 1,
        };
    }
}
