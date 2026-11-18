<?php

namespace App\Actions\Ai\Core;

use LogicException;

/**
 * 会話の中核の口(docs の 設計/AI/会話の中核.md 13.1、口の表 = 設計/AI/会話の中核の口と受け手.md 13.3。決定 = ADR 0049)。
 * 移した版(手元のリポジトリ)と代わりの版(公開)が実装し、使う者は ③ の繋ぎだけ。システムの型には受け手を通してだけ触れる。
 * 受け手の例外は終わり方を返さずに投げ直す(握らない = 設計/AI/エージェントの実行/状態の移り方.md 9.11 の S23)。
 */
interface ConversationCore
{
    /** 1 回の送信(種別は題だけ。題の送信がループの前に使う = 設計/AI/エージェントの実行/途中の表示と上限.md 9.10) */
    public function send(SendRequest $request): SendResult;

    /** ループ(回の答え・道具・続き・要約を、終わり方が決まるまで回す) */
    public function loop(LoopRequest $request): LoopOutcome;

    /**
     * 量を返す口(副作用が無い。起動の時の確かめが使う = 8 節・9.9)。
     *
     * @throws LogicException 読める量が無いとき(ループと量を返す口では必須)
     */
    public function thresholds(ModelSpec $model): CompactThresholds;
}
