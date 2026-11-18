<?php

namespace App\Actions\Ai\Core;

/**
 * 1 回の送信の失敗の種類(docs の 設計/AI/会話の中核の口と受け手.md 13.3 の口の表)。要約の予備へ進む・題を作らずに続けるのは、
 * この種類と終わりの理由の表(設計/AI/会話の中核.md 13.6)で決まるときだけ。
 */
enum SendFailure: string
{
    /** 接続先(送り直しを使い切った・知らない終わりの理由・照合に落ちた断りを含む) */
    case Provider = 'provider';

    /** 時間切れ(流れを読む途中の時間切れを含む) */
    case Timeout = 'timeout';

    /** 長すぎる(接続先の性質の句で見分けた断り) */
    case TooLong = 'too_long';
}
