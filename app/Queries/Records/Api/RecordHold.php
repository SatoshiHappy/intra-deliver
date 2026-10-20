<?php

namespace App\Queries\Records\Api;

/**
 * レコードの持ち主({@see RecordHolds} が返す値。docs の 設計/ワークフロー/業務データとの接続/README.md 4 節)。
 */
final readonly class RecordHold
{
    /**
     * @param  string  $holder  持ち主の鍵(例 'workflow-case:12')
     * @param  string  $reason  拒むときの文言
     */
    public function __construct(
        public string $holder,
        public string $reason,
    ) {}
}
