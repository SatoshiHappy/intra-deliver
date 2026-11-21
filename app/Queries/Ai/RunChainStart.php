<?php

namespace App\Queries\Ai;

use App\Support\AccessContext;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * 会話の最初に始まった実行の始めの日(docs の 設計/AI/会話の中核.md 13.2 の「先頭の日付の文」の値。東京の日)。実行の鎖のうち、始めの時刻の
 * ある実行で鎖の根にいちばん近い物を選ぶ(待ちのまま終わった実行は始めの時刻が無い)。
 */
final class RunChainStart
{
    /**
     * @throws LogicException 鎖に始まった実行が無いとき(今の実行は処理中なので、少なくとも今の実行が始まっている)
     */
    public function firstRunDate(int $runId): string
    {
        $row = DB::selectOne(
            RunChain::cte().' SELECT started_at FROM chain WHERE started_at IS NOT NULL ORDER BY depth DESC LIMIT 1',
            [$runId],
        );
        $startedAt = is_object($row) && is_string($row->started_at ?? null)
            ? $row->started_at
            : throw new LogicException("実行 {$runId} の鎖に始まった実行が無い");

        $tokyo = new DateTimeZone(AccessContext::TIMEZONE);

        return (new DateTimeImmutable($startedAt))->setTimezone($tokyo)->format('Y-m-d');
    }
}
