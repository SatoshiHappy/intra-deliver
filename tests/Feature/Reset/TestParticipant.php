<?php

namespace Tests\Feature\Reset;

use App\Actions\Reset\Api\ResetParticipant;
use Closure;

/** 初期化に加わる口のテスト用の実装(空にする直前に渡した処理を呼ぶ。残す表は無い) */
class TestParticipant implements ResetParticipant
{
    public static ?Closure $before = null;

    public function keptTables(): array
    {
        return [];
    }

    public function beforeTruncate(): void
    {
        if (self::$before !== null) {
            (self::$before)();
        }
    }
}
