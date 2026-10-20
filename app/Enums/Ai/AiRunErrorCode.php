<?php

namespace App\Enums\Ai;

/**
 * AI の実行の失敗の種類(docs の 設計/AI/データと失敗と検査.md 7 節。表 ai_runs の error_code)。値は DB の検査制約と同じ一覧。
 */
enum AiRunErrorCode: string
{
    /** 接続先の誤り(HTTP の失敗・鍵の誤り・利用の上限)。使用量は分からない */
    case Provider = 'provider';

    /** 時間の上限。使用量は分からない */
    case Timeout = 'timeout';

    /** 出力が上限のトークンで切れた・接続先が応答を断った。使用量は分かる */
    case Incomplete = 'incomplete';

    /** 種類の実装の complete の例外(出力の解釈の失敗など)。使用量は分かる */
    case Completion = 'completion';

    /** worker が落ちた(ジョブの failed())。使用量は分からない */
    case WorkerLost = 'worker_lost';
}
