<?php

namespace App\Actions\Ai\Core;

/**
 * ループの終わり方「失敗」の種類(docs の 設計/AI/会話の中核の口と受け手.md 13.3 の終わり方の表)。
 */
enum LoopFailure: string
{
    /** 不完全(出力の上限で切れた答えの続きを使い切った = 設計/AI/エージェントの実行/状態の移り方.md 9.11 の S20) */
    case ContinuationsExhausted = 'continuations_exhausted';

    /** 不完全(断り = 9.11 の S27) */
    case Refused = 'refused';

    /** 時間切れ(9.11 の S26。流れを読む途中の時間切れを含む) */
    case Timeout = 'timeout';

    /** 接続先(送り直しを使い切った・知らない終わりの理由) */
    case Provider = 'provider';
}
