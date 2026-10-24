<?php

namespace App\Enums\Reset;

/**
 * 初期化の記録の行の成否(表 reset_runs の result。動いている間と落ちた回は null = docs の 設計/公開の配備/毎日の初期化.md 4 節)。
 */
enum ResetResult: string
{
    case Succeeded = 'succeeded';

    case Failed = 'failed';
}
