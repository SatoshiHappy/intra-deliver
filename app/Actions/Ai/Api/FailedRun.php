<?php

namespace App\Actions\Ai\Api;

/**
 * 失敗した AI の実行({@see PromptKind::failed()} に渡す。docs の 設計/AI/公開の口.md 3.1、データと失敗と検査.md 7 節)。
 * 細かい理由は呼び手が通知の文を選ぶための値で、失敗の種類 incomplete と completion のときだけ入る(ほかは null)。
 */
final readonly class FailedRun
{
    /** incomplete: 出力が上限のトークンで切れた(応答の最後の終わりの理由が長さの上限) */
    public const OUTPUT_TRUNCATED = 'output_truncated';

    /** incomplete: 接続先が応答を断った */
    public const CONTENT_REFUSED = 'content_refused';

    /** completion: 完了の処理の中でレコードが見つからなかった(404。出どころによらない) */
    public const RECORD_NOT_FOUND = 'record_not_found';

    /** completion: 完了の処理の中でレコードの更新が断られた(422。持ち主の鍵と入れ物の検証を分けない) */
    public const RECORD_REJECTED = 'record_rejected';

    /**
     * @param  int  $runId  実行(ai_runs.id)
     * @param  string  $kindCode  種類のコード
     * @param  int|null  $recordId  対象のレコード(今の値。消されていれば null)
     * @param  int  $requesterUserId  依頼者(users.id)
     * @param  string  $errorCode  失敗の種類(ai_runs.error_code の値)
     * @param  string|null  $reason  細かい理由(このクラスの定数か、受け手が投げた {@see RunCompletionFailed} のコードか、2 段目の依頼が断られた
     *                               {@see RunRequestDeclined} のコード。無ければ null)
     */
    public function __construct(
        public int $runId,
        public string $kindCode,
        public ?int $recordId,
        public int $requesterUserId,
        public string $errorCode,
        public ?string $reason,
    ) {}
}
