<?php

namespace App\Actions\Ai\Conversation;

/**
 * 送る前の確かめの関数の結果の種類(docs の 設計/AI/エージェントの実行/README.md 9.2 の表の結果の欄)。
 */
enum SendCheckResult
{
    /** ステップの行を作って見積もりを積んだ(送る) */
    case Send;

    /** ループの送信を止める */
    case Stop;

    /** 会話の題を送らない(題なし。実行の状態を変えない) */
    case NotSent;

    /**
     * 実行がもう処理中でない(ステップの行を足さない。ほかの処理が実行を終えたため = 設計/AI/エージェントの実行/状態の移り方.md 9.11 の
     * 「終わった実行に処理中のステップの行が残る」を起こさない)
     */
    case Ended;
}
