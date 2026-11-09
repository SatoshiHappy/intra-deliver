<?php

namespace App\Actions\Ai\Api;

/**
 * 失敗した会話の型の実行({@see ConversationKind::failed()} に渡す。docs の 設計/AI/エージェントの実行/口と契約.md 9.13 の「失敗の受け手」)。
 * 項目は {@see FailedRun} から対象のレコードを除き(会話の型は持たない)、会話の題を足したもの。細かい理由は {@see FailedRun} と同じ一覧
 * (会話の型で増えた失敗の種類 setting・confirmation には無い)。
 */
final readonly class ConversationFailed
{
    /**
     * @param  int  $runId  実行(ai_runs.id)
     * @param  string  $kindCode  種類のコード
     * @param  int  $requesterUserId  依頼者(users.id)
     * @param  string  $errorCode  失敗の種類(ai_runs.error_code の値)
     * @param  string|null  $reason  細かい理由(無ければ null)
     * @param  string|null  $title  会話の題(求めて作れたときだけ。① の規則に合うかは受け手が確かめる = 9.10)
     */
    public function __construct(
        public int $runId,
        public string $kindCode,
        public int $requesterUserId,
        public string $errorCode,
        public ?string $reason,
        public ?string $title,
    ) {}
}
