<?php

namespace App\Queries\Ai;

use Illuminate\Support\Facades\DB;

/**
 * 実行の鎖(今の実行から前の実行をたどった列)の道具の行にある呼び出しの id(一意の範囲 = docs の 設計/AI/実行の記録.md 2.4)。
 * 長さの上限を置かない id は md5 の索引で引く(表の一意の索引と同じ式)。
 */
final class RunChainCalls
{
    /**
     * 渡した呼び出しの id のうち、鎖の道具の行に既にある物(渡した順)。
     *
     * @param  list<string>  $callIds
     * @return list<string>
     */
    public function existing(int $runId, array $callIds): array
    {
        if ($callIds === []) {
            return [];
        }

        $marks = implode(', ', array_fill(0, count($callIds), 'md5(?)'));
        $found = DB::select(
            RunChain::cte().' SELECT call_id FROM ai_tool_calls'
            ." WHERE ai_run_id IN (SELECT id FROM chain) AND md5(call_id) IN ({$marks})",
            [$runId, ...$callIds],
        );
        $existing = array_map(strval(...), array_column($found, 'call_id'));

        return array_values(array_filter($callIds, static fn (string $id): bool => in_array($id, $existing, true)));
    }
}
