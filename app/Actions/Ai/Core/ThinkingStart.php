<?php

namespace App\Actions\Ai\Core;

use DateTimeImmutable;

/**
 * 思考の文脈の管理の始めの値(docs の 設計/AI/会話の中核.md 13.2 の「思考の文脈の管理」、受け取る物 = 設計/AI/会話の中核の口と受け手.md 13.3)。
 * 繋ぎがステップの行から引いて実行の始めに渡す(実行をまたいで、会話を 1 つの続いた処理として写す)。
 *
 * @param  bool  $idled  旗(最後の要約の境目より後の回の答えか続きの送信で、前の成功した送信の完了から 1 時間を超えたか)
 * @param  DateTimeImmutable|null  $lastCompletedAt  会話で最後に成功した送信の完了の時刻(どの種別も。無ければ null)
 */
final readonly class ThinkingStart
{
    public function __construct(
        public bool $idled,
        public ?DateTimeImmutable $lastCompletedAt,
    ) {}
}
